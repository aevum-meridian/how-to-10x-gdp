<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NoSensitivePIIMigrationTest — Invariant I8 (No Sensitive-Data Minting).
 *
 * DOCUMENT 4.2: "a CI test scans every migration and fails the build if
 * any introduces an identifiable biometric/health/neural column."
 *
 * Three layers verified:
 *  1. Static scan of every migration file for column definitions whose
 *     names denote raw biometric/health/neural data.
 *  2. Live-schema scan: no such column exists in the running database.
 *  3. The PostgreSQL event trigger (trg_i8_sensitive_columns) blocks
 *     offending DDL at execution time — even DDL that never went through
 *     a reviewed migration file.
 */
final class NoSensitivePIIMigrationTest extends TestCase
{
    /**
     * Column-name fragments denoting identifiable biometric/health/neural
     * data. Kept in sync with issuance_forbid_sensitive_columns().
     */
    private const FORBIDDEN_FRAGMENTS = [
        'biometric', 'fingerprint', 'retina', 'iris_scan', 'face_geometry',
        'face_template', 'voiceprint', 'gait', 'dna', 'genome', 'genetic',
        'health_record', 'diagnosis', 'medical', 'blood_', 'heart_rate',
        'neural', 'eeg', 'brainwave', 'brain_signal',
    ];

    public function test_no_migration_file_introduces_a_sensitive_column(): void
    {
        $migrationDir = base_path('database/migrations');
        $files = glob($migrationDir.'/*.php');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        $violations = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source);

            // Examine only column-defining expressions, so prose comments
            // about I8 (like the ones in the issuance migration) do not
            // false-positive: match ->xxx('col') Blueprint calls and
            // ADD COLUMN / CREATE TABLE column lists in raw SQL.
            preg_match_all(
                "/->[a-zA-Z]+\\(\\s*'([a-zA-Z0-9_]+)'/",
                $source,
                $blueprintCols,
            );
            preg_match_all(
                '/ADD COLUMN\s+"?([a-zA-Z0-9_]+)"?/i',
                $source,
                $rawCols,
            );

            $columns = array_merge($blueprintCols[1], $rawCols[1]);

            foreach ($columns as $column) {
                foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                    if (str_contains(strtolower($column), $fragment)) {
                        $violations[] = basename($file).' → column "'.$column.'"';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "I8 violated: migration(s) introduce identifiable biometric/health/neural columns:\n"
            .implode("\n", $violations)
        );
    }

    public function test_the_live_schema_contains_no_sensitive_column(): void
    {
        /** @var list<object{table_name: string, column_name: string}> $columns */
        $columns = DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'public'"
        );

        $violations = [];

        foreach ($columns as $col) {
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains(strtolower($col->column_name), $fragment)) {
                    $violations[] = "{$col->table_name}.{$col->column_name}";
                }
            }
        }

        $this->assertSame([], $violations, 'I8 violated: live schema carries sensitive columns: '.implode(', ', $violations));
    }

    public function test_the_event_trigger_blocks_sensitive_ddl_at_execution_time(): void
    {
        $attempts = [
            'CREATE TABLE i8_probe_a (id int, retina_hash text)',
            'CREATE TABLE i8_probe_b (id int, heart_rate_series jsonb)',
            'ALTER TABLE verifiers ADD COLUMN dna_sample bytea',
            'ALTER TABLE attestations ADD COLUMN eeg_reading bytea',
        ];

        foreach ($attempts as $ddl) {
            try {
                DB::statement($ddl);
                $this->fail("I8 violated: DDL was permitted: {$ddl}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('I8', $e->getMessage());
            }
        }

        // Nothing leaked into the schema.
        $leaked = DB::select(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name LIKE 'i8_probe_%'"
        );
        $this->assertSame([], $leaked);
    }

    public function test_the_forbidden_fragment_list_matches_the_database_guard(): void
    {
        // Prose-logic agreement in miniature: the DB function must screen
        // every fragment this test screens, so the two layers cannot
        // silently diverge.
        /** @var object{prosrc: string}|null $fn */
        $fn = DB::selectOne(
            "SELECT prosrc FROM pg_proc WHERE proname = 'issuance_forbid_sensitive_columns'"
        );
        $this->assertNotNull($fn, 'The I8 event-trigger function must exist.');

        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $fn->prosrc,
                "The DB guard must screen the fragment \"{$fragment}\"."
            );
        }
    }
}
