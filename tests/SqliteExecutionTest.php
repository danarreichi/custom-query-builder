<?php

require_once __DIR__ . '/ExecutionScenariosTrait.php';

/**
 * SQLite (sqlite3 driver) twin of ExecutionTest.php — shares every test
 * method via ExecutionScenariosTrait (real returned data/row counts, never
 * exact compiled-SQL text, so nothing here is driver-specific except which
 * connection setUp() wires up).
 *
 * Deliberately does NOT extend ExecutionTest: both classes independently
 * `use` the same trait so this file has no load-order dependency on
 * ExecutionTest.php. That matters because CI3's own DB_driver.php::
 * escape_identifiers() caches its quote-char regex in a function-local
 * `static` variable — shared process-wide across every driver instance, not
 * per-connection. Running a mysqli-backed test class and this sqlite3-backed
 * one in the very same PHPUnit process would let whichever ran first "win"
 * that cache for the rest of the run. Class inheritance across driver
 * boundaries would still be safe for THIS trait (none of its assertions
 * touch exact-compiled-SQL text), but the project run script always invokes
 * the mysql and sqlite suites as separate `vendor/bin/phpunit` processes
 * anyway (see phpunit.xml's two testsuites) specifically to sidestep that
 * CI3 quirk for CompiledSqlTest/EdgeCaseTest's exact-string assertions — so
 * every Sqlite*Test class follows the same no-cross-driver-inheritance
 * pattern for consistency.
 */
class SqliteExecutionTest extends CqbTestCase
{
    use ExecutionScenariosTrait;

    protected function setUp(): void
    {
        $this->db = cqb_sqlite_connection();
        $this->db->reset_query();
    }
}
