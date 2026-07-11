<?php

require_once __DIR__ . '/ExecutionScenariosTrait.php';

/**
 * Ported from test-ci3/.../Test_custom_qb.php: scenarios whose regression
 * only shows up in actual query results/return values, not in the compiled
 * SQL string alone (see CompiledSqlTest.php for the SQL-string assertions).
 *
 * Fixtures (seeded once per run by bootstrap.php's cqb_seed_fixtures()):
 *   users:  1=Alice/A, 2=Bob/B, 3=Charlie/A
 *   scores (user_id -> value): 1->10, 1->50, 1->30
 *   category_scores (user_id, category -> value): (1,A)->100, (1,A)->50, (1,B)->999
 *   profiles (user_id -> rank_score): 1->10, 2->30, 3->20
 *
 * See SqliteExecutionTest.php for the sqlite3 twin — same scenarios, shared
 * via ExecutionScenariosTrait, run against an in-memory sqlite3 connection.
 */
class ExecutionTest extends CqbTestCase
{
    use ExecutionScenariosTrait;
}