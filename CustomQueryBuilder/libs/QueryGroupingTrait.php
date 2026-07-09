<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Query Grouping Trait
 *
 * group()/or_group() and the raw group_start()/group_end() overrides, plus
 * the deferred pending_groups queue used when a group is registered before
 * the parent table is known.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait QueryGroupingTrait
{
    /**
     * Override of the native group_start() (and, by extension, or_group_start() /
     * not_group_start() / or_not_group_start(), which all call this internally)
     * to record the bracket-open position for the empty-group protection in
     * group_end() below.
     *
     * BUG FIX: CI's native group_start() decides whether to omit the AND/OR
     * connector before the opening "(" the same way where()/_safe_in_clause()
     * do — by checking if qb_where/qb_cache_where is currently empty. An
     * earlier-registered deferred condition (where_has(), where_aggregate(),
     * etc. — see _has_unresolved_deferred_conditions()) doesn't occupy a
     * qb_where slot until it's flushed, so a group() opened right after one
     * used to see an artificially empty qb_where and wrongly omit the
     * connector before "(", producing invalid SQL like
     * "EXISTS (...) ( `name` = 'Alice' OR ... )" with no AND in between.
     * Same placeholder trick as _append_where_reserving_glue(): seed a
     * throwaway entry so CI computes the connector correctly, then remove it.
     *
     * @param string $not
     * @param string $type
     * @return $this
     */
    public function group_start($not = '', $type = 'AND ')
    {
        // Decide THIS bracket's own connector using whatever was pending
        // relative to the OUTER scope — i.e. BEFORE pushing this bracket's
        // own baseline snapshot below, which would otherwise make any
        // outer-scope pending condition look "already accounted for".
        $would_suppress = $this->_would_wrongly_suppress_connector();

        // Snapshot qb_where_group_started as it was walking IN, alongside the
        // bracket-open position — see group_end()'s BUG FIX below for why.
        $this->_manual_group_stack[] = [
            'pos' => count($this->qb_where),
            'was_group_started' => $this->qb_where_group_started,
        ];

        // Everything pending right now (including whatever this bracket's
        // own connector decision above just accounted for) becomes the floor
        // for _has_unresolved_deferred_conditions() from here on — only
        // conditions registered AFTER entering this bracket should be able
        // to affect connector decisions made inside it. See that method's
        // docblock and _would_wrongly_suppress_connector().
        $this->_deferred_snapshot_stack[] = $this->_snapshot_deferred_counts();

        if ($would_suppress) {
            $needs_placeholder = empty($this->qb_where) && empty($this->qb_cache_where);
            $this->qb_where_group_started = false;
            if ($needs_placeholder) {
                $this->qb_where[] = ['condition' => '(1=1)', 'escape' => false];
            }
            parent::group_start($not, $type);
            if ($needs_placeholder) {
                array_splice($this->qb_where, 0, 1);
            }
            return $this;
        }

        return parent::group_start($not, $type);
    }

    /**
     * Override of the native group_end() — if nothing was added between the
     * matching group_start() and this call (e.g. conditional logic in between
     * ended up adding no conditions), remove the bracket-open entry instead of
     * emitting "( )", which MySQL rejects with a syntax error. Mirrors the
     * same protection group()/or_group() already get via
     * _execute_group_immediately(), for callers using the raw
     * group_start()/group_end() pair directly.
     *
     * BUG FIX: when the vanishing bracket was itself the first thing inside
     * an outer, still-open bracket (qb_where_group_started was TRUE when it
     * opened), unconditionally clearing the flag to FALSE here left the
     * OUTER bracket thinking its first condition had already been "used up"
     * by this now-vanished inner bracket — so the next real condition added
     * to the outer bracket wrongly got an AND/OR connector prefixed, even
     * though (with the empty inner bracket gone) it's actually still the
     * true first entry inside the outer bracket. Restore whatever the flag
     * was before this bracket opened instead of always clearing it.
     *
     * @return $this
     */
    public function group_end()
    {
        array_pop($this->_deferred_snapshot_stack);
        $stack_entry = array_pop($this->_manual_group_stack);
        $bracket_open_pos = is_array($stack_entry) ? $stack_entry['pos'] : $stack_entry;

        if ($bracket_open_pos !== null && count($this->qb_where) - 1 === $bracket_open_pos) {
            array_pop($this->qb_where);
            $this->qb_where_group_started = is_array($stack_entry) ? $stack_entry['was_group_started'] : false;
            return $this;
        }

        return parent::group_end();
    }

    /**
     * Group WHERE conditions with callback
     *
     * Example:
     * $this->db->where('status', 'active')
     *          ->group(function($query) {
     *              $query->where('name', 'John')
     *                    ->or_where('name', 'Jane');
     *          });
     * // Generates: WHERE `status` = 'active' AND (`name` = 'John' OR `name` = 'Jane')
     * 
     * @param callable(CustomQueryBuilder): void $callback Callback function to build grouped conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function group($callback)
    {
        if (!is_callable($callback))
            throw new InvalidArgumentException('Callback must be callable');

        // Execute immediately when the table is already known (from() was called or we are
        // already inside process_pending_groups()), so the WHERE clause order is preserved.
        // Only defer when neither is true (table will be supplied later via get('table')).
        if (
            $this->_in_group_context > 0
            || !empty($this->_temp_table_name)
            || !empty($this->qb_from)
        ) {
            return $this->_execute_group_immediately('AND', $callback);
        }

        $this->pending_groups[] = ['type' => 'AND', 'callback' => $callback, '_order' => $this->_capture_call_order()];
        return $this;
    }

    /**
     * Group WHERE conditions with OR operator and callback
     * 
     * Example:
     * $this->db->where('status', 'active')
     *          ->or_group(function($query) {
     *              $query->where('name', 'John')
     *                    ->where('age', '>', 18);
     *          });
     * // Generates: WHERE `status` = 'active' OR (`name` = 'John' AND `age` > 18)
     * 
     * // Multiple OR groups
     * $this->db->where('status', 'active')
     *          ->or_group(function($query) {
     *              $query->where('role', 'admin');
     *          })
     *          ->or_group(function($query) {
     *              $query->where('role', 'moderator')
     *                    ->where('verified', 1);
     *          });
     * // Generates: WHERE `status` = 'active' OR (`role` = 'admin') OR (`role` = 'moderator' AND `verified` = 1)
     * 
     * @param callable(CustomQueryBuilder): void $callback Callback function to build grouped conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_group($callback)
    {
        if (!is_callable($callback))
            throw new InvalidArgumentException('Callback must be callable');

        // Same as group() — execute immediately when the table is already known.
        if (
            $this->_in_group_context > 0
            || !empty($this->_temp_table_name)
            || !empty($this->qb_from)
        ) {
            return $this->_execute_group_immediately('OR', $callback);
        }

        $this->pending_groups[] = ['type' => 'OR', 'callback' => $callback, '_order' => $this->_capture_call_order()];
        return $this;
    }

    /**
     * Internal helper — executes a group callback immediately with proper bracket wrapping.
     * Used by group(), or_group(), and process_pending_groups().
     *
     * @param string $type 'AND' or 'OR'
     * @param callable $callback
     * @return $this
     */
    protected function _execute_group_immediately($type, $callback)
    {
        $this->_in_group_context++;

        // If there are pending WHERE-aggregate conditions that were added
        // before this deferred group, process them now so they appear
        // outside the group parentheses. We snapshot and clear the
        // pending list to avoid mixing with conditions produced inside
        // the group callback.
        if (!empty($this->pending_where_aggregates)) {
            $parent_table = $this->_temp_table_name;
            if (empty($parent_table) && !empty($this->qb_from)) {
                $parent_table = $this->qb_from[0];
            }

            // Only flush if we know the parent table; otherwise leave them
            // pending so get() can flush them once the table is available.
            if (!empty($parent_table)) {
                $backup_pending_where_aggregates = $this->pending_where_aggregates;
                $this->pending_where_aggregates = [];

                // Temporarily restore the backup into pending and process
                // so the resulting WHERE conditions are added before the group.
                $this->pending_where_aggregates = $backup_pending_where_aggregates;
                $this->process_pending_where_aggregates($parent_table);

                // Ensure no leftover pending aggregates remain
                $this->pending_where_aggregates = [];
            }
        }
        // Similar handling for pending where_has conditions. Conditions
        // defined prior to the group should appear outside the parentheses
        // rather than being swallowed by the empty group. We flush them
        // before opening the bracket and clear the pending list so that
        // any new where_has calls inside the callback will be processed
        // later (inside the group) separately.
        if (!empty($this->pending_where_has)) {
            $backup_pending_where_has = $this->pending_where_has;
            $this->pending_where_has = [];

            // Restore the backup into pending and process so the resulting
            // WHERE condition is added before the group.
            $this->pending_where_has = $backup_pending_where_has;
            $this->process_pending_where_has();

            // ensure nothing remains
            $this->pending_where_has = [];
        }

        // Mark the buffer here, BEFORE the bracket opens. Anything already
        // buffered above (the pre-group where_has/aggregates flush) belongs
        // to the outer scope and must stay buffered — resolving it now would
        // splice into qb_where, corrupting the pure-append assumption
        // process_pending_groups() relies on to measure how many entries
        // this group produced. Only entries added AFTER this mark (i.e.
        // inside the callback below) are safe to resolve before group_end().
        $group_mark = $this->_mark_where_reorder_buffer();

        if ($type === 'OR') {
            $this->or_group_start();
        } else {
            $this->group_start();
        }

        // Position of this group's own bracket-open entry, just pushed by
        // group_start()/or_group_start() above — used below to fix up a
        // deferred condition (where_has(), where_aggregate(), etc.) that got
        // queued inside the callback as the bracket's first real content.
        $bracket_open_entry = end($this->_manual_group_stack);
        $bracket_open_pos = is_array($bracket_open_entry) ? $bracket_open_entry['pos'] : null;

        try {
            $callback($this);

            // Recursively flush any nested deferred groups before closing this bracket
            if (!empty($this->pending_groups)) {
                $this->process_pending_groups();
            }

            // Flush pending WHERE EXISTS queue accumulated inside the callback
            if (!empty($this->pending_where_queue)) {
                $parent_table = $this->_temp_table_name;
                if (empty($parent_table) && !empty($this->qb_from)) {
                    $parent_table = $this->qb_from[0];
                }
                $this->process_pending_where_queue($parent_table);
            }

            // Flush pending WHERE aggregates accumulated inside the callback.
            // Only flush if we know the parent table; otherwise leave them
            // pending so get() can flush them once the table is available.
            if (!empty($this->pending_where_aggregates)) {
                $parent_table = $this->_temp_table_name;
                if (empty($parent_table) && !empty($this->qb_from)) {
                    $parent_table = $this->qb_from[0];
                }
                if (!empty($parent_table)) {
                    $this->process_pending_where_aggregates($parent_table);
                }
            }

            // Flush pending where_has conditions generated inside the callback
            // so they become part of the same grouped expression instead of
            // being appended after the closing parenthesis.
            if (!empty($this->pending_where_has)) {
                $this->process_pending_where_has();
            }

            $this->_flush_where_reorder_buffer_from($group_mark);

            // BUG FIX: a deferred condition (where_has(), where_aggregate(),
            // where_exists_relation(), etc.) queued inside the callback as
            // this bracket's first real content can't reliably know, at the
            // moment it's captured, whether it will truly end up first once
            // spliced back into place — by then, qb_where_group_started may
            // already have been consumed (e.g. by a plain where() call also
            // added inside the callback) and/or qb_where is no longer empty,
            // so it wrongly gets a baked-in AND/OR connector. Whatever now
            // sits immediately after this bracket's own opening "(" is,
            // definitionally, the bracket's true first entry — strip any
            // connector it ended up with.
            if ($bracket_open_pos !== null) {
                $this->_qbw_target()->_qbw_strip_glue_at($bracket_open_pos + 1);
            }
        } catch (Exception $e) {
            $this->_in_group_context--;
            $this->group_end();
            throw $e;
        }

        $this->_in_group_context--;

        // group_end() (overridden below) detects on its own whether anything
        // was actually added since the matching group_start() and unwinds an
        // empty group instead of emitting "( )".
        $this->group_end();
        return $this;
    }

    protected function process_pending_groups()
    {
        if (empty($this->pending_groups))
            return;

        $groups = $this->pending_groups;
        $this->pending_groups = [];

        foreach ($groups as $group) {
            $order = isset($group['_order']) ? $group['_order'] : null;
            $target = $this->_qbw_target();
            $before = $order !== null ? $target->_qbw_count() : null;

            $this->_execute_group_immediately($group['type'], $group['callback']);

            // The group was deferred because the table wasn't known yet at call time,
            // meaning it always runs here — appended at the tail, after whatever
            // synchronous where()/or_where() calls came later in the chain. Move the
            // whole block of entries it just produced (open bracket, inner conditions,
            // close bracket) back to its original call-order position, as a unit.
            if ($order !== null) {
                $after = $target->_qbw_count();
                $n = $after - $before;
                if ($n > 0) {
                    $entries = [];
                    for ($i = 0; $i < $n; $i++) {
                        array_unshift($entries, $target->_qbw_pop());
                    }
                    $target->_qbw_buffer_push($order['pos'], $order['seq'], $entries);
                }
            }
        }
    }
}
