<?php

/**
 * Pins the exact compiled SQL string for the cross-boundary bugs documented
 * in CustomQueryBuilder.php's "BUG FIX" comments — the class of regression
 * the manual smoke-test controller (test-ci3/.../Test_custom_qb.php) can
 * only catch by eyeballing output, not by failing a build.
 *
 * Every assertion string here was captured from a known-good run and should
 * only ever change as a deliberate, reviewed behavior change.
 */
class CompiledSqlTest extends CqbTestCase
{
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
            "SELECT * FROM `users` WHERE `id` = 999 OR (SELECT COUNT(*) FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) >= 2 AND `category` = 'A'",
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
            "SELECT * FROM `users` WHERE `id` = 1 AND ( `category` = 'A' OR EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) ) AND `email` != 'x@example.com'",
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
            'SELECT `id`, `name` FROM `users` WHERE EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`)',
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
            "SELECT *, (SELECT SUM(scores.value) FROM `scores` `scores` WHERE `scores`.`user_id` = `users`.`id`) AS `total` FROM `users` WHERE `id` = 1",
            $sql
        );
    }

    public function test_order_by_relation_qualifies_against_the_aliased_main_table_and_is_backtick_quoted()
    {
        // Regression: order_by_relation() used to emit the invalid, unaliased
        // "users u.id" instead of "`u`.`id`" when the main table had an alias.
        // Also pins the backtick-quoting defense-in-depth added on top of
        // is_valid_table_name()/is_valid_column_name() validation.
        $sql = $this->sql($this->db->order_by_relation('scores', 'user_id', 'id', 'value', 'DESC')
            ->get_compiled_select('users u'));

        $this->assertSame(
            'SELECT * FROM `users` `u` ORDER BY (SELECT `value` FROM `scores` WHERE `user_id` = `u`.`id` LIMIT 1) DESC',
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
            "SELECT * FROM `users` WHERE `id` = 1 OR EXISTS (SELECT 1 FROM `category_scores` WHERE `category_scores`.`user_id` = `users`.`id` AND `category_scores`.`category` = `users`.`category`) AND `name` != 'Bob'",
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
            'SELECT `id`, `name` FROM `users` WHERE EXISTS (SELECT 1 FROM `scores` `ms` WHERE `ms`.`user_id` = `users`.`id`)',
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
            "SELECT * FROM `users` WHERE `id` = 1 AND ( `category` = 'A' OR EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) ) AND `name` != 'Bob'",
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
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`)',
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
            "SELECT *, (SELECT SUM(value) FROM `scores` `scores` WHERE `scores`.`user_id` = `users`.`id`) AS `score_total` FROM `users` WHERE `id` = 1 OR COALESCE((SELECT SUM(value) FROM `scores` `scores_agg` WHERE `scores_agg`.`user_id` = `users`.`id`), 0) BETWEEN 1 AND 100 AND `category` = 'A'",
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
            "SELECT * FROM `users` WHERE `id` = 1 OR EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) AND `category` = 'A' OR (SELECT COUNT(*) FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) >= 2 AND `name` != 'Bob'",
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
            "SELECT * FROM `users` WHERE `id` = 1 AND ( `category` = 'A' ) OR EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) OR ( `name` = 'Bob' )",
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
            "SELECT * FROM `users` WHERE `id` = 1 AND ( `name` LIKE '%john%' ESCAPE '!' OR `email` LIKE '%john%' ESCAPE '!' ) OR (SELECT COUNT(*) FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) >= 2 AND `name` != 'Bob'",
            $sql_a
        );

        $this->db->reset_query();

        $sql_b = $this->sql($this->db->where('id', 1)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->search('john', ['name', 'email'])
            ->where('name !=', 'Bob')
            ->get_compiled_select('users'));

        $this->assertSame(
            "SELECT * FROM `users` WHERE `id` = 1 OR (SELECT COUNT(*) FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) >= 2 AND ( `name` LIKE '%john%' ESCAPE '!' OR `email` LIKE '%john%' ESCAPE '!' ) AND `name` != 'Bob'",
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
            "SELECT `users`.*, `_jagg_scores_count`.`scores_count`, `_jagg_scores_sum`.`scores_sum` FROM `users` LEFT JOIN (SELECT `scores`.`user_id`, COUNT(*) AS `scores_count` FROM `scores` GROUP BY `scores`.`user_id`) `_jagg_scores_count` ON `_jagg_scores_count`.`user_id` = `users`.`id` LEFT JOIN (SELECT `scores`.`user_id`, SUM(`scores`.`value`) AS `scores_sum` FROM `scores` GROUP BY `scores`.`user_id`) `_jagg_scores_sum` ON `_jagg_scores_sum`.`user_id` = `users`.`id` WHERE `id` = 1 AND `name` != 'Bob'",
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
            "SELECT * FROM `users` WHERE `id` = 1 AND ( `category` = 'A' OR EXISTS (SELECT 1 FROM `scores` WHERE `scores`.`user_id` = `users`.`id`) ) AND `name` != 'Bob'",
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
            "SELECT * FROM `users` WHERE `id` = 1 AND `name` != 'Bob'",
            $sql
        );
        $this->assertStringNotContainsString('( )', $sql);
    }

    public function test_custom_expression_with_backtick_quoted_bare_column_is_not_mangled()
    {
        // Controller test 54b: a backtick-quoted bare column in a custom
        // AVG() expression must not get double-backtick-mangled by
        // _prefix_bare_identifiers().
        $sql = $this->sql($this->db->with_avg(['scores' => 'avgval'], 'user_id', 'id', '`value`', true)
            ->where('id', 1)
            ->get_compiled_select('users'));

        $this->assertSame(
            "SELECT *, (SELECT AVG(`value`) FROM `scores` `scores` WHERE `scores`.`user_id` = `users`.`id`) AS `avgval` FROM `users` WHERE `id` = 1",
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
            "SELECT `id`, `name`, (SELECT SUM(ROUND(`scores`.`value`, 2)) FROM `scores` `scores` WHERE `scores`.`user_id` = `users`.`id`) AS `rounded_total` FROM `users` WHERE `id` = 1",
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

    public function test_backtick_quoted_disallowed_function_name_is_still_rejected()
    {
        // Controller test 49: wrapping a disallowed function name in
        // backticks must not bypass is_valid_custom_expression()'s allow-list.
        $this->expectException(InvalidArgumentException::class);
        $this->db->select(['id', 'name'])
            ->with_avg(['scores' => 'x'], 'user_id', 'id', '`SLEEP`(0)', true)
            ->where('id', 1)
            ->get_compiled_select('users');
    }
}
