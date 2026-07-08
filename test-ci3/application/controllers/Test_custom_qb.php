<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Test_custom_qb extends CI_Controller
{
    public function index()
    {
        header('Content-Type: text/plain');

        echo "=== 1. Verify CustomQueryBuilder is extended ===\n";
        echo get_class($this->db) . "\n";
        echo ($this->db instanceof CustomQueryBuilder) ? "OK: instanceof CustomQueryBuilder\n" : "FAIL\n";

        echo "\n=== 2. Plain get() now returns CustomQueryBuilderResult ===\n";
        $result = $this->db->get('users');
        echo get_class($result) . "\n";
        echo "num_rows(): " . $result->num_rows() . "\n";

        echo "\n=== 3. key_by('id') on result() ===\n";
        $keyed = $result->key_by('id');
        foreach ($keyed as $id => $row) {
            echo "id=$id -> name={$row->name}, email={$row->email}\n";
        }

        echo "\n=== 4. key_by() directly on get() return (no ->result() needed) ===\n";
        $keyed2 = $this->db->get('users')->key_by('email');
        foreach ($keyed2 as $email => $row) {
            echo "$email -> {$row->name}\n";
        }

        echo "\n=== 5. key_by() with array output ===\n";
        $keyedArr = $this->db->get('users')->key_by('id', true);
        var_export($keyedArr[1]);
        echo "\n";

        echo "\n=== 6. key_by() with callback ===\n";
        $keyedCb = $this->db->get('users')->key_by(function ($row) {
            return strtoupper($row->name);
        });
        echo implode(',', array_keys($keyedCb)) . "\n";

        echo "\n=== 7. get_where() also wrapped ===\n";
        $resultWhere = $this->db->get_where('users', array('id' => 2));
        echo get_class($resultWhere) . "\n";
        var_export($resultWhere->row());
        echo "\n";

        echo "\n=== 8. Proxy of native method (num_fields via __call) ===\n";
        $ref = new ReflectionProperty($result, '_original_result');
        $ref->setAccessible(true);
        $orig = $ref->getValue($result);
        echo "original_result class: " . (is_object($orig) ? get_class($orig) : var_export($orig, true)) . "\n";
        echo "num_fields() via proxy: " . $result->num_fields() . "\n";

        echo "\n=== 9. query() raw SQL wrapped ===\n";
        $rawResult = $this->db->query("SELECT * FROM users WHERE id > 1");
        echo get_class($rawResult) . "\n";
        $keyedRaw = $rawResult->key_by('id');
        echo implode(',', array_keys($keyedRaw)) . "\n";

        echo "\n=== 10. query() write statement still returns bool ===\n";
        $ok = $this->db->query("UPDATE users SET name = name WHERE id = 1");
        var_export($ok);
        echo "\n";

        echo "\n=== 11. with_one() + order_by DESC regression check ===\n";
        // Simulate a related table to confirm the with_one() DESC/ASC fix still holds
        $this->db->query("CREATE TABLE IF NOT EXISTS scores (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, value INT)");
        $this->db->query("DELETE FROM scores");
        $this->db->query("INSERT INTO scores (user_id, value) VALUES (1, 10), (1, 50), (1, 30)");

        $usersWithTopScore = $this->db->select(['id', 'name'])
            ->with_one('scores', 'user_id', 'id', function ($q) {
                $q->order_by('value', 'DESC');
            })
            ->where('id', 1)
            ->get('users');

        foreach ($usersWithTopScore->result() as $u) {
            echo "user={$u->name} top_score=" . (isset($u->scores->value) ? $u->scores->value : 'NULL') . " (expect 50)\n";
        }

        echo "\n=== 12. value() ===\n";
        $email = $this->db->where('id', 2)->value('email', 'users');
        var_export($email);
        echo "\n";

        $missing = $this->db->where('id', 999)->value('email', 'users');
        var_export($missing);
        echo "\n";

        try {
            $this->db->value('1; DROP TABLE users', 'users');
            echo "SHOULD HAVE THROWN\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected invalid column: " . $e->getMessage() . "\n";
        }

        echo "\n=== 13. value() on already-fetched CustomQueryBuilderResult ===\n";
        $email2 = $this->db->where('id', 2)->get('users')->value('email');
        var_export($email2);
        echo "\n";

        $missing2 = $this->db->where('id', 999)->get('users')->value('email');
        var_export($missing2);
        echo "\n";

        echo "\n=== 14a. NestedQueryBuilder::with() via with_one()/with_many() proxy (goes to CustomQueryBuilder::with()) ===\n";
        try {
            $this->db->with_many('scores', 'user_id', 'id', function ($q) {
                $q->with_one('users; DROP TABLE users', 'id', 'user_id');
            })->get('users');
            echo "SHOULD HAVE THROWN\n";
        } catch (InvalidArgumentException $e) {
            echo "rejected via: " . $e->getMessage() . "\n";
            $this->db->reset_query(); // clear dirty state left by the aborted query above
        }

        echo "\n=== 14b. NestedQueryBuilder::with() called DIRECTLY (low-level API) ===\n";
        try {
            $this->db->with_many('scores', 'user_id', 'id', function ($q) {
                $q->with('users; DROP TABLE users', 'id', 'user_id', false);
            })->get('users');
            echo "SHOULD HAVE THROWN\n";
        } catch (InvalidArgumentException $e) {
            echo "rejected via: " . $e->getMessage() . "\n";
            $this->db->reset_query();
        }

        // Confirm legit nested with_one() still works
        $usersWithScores = $this->db->select(['id', 'name'])
            ->with_many('scores', 'user_id', 'id')
            ->where('id', 1)
            ->get('users');
        foreach ($usersWithScores->result() as $u) {
            echo "user={$u->name} scores_count=" . count($u->scores) . " (expect 3)\n";
        }

        echo "\n=== 15. RelationAggregateTrait: with_count/with_sum (subquery aggregate) ===\n";
        $usersAgg = $this->db->select(['id', 'name'])
            ->with_count('scores', 'user_id', 'id')
            ->with_sum(['scores' => 'scores_total'], 'user_id', 'id', 'value')
            ->where('id', 1)
            ->get('users');
        foreach ($usersAgg->result() as $u) {
            echo "user={$u->name} scores_count={$u->scores_count} scores_total={$u->scores_total} (expect 3, 90)\n";
        }

        echo "\n=== 16. RelationAggregateTrait: join_sum (derived-table JOIN aggregate) ===\n";
        $usersJoin = $this->db->select(['id', 'name'])
            ->join_sum('scores', 'user_id', 'id', 'value')
            ->where('id', 1)
            ->get('users');
        foreach ($usersJoin->result() as $u) {
            echo "user={$u->name} scores_sum={$u->scores_sum} (expect 90)\n";
        }

        echo "\n=== 17. Nested where_aggregate() with BETWEEN + dotted key (previously unsupported/stripped in NestedQueryBuilder) ===\n";
        $snapshot1 = null;
        $this->db->with_many('scores', 'user_id', 'id', function ($q) use (&$snapshot1) {
            $q->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
              ->where_aggregate('score_total BETWEEN', [1, 100]);
            $snapshot1 = $q->pending_where_aggregates; // snapshot BEFORE the pipeline consumes+clears it
        })->where('id', 1)->get('users');

        echo "operator=" . $snapshot1[0]['operator'] . " (expect BETWEEN)\n";
        echo "value=" . json_encode($snapshot1[0]['value']) . " (expect [1,100])\n";

        // Dotted foreign/local key must now pass through as-is (not stripped), per the
        // convention every other processor in this file relies on.
        $snapshot2 = null;
        $this->db->with_many('scores', 'user_id', 'id', function ($q) use (&$snapshot2) {
            $q->with_calculation(['scores' => 'score_total'], 'scores.user_id', 'scores.id', 'SUM(value)')
              ->where_aggregate('score_total >', 0);
            $snapshot2 = $q->pending_where_aggregates;
        })->where('id', 1)->get('users');
        echo "foreign_key=" . json_encode($snapshot2[0]['foreign_key']) . " (expect [\"scores.user_id\"], NOT [\"user_id\"])\n";

        echo "\n=== 18. where_exists_relation() / or_where_exists_relation() (unified into trait, never directly tested) ===\n";
        $hasScores = $this->db->select(['id', 'name'])
            ->where_exists_relation('scores', 'user_id', 'id')
            ->order_by('id', 'ASC')
            ->get('users');
        $names = [];
        foreach ($hasScores->result() as $u) $names[] = $u->name;
        echo "users with scores: " . implode(',', $names) . " (expect Alice only)\n";

        $noScores = $this->db->select(['id', 'name'])
            ->where_not_exists_relation('scores', 'user_id', 'id')
            ->order_by('id', 'ASC')
            ->get('users');
        $names2 = [];
        foreach ($noScores->result() as $u) $names2[] = $u->name;
        echo "users without scores: " . implode(',', $names2) . " (expect Bob,Charlie)\n";

        echo "\n=== 19. or_where_exists_relation() with disable_pending_process=true (immediate-OR branch) ===\n";
        $orResult = $this->db->select(['id', 'name'])
            ->where('id', 2)
            ->or_where_exists_relation('scores', 'user_id', 'id', null, true)
            ->order_by('id', 'ASC')
            ->get('users');
        $names3 = [];
        foreach ($orResult->result() as $u) $names3[] = $u->name;
        echo "id=2 OR has-scores: " . implode(',', $names3) . " (expect Alice,Bob)\n";

        echo "\n=== 20. where_exists_relation() inside group() (_in_group_context / pending_where_queue path) ===\n";
        $groupResult = $this->db->select(['id', 'name'])
            ->group(function ($q) {
                $q->where('id', 3)
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->order_by('id', 'ASC')
            ->get('users');
        $names4 = [];
        foreach ($groupResult->result() as $u) $names4[] = $u->name;
        echo "grouped (id=3 OR has-scores): " . implode(',', $names4) . " (expect Alice,Charlie)\n";

        echo "\n=== 21. join_avg / join_min / join_max (alias bug fix regression) ===\n";
        $r = $this->db->select(['id', 'name'])
            ->join_avg('scores', 'user_id', 'id', 'value')
            ->join_min(['scores' => 'scores_lowest'], 'user_id', 'id', 'value')
            ->join_max('scores', 'user_id', 'id', 'value')
            ->where('id', 1)
            ->get('users');
        foreach ($r->result() as $u) {
            echo "avg={$u->scores_avg} lowest={$u->scores_lowest} max={$u->scores_max} (expect 30, 10, 50)\n";
        }

        echo "\n=== 22. with_avg / with_max / with_min (subquery aggregate variants) ===\n";
        $r2 = $this->db->select(['id', 'name'])
            ->with_avg('scores', 'user_id', 'id', 'value')
            ->with_min(['scores' => 'scores_lowest'], 'user_id', 'id', 'value')
            ->with_max('scores', 'user_id', 'id', 'value')
            ->where('id', 1)
            ->get('users');
        foreach ($r2->result() as $u) {
            echo "avg={$u->scores_avg} lowest={$u->scores_lowest} max={$u->scores_max} (expect 30, 10, 50)\n";
        }

        echo "\n=== 23. Raw where_exists()/where_not_exists()/or_where_exists()/or_where_not_exists() (never executed before) ===\n";
        $r23a = $this->db->select(['id', 'name'])
            ->from('users')
            ->where_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->order_by('id', 'ASC')
            ->get();
        $n = [];
        foreach ($r23a->result() as $u) $n[] = $u->name;
        echo "where_exists: " . implode(',', $n) . " (expect Alice)\n";

        $r23b = $this->db->select(['id', 'name'])
            ->from('users')
            ->where_not_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->order_by('id', 'ASC')
            ->get();
        $n = [];
        foreach ($r23b->result() as $u) $n[] = $u->name;
        echo "where_not_exists: " . implode(',', $n) . " (expect Bob,Charlie)\n";

        $r23c = $this->db->select(['id', 'name'])
            ->from('users')
            ->where('id', 2)
            ->or_where_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->order_by('id', 'ASC')
            ->get();
        $n = [];
        foreach ($r23c->result() as $u) $n[] = $u->name;
        echo "or_where_exists: " . implode(',', $n) . " (expect Alice,Bob)\n";

        $r23d = $this->db->select(['id', 'name'])
            ->from('users')
            ->where('id', 1)
            ->or_where_not_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->order_by('id', 'ASC')
            ->get();
        $n = [];
        foreach ($r23d->result() as $u) $n[] = $u->name;
        echo "or_where_not_exists: " . implode(',', $n) . " (expect Alice,Bob,Charlie)\n";

        echo "\n=== 24. join_count() / join_calculation() (RelationAggregateTrait members never executed) ===\n";
        $r24 = $this->db->select(['id', 'name'])
            ->join_count('scores', 'user_id', 'id')
            ->join_calculation(['scores' => 'value_range'], 'user_id', 'id', 'MAX(value) - MIN(value)')
            ->where('id', 1)
            ->get('users');
        foreach ($r24->result() as $u) {
            echo "scores_count={$u->scores_count} value_range={$u->value_range} (expect 3, 40)\n";
        }

        echo "\n=== 25. with_calculation() at top level (not nested) ===\n";
        $r25 = $this->db->select(['id', 'name'])
            ->with_calculation(['scores' => 'value_range'], 'user_id', 'id', 'MAX(value) - MIN(value)')
            ->where('id', 1)
            ->get('users');
        foreach ($r25->result() as $u) {
            echo "value_range={$u->value_range} (expect 40)\n";
        }

        echo "\n=== 26. count_all_results() after get()/query() wrapping changes ===\n";
        $count = $this->db->where('category', 'A')->count_all_results('users');
        echo "count_all_results(category=A): {$count} (expect 2)\n";

        echo "\n=== 27. pluck() ===\n";
        $names27 = $this->db->order_by('id', 'ASC')->pluck('name', 'users');
        echo "pluck(name): " . implode(',', $names27) . " (expect Alice,Bob,Charlie)\n";

        echo "\n=== 28. chunk() ===\n";
        $seen = [];
        $this->db->order_by('id', 'ASC')->chunk(2, function ($rows, $page) use (&$seen) {
            foreach ($rows as $r) $seen[] = $r->name;
            return true;
        }, 'users');
        echo "chunk() collected: " . implode(',', $seen) . " (expect Alice,Bob,Charlie)\n";

        echo "\n=== 29. insert()/update()/delete() still work after query() wrapping changes ===\n";
        $this->db->insert('scores', ['user_id' => 3, 'value' => 999]);
        echo "insert affected_rows: " . $this->db->affected_rows() . " (expect 1)\n";
        $this->db->where('user_id', 3)->update('scores', ['value' => 1000]);
        echo "update affected_rows: " . $this->db->affected_rows() . " (expect 1)\n";
        $this->db->where('user_id', 3)->delete('scores');
        echo "delete affected_rows: " . $this->db->affected_rows() . " (expect 1)\n";

        echo "\n=== 30. Composite/multi-key: with_many() ===\n";
        $r30 = $this->db->select(['id', 'name', 'category'])
            ->with_many('category_scores', ['user_id', 'category'], ['id', 'category'])
            ->where('id', 1)
            ->get('users');
        foreach ($r30->result() as $u) {
            echo "user={$u->name} category_scores_count=" . count($u->category_scores) . " (expect 2, only same-category rows)\n";
        }

        echo "\n=== 31. Composite/multi-key: where_exists_relation() ===\n";
        $r31 = $this->db->select(['id', 'name'])
            ->where_exists_relation('category_scores', ['user_id', 'category'], ['id', 'category'])
            ->order_by('id', 'ASC')
            ->get('users');
        $n = [];
        foreach ($r31->result() as $u) $n[] = $u->name;
        echo "has matching category_scores: " . implode(',', $n) . " (expect Alice only)\n";

        echo "\n=== 32. Composite/multi-key: where_aggregate() via with_calculation ===\n";
        $r32 = $this->db->select(['id', 'name'])
            ->with_calculation(['category_scores' => 'cat_total'], ['user_id', 'category'], ['id', 'category'], 'SUM(value)')
            ->where('id', 1)
            ->get('users');
        foreach ($r32->result() as $u) {
            echo "cat_total={$u->cat_total} (expect 150, only category A rows)\n";
        }

        echo "\n=== 33. with() rejects array relation with count != 1 ===\n";
        try {
            $this->db->with(['orders' => 'user_orders', 'extra' => 'x'], 'user_id', 'id')->get('users');
            echo "SHOULD HAVE THROWN (multi-element array)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected multi-element array: {$e->getMessage()}\n";
        }
        try {
            $this->db->with([], 'user_id', 'id')->get('users');
            echo "SHOULD HAVE THROWN (empty array)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected empty array: {$e->getMessage()}\n";
        }
        $r33 = $this->db->with(['scores' => 'user_scores'], 'user_id', 'id')->where('id', 1)->get('users');
        foreach ($r33->result() as $u) {
            echo "still works with valid 1-element array: user_scores count=" . count($u->user_scores) . " (expect 3)\n";
        }

        echo "\n=== 34. where_has() default (>=1) delegates to where_exists_relation() ===\n";
        $sql34a = $this->db->where_has('scores', 'user_id', 'id')->get_compiled_select('users');
        echo "SQL: {$sql34a}\n";
        echo "(expect plain EXISTS subquery, same shape as where_exists_relation)\n";

        echo "\n=== 35. where_has()/or_where_has() with count!=1: call-order preservation check ===\n";
        // Call order: where(id=999) OR where_has(count>=2) AND where(category=A)
        // If call-order were preserved (like or_where_exists_relation), we'd expect:
        //   WHERE id=999 OR (subquery)>=2 AND category=A
        $sql35 = $this->db->where('id', 999)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('category', 'A')
            ->get_compiled_select('users');
        echo "SQL: {$sql35}\n";
        echo "(compare clause order against call order: id=999, or_where_has, category=A)\n";

        echo "\n=== 36. Sanity: does or_where_exists_relation() have the same append-at-end ordering? ===\n";
        $sql36 = $this->db->where('id', 999)
            ->or_where_exists_relation('scores', 'user_id', 'id')
            ->where('category', 'A')
            ->get_compiled_select('users');
        echo "SQL: {$sql36}\n";

        echo "\n=== 37. Functional proof: call-order fix changes actual query results, not just SQL text ===\n";
        // Call order: category='Z' (false for everyone) OR has>=3 scores (true only for Alice) AND id=999 (false for everyone)
        // Correct (AND binds tighter than OR, in original call order): category=Z OR (has>=3 AND id=999) = false everywhere -> empty
        // Old buggy behavior (aggregate always appended last): (category=Z AND id=999) OR has>=3 = true for Alice -> [Alice]
        $r37 = $this->db->where('category', 'Z')
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 3)
            ->where('id', 999)
            ->get('users');
        $names37 = [];
        foreach ($r37->result() as $u) $names37[] = $u->name;
        echo "result: " . (empty($names37) ? '(empty)' : implode(',', $names37)) . " (expect empty — old buggy behavior would have returned Alice)\n";

        echo "\n=== 38a. where_exists_relation() via get_compiled_select() ===\n";
        echo $this->db->where_exists_relation('scores', 'user_id', 'id')->get_compiled_select('users') . "\n";

        echo "\n=== 38b. where_not_exists_relation() via get_compiled_select() ===\n";
        echo $this->db->where_not_exists_relation('scores', 'user_id', 'id')->get_compiled_select('users') . "\n";

        echo "\n=== 38c. or_where_exists_relation(disable_pending_process=true) sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->or_where_exists_relation('scores', 'user_id', 'id', null, true)
            ->where('category', 'A')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 OR EXISTS(...scores.user_id = users.id...) AND category='A', in that order)\n";

        echo "\n=== 38d. where_exists_relation() inside group(), sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('email !=', 'x@example.com')
            ->get_compiled_select('users') . "\n";
        echo "(expect the EXISTS clause fully INSIDE the parentheses opened by group())\n";

        echo "\n=== 38e. where_has() default (count=1, delegates) sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->where_has('scores', 'user_id', 'id')
            ->where('category', 'A')
            ->get_compiled_select('users') . "\n";

        echo "\n=== 38f. where_has() with explicit count!=1 sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('category', 'A')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (count subquery)>=2 AND category='A', in that order)\n";

        echo "\n=== 38g. or_where_has() with count!=1 sandwiched between where()s (repeat of test 35, via get_compiled_select) ===\n";
        echo $this->db->where('id', 999)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('category', 'A')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=999 OR (count subquery)>=2 AND category='A', in that order)\n";

        echo "\n=== 38h. where_aggregate()/or_where_aggregate() with BETWEEN sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
            ->or_where_aggregate('score_total BETWEEN', [1, 100])
            ->where('category', 'A')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 OR COALESCE(subquery,0) BETWEEN 1 AND 100 AND category='A', in that order)\n";

        echo "\n=== 38i. Composite-key where_exists_relation() via get_compiled_select() ===\n";
        echo $this->db->where('id', 1)
            ->or_where_exists_relation('category_scores', ['user_id', 'category'], ['id', 'category'])
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect composite AND-joined match conditions inside EXISTS, and OR/AND glue preserved in call order)\n";

        echo "\n=== 38j. Multiple mixed pending conditions interleaved with plain where()s ===\n";
        echo $this->db->where('id', 1)
            ->or_where_exists_relation('scores', 'user_id', 'id')
            ->where('category', 'A')
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect 4 conditions to appear in exact call order: id=1, OR EXISTS, AND category, OR count>=2, AND name!=Bob)\n";

        echo "\n=== 38k. Same as 38d but using from() up front (table known -> group() executes immediately) ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('email !=', 'x@example.com')
            ->get_compiled_select() . "\n";
        echo "(if this matches call order but 38d didn't, the group()-ordering issue is pre-existing and tied to from()-vs-get(table) style, not the reorder fix)\n";

        echo "\n=== 39a. group() BEFORE or_where_has(), table unknown at call time (deferred group) ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR EXISTS(...)) OR (count)>=2 AND name!='Bob', in that order)\n";

        echo "\n=== 39b. or_where_has() BEFORE group(), table unknown at call time (deferred group) ===\n";
        echo $this->db->where('id', 1)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 OR (count)>=2 AND (category='A' OR EXISTS(...)) AND name!='Bob', in that order)\n";

        echo "\n=== 39c. group() BEFORE or_where_has(), table known up front via from() (immediate group) ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select() . "\n";
        echo "(expect same shape as 39a: id=1 AND (category='A' OR EXISTS(...)) OR (count)>=2 AND name!='Bob')\n";

        echo "\n=== 39d. or_where_has() BEFORE group(), table known up front via from() (immediate group) ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select() . "\n";
        echo "(expect same shape as 39b: id=1 OR (count)>=2 AND (category='A' OR EXISTS(...)) AND name!='Bob')\n";

        echo "\n=== 40a. with_count() + group() + or_where_has(), all in call order ===\n";
        echo $this->db->where('id', 1)
            ->with_count('scores', 'user_id', 'id')
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect SELECT *, (subquery) AS scores_count; WHERE id=1 AND (category='A' OR EXISTS(...)) OR (count)>=2 AND name!='Bob')\n";

        echo "\n=== 40b. Same conditions, reversed registration order (or_where_has -> group -> with_count) ===\n";
        echo $this->db->where('id', 1)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->with_count('scores', 'user_id', 'id')
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect same SELECT column, but WHERE: id=1 OR (count)>=2 AND (category='A' OR EXISTS(...)) AND name!='Bob' -- with_count() doesn't touch WHERE ordering at all)\n";

        echo "\n=== 40c. with_sum() + where_aggregate() + group(), interleaved ===\n";
        echo $this->db->where('id', 1)
            ->with_sum('scores', 'user_id', 'id', 'value')
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
            ->or_where_aggregate('score_total >', 50)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect SELECT *, scores_sum, score_total; WHERE id=1 AND (category='A' OR EXISTS(...)) OR COALESCE(subquery,0) > 50 AND name!='Bob')\n";

        echo "\n=== 40d. Two independent groups + where_has() sandwiched between them ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A');
            })
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 1)
            ->or_group(function ($q) {
                $q->where('name', 'Bob');
            })
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A') OR (count)>=1 OR (name='Bob'), in that order — two separate groups, has() sandwiched between them)\n";

        echo "\n=== 40e. with_count() called INSIDE a group() callback ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->with_count('scores', 'user_id', 'id')
                  ->where('category', 'A');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect SELECT column still added despite being called inside group(); WHERE id=1 AND (category='A') AND name!='Bob')\n";

        echo "\n=== 41a. when(true) callback contains or_where_exists_relation(disable_pending_process=true), sandwiched ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id', null, true);
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR EXISTS(...)) AND name!='Bob', in that order)\n";

        echo "\n=== 41b. when(true) callback contains or_where_has(count!=1), sandwiched ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->where('category', 'A')
                  ->or_where_has('scores', 'user_id', 'id', null, '>=', 2);
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR (count)>=2) AND name!='Bob', in that order)\n";

        echo "\n=== 41c. when(true) callback contains group(), sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->group(function ($q2) {
                    $q2->where('category', 'A')->or_where_exists_relation('scores', 'user_id', 'id');
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR EXISTS(...)) AND name!='Bob', in that order)\n";

        echo "\n=== 41d. group() callback contains when(true) with or_where_has() inside, sandwiched ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->when(true, function ($q2) {
                      $q2->or_where_has('scores', 'user_id', 'id', null, '>=', 2);
                  });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR (count)>=2) AND name!='Bob', in that order)\n";

        echo "\n=== 41e. when(false) runs the default callback instead, pending items in default still flush correctly ===\n";
        echo $this->db->where('id', 1)
            ->when(false, function ($q) {
                $q->where('category', 'A'); // should NOT appear
            }, function ($q) {
                $q->where('category', 'B')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='B' OR EXISTS(...)) AND name!='Bob' -- category='A' must NOT appear)\n";

        echo "\n=== 41f. unless(false) — same as when(true) — with or_where_exists_relation inside, sandwiched ===\n";
        echo $this->db->where('id', 1)
            ->unless(false, function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR EXISTS(...)) AND name!='Bob', in that order)\n";

        echo "\n=== 41g. search() (internally uses group()) combined with or_where_has(), both orders ===\n";
        echo $this->db->where('id', 1)
            ->search('john', ['name', 'email'])
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (name LIKE OR email LIKE) OR (count)>=2 AND name!='Bob', in that order)\n";

        echo $this->db->where('id', 1)
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->search('john', ['name', 'email'])
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 OR (count)>=2 AND (name LIKE OR email LIKE) AND name!='Bob', in that order -- reversed)\n";

        echo "\n=== 41h. Multiple when() calls interleaved with group() and where_has() ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) { $q->where('category', 'A'); })
            ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->when(true, function ($q) {
                $q->group(function ($q2) { $q2->where('name', 'Bob')->or_where('name', 'Alice'); });
            })
            ->where('email !=', 'x@example.com')
            ->get_compiled_select('users') . "\n";
        echo "(expect 4 conditions in exact call order: id=1, AND category=A, OR count>=2, AND (name=Bob OR name=Alice), AND email!=...)\n";

        echo "\n=== 41i. Same as 41a but with from('users') called up front ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->when(true, function ($q) {
                $q->where('category', 'A')
                  ->or_where_exists_relation('scores', 'user_id', 'id', null, true);
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select() . "\n";
        echo "(if this is correctly qualified as scores.user_id = users.id but 41a wasn't, the bug is specific to the get('table')-at-the-end style)\n";

        echo "\n=== 42a. when(true) callback contains with_count() + with_sum(), sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->with_count('scores', 'user_id', 'id')
                  ->with_sum('scores', 'user_id', 'id', 'value');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect SELECT *, scores_count, scores_sum; WHERE id=1 AND name!='Bob' -- aggregates don't touch WHERE at all)\n";

        echo "\n=== 42b. when(true) callback contains with_calculation() + where_aggregate(), sandwiched ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
                  ->or_where_aggregate('score_total >', 50);
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect SELECT *, score_total; WHERE id=1 OR COALESCE(subquery,0) > 50 AND name!='Bob', in call order)\n";

        echo "\n=== 42c. when(true) callback contains join_count()/join_sum(), sandwiched ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->join_count('scores', 'user_id', 'id')
                  ->join_sum('scores', 'user_id', 'id', 'value');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect derived-table JOINs added for scores_count and scores_sum; WHERE id=1 AND name!='Bob')\n";

        echo "\n=== 42d. group() containing when() containing with_count() ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->when(true, function ($q2) {
                    $q2->with_count('scores', 'user_id', 'id');
                })->where('category', 'A');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect SELECT column scores_count still added; WHERE id=1 AND (category='A') AND name!='Bob')\n";

        echo "\n=== 42e. Mixing with_count() inside when() and outside, both should appear with distinct aliases ===\n";
        echo $this->db->with_count(['scores' => 'total_scores'], 'user_id', 'id')
            ->where('id', 1)
            ->when(true, function ($q) {
                $q->with_sum(['scores' => 'sum_scores'], 'user_id', 'id', 'value');
            })
            ->get_compiled_select('users') . "\n";
        echo "(expect both total_scores and sum_scores columns present)\n";

        echo "\n=== 43a. when() containing group() containing or_where_has(), get('table')-at-end style ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->group(function ($q2) {
                    $q2->where('category', 'A')
                       ->or_where_has('scores', 'user_id', 'id', null, '>=', 2);
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR (count)>=2) AND name!='Bob', column fully qualified as users.id)\n";

        echo "\n=== 43b. Same as 43a but with from('users') up front ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->when(true, function ($q) {
                $q->group(function ($q2) {
                    $q2->where('category', 'A')
                       ->or_where_has('scores', 'user_id', 'id', null, '>=', 2);
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select() . "\n";
        echo "(expect identical shape to 43a)\n";

        echo "\n=== 43c. group() containing when() containing or_where_exists_relation(disable_pending_process=true), get('table')-at-end ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->when(true, function ($q2) {
                      $q2->or_where_exists_relation('scores', 'user_id', 'id', null, true);
                  });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR EXISTS(...scores.user_id = users.id...)) AND name!='Bob' -- fully qualified, no ambiguity)\n";

        echo "\n=== 43d. Same as 43c but with from('users') up front ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->when(true, function ($q2) {
                      $q2->or_where_exists_relation('scores', 'user_id', 'id', null, true);
                  });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select() . "\n";
        echo "(expect identical shape to 43c)\n";

        echo "\n=== 43e. group() containing when() containing a nested group() ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->where('category', 'A')
                  ->when(true, function ($q2) {
                      $q2->or_group(function ($q3) {
                          $q3->where('name', 'Bob')->or_where_exists_relation('scores', 'user_id', 'id');
                      });
                  });
            })
            ->where('email !=', 'x@example.com')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR (name='Bob' OR EXISTS(...))) AND email!='...', both groups properly nested and qualified)\n";

        echo "\n=== 43f. Triple nesting: when() -> group() -> when() -> or_where_has() ===\n";
        echo $this->db->where('id', 1)
            ->when(true, function ($q) {
                $q->group(function ($q2) {
                    $q2->where('category', 'A')
                       ->when(true, function ($q3) {
                           $q3->or_where_has('scores', 'user_id', 'id', null, '>=', 3);
                       });
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A' OR (count)>=3) AND name!='Bob')\n";

        echo "\n=== 43g. or_group() combined with when(), sandwiched, mixed AND/OR ===\n";
        echo $this->db->where('id', 1)
            ->or_group(function ($q) {
                $q->when(true, function ($q2) {
                    $q2->where('category', 'A')->or_where_has('scores', 'user_id', 'id', null, '>=', 2);
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 OR (category='A' OR (count)>=2) AND name!='Bob' -- outer group is OR, inner condition still OR too)\n";

        echo "\n=== 43h. Multiple groups and whens interleaved at top level ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) { $q->where('category', 'A'); })
            ->when(true, function ($q) { $q->or_where_has('scores', 'user_id', 'id', null, '>=', 2); })
            ->or_group(function ($q) { $q->where('name', 'Bob'); })
            ->when(true, function ($q) { $q->where('email !=', 'x@example.com'); })
            ->get_compiled_select('users') . "\n";
        echo "(expect 5 conditions in exact call order: id=1, AND (category=A), OR (count)>=2, OR (name=Bob), AND email!=...)\n";

        echo "\n=== 44a. group() containing ONLY when(false) with no default — group ends up empty ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->when(false, function ($q2) {
                    $q2->where('category', 'A'); // never runs
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(what does an empty group compile to? is it valid SQL?)\n";

        echo "\n=== 44b. Same as 44a, but actually executed via get() (not just compiled) ===\n";
        try {
            $r44b = $this->db->where('id', 1)
                ->group(function ($q) {
                    $q->when(false, function ($q2) {
                        $q2->where('category', 'A');
                    });
                })
                ->where('name !=', 'Bob')
                ->get('users');
            $names = [];
            foreach ($r44b->result() as $u) $names[] = $u->name;
            echo "executed OK, result: " . implode(',', $names) . "\n";
        } catch (Exception $e) {
            echo "THREW: " . $e->getMessage() . "\n";
        }

        echo "\n=== 44c. group() containing when(false) with no default, from('users') up front ===\n";
        echo $this->db->from('users')->where('id', 1)
            ->group(function ($q) {
                $q->when(false, function ($q2) {
                    $q2->where('category', 'A');
                });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select() . "\n";
        echo "(compare against 44a — same empty-group shape regardless of from()-vs-get(table) style?)\n";

        echo "\n=== 44d. Empty group() as the VERY FIRST condition (no preceding where()) ===\n";
        echo $this->db->group(function ($q) {
                $q->when(false, function ($q2) { $q2->where('category', 'A'); });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: WHERE name != 'Bob' -- with NO leading AND, since the group was popped and this becomes the true first condition)\n";

        echo "\n=== 44e. or_group() that ends up empty, sandwiched between where()s ===\n";
        echo $this->db->where('id', 1)
            ->or_group(function ($q) {
                $q->when(false, function ($q2) { $q2->where('category', 'A'); });
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: WHERE id=1 AND name != 'Bob' -- empty or_group() vanishes cleanly too)\n";

        echo "\n=== 45a. Raw group_start()/group_end() wrapping or_where_exists_relation() (no disable_pending_process) ===\n";
        echo $this->db->where('id', 1)
            ->group_start()
                ->where('category', 'A')
                ->or_where_exists_relation('scores', 'user_id', 'id')
            ->group_end()
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(does EXISTS land INSIDE the manual brackets, or leak out after group_end() like the old bug?)\n";

        echo "\n=== 45b. Raw group_start()/group_end() with nothing added inside (manual empty group) ===\n";
        try {
            $sql45b = $this->db->where('id', 1)
                ->group_start()
                ->group_end()
                ->where('name !=', 'Bob')
                ->get_compiled_select('users');
            echo $sql45b . "\n";
        } catch (Exception $e) {
            echo "THREW: " . $e->getMessage() . "\n";
        }
        echo "(does this get the same empty-group protection as group(), or does it still emit invalid '( )'?)\n";

        echo "\n=== 45c. Raw group_start()/group_end() wrapping or_where_has(count!=1) ===\n";
        echo $this->db->where('id', 1)
            ->group_start()
                ->where('category', 'A')
                ->or_where_has('scores', 'user_id', 'id', null, '>=', 2)
            ->group_end()
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(does the count clause land inside the brackets?)\n";

        echo "\n=== 46a. group_start() -> where() -> group() [callback] -> group_end(), table unknown (get('table')-at-end) ===\n";
        echo $this->db->group_start()
                ->where('category', 'A')
                ->group(function ($q) {
                    $q->where('name', 'Bob')->or_where_exists_relation('scores', 'user_id', 'id');
                })
            ->group_end()
            ->where('id', 1)
            ->get_compiled_select('users') . "\n";
        echo "(does the callback group() land INSIDE the manual brackets, or leak out after group_end()?)\n";

        echo "\n=== 46b. Same as 46a but with from('users') up front (table known) ===\n";
        echo $this->db->from('users')->group_start()
                ->where('category', 'A')
                ->group(function ($q) {
                    $q->where('name', 'Bob')->or_where_exists_relation('scores', 'user_id', 'id');
                })
            ->group_end()
            ->where('id', 1)
            ->get_compiled_select() . "\n";
        echo "(compare against 46a — should be identical if this is safe regardless of style)\n";

        echo "\n=== 46c. group() [callback] containing group_start()/where()/group_end() nested inside ===\n";
        echo $this->db->where('id', 1)
            ->group(function ($q) {
                $q->group_start()
                    ->where('category', 'A')
                    ->or_where('category', 'B')
                  ->group_end()
                  ->or_where_exists_relation('scores', 'user_id', 'id');
            })
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND ((category=A OR category=B) OR EXISTS(...)) AND name!='Bob', properly nested)\n";

        echo "\n=== 46d. Sibling usage at top level: where() -> group_start()/group_end() -> group() [callback] -> where() ===\n";
        echo $this->db->where('id', 1)
            ->group_start()
                ->where('category', 'A')
            ->group_end()
            ->group(function ($q) {
                $q->where('name', 'Bob')->or_where('name', 'Alice');
            })
            ->where('email !=', 'x@example.com')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND (category='A') AND (name='Bob' OR name='Alice') AND email!='...', two independent groups in call order)\n";

        echo "\n=== 46e. Nested raw groups: group_start() -> group_start() -> where() -> group_end() -> group_end() ===\n";
        echo $this->db->where('id', 1)
            ->group_start()
                ->group_start()
                    ->where('category', 'A')
                ->group_end()
                ->or_where_exists_relation('scores', 'user_id', 'id')
            ->group_end()
            ->where('name !=', 'Bob')
            ->get_compiled_select('users') . "\n";
        echo "(expect: id=1 AND ((category='A') OR EXISTS(...)) AND name!='Bob', double-nested brackets correct)\n";

        echo "\n=== 47. NestedQueryBuilder::where_exists() called INSIDE a with_one()/with_many() relation callback ===\n";
        // $q inside the callback is a NestedQueryBuilder wrapping the relation's own
        // base_db. Calling $q->where_exists(...) hits NestedQueryBuilder's own
        // where_exists() (not the proxy), which builds its sub-subquery directly on
        // $this->db (== the relation's base_db, no clone) and then calls
        // get_compiled_select() with the default $reset=true — resetting that shared
        // base_db entirely. If the relation query state survives, category='A' plus
        // the EXISTS condition should both still apply correctly to the OUTER relation.
        try {
            $r47 = $this->db->select(['id', 'name'])
                ->with_many('scores', 'user_id', 'id', function ($q) {
                    // Call where_exists() DIRECTLY on $q (the NestedQueryBuilder) first,
                    // not chained after a proxied call — otherwise __call() returns
                    // $base_db itself and the rest of the chain silently escapes the
                    // NestedQueryBuilder wrapper, calling CustomQueryBuilder's own
                    // (safe) where_exists() instead of NestedQueryBuilder's own.
                    $q->where_exists(function ($nested) {
                        $nested->select('1')->from('users')->where('users.id = scores.user_id');
                    });
                    $q->where('value >', 0);
                })
                ->where('id', 1)
                ->get('users');
            foreach ($r47->result() as $u) {
                echo "user={$u->name} scores_count=" . count($u->scores) . " (expect 3 -- relation's own where('value >',0) must survive)\n";
            }
            echo "executed relation SQL(s):\n";
            foreach ($this->db->all_last_query() as $q) echo "  " . $q . "\n";
        } catch (Exception $e) {
            echo "THREW: " . $e->getMessage() . "\n";
        }

        echo "\n=== 48. _safe_in_clause() rejects malicious column names in where_in()/or_where_in()/where_not_in()/or_where_not_in() ===\n";
        try {
            $this->db->where_in('id; DROP TABLE users', [1, 2, 3])->get('users');
            echo "SHOULD HAVE THROWN (where_in)\n";
        } catch (InvalidArgumentException $e) {
            echo "where_in correctly rejected: " . $e->getMessage() . "\n";
        }
        try {
            $this->db->or_where_in('id; DROP TABLE users', [1, 2, 3])->get('users');
            echo "SHOULD HAVE THROWN (or_where_in)\n";
        } catch (InvalidArgumentException $e) {
            echo "or_where_in correctly rejected: " . $e->getMessage() . "\n";
        }
        try {
            $this->db->where_not_in('id; DROP TABLE users', [1, 2, 3])->get('users');
            echo "SHOULD HAVE THROWN (where_not_in)\n";
        } catch (InvalidArgumentException $e) {
            echo "where_not_in correctly rejected: " . $e->getMessage() . "\n";
        }
        try {
            $this->db->or_where_not_in('id; DROP TABLE users', [1, 2, 3])->get('users');
            echo "SHOULD HAVE THROWN (or_where_not_in)\n";
        } catch (InvalidArgumentException $e) {
            echo "or_where_not_in correctly rejected: " . $e->getMessage() . "\n";
        }

        // Confirm legit usage still works and column is properly protected/qualified
        $sql48 = $this->db->where_in('id', [1, 2, 3])->get_compiled_select('users');
        echo "SQL: {$sql48}\n";
        echo "(expect valid column still works, id wrapped in backticks)\n";

        $r48 = $this->db->where_in('id', [1, 2])->order_by('id', 'ASC')->get('users');
        $names48 = [];
        foreach ($r48->result() as $u) $names48[] = $u->name;
        echo "where_in(id,[1,2]) result: " . implode(',', $names48) . " (expect Alice,Bob)\n";

        echo "\n=== 49. SQL injection: backtick-quoted disallowed function name in a custom aggregate expression is rejected ===\n";
        try {
            $this->db->select(['id', 'name'])
                ->with_avg(['scores' => 'x'], 'user_id', 'id', '`SLEEP`(0)', true)
                ->where('id', 1)
                ->get('users');
            echo "SHOULD HAVE THROWN (backtick-wrapped disallowed function must not bypass validation)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== 50a. or_where_has() now validates relation name (previously accepted silently) ===\n";
        try {
            $this->db->where('id', 1)->or_where_has('scores; DROP TABLE users', 'user_id', 'id', null, '>=', 2);
            echo "SHOULD HAVE THROWN (invalid relation name)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== 50b. or_where_has() now validates operator (previously silently coerced to >=) ===\n";
        try {
            $this->db->where('id', 1)->or_where_has('scores', 'user_id', 'id', null, 'BETWEEN', 2);
            echo "SHOULD HAVE THROWN (invalid operator)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== 50c. or_where_has() now validates count is non-negative (previously accepted silently) ===\n";
        try {
            $this->db->where('id', 1)->or_where_has('scores', 'user_id', 'id', null, '>', -5);
            echo "SHOULD HAVE THROWN (negative count)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== 50d. or_where_has() still works correctly with valid params ===\n";
        $r50d = $this->db->where('id', 999)->or_where_has('scores', 'user_id', 'id', null, '>=', 2)->get('users');
        $names50d = [];
        foreach ($r50d->result() as $u) $names50d[] = $u->name;
        echo "result: " . (empty($names50d) ? '(empty)' : implode(',', $names50d)) . " (expect Alice -- has >=2 scores)\n";

        echo "\n=== 51. where_exists_relation() with an aliased relation string now works (previously always rejected) ===\n";
        $r51 = $this->db->select(['id', 'name'])
            ->where_exists_relation('scores s', 'user_id', 'id')
            ->order_by('id', 'ASC')
            ->get('users');
        $names51 = [];
        foreach ($r51->result() as $u) $names51[] = $u->name;
        echo "result: " . implode(',', $names51) . " (expect Alice only)\n";

        echo "\n=== 52. where_aggregate() nested inside a where_has() callback is no longer silently dropped ===\n";
        // Alice has 3 scores (sum 90). If the nested where_aggregate(score_total > 1000)
        // is actually applied, no user qualifies; if it was silently dropped, this would
        // wrongly return Alice (who does have >=2 scores).
        $r52 = $this->db->select(['id', 'name'])
            ->where_has('scores', 'user_id', 'id', function ($q) {
                $q->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
                  ->where_aggregate('score_total >', 1000);
            }, '>=', 2)
            ->get('users');
        $names52 = [];
        foreach ($r52->result() as $u) $names52[] = $u->name;
        echo "result: " . (empty($names52) ? '(empty)' : implode(',', $names52)) . " (expect empty -- nested where_aggregate() must actually filter)\n";

        echo "\n=== 53a. CustomQueryBuilder::where_exists() no longer drops where_exists_relation() called on the callback param ===\n";
        // Only Alice has any category_scores rows, so the nested where_exists_relation()
        // must narrow the EXISTS subquery down to Alice; if dropped, all 3 users qualify
        // (every user has >=1 score). Local key is fully qualified as 'users.id' since a
        // bare 'id' here would resolve against $nested's own table ('scores'), not 'users'.
        $r53a = $this->db->select(['id', 'name'])
            ->where_exists(function ($nested) {
                $nested->select('1')
                    ->from('scores')
                    ->where('scores.user_id = users.id')
                    ->where_exists_relation('category_scores', 'user_id', 'users.id');
            })
            ->order_by('id', 'ASC')
            ->get('users');
        $names53a = [];
        foreach ($r53a->result() as $u) $names53a[] = $u->name;
        echo "result: " . implode(',', $names53a) . " (expect Alice only)\n";

        echo "\n=== 53b. NestedQueryBuilder::where_exists() (inside a with_many() relation callback) no longer drops where_exists_relation() on the callback param ===\n";
        // Inner where_exists_relation() deliberately filters on an impossible id (999),
        // so if it's correctly applied, the outer EXISTS never matches and scores_count
        // becomes 0. If it were silently dropped (the bug), the outer condition alone
        // (category_scores.user_id = scores.user_id, true for all 3 rows) would leave
        // scores_count at 3.
        $r53b = $this->db->select(['id', 'name'])
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
        foreach ($r53b->result() as $u) {
            echo "user={$u->name} scores_count=" . count($u->scores) . " (expect 0 -- nested where_exists_relation() must actually apply, not be dropped)\n";
        }

        echo "\n=== 54a. Custom sum expression with an already-qualified dotted column is no longer double-prefixed ===\n";
        $sql54a = $this->db->with_sum(['scores' => 'total'], 'user_id', 'id', 'scores.value', true)->where('id', 1)->get_compiled_select('users');
        echo $sql54a . "\n";
        echo "(expect scores.value qualified exactly once -- NOT double-prefixed like `sub`.`scores`.`sub`.`value`)\n";

        echo "\n=== 54b. Custom avg expression with a backtick-quoted bare column is not mangled ===\n";
        $sql54b = $this->db->with_avg(['scores' => 'avgval'], 'user_id', 'id', '`value`', true)->where('id', 1)->get_compiled_select('users');
        echo $sql54b . "\n";
        echo "(expect a single, valid backtick-quoted reference to value -- NOT doubled backticks)\n";

        echo "\n=== 55. order_by_relation() with an aliased main table no longer produces invalid SQL ===\n";
        $sql55 = $this->db->order_by_relation('scores', 'user_id', 'id', 'value', 'DESC')->get_compiled_select('users u');
        echo $sql55 . "\n";
        echo "(expect the subquery's WHERE to reference `u`.`id` -- the alias -- NOT the invalid \"users u.id\")\n";

        echo "\n=== 56a. _temp_table_name no longer leaks after eager-loading get() ===\n";
        $this->db->with_one('scores', 'user_id', 'id')->get('users');
        $ref56a = new ReflectionProperty($this->db, '_temp_table_name');
        $ref56a->setAccessible(true);
        var_export($ref56a->getValue($this->db));
        echo "\n(expect NULL)\n";

        echo "\n=== 56b. _temp_table_name no longer leaks after count_all_results() ===\n";
        $this->db->count_all_results('users');
        $ref56b = new ReflectionProperty($this->db, '_temp_table_name');
        $ref56b->setAccessible(true);
        var_export($ref56b->getValue($this->db));
        echo "\n(expect NULL)\n";

        echo "\n=== 57. key_by() no longer misfires on column names colliding with PHP builtin function names ===\n";
        $catCounts = $this->db->select('category, COUNT(*) as count')->group_by('category')->get('users')->key_by('count');
        echo implode(',', array_keys($catCounts)) . "\n";
        echo "(expect the actual COUNT(*) values as keys, e.g. 1,2 -- NOT collapsed into one bucket via count(\$row))\n";

        echo "\n=== 58a. with_sum() with a raw SQL subquery as the relation (top-level) ===\n";
        try {
            $sql58a = $this->db->select(['id', 'name'])
                ->with_sum(['(SELECT user_id, value FROM scores) scores_sub' => 'nested_sum'], 'user_id', 'id', 'value')
                ->where('id', 1)
                ->get_compiled_select('users');
            echo $sql58a . "\n";
        } catch (Exception $e) {
            echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
            $this->db->reset_query(); // clear dirty state left by the aborted query above
        }

        echo "\n=== 58b. with_sum() with a raw SQL subquery as the relation, INSIDE a with_many() relation callback (nested aggregate path just fixed) ===\n";
        try {
            $r58b = $this->db->select(['id', 'name'])
                ->with_many('scores', 'user_id', 'id', function ($q) {
                    $q->with_sum(['(SELECT user_id, value FROM scores) scores_sub' => 'nested_sum'], 'user_id', 'user_id', 'value');
                })
                ->where('id', 1)
                ->get('users');
            foreach ($r58b->result() as $u) {
                foreach ($u->scores as $s) {
                    echo "score id={$s->id} nested_sum=" . (isset($s->nested_sum) ? $s->nested_sum : 'MISSING') . "\n";
                }
            }
        } catch (Exception $e) {
            echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
            $this->db->reset_query(); // clear dirty state left by the aborted query above
        }

        echo "\n=== 59a. Regular with_many() relation whose ALIAS happens to end in '_sum', and whose rows have a 'value' column ===\n";
        // scores table has columns (id, user_id, value). If load_single_relation()'s
        // alias-suffix regex misfires, it will treat this as an aggregate result and
        // collapse each row down to just the scalar `value`, discarding id/user_id.
        $r59a = $this->db->select(['id', 'name'])
            ->with_many(['scores' => 'user_scores_sum'], 'user_id', 'id')
            ->where('id', 1)
            ->get('users');
        foreach ($r59a->result() as $u) {
            echo "user_scores_sum type=" . gettype($u->user_scores_sum) . "\n";
            var_export($u->user_scores_sum);
            echo "\n(expect an ARRAY of 3 full row objects with id/user_id/value -- NOT a bare scalar)\n";
        }

        echo "\n=== 59b. Regular with_one() relation with NO match, whose ALIAS happens to end in '_count' ===\n";
        // With no matching row at all, if the alias-suffix regex misfires it will
        // silently rename the field (stripping '_count') and default it to 0 instead
        // of the correct with_one() default of null.
        $r59b = $this->db->select(['id', 'name'])
            ->with_one(['scores' => 'top_score_count'], 'user_id', 'id', function ($q) {
                $q->where('value', 999999); // never matches -> no relation row
            })
            ->where('id', 1)
            ->get('users');
        foreach ($r59b->result() as $u) {
            echo "full row dump:\n";
            var_export($u);
            echo "\n(expect field 'top_score_count' = NULL -- NOT stripped to 'top_score' = 0)\n";
        }

        echo "\n=== 59c. Regular with_one() relation (single row, not a list) whose ALIAS happens to end in '_sum', and the matched row has a 'value' column ===\n";
        // with_one() -> multiple=false -> relation_data is a single row hash/object
        // directly (not wrapped in a list), so a real 'value' column sits at the
        // top level here -- this is the actual shape the alias-suffix regex can misfire on.
        $r59c = $this->db->select(['id', 'name'])
            ->with_one(['scores' => 'top_score_sum'], 'user_id', 'id', function ($q) {
                $q->order_by('value', 'DESC'); // deterministic: picks the value=50 row
            })
            ->where('id', 1)
            ->get('users');
        foreach ($r59c->result() as $u) {
            echo "full row dump:\n";
            var_export($u);
            echo "\n(expect 'top_score_sum' = full row OBJECT with id/user_id/value=50 -- NOT collapsed to scalar 50 under a renamed 'top_score' field)\n";
        }

        echo "\n=== 60. with_count()/with_sum() NESTED inside a with_many() relation callback -- confirms the alias-guessing removal didn't touch this separate code path ===\n";
        // category_scores has 3 rows for user_id=1 (100, 50, 999). Each 'scores' row
        // (also user_id=1) should get its own category_scores_count/category_scores_sum
        // columns added directly onto it (a totally different mechanism from the
        // with_one()/with_many() alias-matching code fixed above).
        $r60 = $this->db->select(['id', 'name'])
            ->with_many('scores', 'user_id', 'id', function ($q) {
                $q->with_count('category_scores', 'user_id', 'user_id')
                  ->with_sum(['category_scores' => 'category_scores_total'], 'user_id', 'user_id', 'value');
            })
            ->where('id', 1)
            ->get('users');
        foreach ($r60->result() as $u) {
            foreach ($u->scores as $s) {
                echo "score id={$s->id} category_scores_count={$s->category_scores_count} category_scores_total={$s->category_scores_total} (expect 3, 1149)\n";
            }
        }

        echo "\n=== 61. Top-level with_count()/with_sum() still unaffected (re-check after alias-matching fix) ===\n";
        $r61 = $this->db->select(['id', 'name'])
            ->with_count('scores', 'user_id', 'id')
            ->with_sum(['scores' => 'scores_total'], 'user_id', 'id', 'value')
            ->where('id', 1)
            ->get('users');
        foreach ($r61->result() as $u) {
            echo "user={$u->name} scores_count={$u->scores_count} scores_total={$u->scores_total} (expect 3, 90)\n";
        }

        echo "\n=== 62. Genuine RELATION-OF-A-RELATION (with_one/with_many nested INSIDE another relation callback), alias coincidentally ending in '_sum' ===\n";
        // This is NOT an aggregate at all -- it's a real nested with_one() relation
        // (scores -> owning user), going through the exact same load_relations()/
        // load_single_relation() matching code the alias-guessing fix touched.
        // 'users' table has no 'value' column, so this also sanity-checks the
        // "no coincidental value column" case for a nested (not top-level) relation.
        $r62 = $this->db->select(['id', 'name'])
            ->with_many('scores', 'user_id', 'id', function ($q) {
                $q->with_one(['users' => 'owner_sum'], 'id', 'user_id');
            })
            ->where('id', 1)
            ->get('users');
        foreach ($r62->result() as $u) {
            foreach ($u->scores as $s) {
                echo "score id={$s->id} owner_sum=";
                var_export($s->owner_sum);
                echo "\n(expect full owner_sum OBJECT with id=1,name=Alice,email,category -- NOT stripped/misdetected as an aggregate)\n";
            }
        }

        echo "\n=== 63a. query() override: with_sum() ALONE (no with_one/with_many) -- does the raw query() docblock example actually work? ===\n";
        // Per query()'s own docblock: "With aggregates" example shows with_sum()
        // used with query() directly and claims "Each row will have ->jobsum".
        // query() only checks $has_relations = !empty($this->with_relations) though --
        // with_sum() populates pending_aggregates, not with_relations.
        $r63a = $this->db->with_sum(['scores' => 'total'], 'user_id', 'id', 'value')
            ->query("SELECT id, name FROM users WHERE id = 1");
        echo get_class($r63a) . "\n";
        foreach ($r63a->result() as $u) {
            echo "has 'total' field: " . (property_exists($u, 'total') ? 'yes, value=' . $u->total : 'no') . "\n";
            echo "(per docblock, expect 'total'=90 to be present -- does it actually appear?)\n";
        }

        echo "\n=== 63b. Does pending_aggregates from 63a leak into the NEXT query on the same instance? ===\n";
        $refAgg = new ReflectionProperty($this->db, 'pending_aggregates');
        $refAgg->setAccessible(true);
        $leftover = $refAgg->getValue($this->db);
        echo "pending_aggregates still queued after 63a: " . (empty($leftover) ? 'no (empty)' : 'YES, ' . count($leftover) . ' entr(y/ies) leaked') . "\n";

        $r63b = $this->db->select(['id', 'name'])->where('id', 2)->get('users');
        foreach ($r63b->result() as $u) {
            echo "user={$u->name} has unexpected 'total' field: " . (property_exists($u, 'total') ? 'YES (leaked!) value=' . $u->total : 'no') . "\n";
            echo "(this query never called with_sum() itself -- 'total' should NOT be present)\n";
        }

        echo "\n=== 63c. Sanity: with_relations() combined with raw query() still genuinely works (the one case query() DOES support) ===\n";
        $r63c = $this->db->with_many('scores', 'user_id', 'id', function ($q) {
                $q->with_count('category_scores', 'user_id', 'user_id');
            })
            ->query("SELECT id, name FROM users WHERE id = 1");
        foreach ($r63c->result() as $u) {
            echo "user={$u->name} scores_count=" . count($u->scores) . "\n";
            foreach ($u->scores as $s) {
                echo "  score id={$s->id} category_scores_count={$s->category_scores_count} (expect 3)\n";
            }
        }

        echo "\n=== 64. BUG FIX regression: where_exists_relation() with an already-dotted foreign key, OUTSIDE group() (top-level, pending_where_exists path) ===\n";
        // Foreign key 'ms.user_id' is already qualified. Before the fix, process_pending_where_exists()
        // concatenated $relation_identifier onto it unconditionally, producing the invalid
        // double-qualified "ms.ms.user_id" and causing a DB error. process_single_where_exists()
        // (used inside group()) already handled this correctly via _qualify_key().
        try {
            $sql64 = $this->db->select(['id', 'name'])
                ->where_exists_relation('scores ms', 'ms.user_id', 'id')
                ->order_by('id', 'ASC')
                ->get_compiled_select('users');
            echo $sql64 . "\n";
            echo "(expect ms.user_id qualified exactly once -- NOT ms.ms.user_id)\n";

            $r64 = $this->db->select(['id', 'name'])
                ->where_exists_relation('scores ms', 'ms.user_id', 'id')
                ->order_by('id', 'ASC')
                ->get('users');
            $names64 = [];
            foreach ($r64->result() as $u) $names64[] = $u->name;
            echo "result: " . implode(',', $names64) . " (expect Alice only)\n";
        } catch (Exception $e) {
            echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
            $this->db->reset_query();
        }

        echo "\n=== 65. BUG FIX regression: _prefix_bare_identifiers() no longer mis-qualifies non-aggregate whitelisted SQL functions in a custom expression ===\n";
        // Before the fix, _prefix_bare_identifiers() only skipped a small hardcoded
        // keyword list (SUM/AVG/COUNT/MAX/MIN/CASE/...). ROUND is whitelisted in
        // $ALLOWED_SQL_FUNCTIONS (and is_valid_custom_expression() lets it through),
        // but wasn't in that local list, so it got wrongly qualified into
        // `sub`.`ROUND`(value, 2) -- invalid SQL. Now it reuses $ALLOWED_SQL_FUNCTIONS.
        try {
            $sql65 = $this->db->select(['id', 'name'])
                ->with_sum(['scores' => 'rounded_total'], 'user_id', 'id', 'ROUND(value, 2)', true)
                ->where('id', 1)
                ->get_compiled_select('users');
            echo $sql65 . "\n";
            echo "(expect ROUND(...) intact as a function call -- NOT `sub`.`ROUND`(value, 2))\n";

            $r65 = $this->db->select(['id', 'name'])
                ->with_sum(['scores' => 'rounded_total'], 'user_id', 'id', 'ROUND(value, 2)', true)
                ->where('id', 1)
                ->get('users');
            foreach ($r65->result() as $u) {
                echo "user={$u->name} rounded_total={$u->rounded_total} (expect 90)\n";
            }
        } catch (Exception $e) {
            echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
            $this->db->reset_query();
        }

        echo "\n=== 66. BUG FIX regression: is_valid_custom_expression() now rejects HAVING like is_valid_calculation_expression() already did ===\n";
        try {
            $this->db->select(['id', 'name'])
                ->with_avg(['scores' => 'x'], 'user_id', 'id', '1 HAVING 1=1', true)
                ->where('id', 1)
                ->get('users');
            echo "SHOULD HAVE THROWN (HAVING keyword must be rejected)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== 67. BUG FIX regression: join_count()/join_sum() etc. reject an empty relation cleanly instead of a PHP notice ===\n";
        try {
            $this->db->select(['id', 'name'])->join_count('', 'user_id', 'id')->get('users');
            echo "SHOULD HAVE THROWN (empty relation)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }
        try {
            $this->db->select(['id', 'name'])->join_count('   ', 'user_id', 'id')->get('users');
            echo "SHOULD HAVE THROWN (whitespace-only relation)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== 68. order_by_relation() backtick-quoting defense-in-depth (added on top of existing validation) ===\n";
        $this->db->query("CREATE TABLE IF NOT EXISTS profiles (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, rank_score INT)");
        $this->db->query("DELETE FROM profiles");
        $this->db->query("INSERT INTO profiles (user_id, rank_score) VALUES (1, 10), (2, 30), (3, 20)");

        echo "\n--- 68a. Compiled SQL now shows every identifier backtick-quoted ---\n";
        $sql68a = $this->db->select(['id', 'name'])
            ->order_by_relation('profiles', 'user_id', 'id', 'rank_score', 'DESC')
            ->get_compiled_select('users');
        echo $sql68a . "\n";
        $has_backticks = strpos($sql68a, '(SELECT `rank_score` FROM `profiles` WHERE `user_id` = `users`.`id` LIMIT 1) DESC') !== false;
        echo ($has_backticks ? "OK: subquery fully backtick-quoted\n" : "FAIL: expected fully-quoted subquery not found\n");

        echo "\n--- 68b. Execution unaffected by quoting: DESC actually orders by the related column ---\n";
        $r68b = $this->db->select(['id', 'name'])
            ->order_by_relation('profiles', 'user_id', 'id', 'rank_score', 'DESC')
            ->get('users');
        $names68b = [];
        foreach ($r68b->result() as $u) $names68b[] = $u->name;
        echo "DESC order: " . implode(',', $names68b) . " (expect Bob,Charlie,Alice -- rank_score 30,20,10)\n";

        $r68b2 = $this->db->select(['id', 'name'])
            ->order_by_relation('profiles', 'user_id', 'id', 'rank_score', 'ASC')
            ->get('users');
        $names68b2 = [];
        foreach ($r68b2->result() as $u) $names68b2[] = $u->name;
        echo "ASC order: " . implode(',', $names68b2) . " (expect Alice,Charlie,Bob -- rank_score 10,20,30)\n";

        echo "\n--- 68c. Aliased main table + dotted local key still qualifies and quotes correctly ---\n";
        $sql68c = $this->db->select(['u.id', 'u.name'])
            ->order_by_relation('profiles', 'user_id', 'u.id', 'rank_score', 'ASC')
            ->get_compiled_select('users u');
        echo $sql68c . "\n";
        $has_backticks_c = strpos($sql68c, '(SELECT `rank_score` FROM `profiles` WHERE `user_id` = `u`.`id` LIMIT 1) ASC') !== false;
        echo ($has_backticks_c ? "OK: aliased local key fully backtick-quoted\n" : "FAIL: expected fully-quoted aliased subquery not found\n");

        echo "\n--- 68d. Validation still rejects malicious identifiers (unchanged by the quoting change) ---\n";
        try {
            $this->db->order_by_relation('profiles; DROP TABLE users', 'user_id', 'id', 'rank_score');
            echo "SHOULD HAVE THROWN (invalid table name)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }
        try {
            $this->db->order_by_relation('profiles', 'user_id', 'id', 'rank_score`) UNION SELECT password FROM users --');
            echo "SHOULD HAVE THROWN (invalid column name)\n";
        } catch (InvalidArgumentException $e) {
            echo "correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\nALL TESTS DONE\n";
    }

    public function get()
    {
        $users = $this->db
            ->group(function($q) {
                $q->when(false, function($q) {
                    $q->where('name', 'Alice');
                });
                $q->group_start();
                    $q->when(true, function($q) {
                        $q->where('name', 'Alice');
                    });
                $q->group_end();
            })
            ->with_max('category_scores', ['user_id', 'category'], ['id', 'category'], 'value')
            ->get_compiled_select('users');
        echo ("$users");
    }
}
