<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Joint\Disclosure\Exceptions\SensitiveDataRejectedException;
use App\Domain\Joint\Disclosure\Models\DisclosureProof;
use App\Domain\Joint\Disclosure\Services\MinimizedDisclosureService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MinimizedDisclosureTest — DOCUMENT 6.4 (I8, third layer).
 *
 * Prove the criterion, never reveal the measurement: ingestion rejects
 * any raw sensitive field (validation layer); the schema has no witness
 * column (allowlist layer — verified live here); the neural red line
 * admits NO proof path at all; unrecognized proof systems fail CLOSED;
 * consent is per-attestation and revocable.
 */
final class MinimizedDisclosureTest extends TestCase
{
    private MinimizedDisclosureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MinimizedDisclosureService();
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'statement' => 'over_18',
            'subject_commitment' => 'commit:'.bin2hex(random_bytes(8)),
            'proof_blob' => base64_encode(random_bytes(64)),
            'proof_system' => 'groth16-audited-v1',
        ];
    }

    public function test_a_valid_proof_ingests_storing_only_statement_commitment_and_proof(): void
    {
        $proof = $this->service->ingest($this->validPayload());

        $this->assertTrue($proof->verified);
        $this->assertTrue($this->service->stands($proof));
        $this->assertSame('over_18', $proof->statement);

        // The stored row consists of exactly the disclosed trio + status
        // columns — nothing that could carry a measurement.
        /** @var list<object{column_name: string}> $columns */
        $columns = DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'disclosure_proofs'
             ORDER BY column_name"
        );

        $names = array_map(static fn (object $c): string => $c->column_name, $columns);

        $this->assertSame([
            'consent_revoked', 'created_at', 'expires_at', 'id', 'proof_blob',
            'proof_system', 'statement', 'subject_commitment', 'updated_at', 'verified',
        ], $names);

        $this->assertNotContains('witness', $names);
    }

    public function test_raw_sensitive_fields_are_rejected_at_the_door(): void
    {
        $sensitiveKeys = [
            'fingerprint_template', 'iris_data', 'heart_rate_series',
            'diagnosis_code', 'birthdate', 'dna_sequence', 'witness',
            'blood_pressure', 'face_embedding',
        ];

        foreach ($sensitiveKeys as $key) {
            $payload = $this->validPayload();
            $payload[$key] = 'raw-measurement-data';

            try {
                $this->service->ingest($payload);
                $this->fail("A payload carrying \"{$key}\" must be rejected.");
            } catch (SensitiveDataRejectedException $e) {
                $this->assertStringContainsString('I8', $e->getMessage());
            }
        }

        // Nothing partial persisted from any rejected ingestion.
        $this->assertSame(0, DisclosureProof::query()->count());
    }

    public function test_the_neural_red_line_admits_no_proof_path_at_all(): void
    {
        foreach (['neural_activity_above_threshold', 'eeg_alpha_criterion', 'brainwave_pattern_match'] as $statement) {
            $payload = $this->validPayload();
            $payload['statement'] = $statement;

            try {
                $this->service->ingest($payload);
                $this->fail("A neural statement (\"{$statement}\") must be refused even as a proof.");
            } catch (SensitiveDataRejectedException $e) {
                $this->assertStringContainsString('neural red line', $e->getMessage());
                $this->assertStringContainsString('outside the system', $e->getMessage());
            }
        }

        $this->assertSame(0, DisclosureProof::query()->count());
    }

    public function test_unrecognized_proof_systems_fail_closed(): void
    {
        $payload = $this->validPayload();
        $payload['proof_system'] = 'homemade-zk-v0';

        try {
            $this->service->ingest($payload);
            $this->fail('An unaudited proof system must fail closed.');
        } catch (SensitiveDataRejectedException $e) {
            $this->assertStringContainsString('fails CLOSED', $e->getMessage());
        }

        // A bare claim without a proof blob is not a proof.
        $payload = $this->validPayload();
        $payload['proof_blob'] = '  ';

        try {
            $this->service->ingest($payload);
            $this->fail('A bare claim must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not a proof', $e->getMessage());
        }
    }

    public function test_consent_is_revocable_and_expiry_fails_closed(): void
    {
        $proof = $this->service->ingest($this->validPayload());
        $this->assertTrue($this->service->stands($proof));

        // Revocation is honored immediately.
        $revoked = $this->service->revokeConsent($proof);
        $this->assertFalse($this->service->stands($revoked));

        // Expiry fails closed.
        $payload = $this->validPayload();
        $payload['expires_at'] = now()->subDay()->toIso8601String();
        $expired = $this->service->ingest($payload);
        $this->assertFalse($this->service->stands($expired));

        // Live proof with future expiry stands.
        $payload = $this->validPayload();
        $payload['expires_at'] = now()->addDay()->toIso8601String();
        $live = $this->service->ingest($payload);
        $this->assertTrue($this->service->stands($live));
    }

    public function test_the_live_schema_holds_no_sensitive_column_anywhere_in_the_identity_layer(): void
    {
        // Layer 2 of I8, verified live across ALL DEV-6.x tables: the
        // schema cannot hold a measurement even if code tried.
        $forbidden = [
            'biometric', 'fingerprint', 'retina', 'iris_scan', 'face_geometry',
            'face_template', 'voiceprint', 'gait', 'dna', 'genome', 'genetic',
            'health_record', 'diagnosis', 'medical', 'blood_', 'heart_rate',
            'neural', 'eeg', 'brainwave', 'brain_signal', 'witness', 'birthdate',
        ];

        /** @var list<object{table_name: string, column_name: string}> $columns */
        $columns = DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('identities', 'constitutional_parameters',
                                  'rung1_pool_grants', 'attestation_vestings',
                                  'sybil_bounties', 'guardian_sets',
                                  'recovery_attempts', 'offline_vouchers',
                                  'deferred_settlements', 'disclosure_proofs')"
        );

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            foreach ($forbidden as $fragment) {
                $this->assertFalse(
                    str_contains(strtolower($column->column_name), $fragment),
                    "I8 violated: {$column->table_name}.{$column->column_name} can hold sensitive data."
                );
            }
        }
    }

    public function test_disclosure_never_gates_basic_ledger_access(): void
    {
        // DOCUMENT 6.4 §2: consent is never a precondition for
        // non-credit ledger access. Structural check: the ledger core
        // and settlement paths reference no disclosure vocabulary.
        $roots = [
            app_path('Domain/Meridian/Ledger'),
            app_path('Domain/Meridian/Settlement'),
        ];

        $scanned = 0;

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $scanned++;
                $source = (string) file_get_contents($file->getPathname());

                foreach (['DisclosureProof', 'MinimizedDisclosureService', 'disclosure_proofs'] as $token) {
                    $this->assertFalse(
                        str_contains($source, $token),
                        "{$file->getPathname()} gates ledger access on disclosure via \"{$token}\" — "
                        .'a person can use the ledger without ever submitting a proof.'
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $scanned);
    }
}
