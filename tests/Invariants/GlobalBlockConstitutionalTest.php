<?php

// SPDX-License-Identifier: LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Aevum\Fabric\Data\Asset;
use App\Domain\Aevum\Fabric\Data\UserRules;
use App\Domain\Aevum\Fabric\Enums\BlockStatus;
use App\Domain\Aevum\Fabric\Enums\FilterVerdict;
use App\Domain\Aevum\Fabric\Exceptions\ConstitutionalProcessException;
use App\Domain\Aevum\Fabric\Models\GlobalBlock;
use App\Domain\Aevum\Fabric\Services\EthicalFilter;
use App\Domain\Aevum\Fabric\Services\GlobalBlockList;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GlobalBlockConstitutionalTest — A-§C.10 (User Sovereignty / Global
 * Block).
 *
 * DOCUMENT 4.4 / DOCUMENT 0.5: no global block activates without
 * timelock + justification + appeal path; a unilateral or silent
 * global block is rejected — at the SERVICE layer and, independently,
 * at the DATABASE layer (trigger global_blocks_guard_activation). The
 * filter is user-sovereign by default; the global block is the ONE
 * membrane power that overrides individual sovereignty, which is
 * precisely why it is the one that is constitutionally gated.
 */
final class GlobalBlockConstitutionalTest extends TestCase
{
    private GlobalBlockList $blocks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blocks = app(GlobalBlockList::class);
    }

    private function assetRef(): string
    {
        return 'asset-'.strtolower(Str::random(12));
    }

    public function test_a_block_can_only_enter_the_process_as_proposed(): void
    {
        // Service: propose() is the only entry and it births 'proposed'.
        $block = $this->blocks->propose(
            assetRef: $this->assetRef(),
            justification: 'Legally compelled: listed on the applicable sanctions register.',
            timelockUntil: now()->addDays(45),
            transparencyLogRef: 'tlog-'.Str::random(8),
        );

        $this->assertSame(BlockStatus::Proposed, $block->status);

        // DB: a row cannot be BORN active — the trigger rejects the
        // birth regardless of what any (compromised) service does.
        try {
            DB::table('global_blocks')->insert([
                'id' => strtolower((string) Str::ulid()),
                'asset_ref' => $this->assetRef(),
                'justification' => 'Born active — unilateral in effect.',
                'proposed_at' => now(),
                'timelock_until' => now()->addDays(45),
                'appeal_status' => 'none',
                'status' => 'active',
                'transparency_log_ref' => 'tlog-x',
            ]);
            $this->fail('A-§C.10 violated: a global block was born active.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('A-C.10', $e->getMessage());
        }
    }

    public function test_a_silent_block_without_justification_is_rejected(): void
    {
        // Service layer.
        try {
            $this->blocks->propose(
                assetRef: $this->assetRef(),
                justification: '   ',
                timelockUntil: now()->addDays(45),
                transparencyLogRef: 'tlog-'.Str::random(8),
            );
            $this->fail('A-§C.10 violated: a silent block entered the process.');
        } catch (ConstitutionalProcessException $e) {
            $this->assertStringContainsString('justification', $e->getMessage());
        }

        // DB layer: the CHECK rejects an empty justification outright.
        try {
            DB::table('global_blocks')->insert([
                'id' => strtolower((string) Str::ulid()),
                'asset_ref' => $this->assetRef(),
                'justification' => '',
                'proposed_at' => now(),
                'timelock_until' => now()->addDays(45),
                'appeal_status' => 'none',
                'status' => 'proposed',
                'transparency_log_ref' => 'tlog-x',
            ]);
            $this->fail('A-§C.10 violated: a justification-less block row was stored.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('global_blocks_justification_nonempty', $e->getMessage());
        }
    }

    public function test_activation_before_the_timelock_elapses_is_rejected_at_both_layers(): void
    {
        $block = $this->blocks->propose(
            assetRef: $this->assetRef(),
            justification: 'Legally compelled block, timelock still running.',
            timelockUntil: now()->addDays(45),
            transparencyLogRef: 'tlog-'.Str::random(8),
        );

        // Service layer refuses.
        try {
            $this->blocks->activate($block);
            $this->fail('A-§C.10 violated: activation before timelock elapsed.');
        } catch (ConstitutionalProcessException $e) {
            $this->assertStringContainsString('timelock', $e->getMessage());
        }

        // DB layer refuses the same transition independently.
        try {
            DB::table('global_blocks')->where('id', $block->id)->update(['status' => 'active']);
            $this->fail('A-§C.10 violated: raw SQL activated a timelocked block.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('timelock has not elapsed', $e->getMessage());
        }

        $this->assertSame(BlockStatus::Proposed, $block->refresh()->status);
    }

    public function test_an_open_appeal_halts_activation_and_an_upheld_appeal_voids_forever(): void
    {
        $block = $this->blocks->propose(
            assetRef: $this->assetRef(),
            justification: 'Contested block under appeal.',
            timelockUntil: now()->addDays(45),
            transparencyLogRef: 'tlog-'.Str::random(8),
        );

        $appealed = $this->blocks->appeal($block);
        $this->assertSame(BlockStatus::Appealed, $appealed->status);
        $this->assertSame('open', $appealed->appeal_status);

        // Even with the timelock artificially elapsed, an open appeal
        // blocks activation at the DB layer. (Timelock is immutable
        // public record, so we simulate elapse by direct clock math:
        // the trigger checks appeal BEFORE timelock here because the
        // status transition from 'appealed' is itself forbidden.)
        try {
            DB::table('global_blocks')->where('id', $appealed->id)->update(['status' => 'active']);
            $this->fail('A-§C.10 violated: an appealed block activated.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('only a proposed global block may activate', $e->getMessage());
        }

        // An upheld appeal must void the block — the CHECK constraint
        // rejects any other terminal state for an upheld appeal.
        try {
            DB::table('global_blocks')->where('id', $appealed->id)
                ->update(['appeal_status' => 'upheld', 'status' => 'appealed']);
            $this->fail('A-§C.10 violated: an upheld appeal left the block alive.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('global_blocks_upheld_appeal_voids', $e->getMessage());
        }

        DB::table('global_blocks')->where('id', $appealed->id)
            ->update(['appeal_status' => 'upheld', 'status' => 'void']);
        $this->assertSame(BlockStatus::Void, $appealed->refresh()->status);
    }

    public function test_the_public_record_of_a_proposal_is_immutable(): void
    {
        $block = $this->blocks->propose(
            assetRef: $this->assetRef(),
            justification: 'The published justification.',
            timelockUntil: now()->addDays(45),
            transparencyLogRef: 'tlog-'.Str::random(8),
        );

        // Quietly shortening the timelock after proposal is rejected.
        try {
            DB::table('global_blocks')->where('id', $block->id)
                ->update(['timelock_until' => now()->addSecond()]);
            $this->fail('A-§C.10 violated: the timelock was quietly rewritten.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable public record', $e->getMessage());
        }

        // Quietly editing the published justification is rejected.
        try {
            DB::table('global_blocks')->where('id', $block->id)
                ->update(['justification' => 'A different story.']);
            $this->fail('A-§C.10 violated: the justification was quietly rewritten.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable public record', $e->getMessage());
        }
    }

    public function test_a_lawfully_completed_process_activates_and_only_then_binds_everyone(): void
    {
        $assetRef = $this->assetRef();

        // A lawfully-aged proposal: proposed 60 days ago, timelocked
        // 45 of them, appeal window closed with no appeal. Seeded
        // directly because the DB trigger checks PostgreSQL's real
        // clock — the proposal SHAPE is exactly what propose() writes,
        // and the trigger admits any birth in 'proposed'.
        $id = strtolower((string) Str::ulid());
        DB::table('global_blocks')->insert([
            'id' => $id,
            'asset_ref' => $assetRef,
            'justification' => 'Legally compelled: final, appeal window closed.',
            'proposed_at' => now()->subDays(60),
            'timelock_until' => now()->subDays(15),
            'appeal_status' => 'none',
            'status' => 'proposed',
            'transparency_log_ref' => 'tlog-'.Str::random(8),
        ]);
        /** @var GlobalBlock $block */
        $block = GlobalBlock::query()->findOrFail($id);

        // Before activation, the filter does NOT refuse for other users:
        // a proposed block binds no one (user sovereignty intact).
        $filter = app(EthicalFilter::class);
        $verdict = $filter->evaluate(
            new Asset(assetRef: $assetRef, labelCategories: []),
            new UserRules(userRef: 'user-2'),
        );
        $this->assertSame(FilterVerdict::Admit, $verdict->verdict);

        $activated = $this->blocks->activate($block);
        $this->assertSame(BlockStatus::Active, $activated->status);

        // Only NOW is the refusal mandatory and non-overridable.
        $verdict = $filter->evaluate(
            new Asset(assetRef: $assetRef, labelCategories: []),
            new UserRules(userRef: 'user-2'),
        );
        $this->assertSame(FilterVerdict::Refuse, $verdict->verdict);
        $this->assertFalse($verdict->userOverridable);
        $this->assertStringContainsString('Constitutional global block', $verdict->reason);
    }

    public function test_user_rules_bind_only_their_own_wallet(): void
    {
        // User sovereignty, the default side of A-§C.10: one user's
        // refusal never becomes another user's block.
        $filter = app(EthicalFilter::class);
        $asset = new Asset(assetRef: $this->assetRef(), labelCategories: ['gambling']);

        $refusing = $filter->evaluate($asset, new UserRules(
            userRef: 'ascetic',
            refuseCategories: ['gambling'],
        ));
        $this->assertSame(FilterVerdict::Refuse, $refusing->verdict);
        $this->assertTrue($refusing->userOverridable, 'A user-rule refusal is the user\'s to change.');

        $admitting = $filter->evaluate($asset, new UserRules(userRef: 'other'));
        $this->assertSame(FilterVerdict::Admit, $admitting->verdict);
    }
}
