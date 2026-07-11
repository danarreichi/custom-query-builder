<?php

/**
 * Shared test scenarios for ExecutionTest (mysqli) and SqliteExecutionTest
 * (sqlite3) — real query results/return values, never exact compiled-SQL
 * text (see CompiledSqlTest.php/SqliteCompiledSqlTest.php for that), so
 * every scenario here runs identically regardless of which driver the
 * concrete test class's setUp() connects to.
 *
 * Fixtures (seeded once per run by bootstrap.php's cqb_seed_fixtures()):
 *   users:  1=Alice/A, 2=Bob/B, 3=Charlie/A
 *   scores (user_id -> value): 1->10, 1->50, 1->30
 *   category_scores (user_id, category -> value): (1,A)->100, (1,A)->50, (1,B)->999
 *   profiles (user_id -> rank_score): 1->10, 2->30, 3->20
 */
trait ExecutionScenariosTrait
{
    public function test_get_returns_custom_result_wrapper_with_correct_row_count()
    {
        // Controller test 2.
        $result = $this->db->get('users');
        $this->assertInstanceOf(CustomQueryBuilderResult::class, $result);
        $this->assertSame(3, $result->num_rows());
    }

    public function test_with_one_respects_order_by_desc_inside_relation_callback()
    {
        // Controller test 11: with_one() must keep the FIRST matching row
        // after the relation's own order_by() is applied, i.e. the highest
        // value when ordered DESC — not an arbitrary/last row.
        $result = $this->db->select(['id', 'name'])
            ->with_one('scores', 'user_id', 'id', function ($q) {
                $q->order_by('value', 'DESC');
            })
            ->where('id', 1)
            ->get('users');

        $user = $result->row();
        $this->assertSame('50', (string) $user->scores->value);
    }

    public function test_value_returns_column_from_first_matching_row_or_null()
    {
        // Controller test 12.
        $this->assertSame('bob@example.com', $this->db->where('id', 2)->value('email', 'users'));
        $this->assertNull($this->db->where('id', 999)->value('email', 'users'));
    }

    public function test_value_rejects_invalid_column_name()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->value('1; DROP TABLE users', 'users');
    }

    public function test_with_rejects_array_relation_with_more_or_fewer_than_one_element()
    {
        // Controller test 33.
        try {
            $this->db->with(['orders' => 'user_orders', 'extra' => 'x'], 'user_id', 'id')->get('users');
            $this->fail('Expected InvalidArgumentException for a multi-element relation array');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exactly one element', $e->getMessage());
        }

        $this->db->reset_query();

        try {
            $this->db->with([], 'user_id', 'id')->get('users');
            $this->fail('Expected InvalidArgumentException for an empty relation array');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exactly one element', $e->getMessage());
        }

        $this->db->reset_query();

        $result = $this->db->with(['scores' => 'user_scores'], 'user_id', 'id')->where('id', 1)->get('users');
        $this->assertCount(3, $result->row()->user_scores);
    }

    public function test_where_exists_relation_and_where_not_exists_relation_partition_users_correctly()
    {
        // Controller test 18.
        $has = $this->db->select(['id', 'name'])
            ->where_exists_relation('scores', 'user_id', 'id')
            ->order_by('id', 'ASC')
            ->get('users');
        $this->assertSame(['Alice'], array_column($has->result_array(), 'name'));

        $this->db->reset_query();

        $has_not = $this->db->select(['id', 'name'])
            ->where_not_exists_relation('scores', 'user_id', 'id')
            ->order_by('id', 'ASC')
            ->get('users');
        $this->assertSame(['Bob', 'Charlie'], array_column($has_not->result_array(), 'name'));
    }

    public function test_call_order_fix_changes_actual_query_results_not_just_sql_text()
    {
        // Controller test 37: category='Z' (false for everyone) OR has>=3
        // scores (true only for Alice) AND id=999 (false for everyone).
        // Correct call-order semantics: category=Z OR (has>=3 AND id=999) =
        // false everywhere. The old bug (aggregate always appended last)
        // would have produced (category=Z AND id=999) OR has>=3 = Alice.
        $result = $this->db->where('category', 'Z')
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 3)
            ->where('id', 999)
            ->get('users');

        $this->assertSame(0, $result->num_rows());
    }

    public function test_composite_key_with_many_only_loads_same_category_rows()
    {
        // Controller test 30: user 1 is category A, so only the two
        // category_scores rows also in category A should attach — not the
        // category-B row.
        $result = $this->db->select(['id', 'name', 'category'])
            ->with_many('category_scores', ['user_id', 'category'], ['id', 'category'])
            ->where('id', 1)
            ->get('users');

        $this->assertCount(2, $result->row()->category_scores);
    }

    public function test_count_all_results_applies_where_conditions()
    {
        // Controller test 26.
        $this->assertSame(2, $this->db->where('category', 'A')->count_all_results('users'));
    }

    public function test_pluck_returns_flat_array_of_column_values()
    {
        // Controller test 27.
        $this->assertSame(
            ['Alice', 'Bob', 'Charlie'],
            $this->db->order_by('id', 'ASC')->pluck('name', 'users')
        );
    }

    public function test_or_where_has_validates_relation_operator_and_count()
    {
        // Controller tests 50a/50b/50c.
        $this->expectException(InvalidArgumentException::class);
        $this->db->where('id', 1)->or_where_has('scores; DROP TABLE users', 'user_id', 'id', null, '>=', 2);
    }

    public function test_or_where_has_rejects_invalid_operator()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->where('id', 1)->or_where_has('scores', 'user_id', 'id', null, 'BETWEEN', 2);
    }

    public function test_or_where_has_rejects_negative_count()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->where('id', 1)->or_where_has('scores', 'user_id', 'id', null, '>', -5);
    }

    public function test_or_where_has_works_correctly_with_valid_params()
    {
        // Controller test 50d.
        $result = $this->db->where('id', 999)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->get('users');
        $this->assertSame(['Alice'], array_column($result->result_array(), 'name'));
    }

    public function test_where_exists_relation_accepts_an_aliased_relation_string()
    {
        // Controller test 51.
        $result = $this->db->select(['id', 'name'])
            ->where_exists_relation('scores s', 'user_id', 'id')
            ->order_by('id', 'ASC')
            ->get('users');
        $this->assertSame(['Alice'], array_column($result->result_array(), 'name'));
    }

    public function test_where_aggregate_nested_inside_where_has_callback_actually_filters()
    {
        // Controller test 52: Alice has 3 scores (sum 90, satisfies >=2), but
        // the nested where_aggregate(score_total > 1000) must still exclude
        // her — if the nested condition were silently dropped, this would
        // wrongly return Alice.
        $result = $this->db->select(['id', 'name'])
            ->where_has('scores', 'user_id', 'id', function ($q) {
                $q->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
                  ->where_aggregate('score_total >', 1000);
            }, '>=', 2)
            ->get('users');

        $this->assertSame(0, $result->num_rows());
    }

    public function test_where_exists_relation_nested_inside_where_exists_callback_is_not_dropped()
    {
        // Controller test 53a: only Alice has any category_scores rows, so
        // the nested where_exists_relation() must narrow the outer EXISTS
        // down to her — if dropped, all 3 users would qualify (every user
        // has >=1 score).
        $result = $this->db->select(['id', 'name'])
            ->where_exists(function ($nested) {
                $nested->select('1')
                    ->from('scores')
                    ->where('scores.user_id = users.id')
                    ->where_exists_relation('category_scores', 'user_id', 'users.id');
            })
            ->order_by('id', 'ASC')
            ->get('users');

        $this->assertSame(['Alice'], array_column($result->result_array(), 'name'));
    }

    public function test_nested_where_exists_relation_inside_with_many_callback_is_not_dropped()
    {
        // Controller test 53b: the inner where_exists_relation() filters on
        // an impossible id (999). If actually applied, scores_count must be
        // 0; if silently dropped, it would wrongly stay at 3.
        $result = $this->db->select(['id', 'name'])
            ->with_many('scores', 'user_id', 'id', function ($q) {
                $q->where_exists(function ($nested) {
                    $nested->select('1')
                        ->from('category_scores')
                        ->where('category_scores.user_id = scores.user_id')
                        ->where_exists_relation('users', 'id', 'category_scores.user_id', function ($q2) {
                            $q2->where('id', 999);
                        });
                });
            })
            ->where('id', 1)
            ->get('users');

        $this->assertCount(0, $result->row()->scores);
    }

    public function test_temp_table_name_does_not_leak_after_eager_loading_get()
    {
        // Controller test 56a.
        $this->db->with_one('scores', 'user_id', 'id')->get('users');
        $ref = new ReflectionProperty($this->db, '_temp_table_name');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($this->db));
    }

    public function test_temp_table_name_does_not_leak_after_count_all_results()
    {
        // Controller test 56b.
        $this->db->count_all_results('users');
        $ref = new ReflectionProperty($this->db, '_temp_table_name');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($this->db));
    }

    public function test_key_by_does_not_collide_with_php_builtin_function_names()
    {
        // Controller test 57: a selected column literally named "count" used
        // to misfire key_by() by colliding with PHP's builtin count().
        $counts = $this->db->select('category, COUNT(*) as count')->group_by('category')->get('users')->key_by('count');
        $this->assertEqualsCanonicalizing([1, 2], array_keys($counts));
    }

    public function test_with_sum_rejects_a_raw_sql_subquery_as_the_relation_name()
    {
        // Controller test 58a: relation names go through is_valid_table_name(),
        // which rejects parentheses/raw SQL — a subquery must be given via
        // from()/join(), not as a "relation name" string.
        $this->expectException(InvalidArgumentException::class);
        $this->db->select(['id', 'name'])
            ->with_sum(['(SELECT user_id, value FROM scores) scores_sub' => 'nested_sum'], 'user_id', 'id', 'value')
            ->where('id', 1)
            ->get('users');
    }

    public function test_relation_alias_ending_in_sum_is_not_misdetected_as_an_aggregate_result()
    {
        // Controller test 59a: a with_many() relation whose alias happens to
        // end in "_sum" and whose rows contain a real "value" column must
        // stay a full array of row objects, not get collapsed into a scalar
        // by alias-suffix guessing.
        $result = $this->db->select(['id', 'name'])
            ->with_many(['scores' => 'user_scores_sum'], 'user_id', 'id')
            ->where('id', 1)
            ->get('users');

        $rows = $result->row()->user_scores_sum;
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);
        $this->assertTrue(property_exists($rows[0], 'value'));
    }

    public function test_with_one_no_match_with_alias_ending_in_count_stays_null_not_zero()
    {
        // Controller test 59b: with no matching relation row, a with_one()
        // alias ending in "_count" must default to NULL — not get stripped
        // and defaulted to 0 as if it were an aggregate.
        $result = $this->db->select(['id', 'name'])
            ->with_one(['scores' => 'top_score_count'], 'user_id', 'id', function ($q) {
                $q->where('value', 999999);
            })
            ->where('id', 1)
            ->get('users');

        $user = $result->row();
        $this->assertTrue(property_exists($user, 'top_score_count'));
        $this->assertNull($user->top_score_count);
    }

    public function test_relation_of_a_relation_with_alias_ending_in_sum_is_not_misdetected()
    {
        // Controller test 62: a genuine nested with_one() relation (not an
        // aggregate at all) whose alias coincidentally ends in "_sum" must
        // stay a full object.
        $result = $this->db->select(['id', 'name'])
            ->with_many('scores', 'user_id', 'id', function ($q) {
                $q->with_one(['users' => 'owner_sum'], 'id', 'user_id');
            })
            ->where('id', 1)
            ->get('users');

        $owner = $result->row()->scores[0]->owner_sum;
        $this->assertSame('Alice', $owner->name);
    }

    public function test_order_by_relation_actually_orders_rows_by_the_related_column()
    {
        // Controller test 68b: the backtick-quoting defense-in-depth added to
        // order_by_relation() must not change actual query behavior.
        $desc = $this->db->select(['id', 'name'])
            ->order_by_relation('profiles', 'user_id', 'id', 'rank_score', 'DESC')
            ->get('users');
        $this->assertSame(['Bob', 'Charlie', 'Alice'], array_column($desc->result_array(), 'name'));

        $this->db->reset_query();

        $asc = $this->db->select(['id', 'name'])
            ->order_by_relation('profiles', 'user_id', 'id', 'rank_score', 'ASC')
            ->get('users');
        $this->assertSame(['Alice', 'Charlie', 'Bob'], array_column($asc->result_array(), 'name'));
    }

    public function test_get_where_applies_array_conditions_directly()
    {
        $names = array_column(
            $this->db->order_by('id', 'ASC')->get_where('users', ['category' => 'A'])->result_array(),
            'name'
        );
        $this->assertSame(['Alice', 'Charlie'], $names);
    }

    public function test_exists_and_doesnt_exist_reflect_whether_rows_match()
    {
        $this->assertTrue($this->db->where('id', 1)->exists('users'));
        $this->assertFalse($this->db->where('id', 999)->exists('users'));

        $this->db->reset_query();

        $this->assertTrue($this->db->where('id', 999)->doesnt_exist('users'));
        $this->assertFalse($this->db->where('id', 1)->doesnt_exist('users'));
    }

    public function test_first_returns_first_row_or_null_when_no_match()
    {
        $user = $this->db->where('id', 2)->first('users');
        $this->assertSame('Bob', $user->name);

        $this->db->reset_query();

        $this->assertNull($this->db->where('id', 999)->first('users'));
    }

    public function test_get_found_rows_returns_the_backward_compatible_total()
    {
        // Old-style usage documented on get_found_rows(): call it on $this->db
        // right after a calc_rows() query instead of $result->found_rows().
        $this->db->select(['id', 'name'])->calc_rows()->get('users', 2, 0);
        $this->assertSame(3, $this->db->get_found_rows());
    }

    public function test_all_last_query_returns_every_executed_query_including_eager_loading()
    {
        $this->db->get('users');
        $this->assertCount(1, $this->db->all_last_query());

        $this->db->reset_query();

        // Eager loading executes an extra query per relation, so
        // all_last_query() must report the main query plus the relation query.
        $this->db->with_one('scores', 'user_id', 'id')->where('id', 1)->get('users');
        $this->assertCount(2, $this->db->all_last_query());
    }

    public function test_deferred_condition_as_first_content_inside_a_group_filters_correctly()
    {
        // Real-execution counterpart to CompiledSqlTest's SQL-string pin for
        // the same bug: a deferred where_exists_relation() as the first
        // content inside a group(), mixed with a no-op when(false) and a
        // fully-vanishing empty nested group, must not just compile to valid
        // SQL but actually filter to the right rows.
        $result = $this->db->select(['id', 'name'])
            ->where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->group_start()
                ->where('name !=', 'Bob')
                ->group(function ($q) {
                    $q->when(false, fn ($q) => $q->where('name', 'Heheh'));
                    $q->group(fn ($q) => $q->when(false, fn ($q) => $q->where('name', 'Hahaha')));
                    $q->where_exists_relation('profiles', 'user_id', 'id');
                })
            ->group_end()
            ->order_by('id', 'ASC')
            ->get('users');

        // Alice: has scores, name != Bob, has a profile row -> matches.
        $this->assertSame(['Alice'], array_column($result->result_array(), 'name'));
    }

    public function test_with_calculation_handles_expression_without_function_wrapper()
    {
        // BUG FIX regression: process_pending_aggregates()/process_pending_where_aggregates()
        // used to call $subquery->select($aggregate_function) without escape=false, even
        // though $aggregate_function is always already fully-formed. This was silently
        // harmless for every other test because they all use function-wrapped expressions
        // like SUM(value) — CI3's own protect_identifiers() skips re-escaping any string
        // containing "(". A bare arithmetic expression with no wrapping function has no
        // parens, so it used to fall through to CI3's normal column-parsing/quoting path,
        // which mangled it once the active driver's escape char wasn't a backtick.
        $result = $this->db->select(['id'])
            ->with_calculation(['scores' => 'value_plus_bonus'], 'user_id', 'id', 'value + 5', function ($q) {
                // Pin to a single row so the scalar subquery's result is
                // deterministic regardless of row order.
                $q->where('value', 10);
            })
            ->where('id', 1)
            ->get('users');

        $this->assertSame('15', (string) $result->row()->value_plus_bonus);
    }
}
