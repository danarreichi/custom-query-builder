<?php

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
 */
class EdgeCaseTest extends CqbTestCase
{
    // ---------------------------------------------------------------
    // transaction()
    // ---------------------------------------------------------------

    public function test_transaction_commits_and_passes_db_instance_to_callback()
    {
        // BUG FIX regression: the callback must receive the builder instance,
        // matching the documented `function ($db) { ... }` signature.
        $before = $this->db->count_all_results('users');

        $result = $this->db->transaction(function ($db) {
            $db->insert('users', ['name' => 'TxCommit', 'email' => 'txcommit@example.com', 'category' => 'Z']);
            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
        $this->assertSame($before + 1, $this->db->count_all_results('users'));

        $this->db->query("DELETE FROM users WHERE email = 'txcommit@example.com'");
    }

    public function test_transaction_rolls_back_and_returns_false_on_thrown_exception()
    {
        $before = $this->db->count_all_results('users');

        $result = $this->db->transaction(function ($db) {
            $db->insert('users', ['name' => 'TxRollback', 'email' => 'txrollback@example.com', 'category' => 'Z']);
            throw new Exception('deliberate failure');
        });

        $this->assertFalse($result);
        $this->assertSame($before, $this->db->count_all_results('users'), 'Insert must be rolled back');
    }

    public function test_transaction_strict_mode_rethrows_wrapped_exception_and_still_rolls_back()
    {
        $before = $this->db->count_all_results('users');

        try {
            $this->db->transaction(function ($db) {
                $db->insert('users', ['name' => 'TxStrict', 'email' => 'txstrict@example.com', 'category' => 'Z']);
                throw new Exception('strict failure');
            }, true);
            $this->fail('Expected an Exception to be thrown in strict mode');
        } catch (Exception $e) {
            $this->assertStringContainsString('strict failure', $e->getMessage());
        }

        $this->assertSame($before, $this->db->count_all_results('users'), 'Insert must be rolled back even in strict mode');
    }

    public function test_transaction_rejects_non_callable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->transaction('not_a_real_function');
    }

    // ---------------------------------------------------------------
    // chunk() / chunk_by_id()
    // ---------------------------------------------------------------

    public function test_chunk_processes_all_rows_when_total_is_an_exact_multiple_of_page_size()
    {
        // 3 users, page_size=3 is an exact multiple: chunk() must issue one
        // extra (empty) query after the full page rather than stopping early,
        // but the callback itself must only fire once (for the real page).
        $calls = 0;
        $total = $this->db->chunk(3, function ($rows) use (&$calls) {
            $calls++;
        }, 'users');

        $this->assertSame(3, $total);
        $this->assertSame(1, $calls);
    }

    public function test_chunk_callback_returning_false_on_first_page_stops_immediately()
    {
        $calls = 0;
        $total = $this->db->chunk(1, function ($rows) use (&$calls) {
            $calls++;
            return false;
        }, 'users');

        $this->assertSame(0, $total);
        $this->assertSame(1, $calls);
    }

    public function test_chunk_by_id_accumulates_across_pages_in_id_order()
    {
        $seen_ids = [];
        $total = $this->db->chunk_by_id(1, function ($rows) use (&$seen_ids) {
            foreach ($rows as $row) {
                $seen_ids[] = (int) $row->id;
            }
        }, 'id', 'users');

        $this->assertSame(3, $total);
        $this->assertSame($seen_ids, array_values(array_unique($seen_ids)), 'No row should be processed twice');
        $sorted = $seen_ids;
        sort($sorted);
        $this->assertSame($sorted, $seen_ids, 'Rows must be processed in ascending id order');
    }

    public function test_chunk_by_id_throws_when_ordering_column_is_missing_from_result_row()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('name')->chunk_by_id(1, function ($rows) {
            // never reached for the second page onward
        }, 'id', 'users');
    }

    // ---------------------------------------------------------------
    // calc_rows()
    // ---------------------------------------------------------------

    public function test_calc_rows_found_rows_ignores_limit_and_survives_eager_loading()
    {
        $result = $this->db->select(['id', 'name'])
            ->with_many('scores', 'user_id', 'id')
            ->calc_rows()
            ->get('users', 1, 0);

        $this->assertCount(1, $result->result());
        $this->assertSame(3, $result->found_rows());
        $this->assertIsArray($result->row()->scores);
    }

    // ---------------------------------------------------------------
    // where_between() / where_not_between()
    // ---------------------------------------------------------------

    public function test_where_between_rejects_arrays_that_are_not_exactly_two_elements()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->where_between('id', [1])->get_compiled_select('users');
    }

    public function test_where_not_between_excludes_rows_inside_the_range()
    {
        $result = $this->db->select('id')->where_not_between('id', [1, 2])->get('users');
        $this->assertSame([3], array_map('intval', array_column($result->result_array(), 'id')));
    }

    // ---------------------------------------------------------------
    // order_by_sequence()
    // ---------------------------------------------------------------

    public function test_order_by_sequence_rejects_empty_array()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->order_by_sequence('category', []);
    }

    public function test_order_by_sequence_rejects_invalid_column_format()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->order_by_sequence('category; DROP TABLE users', ['A', 'B']);
    }

    public function test_order_by_sequence_sorts_values_absent_from_the_list_last()
    {
        // Alice/Charlie = 'A', Bob = 'B'. Sequence only lists 'B' first, so any
        // row whose value ISN'T in the array falls through to the ELSE branch
        // (index = count($array)) and must sort after every listed value.
        $result = $this->db->select(['id', 'category'])->order_by_sequence('category', ['B'])->get('users');
        $categories = array_column($result->result_array(), 'category');
        $this->assertSame('B', $categories[0]);
    }

    // ---------------------------------------------------------------
    // with() validation
    // ---------------------------------------------------------------

    public function test_with_rejects_relation_alias_that_fails_table_name_validation()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->with(['scores' => 'bad-alias!'], 'user_id', 'id')->get('users');
    }

    public function test_with_rejects_non_boolean_multiple_flag()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->with('scores', 'user_id', 'id', 'yes')->get('users');
    }

    // ---------------------------------------------------------------
    // key_by()
    // ---------------------------------------------------------------

    public function test_key_by_last_matching_row_wins_on_duplicate_keys()
    {
        $result = $this->db->select(['id', 'category'])->order_by('id', 'ASC')->get('users');
        $keyed = $result->key_by('category');

        // Alice (id=1) and Charlie (id=3) both have category 'A' -> Charlie wins (last).
        $this->assertSame(3, (int) $keyed['A']->id);
        $this->assertCount(2, $keyed); // 'A' and 'B' only
    }

    public function test_key_by_on_empty_result_returns_empty_array()
    {
        $result = $this->db->where('id', 999999)->get('users');
        $this->assertSame([], $result->key_by('id'));
    }

    public function test_key_by_closure_returning_non_scalar_key_throws()
    {
        $result = $this->db->get('users');
        $this->expectException(TypeError::class);
        $result->key_by(function ($row) {
            return [$row->id]; // illegal array offset
        });
    }

    // ---------------------------------------------------------------
    // CustomQueryBuilderResult direct-call edge cases
    // ---------------------------------------------------------------

    public function test_result_call_to_undefined_method_throws_bad_method_call_exception()
    {
        $result = $this->db->get('users');
        $this->expectException(BadMethodCallException::class);
        $result->this_method_does_not_exist_anywhere();
    }

    public function test_result_value_on_wrapper_returns_null_for_missing_property()
    {
        $result = $this->db->select('id')->where('id', 1)->get('users');
        $this->assertNull($result->value('no_such_column'));
    }

    // ---------------------------------------------------------------
    // where_exists() / search() / unless() / where_has() shorthand
    // ---------------------------------------------------------------

    public function test_where_exists_rejects_non_callable_callback()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->from('users')->where_exists('not-a-callback');
    }

    public function test_where_exists_callback_exception_does_not_corrupt_outer_builder_state()
    {
        try {
            $this->db->from('users')->where_exists(function ($q) {
                throw new RuntimeException('boom inside subquery callback');
            });
            $this->fail('Expected the callback exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom inside subquery callback', $e->getMessage());
        }

        // The outer builder must still be usable for an unrelated query.
        $this->db->reset_query();
        $result = $this->db->where('id', 1)->get('users');
        $this->assertSame(1, $result->num_rows());
    }

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

    public function test_where_has_shorthand_operator_without_explicit_count_defaults_to_one()
    {
        // BUG FIX regression (see bug-fix comment above where_has()): the
        // shorthand where_has($rel, $fk, $lk, '>') used to pick up the old
        // $operator default '>=' as the count instead of defaulting to 1.
        $result = $this->db->where_has('scores', 'user_id', 'id', '>=')->get('users');
        $this->assertSame(['Alice'], array_column($result->result_array(), 'name'));
    }

    // ---------------------------------------------------------------
    // query() with pending relations / non-SELECT statements
    // ---------------------------------------------------------------

    public function test_query_with_non_select_statement_still_returns_plain_bool_when_relations_pending()
    {
        $this->db->with_many('scores', 'user_id', 'id');
        $ok = $this->db->query("UPDATE users SET category = category WHERE id = 1");
        $this->assertIsBool($ok);
        $this->db->reset_query();
    }

    // ---------------------------------------------------------------
    // RelationAggregateTrait: where_aggregate()
    // ---------------------------------------------------------------

    public function test_where_aggregate_rejects_an_alias_that_was_never_registered()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->where_aggregate('never_defined_alias >', 100)->get_compiled_select('users');
    }

    public function test_where_aggregate_between_rejects_a_one_element_array()
    {
        // BUG FIX regression: previously silently compiled to
        // "BETWEEN 1 AND NULL" (always false) instead of throwing.
        $this->expectException(InvalidArgumentException::class);
        $this->db->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
            ->where_aggregate('score_total BETWEEN', [1])
            ->get_compiled_select('users');
    }

    public function test_where_aggregate_between_rejects_a_three_element_array()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
            ->where_aggregate('score_total BETWEEN', [1, 2, 3])
            ->get_compiled_select('users');
    }

    public function test_where_aggregate_coalesces_null_to_zero_for_non_between_operators()
    {
        // Bob has zero rows in `scores`; without COALESCE, SUM(...) is NULL and
        // `NULL != 0` is NULL (never true), which would wrongly exclude him.
        $result = $this->db->select(['id', 'name'])
            ->with_sum(['scores' => 'total'], 'user_id', 'id', 'value')
            ->where_aggregate('total =', 0)
            ->get('users');

        $this->assertContains('Bob', array_column($result->result_array(), 'name'));
    }

    // ---------------------------------------------------------------
    // RelationAggregateTrait: join_*
    // ---------------------------------------------------------------

    public function test_join_sum_requires_a_column()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->join_sum('scores', 'user_id', 'id', null)->get_compiled_select('users');
    }

    public function test_join_sum_rejects_invalid_column_name()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->join_sum('scores', 'user_id', 'id', 'value; DROP TABLE users')->get_compiled_select('users');
    }

    public function test_join_calculation_rejects_a_dangerous_expression()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->join_calculation('scores', 'user_id', 'id', 'SUM(value) UNION SELECT 1')->get_compiled_select('users');
    }

    // ---------------------------------------------------------------
    // RelationAggregateTrait: with_calculation() expression validation
    // ---------------------------------------------------------------

    public function test_with_calculation_rejects_unbalanced_parentheses()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->with_calculation(['scores' => 'x'], 'user_id', 'id', 'SUM(value')->get_compiled_select('users');
    }

    public function test_with_calculation_rejects_dangerous_keyword()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->with_calculation(['scores' => 'x'], 'user_id', 'id', 'SUM(value) UNION SELECT 1')->get_compiled_select('users');
    }

    public function test_with_calculation_rejects_expression_over_length_limit()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->with_calculation(['scores' => 'x'], 'user_id', 'id', str_repeat('a', 2001))->get_compiled_select('users');
    }

    public function test_with_avg_rejects_lowercase_disallowed_function_name()
    {
        // Case-insensitive WAF-bypass guard: is_allowed_sql_function() upper-cases
        // before checking the allow-list, so a lowercase "sleep(0)" must be
        // rejected exactly like the already-covered backtick-quoted `SLEEP`(0).
        $this->expectException(InvalidArgumentException::class);
        $this->db->with_avg(['scores' => 'x'], 'user_id', 'id', 'sleep(0)', true)->get_compiled_select('users');
    }

    // ---------------------------------------------------------------
    // QueryValidationTrait: identifier length boundary
    // ---------------------------------------------------------------

    public function test_column_name_of_exactly_64_chars_is_accepted()
    {
        $column = str_repeat('a', 64);
        // Should not throw — 64 is the documented maximum, not the cutoff.
        $sql = $this->sql($this->db->where_in($column, [1])->get_compiled_select('users'));
        $this->assertStringContainsString('`' . $column . '`', $sql);
    }

    public function test_column_name_of_65_chars_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->where_in(str_repeat('a', 65), [1])->get_compiled_select('users');
    }
}
