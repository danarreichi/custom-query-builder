<?php

/**
 * SQLite (sqlite3 driver) twin of CompiledSqlTest.php — same scenarios,
 * same assertions, run against an in-memory sqlite3 connection instead of
 * mysqli. Expected SQL strings use double-quote identifier quoting instead
 * of backticks (CI3's own driver-aware escape char), proving the same
 * cross-boundary bugs stay fixed regardless of which driver is active.
 *
 * Every assertion string here was captured from a known-good run and should
 * only ever change as a deliberate, reviewed behavior change.
 */
class SqliteCompiledSqlTest extends CqbTestCase
{
    protected function setUp(): void
    {
        $this->db = cqb_sqlite_connection();
        $this->db->reset_query();
    }

    public function test_call_order_is_preserved_across_or_where_has_and_plain_where()
    {
        // Regression: or_where_has()/where_has() with an explicit count used to
        // always append the count-subquery condition LAST regardless of when it
        // was actually called, silently changing AND/OR binding vs. the call order.
        $sql = $this->sql($this->db->where('id', 999)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('category', 'A')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 999 OR (SELECT COUNT(*) FROM "scores" WHERE "scores"."user_id" = "users"."id") >= 2 AND "category" = \'A\'',
            $sql
        );
    }

    public function test_where_exists_relation_inside_group_stays_inside_the_parentheses()
    {
        // Regression: the EXISTS clause used to leak out and land AFTER
        // group_end() instead of inside the group's parentheses.
        $sql = $this->sql($this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('email !=', 'x@example.com')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( "category" = \'A\' OR EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") ) AND "email" != \'x@example.com\'',
            $sql
        );
    }

    public function test_where_exists_does_not_leak_parent_pending_state_into_subquery()
    {
        // Regression: where_exists()'s subquery clone used to flush pending
        // relation state against a literal '__parent__' placeholder table,
        // producing invalid SQL or silently dropping nested conditions.
        $sql = $this->sql($this->db->select(['id', 'name'])
            ->from('users')
            ->where_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->get_compiled_select());

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $sql
        );
    }

    public function test_custom_expression_with_already_qualified_column_is_not_double_prefixed()
    {
        // Regression: _prefix_bare_identifiers() used to double-qualify an
        // already-dotted column in a custom SUM()/AVG() expression, e.g.
        // "scores.value" becoming "`sub`.`scores`.`sub`.`value`".
        $sql = $this->sql($this->db->with_sum(['scores' => 'total'], 'user_id', 'id', 'scores.value', true)
            ->where('id', 1)
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT *, (SELECT SUM(scores.value) FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "total" FROM "users" WHERE "id" = 1',
            $sql
        );
    }

    public function test_order_by_relation_qualifies_against_the_aliased_main_table_and_is_identifier_quoted()
    {
        // Regression: order_by_relation() used to emit the invalid, unaliased
        // "users u.id" instead of "`u`.`id`" when the main table had an alias.
        // Also pins the backtick-quoting defense-in-depth added on top of
        // is_valid_table_name()/is_valid_column_name() validation.
        $sql = $this->sql($this->db->order_by_relation('scores', 'user_id', 'id', 'value', 'DESC')
            ->get_compiled_select('users u'));

        $this->assertSame(
            'SELECT * FROM "users" "u" ORDER BY (SELECT "value" FROM "scores" WHERE "user_id" = "u"."id" LIMIT 1) DESC',
            $sql
        );
    }

    public function test_composite_key_where_exists_relation_ands_every_key_pair_inside_the_exists()
    {
        $sql = $this->sql($this->db->where('id', 1)
            ->or_where_exists_relation('category_scores', ['user_id', 'category'], ['id', 'category'])
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR EXISTS (SELECT 1 FROM "category_scores" WHERE "category_scores"."user_id" = "users"."id" AND "category_scores"."category" = "users"."category") AND "name" != \'Bob\'',
            $sql
        );
    }

    public function test_already_dotted_foreign_key_is_not_double_qualified()
    {
        // Regression: process_pending_where_exists() used to unconditionally
        // prepend the relation alias onto an already-qualified foreign key,
        // producing invalid SQL like "ms.ms.user_id" instead of "ms.user_id".
        $sql = $this->sql($this->db->select(['id', 'name'])
            ->where_exists_relation('scores ms', 'ms.user_id', 'id')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" "ms" WHERE "ms"."user_id" = "users"."id")',
            $sql
        );
    }

    public function test_group_containing_when_containing_or_where_exists_relation_stays_nested()
    {
        $sql = $this->sql($this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->when(true, function ($q2) {
                      $q2->or_where_exists_relation('scores', 'user_id', 'id', null, true);
                  });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( "category" = \'A\' OR EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") ) AND "name" != \'Bob\'',
            $sql
        );
    }

    public function test_order_by_relation_rejects_sql_injection_attempts()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->order_by_relation('profiles; DROP TABLE users', 'user_id', 'id', 'rank_score');
    }

    public function test_where_in_rejects_sql_injection_in_column_name()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->where_in('id; DROP TABLE users', [1, 2, 3])->get_compiled_select('users');
    }

    // --- Ported from test-ci3/.../Test_custom_qb.php (see test numbers in each docblock) ---

    public function test_where_has_default_count_delegates_to_plain_exists_subquery()
    {
        // Controller test 34: where_has() with the default (>=1) must compile
        // to the same plain EXISTS shape as where_exists_relation(), not a
        // COUNT(*) subquery.
        $sql = $this->sql($this->db->where_has('scores', 'user_id', 'id')->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $sql
        );
    }

    public function test_where_aggregate_between_preserves_call_order_with_plain_wheres()
    {
        // Controller test 38h: or_where_aggregate() BETWEEN must land exactly
        // where it was called relative to surrounding where()s, wrapped in
        // COALESCE(...) so a user with zero related rows still evaluates
        // (NULL BETWEEN ... is neither true nor false, COALESCE forces 0).
        $sql = $this->sql($this->db->where('id', 1)
            ->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
            ->or_where_aggregate('score_total BETWEEN', [1, 100])
            ->where('category', 'A')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT *, (SELECT SUM(value) FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "score_total" FROM "users" WHERE "id" = 1 OR COALESCE((SELECT SUM(value) FROM "scores" "scores_agg" WHERE "scores_agg"."user_id" = "users"."id"), 0) BETWEEN 1 AND 100 AND "category" = \'A\'',
            $sql
        );
    }

    public function test_multiple_mixed_pending_conditions_preserve_exact_call_order()
    {
        // Controller test 38j: where_exists_relation() and where_has() (count!=1)
        // interleaved with plain where()s must all compile in call order, not
        // grouped by condition type.
        $sql = $this->sql($this->db->where('id', 1)
            ->or_where_exists_relation('scores', 'user_id', 'id')
            ->where('category', 'A')
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND "category" = \'A\' OR (SELECT COUNT(*) FROM "scores" WHERE "scores"."user_id" = "users"."id") >= 2 AND "name" != \'Bob\'',
            $sql
        );
    }

    public function test_two_independent_groups_with_where_has_sandwiched_between_them()
    {
        // Controller test 40d: two separate group() calls with a where_has()
        // registered between them must stay as three independent top-level
        // conditions in call order, not merged into one group.
        $sql = $this->sql($this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A');
            })
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->or_group(function ($q) {
                $q->where('name', 'Bob');
            })
            ->get_compiled_select('users'));

        // or_where_has() with the default count of 1 (>=1) delegates to a
        // plain EXISTS subquery, same optimization where_has() gets.
        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( "category" = \'A\' ) OR EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") OR ( "name" = \'Bob\' )',
            $sql
        );
    }

    public function test_search_combined_with_or_where_has_preserves_call_order_both_directions()
    {
        // Controller test 41g: search() (which internally uses group()) and
        // or_where_has() must compile in call order regardless of which one
        // is registered first.
        $sql_a = $this->sql($this->db->where('id', 1)
            ->search('john', ['name', 'email'])
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( "name" LIKE \'%john%\' ESCAPE \'!\' OR "email" LIKE \'%john%\' ESCAPE \'!\' ) OR (SELECT COUNT(*) FROM "scores" WHERE "scores"."user_id" = "users"."id") >= 2 AND "name" != \'Bob\'',
            $sql_a
        );

        $this->db->reset_query();

        $sql_b = $this->sql($this->db->where('id', 1)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->search('john', ['name', 'email'])
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR (SELECT COUNT(*) FROM "scores" WHERE "scores"."user_id" = "users"."id") >= 2 AND ( "name" LIKE \'%john%\' ESCAPE \'!\' OR "email" LIKE \'%john%\' ESCAPE \'!\' ) AND "name" != \'Bob\'',
            $sql_b
        );
    }

    public function test_join_count_and_join_sum_produce_derived_table_left_joins()
    {
        // Controller test 42c: join_count()/join_sum() must add derived-table
        // LEFT JOINs (not correlated subqueries in SELECT), leaving WHERE
        // untouched.
        $sql = $this->sql($this->db->where('id', 1)
            ->join_count('scores', 'user_id', 'id')
            ->join_sum('scores', 'user_id', 'id', 'value')
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT "users".*, "_jagg_scores_count"."scores_count", "_jagg_scores_sum"."scores_sum" FROM "users" LEFT JOIN (SELECT "scores"."user_id", COUNT(*) AS "scores_count" FROM "scores" GROUP BY "scores"."user_id") "_jagg_scores_count" ON "_jagg_scores_count"."user_id" = "users"."id" LEFT JOIN (SELECT "scores"."user_id", SUM("scores"."value") AS "scores_sum" FROM "scores" GROUP BY "scores"."user_id") "_jagg_scores_sum" ON "_jagg_scores_sum"."user_id" = "users"."id" WHERE "id" = 1 AND "name" != \'Bob\'',
            $sql
        );
    }

    public function test_raw_group_start_end_keeps_or_where_exists_relation_inside_the_brackets()
    {
        // Controller test 45a: raw group_start()/group_end() (not the
        // callback-based group()) must also keep a nested
        // or_where_exists_relation() inside the manual brackets.
        $sql = $this->sql($this->db->where('id', 1)
            ->group_start()
                ->where('category', 'A')
                ->or_where_exists_relation('scores', 'user_id', 'id')
            ->group_end()
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( "category" = \'A\' OR EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") ) AND "name" != \'Bob\'',
            $sql
        );
    }

    public function test_raw_group_start_end_with_nothing_added_vanishes_cleanly()
    {
        // Controller test 45b: an empty group_start()/group_end() pair must
        // not emit invalid "( )" SQL — it should disappear entirely, same
        // empty-group protection group()/or_group() already had.
        $sql = $this->sql($this->db->where('id', 1)
            ->group_start()
            ->group_end()
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND "name" != \'Bob\'',
            $sql
        );
        $this->assertStringNotContainsString('( )', $sql);
    }

    public function test_custom_expression_with_pre_quoted_bare_column_is_not_mangled()
    {
        // Controller test 54b: a backtick-quoted bare column in a custom
        // AVG() expression must not get double-backtick-mangled by
        // _prefix_bare_identifiers().
        $sql = $this->sql($this->db->with_avg(['scores' => 'avgval'], 'user_id', 'id', '"value"', true)
            ->where('id', 1)
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT *, (SELECT AVG("value") FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "avgval" FROM "users" WHERE "id" = 1',
            $sql
        );
    }

    public function test_round_function_is_not_mis_qualified_in_custom_expression()
    {
        // Controller test 65: ROUND() is in $ALLOWED_SQL_FUNCTIONS but
        // _prefix_bare_identifiers() used to only skip a small hardcoded
        // keyword list, wrongly qualifying it into `sub`.`ROUND`(...).
        $sql = $this->sql($this->db->select(['id', 'name'])
            ->with_sum(['scores' => 'rounded_total'], 'user_id', 'id', 'ROUND(value, 2)', true)
            ->where('id', 1)
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT "id", "name", (SELECT SUM(ROUND("scores"."value", 2)) FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "rounded_total" FROM "users" WHERE "id" = 1',
            $sql
        );
    }

    public function test_custom_expression_rejects_having_keyword()
    {
        // Controller test 66.
        $this->expectException(InvalidArgumentException::class);
        $this->db->select(['id', 'name'])
            ->with_avg(['scores' => 'x'], 'user_id', 'id', '1 HAVING 1=1', true)
            ->where('id', 1)
            ->get_compiled_select('users');
    }

    public function test_join_count_rejects_empty_relation_name()
    {
        // Controller test 67: empty/whitespace-only relation must throw
        // cleanly instead of a PHP notice further down the call chain.
        $this->expectException(InvalidArgumentException::class);
        $this->db->select(['id', 'name'])->join_count('', 'user_id', 'id')->get_compiled_select('users');
    }

    public function test_quoted_disallowed_function_name_is_still_rejected()
    {
        // Controller test 49: wrapping a disallowed function name in
        // backticks must not bypass is_valid_custom_expression()'s allow-list.
        $this->expectException(InvalidArgumentException::class);
        $this->db->select(['id', 'name'])
            ->with_avg(['scores' => 'x'], 'user_id', 'id', '"SLEEP"(0)', true)
            ->where('id', 1)
            ->get_compiled_select('users');
    }

    public function test_or_where_in_where_not_in_or_where_not_in_produce_correct_sql()
    {
        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "id" IN (2,3)',
            $this->sql($this->db->where('id', 1)->or_where_in('id', [2, 3])->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" NOT IN (1,2)',
            $this->sql($this->db->where_not_in('id', [1, 2])->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "id" NOT IN (2,3)',
            $this->sql($this->db->where('id', 1)->or_where_not_in('id', [2, 3])->get_compiled_select('users'))
        );
    }

    public function test_where_null_family_and_or_variants_produce_correct_sql()
    {
        $this->assertSame(
            'SELECT * FROM "users" WHERE "email" IS NULL',
            $this->sql($this->db->where_null('email')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "email" IS NOT NULL',
            $this->sql($this->db->where_not_null('email')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "email" IS NULL',
            $this->sql($this->db->where('id', 1)->or_where_null('email')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "email" IS NOT NULL',
            $this->sql($this->db->where('id', 1)->or_where_not_null('email')->get_compiled_select('users'))
        );
    }

    public function test_where_not_and_or_where_not_produce_correct_sql()
    {
        $this->assertSame(
            'SELECT * FROM "users" WHERE "category" != \'A\'',
            $this->sql($this->db->where_not('category', 'A')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "category" != \'A\'',
            $this->sql($this->db->where('id', 1)->or_where_not('category', 'A')->get_compiled_select('users'))
        );
    }

    public function test_or_where_between_and_or_where_not_between_produce_correct_sql()
    {
        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "id" BETWEEN 2 AND 3',
            $this->sql($this->db->where('id', 1)->or_where_between('id', [2, 3])->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR "id" NOT BETWEEN 2 AND 3',
            $this->sql($this->db->where('id', 1)->or_where_not_between('id', [2, 3])->get_compiled_select('users'))
        );
    }

    public function test_where_doesnt_have_and_variants_delegate_to_not_exists_relation()
    {
        // where_doesnt_have()/or_where_doesnt_have() are documented aliases of
        // where_not_exists_relation()/or_where_not_exists_relation() — pin that
        // the alias actually produces the identical NOT EXISTS subquery shape.
        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE NOT EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $this->sql($this->db->select(['id', 'name'])->where_doesnt_have('scores', 'user_id', 'id')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR NOT EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $this->sql($this->db->where('id', 1)->or_where_doesnt_have('scores', 'user_id', 'id')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 OR NOT EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $this->sql($this->db->where('id', 1)->or_where_not_exists_relation('scores', 'user_id', 'id')->get_compiled_select('users'))
        );
    }

    public function test_where_not_exists_or_where_exists_or_where_not_exists_callback_variants()
    {
        // Top-level callback-based EXISTS variants (see where_exists() above,
        // which already has dedicated regression coverage) — pin the
        // NOT EXISTS / OR EXISTS / OR NOT EXISTS siblings.
        $build = function ($q) {
            $q->select('1')->from('scores')->where('scores.user_id = users.id');
        };

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE NOT EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $this->sql($this->db->select(['id', 'name'])->from('users')->where_not_exists($build)->get_compiled_select())
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE "id" = 1 OR EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $this->sql($this->db->select(['id', 'name'])->from('users')->where('id', 1)->or_where_exists($build)->get_compiled_select())
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE "id" = 1 OR NOT EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id")',
            $this->sql($this->db->select(['id', 'name'])->from('users')->where('id', 1)->or_where_not_exists($build)->get_compiled_select())
        );
    }

    public function test_with_count_with_min_with_max_produce_correct_select_subqueries()
    {
        $this->assertSame(
            'SELECT *, (SELECT COUNT(*) FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "scores_count" FROM "users" WHERE "id" = 1',
            $this->sql($this->db->with_count('scores', 'user_id', 'id')->where('id', 1)->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT *, (SELECT MIN("scores"."value") FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "lowest" FROM "users" WHERE "id" = 1',
            $this->sql($this->db->with_min(['scores' => 'lowest'], 'user_id', 'id', 'value')->where('id', 1)->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT *, (SELECT MAX("scores"."value") FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "highest" FROM "users" WHERE "id" = 1',
            $this->sql($this->db->with_max(['scores' => 'highest'], 'user_id', 'id', 'value')->where('id', 1)->get_compiled_select('users'))
        );
    }

    public function test_join_avg_join_min_join_max_produce_derived_table_left_joins()
    {
        // Same derived-table JOIN shape as the existing join_count/join_sum test above.
        $this->assertSame(
            'SELECT "users".*, "_jagg_scores_avg"."scores_avg" FROM "users" LEFT JOIN (SELECT "scores"."user_id", AVG("scores"."value") AS "scores_avg" FROM "scores" GROUP BY "scores"."user_id") "_jagg_scores_avg" ON "_jagg_scores_avg"."user_id" = "users"."id" WHERE "id" = 1 AND "name" != \'Bob\'',
            $this->sql($this->db->where('id', 1)->join_avg('scores', 'user_id', 'id', 'value')->where('name !=', 'Bob')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT "users".*, "_jagg_scores_min"."scores_min" FROM "users" LEFT JOIN (SELECT "scores"."user_id", MIN("scores"."value") AS "scores_min" FROM "scores" GROUP BY "scores"."user_id") "_jagg_scores_min" ON "_jagg_scores_min"."user_id" = "users"."id" WHERE "id" = 1 AND "name" != \'Bob\'',
            $this->sql($this->db->where('id', 1)->join_min('scores', 'user_id', 'id', 'value')->where('name !=', 'Bob')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT "users".*, "_jagg_scores_max"."scores_max" FROM "users" LEFT JOIN (SELECT "scores"."user_id", MAX("scores"."value") AS "scores_max" FROM "scores" GROUP BY "scores"."user_id") "_jagg_scores_max" ON "_jagg_scores_max"."user_id" = "users"."id" WHERE "id" = 1 AND "name" != \'Bob\'',
            $this->sql($this->db->where('id', 1)->join_max('scores', 'user_id', 'id', 'value')->where('name !=', 'Bob')->get_compiled_select('users'))
        );
    }

    public function test_add_select_join_limit_latest_oldest_parent_overrides()
    {
        $this->assertSame(
            'SELECT "id", "name" FROM "users"',
            $this->sql($this->db->select('id')->add_select('name')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT "users"."id" FROM "users" JOIN "scores" ON "scores"."user_id" = "users"."id"',
            $this->sql($this->db->select(['users.id'])->join('scores', 'scores.user_id = users.id')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" LIMIT 2',
            $this->sql($this->db->limit(2)->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" LIMIT 1, 2',
            $this->sql($this->db->limit(2, 1)->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" ORDER BY "id" DESC',
            $this->sql($this->db->latest('id')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" ORDER BY "id" ASC',
            $this->sql($this->db->oldest('id')->get_compiled_select('users'))
        );
    }

    public function test_process_where_has_manually_flushes_pending_where_has_before_further_chaining()
    {
        // process_where_has() is documented as safe to call manually inside
        // callback contexts to force-resolve pending where_has() conditions
        // early. Chain a leading where() first (see where_has()'s own
        // regression tests above for why a bare first deferred condition is
        // a separate, already call-order-sensitive case) and confirm a
        // where() added AFTER the manual flush still glues in correctly.
        $sql = $this->sql($this->db->from('users')
            ->where('id', 1)
            ->where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->process_where_has()
            ->where('category', 'A')
            ->get_compiled_select());

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND (SELECT COUNT(*) FROM "scores" WHERE "scores"."user_id" = "users"."id") >= 2 AND "category" = \'A\'',
            $sql
        );
    }

    public function test_process_aggregates_manually_flushes_pending_select_aggregates()
    {
        $sql = $this->sql($this->db->from('users')
            ->with_count('scores', 'user_id', 'id')
            ->process_aggregates()
            ->where('id', 1)
            ->get_compiled_select());

        $this->assertSame(
            'SELECT *, (SELECT COUNT(*) FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "scores_count" FROM "users" WHERE "id" = 1',
            $sql
        );
    }

    public function test_process_join_aggregates_manually_flushes_pending_join_aggregates()
    {
        $sql = $this->sql($this->db->from('users')
            ->join_count('scores', 'user_id', 'id')
            ->process_join_aggregates()
            ->where('id', 1)
            ->get_compiled_select());

        $this->assertSame(
            'SELECT "users".*, "_jagg_scores_count"."scores_count" FROM "users" LEFT JOIN (SELECT "scores"."user_id", COUNT(*) AS "scores_count" FROM "scores" GROUP BY "scores"."user_id") "_jagg_scores_count" ON "_jagg_scores_count"."user_id" = "users"."id" WHERE "id" = 1',
            $sql
        );
    }

    public function test_process_where_aggregates_manually_flushes_pending_where_aggregates()
    {
        $sql = $this->sql($this->db->from('users')
            ->where('id', 1)
            ->with_sum(['scores' => 'total'], 'user_id', 'id', 'value')
            ->where_aggregate('total >', 50)
            ->process_where_aggregates()
            ->where('category', 'A')
            ->get_compiled_select());

        $this->assertSame(
            'SELECT *, (SELECT SUM("scores"."value") FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "total" FROM "users" WHERE "id" = 1 AND COALESCE((SELECT SUM("scores_agg"."value") FROM "scores" "scores_agg" WHERE "scores_agg"."user_id" = "users"."id"), 0) > 50 AND "category" = \'A\'',
            $sql
        );
    }

    public function test_process_where_exists_manually_flushes_pending_where_exists_relation()
    {
        $sql = $this->sql($this->db->from('users')
            ->where('id', 1)
            ->where_exists_relation('scores', 'user_id', 'id')
            ->process_where_exists('users')
            ->where('category', 'A')
            ->get_compiled_select());

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND "category" = \'A\'',
            $sql
        );
    }

    public function test_deferred_condition_registered_first_still_glues_to_a_later_plain_where()
    {
        // BUG FIX: where_has()/where_aggregate()/where_exists_relation() don't
        // occupy a slot in qb_where until they're flushed (at get() time, or
        // by a manual process_*() call) — a plain where()/or_where() chained
        // right after one, but before that flush happens, used to see an
        // artificially empty qb_where and wrongly omit its AND/OR connector,
        // producing invalid SQL like "EXISTS (...) `category` = 'A'" once the
        // deferred condition was spliced back in front of it.
        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND "category" = \'A\'',
            $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)->where('category', 'A')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND "category" = \'A\'',
            $this->sql($this->db->where_exists_relation('scores', 'user_id', 'id')->where('category', 'A')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT *, (SELECT SUM("scores"."value") FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "total" FROM "users" WHERE COALESCE((SELECT SUM("scores_agg"."value") FROM "scores" "scores_agg" WHERE "scores_agg"."user_id" = "users"."id"), 0) > 5 AND "category" = \'A\'',
            $this->sql($this->db->with_sum(['scores' => 'total'], 'user_id', 'id', 'value')->where_aggregate('total >', 5)->where('category', 'A')->get_compiled_select('users'))
        );

        $this->db->reset_query();

        // where_in() family goes through _safe_in_clause(), a separate glue
        // computation from where()/or_where() — needs the same fix.
        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND "id" IN (1,2,3)',
            $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)->where_in('id', [1, 2, 3])->get_compiled_select('users'))
        );
    }

    public function test_two_consecutive_deferred_conditions_with_nothing_else_still_glue_together()
    {
        // BUG FIX: the same trap as above, but between two deferred
        // conditions rather than a deferred condition and a plain where() —
        // each one alone looks like "the first condition" (qb_where is
        // empty both times, since the first one is popped back out for
        // reorder-buffering before the second is processed), so both used
        // to omit their connector, producing "EXISTS (...) EXISTS (...)"
        // with no AND between them.
        $sql = $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->where_has('category_scores', 'user_id', 'id', null, '>=', 1)
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND EXISTS (SELECT 1 FROM "category_scores" WHERE "category_scores"."user_id" = "users"."id")',
            $sql
        );
    }

    public function test_deferred_condition_registered_first_still_glues_to_a_following_group()
    {
        // BUG FIX: group_start() (and, via CI's own delegation, or_group_start()/
        // not_group_start()/or_not_group_start(), and group()/or_group()'s
        // deferred path through _execute_group_immediately()) decides whether to
        // omit the connector before its opening "(" the same way where() does —
        // by checking if qb_where is currently empty. A deferred condition
        // registered earlier doesn't occupy a qb_where slot yet, so a group
        // opened right after one used to see an artificially empty qb_where and
        // wrongly omit the connector, producing invalid SQL like
        // "EXISTS (...) ( `name` = 'Alice' OR ... )" with no AND in between.
        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND ( "name" = \'Alice\' OR "name" = \'Bob\' )',
            $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)
                ->group(function ($q) {
                    $q->where('name', 'Alice')->or_where('name', 'Bob');
                })
                ->get_compiled_select('users'))
        );

        $this->db->reset_query();

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") OR ( "name" = \'Alice\' OR "name" = \'Bob\' )',
            $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)
                ->or_group(function ($q) {
                    $q->where('name', 'Alice')->or_where('name', 'Bob');
                })
                ->get_compiled_select('users'))
        );

        $this->db->reset_query();

        // Same fix, exercised via the raw group_start()/group_end() pair
        // instead of the callback-based group().
        $sql = $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->group_start()
                ->where('name', 'Alice')
                ->or_where('name', 'Bob')
            ->group_end()
            ->where('category', 'A')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND ( "name" = \'Alice\' OR "name" = \'Bob\' ) AND "category" = \'A\'',
            $sql
        );
    }

    public function test_vanished_nested_group_restores_outer_group_started_flag()
    {
        // BUG FIX: group_end()'s empty-group-vanish path (see group_start()/
        // group_end() above) used to unconditionally clear qb_where_group_started
        // to FALSE whenever the bracket it just closed turned out empty. But if
        // that now-vanished bracket was itself the very first thing inside an
        // OUTER still-open bracket, the flag being TRUE was what remembered
        // "the outer bracket hasn't gotten its first real condition yet". Clearing
        // it unconditionally made the outer bracket think its first-condition slot
        // had already been used up by the vanished inner bracket, so the next real
        // condition wrongly got an AND connector immediately after the outer "(" —
        // e.g. "( AND ( `name` = 'Hihih' ) )" instead of "( ( `name` = 'Hihih' ) )".
        // The fix restores whatever the flag was before the vanishing bracket
        // opened, instead of always clearing it.
        $sql = $this->sql($this->db->where('id', 1)
            ->group(function ($q) {
                // Contributes nothing (condition false, no default).
                $q->when(false, fn ($q) => $q->where('name', 'Heheh'));
                // A nested group whose own content also contributes nothing —
                // this whole bracket vanishes cleanly.
                $q->group(fn ($q) => $q->when(false, fn ($q) => $q->where('name', 'Hahaha')));
                // The first REAL content inside the outer group — must NOT get
                // a stray connector right after the outer "(".
                $q->group_start();
                $q->where('name', 'Hihih');
                $q->group_end();
            })
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( ( "name" = \'Hihih\' ) )',
            $sql
        );

        $this->db->reset_query();

        // Full combination: a deferred condition first, an outer manual group,
        // and the vanishing-nested-group case inside a further callback-based
        // group — every fix in this file cooperating at once.
        $sql = $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->group_start()
                ->where('name', 'Alice')
                ->or_where('name', 'Bob')
                ->group(function ($q) {
                    $q->when(false, fn ($q) => $q->where('name', 'Heheh'));
                    $q->group(fn ($q) => $q->when(false, fn ($q) => $q->where('name', 'Hahaha')));
                    $q->group_start();
                    $q->where('name', 'Hihih');
                    $q->group_end();
                })
            ->group_end()
            ->where('category', 'A')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND ( "name" = \'Alice\' OR "name" = \'Bob\' AND ( ( "name" = \'Hihih\' ) ) ) AND "category" = \'A\'',
            $sql
        );
    }

    public function test_deferred_condition_as_first_content_inside_a_group_still_glues_correctly()
    {
        // BUG FIX: a deferred condition (where_has(), where_aggregate(),
        // where_exists_relation(), etc.) registered as the FIRST thing INSIDE
        // a group()/group_start() callback — rather than before the group
        // itself — doesn't occupy a qb_where slot yet and doesn't touch
        // qb_where_group_started, so a plain where() added right after it in
        // the same callback used to wrongly grab the "first inside this
        // bracket" slot (no connector) that rightfully belongs to the
        // deferred condition once it's flushed — producing invalid SQL like
        // "( AND EXISTS (...) `category` = 'A' )": a stray connector right
        // after the opening "(", and a missing one before `category` = 'A'.
        $sql = $this->sql($this->db->group(function ($q) {
            $q->where_has('scores', 'user_id', 'id', null, '>=', 1);
            $q->where('category', 'A');
        })->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE ( EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND "category" = \'A\' )',
            $sql
        );

        $this->db->reset_query();

        // Two deferred conditions in a row, both as the first content inside
        // the group, with nothing real in between — same trap the two of
        // them spring on each other at the top level (see
        // test_two_consecutive_deferred_conditions_with_nothing_else_still_glue_together
        // above), reproduced one bracket-scope deeper.
        $sql = $this->sql($this->db->where('id', 1)->group(function ($q) {
            $q->where_has('scores', 'user_id', 'id', null, '>=', 1);
            $q->where_has('category_scores', 'user_id', 'id', null, '>=', 1);
        })->get_compiled_select('users'));

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" = 1 AND ( EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND EXISTS (SELECT 1 FROM "category_scores" WHERE "category_scores"."user_id" = "users"."id") )',
            $sql
        );
    }

    public function test_deep_mix_of_deferred_conditions_nested_groups_and_when_all_glue_correctly()
    {
        // Kitchen-sink combination exercising every fix in this file at once:
        // a top-level deferred condition before a manual group_start(), real
        // where()/or_where() inside it, a nested callback-based group()
        // containing (in order) a no-op when(false), a fully-vanishing empty
        // nested group, a manual group_start()/where()/group_end() block,
        // then FOUR more deferred conditions of different kinds
        // (where_has(), with_sum()+where_aggregate(), where_exists_relation(),
        // or_where_has()) registered as the first-ever real content added
        // after that manual block — each of which must correctly glue to
        // whatever precedes it once flushed.
        $sql = $this->sql($this->db->where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->group_start()
                ->where('name', 'Alice')
                ->or_where('name', 'Bob')
                ->group(function ($q) {
                    $q->when(false, fn ($q) => $q->where('name', 'Heheh'));
                    $q->group(fn ($q) => $q->when(false, fn ($q) => $q->where('name', 'Hahaha')));
                    $q->group_start();
                    $q->where('name', 'Hihih');
                    $q->group_end();
                    $q->where_has('category_scores', 'user_id', 'id', null, '>=', 1);
                    $q->with_sum(['scores' => 'total'], 'user_id', 'id', 'value');
                    $q->where_aggregate('total >', 5);
                    $q->where_exists_relation('profiles', 'user_id', 'id');
                    $q->or_where_has('scores', 'user_id', 'id', null, '>=', 2);
                })
            ->group_end()
            ->where('category', 'A')
            ->get_compiled_select('users'));

        $this->assertSame(
            'SELECT *, (SELECT SUM("scores"."value") FROM "scores" "scores" WHERE "scores"."user_id" = "users"."id") AS "total" FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND ( "name" = \'Alice\' OR "name" = \'Bob\' AND ( ( "name" = \'Hihih\' ) AND EXISTS (SELECT 1 FROM "category_scores" WHERE "category_scores"."user_id" = "users"."id") AND COALESCE((SELECT SUM("scores_agg"."value") FROM "scores" "scores_agg" WHERE "scores_agg"."user_id" = "users"."id"), 0) > 5 AND EXISTS (SELECT 1 FROM "profiles" WHERE "profiles"."user_id" = "users"."id") OR (SELECT COUNT(*) FROM "scores" WHERE "scores"."user_id" = "users"."id") >= 2 ) ) AND "category" = \'A\'',
            $sql
        );
    }
}
