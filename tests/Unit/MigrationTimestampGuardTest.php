<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Deploy-defect guard (release 02, Defect 1): on MariaDB with
 * explicit_defaults_for_timestamp OFF (the MariaDB 10.6 default on
 * the production host), a TIMESTAMP column declared NOT NULL with
 * no default and no CURRENT_TIMESTAMP clause is rejected with
 * SQLSTATE 1067 (invalid default value) whenever an earlier
 * TIMESTAMP column in the same table takes the implicit-default
 * slot. SQLite accepts the same definitions silently, so the
 * feature suite cannot see this class of failure; this guard scans
 * the migration sources instead. Every timestamp() or timestampTz()
 * column must carry ->nullable(), ->useCurrent(),
 * ->useCurrentOnUpdate(), or an explicit ->default().
 *
 * The $table->timestamps() and $table->softDeletes() helpers are
 * exempt by construction: Laravel compiles them nullable.
 */
class MigrationTimestampGuardTest extends TestCase
{
    public function test_no_bare_not_null_timestamp_columns_in_migrations(): void
    {
        $dir = dirname(__DIR__, 2).'/database/migrations';
        $files = glob($dir.'/*.php');
        $this->assertNotEmpty($files, 'migration directory must not be empty');

        $violations = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            // One schema statement per match: from the column call
            // to the terminating semicolon, including its fluent
            // modifier chain.
            preg_match_all(
                "/->timestamp(?:Tz)?\\(\\s*'([^']+)'\\s*\\).*?;/s",
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $statement = $match[0];
                $guarded = str_contains($statement, '->nullable(')
                    || str_contains($statement, '->useCurrent(')
                    || str_contains($statement, '->useCurrentOnUpdate(')
                    || str_contains($statement, '->default(');

                if (! $guarded) {
                    $violations[] = basename($file).': '.$match[1]
                        .' (timestamp with no nullable/default/useCurrent; '
                        .'MariaDB rejects this with SQLSTATE 1067 when it is '
                        .'not the first timestamp column of its table)';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Bare NOT NULL timestamp columns found:\n".implode("\n", $violations)
        );
    }

    public function test_guard_actually_scans_timestamp_columns(): void
    {
        // Canary: the scan must be finding real timestamp columns,
        // otherwise the guard above could pass vacuously if the
        // pattern ever stops matching the migration style.
        $dir = dirname(__DIR__, 2).'/database/migrations';
        $count = 0;
        foreach (glob($dir.'/*.php') as $file) {
            $count += preg_match_all(
                "/->timestamp(?:Tz)?\\(\\s*'[^']+'\\s*\\)/",
                (string) file_get_contents($file)
            );
        }

        $this->assertGreaterThan(20, $count, 'guard pattern should match the known timestamp columns');
    }
}
