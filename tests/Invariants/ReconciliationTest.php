<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Models\CrossSystemEvent;
use App\Domain\Joint\EventContract\Models\EventSigner;
use App\Domain\Joint\EventContract\Services\EventChainService;
use App\Domain\Joint\Reconciliation\Services\ReconciliationService;
use App\Domain\Meridian\Ingress\Services\ProposalIngressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * ReconciliationTest — DEV-7.2 (DOCUMENT 7.2).
 *
 * The two legs keep each other honest: every Aevum-believed committed
 * fact maps to a posted balanced Meridian transaction and vice versa;
 * drift raises a hash-chained reconciliation.alert; and the job NEVER
 * silently auto-corrects — it has no correcting capability at all.
 */
final class ReconciliationTest extends TestCase
{
    private EventChainService $chain;

    private ReconciliationService $reconciliation;

    /** @var non-empty-string */
    private string $aevumSecret;

    /** @var non-empty-string */
    private string $meridianSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chain = app(EventChainService::class);
        $this->reconciliation = app(ReconciliationService::class);

        $aevumPair = sodium_crypto_sign_keypair();
        $meridianPair = sodium_crypto_sign_keypair();
        $this->aevumSecret = sodium_crypto_sign_secretkey($aevumPair);
        $this->meridianSecret = sodium_crypto_sign_secretkey($meridianPair);

        foreach ([['aevum', $aevumPair], ['meridian', $meridianPair]] as [$source, $pair]) {
            EventSigner::query()->create([
                'source' => $source,
                'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'status' => 'active',
                'registered_at' => now(),
            ]);
        }
    }

    private function commitOneTransferThroughTheContract(): void
    {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 10_000);

        $proposal = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            [
                'from_account_id' => $alice->id,
                'to_account_id' => $bob->id,
                'currency_id' => $currency->id,
                'amount_minor' => 1_500,
                'holder_authorization_ref' => 'auth:'.Str::random(8),
            ],
            'prop-'.Str::random(12),
            $this->aevumSecret,
        )->event;

        app(ProposalIngressService::class)->receiveProposal($proposal, $this->meridianSecret);
    }

    public function test_a_healthy_period_reconciles_clean_in_both_directions(): void
    {
        $this->commitOneTransferThroughTheContract();
        $this->commitOneTransferThroughTheContract();

        $report = $this->reconciliation->reconcile($this->meridianSecret);

        $this->assertTrue($report->clean(), json_encode($report->drifts, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $report->confirmationsChecked);
        $this->assertSame(2, $report->transactionsChecked);
        $this->assertSame([], $report->alertEventIds);
    }

    public function test_a_believed_but_unposted_fact_raises_a_chained_alert(): void
    {
        // Forge Aevum's belief: a confirmation.committed pointing at a
        // transaction that never existed (the "Aevum thinks money moved
        // that did not" drift).
        $this->chain->append(
            EventSource::Meridian,
            EventKind::ConfirmationCommitted,
            ['proposal_event_id' => 'phantom', 'transaction_id' => strtolower((string) Str::ulid())],
            'phantom-'.Str::random(8),
            $this->meridianSecret,
        );

        $report = $this->reconciliation->reconcile($this->meridianSecret);

        $this->assertFalse($report->clean());
        $this->assertCount(1, $report->drifts);
        $this->assertSame('believed_but_unposted', $report->drifts[0]['type']);
        $this->assertCount(1, $report->alertEventIds);

        // The alert is itself a chained, signed event — tamper-evident.
        $alert = CrossSystemEvent::query()->findOrFail($report->alertEventIds[0]);
        $this->assertSame(EventKind::ReconciliationAlert, $alert->kind);
        $verification = $this->chain->verifyChain();
        $this->assertTrue($verification->intact);

        // Re-running the cycle does NOT duplicate the alert (idempotent
        // by drift content).
        $second = $this->reconciliation->reconcile($this->meridianSecret);
        $this->assertSame($report->alertEventIds, $second->alertEventIds);
        $this->assertSame(1, CrossSystemEvent::query()
            ->where('kind', EventKind::ReconciliationAlert->value)->count());
    }

    public function test_a_posted_but_unattributed_transaction_raises_an_alert(): void
    {
        // A ledger transaction claiming ingress origin with no matching
        // Aevum proposal ("money moved Meridian cannot attribute").
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 5_000);
        $bob = LedgerFixtures::personalAccount($currency);

        LedgerFixtures::transfer($alice, $bob, 1_000, metadata: [
            'proposal_event_id' => strtolower((string) Str::ulid()), // no such event
        ]);

        $report = $this->reconciliation->reconcile($this->meridianSecret);

        $this->assertFalse($report->clean());
        $this->assertSame('posted_but_unattributed', $report->drifts[0]['type']);
        $this->assertCount(1, $report->alertEventIds);
    }

    public function test_reconciliation_detects_but_never_corrects(): void
    {
        // Seed a drift, snapshot the entire economic state, reconcile,
        // and prove the job changed NOTHING but the alert stream.
        $this->chain->append(
            EventSource::Meridian,
            EventKind::ConfirmationCommitted,
            ['proposal_event_id' => 'phantom-2', 'transaction_id' => strtolower((string) Str::ulid())],
            'phantom2-'.Str::random(8),
            $this->meridianSecret,
        );

        $entriesBefore = (int) DB::table('entries')->count();
        $transactionsBefore = (int) DB::table('transactions')->count();
        /** @var object{checksum: string|null} $balancesBefore */
        $balancesBefore = DB::selectOne(
            "SELECT string_agg(id || ':' || balance_minor, ',' ORDER BY id) AS checksum FROM accounts"
        );

        $report = $this->reconciliation->reconcile($this->meridianSecret);
        $this->assertFalse($report->clean());

        // Nothing economic moved: no entry, no transaction, no balance.
        $this->assertSame(
            $entriesBefore,
            (int) DB::table('entries')->count(),
            'DEV-7.2 violated: reconciliation wrote a ledger entry.'
        );
        $this->assertSame(
            $transactionsBefore,
            (int) DB::table('transactions')->count(),
            'DEV-7.2 violated: reconciliation posted a transaction.'
        );
        /** @var object{checksum: string|null} $balancesAfter */
        $balancesAfter = DB::selectOne(
            "SELECT string_agg(id || ':' || balance_minor, ',' ORDER BY id) AS checksum FROM accounts"
        );
        $this->assertSame(
            $balancesBefore->checksum,
            $balancesAfter->checksum,
            'DEV-7.2 violated: reconciliation adjusted a balance to make the sides agree.'
        );
    }

    public function test_the_reconciliation_module_has_no_correcting_vocabulary(): void
    {
        // The module wall: the reconciler cannot even NAME the ledger
        // write path — same self-catching convention as I7/I9/A-§C.14.
        $forbidden = [
            'LedgerService',
            'DisputeService',
            'IssuanceService',
            'EntryDraft',
            'TransactionDraft',
            '->post(',
            '->persist(',
            'INSERT INTO entries',
            'INSERT INTO transactions',
            'UPDATE accounts',
        ];

        $files = iterator_to_array(
            (new Finder())->files()->in(app_path('Domain/Joint/Reconciliation'))->name('*.php')
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $token) {
                $this->assertFalse(
                    str_contains($source, $token),
                    "DEV-7.2 violated: {$file->getRelativePathname()} references the "
                    ."correcting/write path via \"{$token}\"."
                );
            }
        }
    }

    public function test_comparison_runs_on_economic_facts_needing_no_pii(): void
    {
        // The reconciler's queries touch only ids, amounts, accounts,
        // currencies, and event payloads — verified by exercising a
        // full clean cycle with NO identity data anywhere in reach.
        $this->commitOneTransferThroughTheContract();

        $report = $this->reconciliation->reconcile($this->meridianSecret);
        $this->assertTrue($report->clean());

        // And the module's source references no PII vocabulary.
        $files = iterator_to_array(
            (new Finder())->files()->in(app_path('Domain/Joint/Reconciliation'))->name('*.php')
        );
        foreach ($files as $file) {
            $source = strtolower((string) file_get_contents($file->getPathname()));
            foreach (['email', 'phone', 'passport', 'biometric', 'birth'] as $token) {
                $this->assertFalse(
                    str_contains($source, $token),
                    'DEV-7.2: reconciliation must never need PII.'
                );
            }
        }
    }
}
