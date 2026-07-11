<?php

require_once __DIR__ . '/EdgeCaseScenariosTrait.php';

/**
 * Edge-case coverage identified by a targeted audit of CustomQueryBuilder.php,
 * RelationAggregateTrait.php, QueryValidationTrait.php, CustomQueryBuilderResult.php,
 * and NestedQueryBuilder.php against the existing suite (CompiledSqlTest.php,
 * ExecutionTest.php). Two real bugs were found and fixed alongside these tests:
 *
 *  - transaction() called its callback via call_user_func($callback) with NO
 *    arguments, so the documented `function ($db) { ... }` signature (see
 *    README's Transactions section) threw ArgumentCountError. Fixed to pass
 *    $this through.
 *  - where_aggregate()/or_where_aggregate() with a BETWEEN/NOT BETWEEN operator
 *    never validated the value array's arity, so a 1-element array silently
 *    compiled to "BETWEEN 1 AND NULL" (always false, no error) instead of
 *    throwing. Fixed to require exactly 2 elements.
 *
 * Fixtures (see bootstrap.php's cqb_seed_fixtures()):
 *   users:  1=Alice/A, 2=Bob/B, 3=Charlie/A
 *   scores (user_id -> value): 1->10, 1->50, 1->30
 *   category_scores (user_id, category -> value): (1,A)->100, (1,A)->50, (1,B)->999
 *   profiles (user_id -> rank_score): 1->10, 2->30, 3->20
 *
 * See SqliteEdgeCaseTest.php for the sqlite3 twin — shares every scenario
 * except the handful below via EdgeCaseScenariosTrait; these 5 assert exact
 * compiled-SQL text (backtick-quoted here, double-quote-quoted there).
 */
class EdgeCaseTest extends CqbTestCase
{
    use EdgeCaseScenariosTrait;

    public function test_sibling_where_exists_calls_do_not_leak_state_between_each_other()
    {
        $sql = $this->sql($this->db->from('users')
            ->where_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->where_exists(function ($q) {
                $q->select('1')->from('profiles')->where('profiles.user_id = users.id');
            })
            ->get_compiled_select());

        $this->assertSame(
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) AND EXISTS (SELECT 1 FROM `profiles` WHERE `profiles`.`user_id` = `users`.`id`)',
            $sql
        );
    }

    public function test_search_with_empty_columns_array_leaves_query_unchanged()
    {
        $sql = $this->sql($this->db->search('john', [])->get_compiled_select('users'));
        $this->assertSame('SELECT * FROM `users`', $sql);
    }

    public function test_search_skips_blank_entries_in_columns_without_producing_invalid_sql()
    {
        // Regression guard: an empty-string column at index 0 must not leave a
        // dangling "OR" as the first condition inside the group.
        $sql = $this->sql($this->db->search('john', ['', 'name'])->get_compiled_select('users'));
        $this->assertSame("SELECT * FROM `users` WHERE ( `name` LIKE '%john%' ESCAPE '!' )", $sql);
    }

    public function test_unless_runs_default_callback_when_condition_is_true()
    {
        $sql = $this->sql($this->db->unless(true, function ($q) {
            $q->where('category', 'A');
        }, function ($q) {
            $q->where('category', 'B');
        })->get_compiled_select('users'));

        $this->assertSame("SELECT * FROM `users` WHERE `category` = 'B'", $sql);
    }

    public function test_column_name_of_exactly_64_chars_is_accepted()
    {
        $column = str_repeat('a', 64);
        // Should not throw — 64 is the documented maximum, not the cutoff.
        $sql = $this->sql($this->db->where_in($column, [1])->get_compiled_select('users'));
        $this->assertStringContainsString('`' . $column . '`', $sql);
    }
}