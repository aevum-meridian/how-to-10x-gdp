<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Joint\Crisis\Enums\ClaimCategory;
use App\Domain\Joint\Crisis\Enums\DisclosureKind;
use App\Domain\Joint\Crisis\Enums\Severity;
use App\Domain\Joint\Crisis\Exceptions\CrisisProcessException;
use App\Domain\Joint\Crisis\Exceptions\FundBoundaryException;
use App\Domain\Joint\Crisis\Models\Incident;
use App\Domain\Joint\Crisis\Services\CrisisService;
use App\Domain\Joint\Crisis\Services\LossFundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * CrisisCharterTest — DOCUMENT 8.1 (Crisis Runbook & Incident-Commander
 * Charter) + DOCUMENT 8.2 (The Protocol-Loss Fund Charter), operational
 * enforcement of M-§C.15.
 *
 * Layers proven:
 *  - severity binds the disclosure clock at declaration from pre-published
 *    commitments; deadlines and severity are one-way at the DB
 *  - disclosures append-only; closure requires the full ladder; incidents
 *    undeletable; overdue is reported, not suppressible
 *  - reserve shortfall auto-declares an S1 (8.1 §4) alongside the breaker
 *  - the commander holds NO ledger power: module import wall + DB grants
 *  - the fund boundary: only protocol_bug is approvable (service AND DB);
 *    exclusions stated up front on every claim; decisions carry public
 *    receipts; appeal path exists; payout attaches only after verifying
 *    the posted entries pay the approved claimant the approved amount;
 *    paid claims immutable
 *  - the drill (8.2 §5): a simulated bug-loss claim is compensated and a
 *    simulated market-loss claim is refused at every layer
 */
final class CrisisCharterTest extends TestCase
{
    private CrisisService $crisis;

    private LossFundService $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crisis = app(CrisisService::class);
        $this->fund = app(LossFundService::class);
    }

    // ------------------------------------------------------------------
    // 8.1 — the bound disclosure clock.
    // ------------------------------------------------------------------

    public function test_severity_binds_the_disclosure_clock_at_declaration(): void
    {
        $incident = $this->crisis->declare(Severity::S1, 'Confirmed invariant breach in test drill.');

        // S1: status page within 30 minutes, preliminary within 12 hours,
        // post-mortem within 7 days — computed from the pre-published
        // commitments, not negotiated in the moment.
        $this->assertSame(30.0, round($incident->declared_at->diffInMinutes($incident->status_page_due_at)));
        $this->assertSame(12.0, round($incident->declared_at->diffInHours($incident->preliminary_report_due_at)));
        $this->assertSame(7.0, round($incident->declared_at->diffInDays($incident->postmortem_due_at)));

        // Each severity is strictly tighter than the one below it.
        $previous = null;

        foreach ([Severity::S1, Severity::S2, Severity::S3, Severity::S4] as $severity) {
            $clock = $severity->disclosureCommitments();

            if ($previous !== null) {
                $this->assertLessThan($clock['status_page_minutes'], $previous['status_page_minutes']);
                $this->assertLessThan($clock['preliminary_hours'], $previous['preliminary_hours']);
                $this->assertLessThan($clock['postmortem_days'], $previous['postmortem_days']);
            }

            $previous = $clock;
        }
    }

    public function test_the_clock_is_one_way_at_the_database(): void
    {
        $incident = $this->crisis->declare(Severity::S2, 'Breaker fired on real anomaly.');

        // A deadline can never be pushed later.
        try {
            DB::table('incidents')->where('id', $incident->id)
                ->update(['postmortem_due_at' => now()->addYears(10)]);
            $this->fail('A disclosure deadline was pushed later.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('never be pushed later', $e->getMessage());
        }

        // The severity that set the clock can never be rewritten.
        try {
            DB::table('incidents')->where('id', $incident->id)->update(['severity' => 's4']);
            $this->fail('An incident severity was quietly downgraded.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('cannot be rewritten', $e->getMessage());
        }

        // The incident record is never deleted.
        try {
            DB::table('incidents')->where('id', $incident->id)->delete();
            $this->fail('An incident was deleted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }

        // Tightening a deadline (earlier) is lawful — the commitment is a
        // ceiling, not a floor.
        DB::table('incidents')->where('id', $incident->id)
            ->update(['postmortem_due_at' => $incident->postmortem_due_at->copy()->subDay()]);
        $this->assertTrue(true);
    }

    public function test_disclosures_are_append_only_and_closure_requires_the_full_ladder(): void
    {
        $incident = $this->crisis->declare(Severity::S3, 'Contained vulnerability, drill.');

        // No silent closure: every rung of the ladder must be published.
        $this->crisis->publish($incident, DisclosureKind::StatusPage, 'We are investigating an anomaly.');

        try {
            $this->crisis->close($incident);
            $this->fail('An incident closed without its preliminary report and post-mortem.');
        } catch (CrisisProcessException $e) {
            $this->assertStringContainsString('has not been published', $e->getMessage());
        }

        $this->crisis->publish($incident, DisclosureKind::PreliminaryReport, 'Root cause identified; no value loss.');
        $this->crisis->publish($incident, DisclosureKind::Postmortem, 'Full public post-mortem: timeline, cause, remediation.');
        $closed = $this->crisis->close($incident);
        $this->assertSame('closed', $closed->status);

        // A published disclosure can never be edited or removed.
        $statusPageId = (string) DB::table('incident_disclosures')
            ->where('incident_id', $incident->id)
            ->where('kind', 'status_page')
            ->value('id');

        try {
            DB::table('incident_disclosures')->where('id', $statusPageId)
                ->update(['content' => 'Nothing happened.']);
            $this->fail('A published disclosure was rewritten.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('incident_disclosures')->where('id', $statusPageId)->delete();
            $this->fail('A published disclosure was removed.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Publishing the same kind twice is refused by the unique bound.
        try {
            $this->crisis->publish($closed, DisclosureKind::Postmortem, 'A second, friendlier post-mortem.');
            $this->fail('A disclosure kind was published twice.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('unique', strtolower($e->getMessage()));
        }
    }

    public function test_overdue_disclosure_is_reported_and_cannot_be_suppressed(): void
    {
        // Seed an incident declared 3 days ago (born open — the trigger
        // guards UPDATEs; DB clock governs, so seed directly).
        $id = (string) Str::ulid();
        DB::table('incidents')->insert([
            'id' => $id,
            'severity' => 's1',
            'summary' => 'Aged drill incident.',
            'commander_role' => 'incident-commander',
            'status' => 'open',
            'trigger_source' => 'manual',
            'declared_at' => now()->subDays(3),
            'status_page_due_at' => now()->subDays(3)->addMinutes(30),
            'preliminary_report_due_at' => now()->subDays(3)->addHours(12),
            'postmortem_due_at' => now()->subDays(3)->addDays(7),
        ]);
        $incident = Incident::query()->findOrFail($id);

        $status = $this->crisis->disclosureStatus($incident);
        $this->assertSame('OVERDUE', $status['status_page']);
        $this->assertSame('OVERDUE', $status['preliminary_report']);
        $this->assertSame('pending', $status['postmortem']);

        // Publishing late is still publishing — the record shows both the
        // deadline and the late publication, and neither can be erased.
        $this->crisis->publish($incident, DisclosureKind::StatusPage, 'Late but true.');
        $this->assertSame('published', $this->crisis->disclosureStatus($incident)['status_page']);
    }

    public function test_a_reserve_shortfall_auto_declares_an_s1_incident(): void
    {
        // Count S1 reserve incidents before.
        $before = Incident::query()->where('trigger_source', 'reserve_attestation')->count();

        $incident = $this->crisis->declareReserveShortfall('RSVTEST', 50_000, 80_000);

        $this->assertSame(Severity::S1, $incident->severity);
        $this->assertSame('reserve_attestation', $incident->trigger_source);
        $this->assertStringContainsString('disclosure clock started automatically', $incident->summary);
        $this->assertSame($before + 1, Incident::query()->where('trigger_source', 'reserve_attestation')->count());
    }

    // ------------------------------------------------------------------
    // 8.1 §3 — the commander cannot cross the spine.
    // ------------------------------------------------------------------

    public function test_the_crisis_module_holds_no_ledger_power(): void
    {
        // Import wall: the crisis module may not reference the ledger
        // writer or its drafts — the emergency powers are halt and
        // disclose, never post. (recordPayout READS entries to verify a
        // treasury-posted payout; reading is not power.)
        $root = app_path('Domain/Joint/Crisis');
        $forbidden = ['LedgerService', 'TransactionDraft', 'EntryDraft', '->post(', 'IssuanceService', 'DisputeService'];
        $scanned = 0;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $source = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $token) {
                $this->assertFalse(
                    str_contains($source, $token),
                    "{$file->getPathname()} references \"{$token}\" — the incident commander's powers are "
                    .'halt and disclose, never a ledger write. Emergency is not a license to violate the spine.'
                );
            }
        }

        $this->assertGreaterThanOrEqual(9, $scanned);

        // And even in a declared S1, the ledger's own I6 guard still binds
        // every writer: a bare punitive debit remains impossible.
        $this->crisis->declare(Severity::S1, 'Spine test: emergency grants no override.');
        $currency = LedgerFixtures::currency();
        $holder = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($holder, 5_000);

        try {
            DB::transaction(function () use ($holder, $currency): void {
                $txnId = (string) Str::ulid();
                DB::table('transactions')->insert([
                    'id' => $txnId,
                    'kind' => 'transfer',
                    'idempotency_key' => 'emergency-seize:'.Str::ulid(),
                    'metadata' => json_encode(['emergency' => true]),
                ]);
                DB::table('entries')->insert([
                    'transaction_id' => $txnId,
                    'account_id' => $holder->id,
                    'currency_id' => $currency->id,
                    'amount' => -5_000,
                    'balance_after' => 0,
                ]);
            });
            $this->fail('A crisis-era punitive debit posted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('I6', $e->getMessage());
        }

        $this->assertSame(5_000, $holder->refresh()->balance_minor);
    }

    // ------------------------------------------------------------------
    // 8.2 — the fund boundary, exercised as the charter's drill.
    // ------------------------------------------------------------------

    public function test_the_drill_a_bug_loss_claim_is_compensated_and_a_market_loss_claim_is_refused(): void
    {
        $currency = LedgerFixtures::currency();
        $victim = LedgerFixtures::personalAccount($currency);

        // --- The bug-loss claim (compensable). ---
        $bugClaim = $this->fund->submit($victim->id, 25_000, 'A settlement defect double-released my escrow.');

        // The exclusions were stated up front, on the claim itself.
        $this->assertStringContainsString('does NOT cover', $bugClaim->exclusions_disclosed);
        $this->assertStringContainsString('market risk', $bugClaim->exclusions_disclosed);
        $this->assertStringContainsString('user error', $bugClaim->exclusions_disclosed);

        $decided = $this->fund->decide($bugClaim, ClaimCategory::ProtocolBug, true, 'Public receipt: defect confirmed in settlement release path; loss traced.');
        $this->assertSame('approved', $decided->status);

        // Treasury posts the payout through the ordinary guarded path;
        // the fund verifies the entries before attaching it.
        $payout = LedgerFixtures::mint($victim, 25_000);
        $paid = $this->fund->recordPayout($decided, $payout->id);
        $this->assertSame($payout->id, $paid->payout_transaction_id);

        // --- The market-loss claim (refused at every layer). ---
        $marketClaim = $this->fund->submit($victim->id, 10_000, 'My held asset fell 40% this week.');

        try {
            $this->fund->decide($marketClaim, ClaimCategory::MarketRisk, true, 'Sympathy approval.');
            $this->fail('The fund compensated market risk.');
        } catch (FundBoundaryException $e) {
            $this->assertStringContainsString('THE BOUNDARY', $e->getMessage());
        }

        // DB layer: an approved market-risk row is unrepresentable.
        try {
            DB::table('loss_fund_claims')->where('id', $marketClaim->id)->update([
                'category' => 'market_risk',
                'status' => 'approved',
                'decision_receipt' => 'quietly expanded',
                'decided_at' => now(),
            ]);
            $this->fail('The database accepted an approved market-risk claim.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('boundary', strtolower($e->getMessage()));
        }

        // Denial with a public receipt is the lawful outcome.
        $denied = $this->fund->decide($marketClaim, ClaimCategory::MarketRisk, false, 'Public receipt: the loss is market movement within disclosed risk; the exclusions were stated at submission.');
        $this->assertSame('denied', $denied->status);

        // User error and disclosed experimental risk are equally
        // unapprovable.
        foreach ([ClaimCategory::UserError, ClaimCategory::DisclosedExperimental] as $category) {
            $claim = $this->fund->submit($victim->id, 1_000, 'Claim in excluded category: '.$category->value);

            try {
                $this->fund->decide($claim, $category, true, 'receipt');
                $this->fail("The fund approved a {$category->value} claim.");
            } catch (FundBoundaryException) {
                // The boundary held.
            }
        }
    }

    public function test_decisions_require_receipts_appeals_exist_and_paid_claims_are_immutable(): void
    {
        $currency = LedgerFixtures::currency();
        $claimant = LedgerFixtures::personalAccount($currency);

        // No decision without a public receipt — service and DB agree.
        $claim = $this->fund->submit($claimant->id, 5_000, 'Alleged ledger defect.');

        try {
            $this->fund->decide($claim, ClaimCategory::ProtocolBug, false, '   ');
            $this->fail('A decision was issued without a receipt.');
        } catch (FundBoundaryException $e) {
            $this->assertStringContainsString('receipt', $e->getMessage());
        }

        try {
            DB::table('loss_fund_claims')->where('id', $claim->id)
                ->update(['status' => 'denied', 'decided_at' => now()]);
            $this->fail('The database accepted a decision without a receipt.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('decision_shape', $e->getMessage());
        }

        // The appeal path: a denied claim can be appealed with a reason,
        // and re-decided.
        $denied = $this->fund->decide($claim, ClaimCategory::UserError, false, 'Public receipt: no defect found; the loss traces to a mistyped address.');
        $appealed = $this->fund->appeal($denied, 'New evidence: the address parser mangled valid input.');
        $this->assertSame('appealed', $appealed->status);

        $approved = $this->fund->decide($appealed, ClaimCategory::ProtocolBug, true, 'Public receipt on appeal: parser defect confirmed; the loss was the protocol fault after all.');
        $this->assertSame('approved', $approved->status);

        // Pay it, then prove the paid record is history.
        $payout = LedgerFixtures::mint($claimant, 5_000);
        $paid = $this->fund->recordPayout($approved, $payout->id);

        try {
            DB::table('loss_fund_claims')->where('id', $paid->id)
                ->update(['decision_receipt' => 'revised history']);
            $this->fail('A paid claim was rewritten.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('closed historical fact', $e->getMessage());
        }

        try {
            DB::table('loss_fund_claims')->where('id', $paid->id)->delete();
            $this->fail('A claim was deleted from the public record.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }
    }

    public function test_a_payout_must_match_the_approved_claim_exactly(): void
    {
        $currency = LedgerFixtures::currency();
        $claimant = LedgerFixtures::personalAccount($currency);
        $stranger = LedgerFixtures::personalAccount($currency);

        $claim = $this->fund->submit($claimant->id, 9_000, 'Issuance defect burned my balance.');
        $this->fund->decide($claim, ClaimCategory::ProtocolBug, true, 'Public receipt: confirmed.');

        // A payout of the wrong amount is refused.
        $short = LedgerFixtures::mint($claimant, 4_000);

        try {
            $this->fund->recordPayout($claim, $short->id);
            $this->fail('A mismatched payout was attached.');
        } catch (FundBoundaryException $e) {
            $this->assertStringContainsString('exactly what it decided', $e->getMessage());
        }

        // A payout to the wrong account is refused.
        $misdirected = LedgerFixtures::mint($stranger, 9_000);

        try {
            $this->fund->recordPayout($claim, $misdirected->id);
            $this->fail('A payout to a stranger was attached.');
        } catch (FundBoundaryException) {
            // The claimant received nothing in that transaction.
        }

        // A payout on an unapproved claim is refused at service AND DB.
        $pending = $this->fund->submit($claimant->id, 1_000, 'Pending claim.');
        $mint = LedgerFixtures::mint($claimant, 1_000);

        try {
            $this->fund->recordPayout($pending, $mint->id);
            $this->fail('A payout attached to an undecided claim.');
        } catch (FundBoundaryException) {
            // Approval first.
        }

        try {
            DB::table('loss_fund_claims')->where('id', $pending->id)
                ->update(['payout_transaction_id' => $mint->id]);
            $this->fail('The database attached a payout to an undecided claim.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('payout_requires_approval', $e->getMessage());
        }
    }

    public function test_the_public_record_is_readable_but_unwritable_by_aevum(): void
    {
        // Transparency is the point: aevum_app may read the incidents,
        // disclosures, and claims — and can write none of them.
        /** @var list<object{table_name: string, privilege_type: string}> $grants */
        $grants = DB::select(
            "SELECT table_name, privilege_type FROM information_schema.role_table_grants
             WHERE grantee = 'aevum_app'
               AND table_name IN ('incidents', 'incident_disclosures', 'loss_fund_claims')
             ORDER BY table_name, privilege_type",
        );

        $byTable = [];

        foreach ($grants as $grant) {
            $byTable[$grant->table_name][] = $grant->privilege_type;
        }

        foreach (['incidents', 'incident_disclosures', 'loss_fund_claims'] as $table) {
            $this->assertSame(['SELECT'], $byTable[$table] ?? [], "aevum_app grants on {$table}");
        }

        // The policy engine reads incidents and writes nothing here.
        /** @var list<object{privilege_type: string}> $policyGrants */
        $policyGrants = DB::select(
            "SELECT privilege_type FROM information_schema.role_table_grants
             WHERE grantee = 'meridian_policy_engine' AND table_name = 'incidents'",
        );
        $this->assertSame(['SELECT'], array_map(static fn (object $g): string => $g->privilege_type, $policyGrants));
    }
}
