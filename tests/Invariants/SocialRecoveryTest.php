<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Identity\Enums\AppealStatus;
use App\Domain\Identity\Enums\RecoveryStatus;
use App\Domain\Identity\Exceptions\RecoveryProcessException;
use App\Domain\Identity\Models\GuardianSet;
use App\Domain\Identity\Models\Identity;
use App\Domain\Identity\Models\RecoveryAttempt;
use App\Domain\Identity\Recovery\SocialRecoveryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SocialRecoveryTest — DOCUMENT 6.2 §3.
 *
 * Mandatory social recovery: M-of-N guardians, a timelocked challenge
 * window, high friction, NEVER an email reset. Enforced at the SERVICE
 * layer and independently at the DATABASE layer (trigger
 * recovery_guard_completion): born-initiated only, no completion before
 * the window, no sub-threshold completion, no contested completion, and
 * a completed recovery always carries a receipt + elevated monitoring.
 */
final class SocialRecoveryTest extends TestCase
{
    private SocialRecoveryService $recovery;

    /** @var list<array{public: string, secret: string}> */
    private array $guardians = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recovery = new SocialRecoveryService();
        $this->guardians = [];

        for ($i = 0; $i < 3; $i++) {
            $pair = sodium_crypto_sign_keypair();
            $this->guardians[] = [
                'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            ];
        }
    }

    private function identity(): Identity
    {
        $identity = new Identity([
            'subject_commitment' => 'commit:'.Str::ulid(),
            'aggregation_version' => 'agg-v1.0',
            'effective_rung' => 1,
            'provider_attestations' => [],
            'appeal_status' => AppealStatus::None,
            'explanation' => 'test identity',
        ]);
        $identity->save();

        return $identity;
    }

    private function guardianSet(): GuardianSet
    {
        return $this->recovery->registerGuardians(
            $this->identity(),
            array_column($this->guardians, 'public'),
            threshold: 2,
        );
    }

    private function signApproval(RecoveryAttempt $attempt, int $guardianIndex): string
    {
        $secret = base64_decode($this->guardians[$guardianIndex]['secret'], true);
        assert($secret !== false);

        return base64_encode(sodium_crypto_sign_detached(
            SocialRecoveryService::bindingMessage($attempt),
            $secret,
        ));
    }

    /**
     * Seed an attempt whose window has ALREADY elapsed, directly at the
     * DB layer (born initiated, so the trigger admits it): the DB clock
     * is PostgreSQL's own, unaffected by Laravel time travel.
     */
    private function agedAttempt(GuardianSet $set): RecoveryAttempt
    {
        $id = strtolower((string) Str::ulid());
        DB::table('recovery_attempts')->insert([
            'id' => $id,
            'guardian_set_id' => $set->id,
            'new_key_commitment' => 'newkey:'.Str::ulid(),
            'status' => 'initiated',
            'initiated_at' => now()->subDays(10),
            'challenge_window_ends_at' => now()->subDay(),
            'guardian_approvals' => '[]',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        return RecoveryAttempt::query()->findOrFail($id);
    }

    public function test_recovery_never_collapses_to_one_party(): void
    {
        $identity = $this->identity();

        // Threshold below 2 refused by the service…
        try {
            $this->recovery->registerGuardians($identity, [$this->guardians[0]['public'], $this->guardians[1]['public']], threshold: 1);
            $this->fail('A threshold of 1 must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('at least 2', $e->getMessage());
        }

        // …and independently by the DB CHECK.
        try {
            DB::table('guardian_sets')->insert([
                'id' => strtolower((string) Str::ulid()),
                'identity_id' => $identity->id,
                'guardian_public_keys' => json_encode([$this->guardians[0]['public']]),
                'threshold' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The DB CHECK must refuse a threshold of 1.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Fewer guardians than the threshold is refused.
        try {
            $this->recovery->registerGuardians($identity, [$this->guardians[0]['public']], threshold: 2);
            $this->fail('2-of-1 must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('cannot satisfy', $e->getMessage());
        }
    }

    public function test_an_attempt_cannot_be_born_completed(): void
    {
        $set = $this->guardianSet();

        // DB layer: born-completed is rejected by the trigger.
        try {
            DB::table('recovery_attempts')->insert([
                'id' => strtolower((string) Str::ulid()),
                'guardian_set_id' => $set->id,
                'new_key_commitment' => 'newkey:x',
                'status' => 'completed',
                'initiated_at' => now(),
                'challenge_window_ends_at' => now()->addDays(7),
                'guardian_approvals' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A recovery born completed must be refused by the DB trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('born initiated', $e->getMessage());
        }

        // A zero-length challenge window is likewise refused at birth.
        try {
            DB::table('recovery_attempts')->insert([
                'id' => strtolower((string) Str::ulid()),
                'guardian_set_id' => $set->id,
                'new_key_commitment' => 'newkey:y',
                'status' => 'initiated',
                'initiated_at' => now(),
                'challenge_window_ends_at' => now(),
                'guardian_approvals' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A zero-length window must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('real interval', $e->getMessage());
        }

        // Service layer: windows below the floor are refused.
        try {
            $this->recovery->initiate($set, 'newkey:z', windowDays: 1);
            $this->fail('A window below the floor must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('may not be shorter', $e->getMessage());
        }
    }

    public function test_completion_before_the_window_is_refused_at_both_layers(): void
    {
        $set = $this->guardianSet();
        $attempt = $this->recovery->initiate($set, 'newkey:'.Str::ulid());

        // Gather full approvals — the window still gates.
        $attempt = $this->recovery->approve($attempt, $this->guardians[0]['public'], $this->signApproval($attempt, 0));
        $attempt = $this->recovery->approve($attempt, $this->guardians[1]['public'], $this->signApproval($attempt, 1));

        // Service layer.
        try {
            $this->recovery->complete($attempt);
            $this->fail('Completion before the window must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('has not elapsed', $e->getMessage());
        }

        // DB layer, bypassing the service.
        try {
            DB::table('recovery_attempts')->where('id', $attempt->id)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'decision_receipt' => 'forged receipt',
                'elevated_monitoring' => true,
            ]);
            $this->fail('The DB trigger must refuse pre-window completion.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('has not elapsed', $e->getMessage());
        }

        $this->assertSame(RecoveryStatus::Initiated, $attempt->refresh()->status);
    }

    public function test_sub_threshold_and_forged_approvals_are_refused(): void
    {
        $set = $this->guardianSet();
        $aged = $this->agedAttempt($set);

        // One approval where two are required: service refuses…
        $aged = $this->recovery->approve($aged, $this->guardians[0]['public'], $this->signApproval($aged, 0));

        try {
            $this->recovery->complete($aged);
            $this->fail('Sub-threshold completion must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('sub-threshold', $e->getMessage());
        }

        // …and the DB trigger refuses the same, independently.
        try {
            DB::table('recovery_attempts')->where('id', $aged->id)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'decision_receipt' => 'forged',
                'elevated_monitoring' => true,
            ]);
            $this->fail('The DB trigger must refuse sub-threshold completion.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('sub-threshold', $e->getMessage());
        }

        // A non-guardian's signature is refused.
        $intruder = sodium_crypto_sign_keypair();

        try {
            $this->recovery->approve(
                $aged,
                base64_encode(sodium_crypto_sign_publickey($intruder)),
                base64_encode(sodium_crypto_sign_detached(SocialRecoveryService::bindingMessage($aged), sodium_crypto_sign_secretkey($intruder))),
            );
            $this->fail('A non-guardian approval must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('not a registered guardian', $e->getMessage());
        }

        // A registered guardian with a WRONG signature is refused.
        try {
            $this->recovery->approve($aged, $this->guardians[1]['public'], base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)));
            $this->fail('An invalid signature must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('does not verify', $e->getMessage());
        }

        // The same guardian approving twice counts once.
        $aged = $this->recovery->approve($aged, $this->guardians[0]['public'], $this->signApproval($aged, 0));
        $this->assertCount(1, $aged->guardian_approvals);

        // An approval signed for a DIFFERENT attempt does not transfer.
        $other = $this->recovery->initiate($set, 'newkey:other:'.Str::ulid());

        try {
            $this->recovery->approve($aged, $this->guardians[1]['public'], $this->signApproval($other, 1));
            $this->fail('A replayed approval from another attempt must be refused.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('does not verify', $e->getMessage());
        }
    }

    public function test_a_contested_recovery_cannot_complete(): void
    {
        $set = $this->guardianSet();
        $aged = $this->agedAttempt($set);

        $aged = $this->recovery->approve($aged, $this->guardians[0]['public'], $this->signApproval($aged, 0));
        $aged = $this->recovery->approve($aged, $this->guardians[1]['public'], $this->signApproval($aged, 1));

        // Contesting is deliberately cheap.
        $aged = $this->recovery->contest($aged);
        $this->assertSame(RecoveryStatus::Contested, $aged->status);

        // Service refuses completion…
        try {
            $this->recovery->complete($aged);
            $this->fail('A contested recovery must not complete.');
        } catch (RecoveryProcessException $e) {
            $this->assertStringContainsString('cannot complete', $e->getMessage());
        }

        // …and the DB trigger refuses it independently.
        try {
            DB::table('recovery_attempts')->where('id', $aged->id)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'decision_receipt' => 'forged',
                'elevated_monitoring' => true,
            ]);
            $this->fail('The DB trigger must refuse contested completion.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('contested', $e->getMessage());
        }
    }

    public function test_a_lawful_recovery_completes_with_receipt_and_monitoring(): void
    {
        $set = $this->guardianSet();
        $aged = $this->agedAttempt($set);

        $aged = $this->recovery->approve($aged, $this->guardians[0]['public'], $this->signApproval($aged, 0));
        $aged = $this->recovery->approve($aged, $this->guardians[2]['public'], $this->signApproval($aged, 2));

        $completed = $this->recovery->complete($aged);

        $this->assertSame(RecoveryStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNotNull($completed->decision_receipt);
        $this->assertStringContainsString('2/2 guardian approvals', $completed->decision_receipt);
        $this->assertStringContainsString('uncontested', $completed->decision_receipt);
        $this->assertTrue($completed->elevated_monitoring);

        // The window cannot be retroactively shortened even afterwards
        // (the attempt is a public record).
        try {
            DB::table('recovery_attempts')->where('id', $completed->id)->update([
                'challenge_window_ends_at' => now()->subDays(30),
            ]);
            $this->fail('Shortening the window retroactively must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be shortened', $e->getMessage());
        }
    }

    public function test_recovery_is_never_an_email_reset(): void
    {
        // Structural: no email/phone/OTP vocabulary anywhere in the
        // recovery schema or the recovery service — the low-friction
        // reset path does not exist to be misused.
        /** @var list<object{column_name: string}> $columns */
        $columns = DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('guardian_sets', 'recovery_attempts')"
        );

        foreach ($columns as $column) {
            foreach (['email', 'phone', 'sms', 'otp', 'password'] as $fragment) {
                $this->assertFalse(
                    str_contains(strtolower($column->column_name), $fragment),
                    "Recovery schema contains a low-friction reset vector: {$column->column_name}"
                );
            }
        }

        $source = (string) file_get_contents(app_path('Domain/Identity/Recovery/SocialRecoveryService.php'));

        foreach (['sendEmail', 'Mail::', 'resetLink', 'otp', 'OneTimePassword'] as $token) {
            $this->assertFalse(
                str_contains($source, $token),
                "SocialRecoveryService references a low-friction reset vector: \"{$token}\""
            );
        }

        // And the service's public surface is exactly the high-friction
        // path: register → initiate → approve/contest → complete.
        $reflection = new \ReflectionClass(SocialRecoveryService::class);
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $this->assertSame(
            ['approve', 'bindingMessage', 'complete', 'contest', 'initiate', 'registerGuardians'],
            $methods,
        );
    }
}
