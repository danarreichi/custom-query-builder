<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Relation Aggregate Trait
 *
 * Shared implementation for eager-loading relation/aggregate registration
 * methods (with, with_count/sum/avg/max/min, with_calculation, join_*,
 * where_aggregate/or_where_aggregate). Used by both CustomQueryBuilder
 * (top-level queries) and NestedQueryBuilder (relation callbacks) so both
 * contexts share identical validation and behavior.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait RelationAggregateTrait
{
    /**
     * @var array Pending WHERE conditions queued while inside a group() callback
     * (used by add_where_exists_relation_internal so grouping stays correct).
     * Only meaningful for CustomQueryBuilder — NestedQueryBuilder always uses
     * the pending_where_exists path since it has no group-context concept.
     */
    protected $pending_where_queue = [];

    /**
     * @var array Queue of pending order_by_relation() calls (resolved at get() time)
     */
    protected $pending_order_by_relations = [];

    /**
     * @var int Depth counter for nested group()/or_group() callbacks.
     * Only meaningful for CustomQueryBuilder; stays 0 for NestedQueryBuilder.
     */
    protected $_in_group_context = 0;

    /**
     * @var int Monotonically increasing counter used to preserve the original
     * call-order of deferred WHERE conditions (where_exists_relation, where_has,
     * where_aggregate, etc.) relative to each other and to plain where()/or_where()
     * calls that were already sitting in qb_where at capture time.
     */
    protected $_call_order_seq = 0;

    /**
     * @var array Buffer of deferred WHERE entries waiting to be spliced back into
     * qb_where at their originally-captured position. Populated by _defer_where_append()
     * and drained by _flush_where_reorder_buffer().
     */
    protected $_pending_where_reorder_buffer = [];

    /**
     * Resolve the object that actually owns the real $qb_where array.
     *
     * CustomQueryBuilder extends CI_DB_query_builder and owns qb_where directly.
     * NestedQueryBuilder is a plain wrapper around a $db instance (proxying
     * where()/or_where() via __call()) and has no qb_where of its own — for
     * reordering purposes it must always operate on $this->db instead.
     *
     * @return object
     */
    protected function _qbw_target()
    {
        return ($this instanceof NestedQueryBuilder) ? $this->db : $this;
    }

    /**
     * Return the current number of entries in qb_where. Public because it may
     * be invoked across object boundaries (NestedQueryBuilder calling on its
     * wrapped $db) where qb_where itself — being protected on CI_DB_query_builder —
     * would not be directly accessible.
     *
     * @return int
     */
    public function _qbw_count()
    {
        return count($this->qb_where);
    }

    /**
     * Allocate and return the next call-order sequence number. Used as a
     * tie-breaker when two deferred conditions were captured at the same
     * qb_where position (i.e. called back-to-back with no synchronous
     * where()/or_where() between them).
     *
     * @return int
     */
    public function _qbw_next_seq()
    {
        return $this->_call_order_seq++;
    }

    /**
     * Remove and return the last qb_where entry (the one just appended by
     * where()/or_where()).
     *
     * @return array
     */
    public function _qbw_pop()
    {
        return array_pop($this->qb_where);
    }

    /**
     * Splice a previously-popped qb_where entry back in at the given index.
     *
     * @param int $pos
     * @param array $entry
     * @return void
     */
    public function _qbw_splice($pos, $entry)
    {
        array_splice($this->qb_where, $pos, 0, [$entry]);
    }

    /**
     * Strip the leading AND/OR connector from whichever entry now sits at
     * qb_where[0] — CI3 only omits the connector for the entry that is
     * truly first, and reordering may have moved a different entry there.
     *
     * @return void
     */
    public function _qbw_strip_leading_glue()
    {
        if (!empty($this->qb_where) && isset($this->qb_where[0]['condition'])) {
            $this->qb_where[0]['condition'] = preg_replace('/^(AND |OR )/', '', $this->qb_where[0]['condition']);
        }
    }

    /**
     * Push one or more qb_where entries into the reorder buffer as a single
     * block — they will be spliced back in together, in the given order,
     * preserving their relative order. A single deferred WHERE condition
     * pushes a 1-element block; a deferred group() pushes every entry the
     * group produced (open bracket, inner conditions, close bracket) as one
     * block so the whole group moves as a unit.
     *
     * @param int $pos
     * @param int $seq
     * @param array $entries
     * @return void
     */
    public function _qbw_buffer_push($pos, $seq, $entries)
    {
        $this->_pending_where_reorder_buffer[] = ['pos' => $pos, 'seq' => $seq, 'entries' => $entries];
    }

    /**
     * Remove and return all buffered entries, clearing the buffer.
     *
     * @return array
     */
    public function _qbw_buffer_drain()
    {
        $buffer = $this->_pending_where_reorder_buffer;
        $this->_pending_where_reorder_buffer = [];
        return $buffer;
    }

    /**
     * Return the current buffer length, to be passed back into
     * _qbw_buffer_flush_from() later as a high-water mark.
     *
     * @return int
     */
    public function _qbw_buffer_mark()
    {
        return count($this->_pending_where_reorder_buffer);
    }

    /**
     * Splice back and remove only the buffer entries added SINCE the given
     * mark, leaving anything buffered before it untouched. Used by
     * _execute_group_immediately(): conditions registered before a deferred
     * group started (e.g. a where_has() flushed just ahead of the bracket)
     * belong to the outer scope and must stay buffered — resolving them here,
     * before the group's own block gets extracted and repositioned by
     * process_pending_groups(), would splice them at coordinates that go
     * stale the moment that repositioning happens. Only entries produced by
     * the group's own callback (added after the mark) are safe to resolve
     * immediately, since they must land inside this group's brackets, which
     * are about to become a single opaque moved block anyway.
     *
     * @param int $mark
     * @return void
     */
    public function _qbw_buffer_flush_from($mark)
    {
        $count = count($this->_pending_where_reorder_buffer);
        if ($count <= $mark)
            return;

        $buffer = array_slice($this->_pending_where_reorder_buffer, $mark);
        $this->_pending_where_reorder_buffer = array_slice($this->_pending_where_reorder_buffer, 0, $mark);

        usort($buffer, function ($a, $b) {
            if ($a['pos'] === $b['pos'])
                return $a['seq'] - $b['seq'];
            return $a['pos'] - $b['pos'];
        });

        $offset = 0;
        foreach ($buffer as $item) {
            foreach ($item['entries'] as $entry) {
                $this->_qbw_splice($item['pos'] + $offset, $entry);
                $offset++;
            }
        }
    }

    /**
     * Capture the current call-order position for a deferred WHERE condition.
     * Call this at the moment a pending condition is registered (e.g. inside
     * where_exists_relation(), where_has(), where_aggregate()) — NOT when it is
     * actually processed later in get() — so that its recorded position reflects
     * how many synchronous where()/or_where() calls preceded it in the chain.
     *
     * @return array{seq:int,pos:int}
     */
    protected function _capture_call_order()
    {
        $target = $this->_qbw_target();
        return ['seq' => $target->_qbw_next_seq(), 'pos' => $target->_qbw_count()];
    }

    /**
     * Append a compiled WHERE clause fragment, then immediately move it into the
     * reorder buffer instead of leaving it at the tail of qb_where. Used by every
     * deferred-condition processor (where_has, where_exists_relation, where_aggregate)
     * so that _flush_where_reorder_buffer() can later splice it back to the position
     * it was originally called at, preserving order relative to plain where()/or_where().
     *
     * @param string $clause Compiled SQL fragment (already escaped/validated by caller)
     * @param bool $is_or Whether this uses OR glue instead of AND
     * @param array{seq:int,pos:int}|null $order Captured position from _capture_call_order(), or null to skip reordering (append normally at the tail)
     * @return void
     */
    protected function _defer_where_append($clause, $is_or, $order = null)
    {
        if ($is_or) {
            $this->or_where($clause, null, false);
        } else {
            $this->where($clause, null, false);
        }

        if ($order === null)
            return;

        $target = $this->_qbw_target();
        $entry = $target->_qbw_pop();
        $target->_qbw_buffer_push($order['pos'], $order['seq'], [$entry]);
    }

    /**
     * Drain the reorder buffer, splicing each entry back into qb_where at its
     * originally-captured position (adjusted for entries already spliced in
     * ahead of it), so that the final WHERE clause reflects the order conditions
     * were actually called in — not the order their subqueries happened to
     * finish compiling in. Must be called after all pending processing for a
     * given get()/get_where()/get_compiled_select() call, right before compiling.
     *
     * @return void
     */
    protected function _flush_where_reorder_buffer()
    {
        $target = $this->_qbw_target();
        $buffer = $target->_qbw_buffer_drain();
        if (empty($buffer))
            return;

        usort($buffer, function ($a, $b) {
            if ($a['pos'] === $b['pos'])
                return $a['seq'] - $b['seq'];
            return $a['pos'] - $b['pos'];
        });

        $offset = 0;
        foreach ($buffer as $item) {
            foreach ($item['entries'] as $entry) {
                $target->_qbw_splice($item['pos'] + $offset, $entry);
                $offset++;
            }
        }

        $target->_qbw_strip_leading_glue();
    }

    /**
     * Return a high-water mark for the reorder buffer, to be passed to
     * _flush_where_reorder_buffer_from() later.
     *
     * @return int
     */
    protected function _mark_where_reorder_buffer()
    {
        return $this->_qbw_target()->_qbw_buffer_mark();
    }

    /**
     * Resolve only the buffer entries added since $mark — see
     * _qbw_buffer_flush_from() for why this must not touch entries buffered
     * before a deferred group() started.
     *
     * @param int $mark
     * @return void
     */
    protected function _flush_where_reorder_buffer_from($mark)
    {
        $this->_qbw_target()->_qbw_buffer_flush_from($mark);
    }

    /**
     * Add eager loading relation
     *
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param bool $multiple Whether relation returns multiple records
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     * @throws InvalidArgumentException
     */
    public function with($relation, $foreignKey, $localKey, $multiple = true, $callback = null)
    {
        $relation_name = '';
        $alias = '';

        if (!is_bool($multiple))
            throw new InvalidArgumentException('Parameter $multiple must be a boolean value (true or false).');

        if (is_array($relation)) {
            if (count($relation) !== 1) {
                throw new InvalidArgumentException('Parameter $relation array must contain exactly one element in the form ["relation_name" => "alias"].');
            }
            $relation_name = key($relation);
            $alias = current($relation);
        } else {
            $relation_name = $relation;
            $alias = $relation;
        }

        // VALIDASI KEAMANAN: validate the FULL relation string directly (documented
        // usage is always a bare table name; use the ['table' => 'alias'] array form
        // for an alias). Previously this validated extract_table_name($relation_name)
        // — only the first whitespace token — while storing/using $relation_name
        // (the original, full string) untouched, letting anything after the first
        // space through unchecked (e.g. "orders UNION SELECT ...-- ").
        if (!$this->is_valid_table_name($relation_name)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation_name}. Only alphanumeric characters and underscores are allowed.");
        }
        if (!$this->is_valid_table_name($alias)) {
            throw new InvalidArgumentException("Invalid relation alias: {$alias}. Only alphanumeric characters and underscores are allowed.");
        }

        $processed_local_keys = $this->process_keys($localKey, 'local key');
        $processed_foreign_keys = $this->process_keys($foreignKey, 'foreign key');
        $this->validate_key_count_match($processed_foreign_keys, $processed_local_keys);

        $this->with_relations[] = [
            'relation' => $relation_name,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'multiple' => $multiple,
            'callback' => $callback,
            'alias' => $alias
        ];
        return $this;
    }

    /**
     * Add eager loading relation with aggregation (internal helper)
     * 
     * @param string $type Aggregate type ('count', 'sum', 'avg', 'max', 'min')
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string|null $column Column to aggregate (null for count)
     * @param bool $is_custom_expression Whether $column is a custom SQL expression
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    protected function add_aggregate($type, $relation, $foreignKey, $localKey, $column = null, $is_custom_expression = false, $callback = null)
    {
        if (is_callable($is_custom_expression)) {
            $callback = $is_custom_expression;
            $is_custom_expression = true;
        }
        // Validate column if provided
        if ($column !== null) {
            $this->validate_column_or_expression($column, $is_custom_expression);
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $default_alias = $relation_name . '_' . $type;
        $aggregate_alias = is_array($relation) ? current($relation) : $default_alias;

        // VALIDASI KEAMANAN: this previously had NO validation at all on $relation
        // or the alias, even though both are concatenated directly into the
        // generated aggregate subquery's FROM clause and result alias.
        if (!$this->is_valid_table_name($relation_name)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation_name}. Only alphanumeric characters and underscores are allowed.");
        }
        if (!$this->is_valid_table_name($aggregate_alias)) {
            throw new InvalidArgumentException("Invalid aggregate alias: {$aggregate_alias}. Only alphanumeric characters and underscores are allowed.");
        }

        $foreign_keys = $this->process_keys($foreignKey, 'foreign key');
        $local_keys = $this->process_keys($localKey, 'local key');
        $this->validate_key_count_match($foreign_keys, $local_keys);

        $this->pending_aggregates[] = [
            'type' => $type,
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $aggregate_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with count aggregation
     * 
     * Now works as subquery in main SELECT clause for better sorting capability.
     * 
     * Example:
     * // Get users with their order count (can be sorted)
     * $users = $this->db->with_count('orders', 'user_id', 'id')
     *                   ->order_by('orders_count', 'DESC')
     *                   ->get('users');
     * // Result: $user->orders_count
     * 
     * // With alias
     * $this->db->with_count(['orders' => 'total_orders'], 'user_id', 'id');
     * // Result: $user->total_orders
     * 
     * // Can be used with with_many() and with_one()
     * $users = $this->db->with_count('orders', 'user_id', 'id')
     *                   ->with_many('posts', 'user_id', 'id')
     *                   ->get('users');
     * 
     * // Can be used in callbacks (for relation subqueries)
     * $posts = $this->db->with_many('comments', 'post_id', 'id', function($query) {
     *     $query->with_count('likes', 'comment_id', 'id');
     * })->get('posts');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_count($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->add_aggregate('count', $relation, $foreignKey, $localKey, null, false, $callback);
    }

    /**
     * Add eager loading relation with sum aggregation
     * 
     * Now works as subquery in main SELECT clause for better sorting capability.
     * 
     * Example:
     * // Get users with total order amount (can be sorted)
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->order_by('orders_sum', 'DESC')
     *                   ->get('users');
     * // Result: $user->orders_sum
     * 
     * // With alias
     * $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'total_amount');
     * // Result: $user->total_spent
     * 
     * // With custom expression (mathematical operations)
     * $invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
     *     'idinvoice', 'id', '(job_total_price_before_discount - job_discount)', true);
     * // Result: $invoice->total_after_discount
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('created_at >=', '2023-01-01');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
     *     'idinvoice', 'id', '(job_total_price_before_discount - job_discount)', true, 
     *     function($query) {
     *         $query->where('status', 'active');
     *     }
     * );
     * 
     * // Can be used with with_many() and with_one()
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->with_many('posts', 'user_id', 'id')
     *                   ->get('users');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to sum or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_sum($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        return $this->add_aggregate('sum', $relation, $foreignKey, $localKey, $column, $is_custom_expression, $callback);
    }

    /**
     * Add eager loading relation with average aggregation
     * 
     * Example:
     * // Get users with average order amount
     * $users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_avg
     * 
     * // With alias
     * $this->db->with_avg(['orders' => 'avg_order_value'], 'user_id', 'id', 'total_amount');
     * // Result: $user->avg_order_value
     * 
     * // With custom expression (mathematical operations)
     * $orders = $this->db->with_avg('items', 'order_id', 'id', '(price * quantity)', true);
     * // Result: $order->items_avg (average of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where_between('created_at', ['2023-01-01', '2023-12-31']);
     * })->get('users');
     * 
     * // With custom expression and callback
     * $orders = $this->db->with_avg('items', 'order_id', 'id', '(price * quantity)', true,
     *     function($query) {
     *         $query->where('is_active', 1);
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to calculate average or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_avg($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        return $this->add_aggregate('avg', $relation, $foreignKey, $localKey, $column, $is_custom_expression, $callback);
    }

    /**
     * Add eager loading relation with maximum value aggregation
     * 
     * Example:
     * // Get users with their highest order amount
     * $users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_max
     * 
     * // Get posts with latest comment date
     * $this->db->with_max(['comments' => 'latest_comment'], 'post_id', 'id', 'created_at');
     * // Result: $post->latest_comment
     * 
     * // With custom expression (mathematical operations)
     * $products = $this->db->with_max('sales', 'product_id', 'id', '(base_price + tax)', true);
     * // Result: $product->sales_max (maximum of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('payment_status', 'paid');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $products = $this->db->with_max('sales', 'product_id', 'id', '(base_price + tax)', true,
     *     function($query) {
     *         $query->where('sale_date >=', '2023-01-01');
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to find maximum value or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_max($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        return $this->add_aggregate('max', $relation, $foreignKey, $localKey, $column, $is_custom_expression, $callback);
    }

    /**
     * Add eager loading relation with minimum value aggregation
     * 
     * Example:
     * // Get users with their lowest order amount
     * $users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_min
     * 
     * // Get categories with earliest post date
     * $this->db->with_min(['posts' => 'first_post'], 'category_id', 'id', 'created_at');
     * // Result: $category->first_post
     * 
     * // With custom expression (mathematical operations)
     * $transactions = $this->db->with_min('payments', 'transaction_id', 'id', '(amount - discount)', true);
     * // Result: $transaction->payments_min (minimum of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('discount', 0);  // Orders without discount
     * })->get('users');
     * 
     * // With custom expression and callback
     * $transactions = $this->db->with_min('payments', 'transaction_id', 'id', '(amount - discount)', true,
     *     function($query) {
     *         $query->where('payment_method', 'cash')
     *               ->where('is_verified', 1);
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to find minimum value or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_min($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        return $this->add_aggregate('min', $relation, $foreignKey, $localKey, $column, $is_custom_expression, $callback);
    }

    // =================================================================
    // JOIN AGGREGATE METHODS (Derived Table JOIN — lighter DB load)
    // Pre-aggregates the relation table in a single subquery then JOINs
    // back to the main table — scans relation table once, regardless of
    // how many rows the main query returns.
    // Use these instead of with_sum/with_count/etc. when result sets
    // are large and you only need the scalar aggregate value.
    // =================================================================

    /**
     * Internal helper — register a derived-table JOIN aggregate
     *
     * @param string        $type       Aggregate type: count|sum|avg|min|max
     * @param string        $relation   Related table name or raw SQL subquery (e.g. "(SELECT ...) alias")
     * @param string|array  $foreignKey FK column(s) in the relation table
     * @param string|array  $localKey   Local key column(s) in the main table
     * @param string|null   $column     Column to aggregate (null for COUNT)
     * @param string|null   $alias      Result alias (auto-generated if null)
     * @param callable|null $callback   Optional callback to add WHERE inside derived table
     * @return $this
     * @throws InvalidArgumentException
     */
    protected function add_join_aggregate($type, $relation, $foreignKey, $localKey, $column = null, $alias = null, $callback = null)
    {
        // Detect raw SQL subquery passed as relation (e.g. "(SELECT ...) alias")
        // BUG FIX: an empty/all-whitespace $relation made ltrim($relation)[0] an
        // undefined-offset access before the "invalid table name" check below ever
        // ran. Guard the empty case first so it throws a clean InvalidArgumentException.
        $trimmed_relation = ltrim(is_string($relation) ? $relation : '');
        if ($trimmed_relation === '') {
            throw new InvalidArgumentException("join_{$type}: relation cannot be empty.");
        }
        $is_subquery_relation = $trimmed_relation[0] === '(';

        // VALIDASI KEAMANAN: previously validated only extract_table_name($relation)
        // (the first whitespace token) while storing/using the full $relation string,
        // letting anything after the first space through unchecked. Documented usage
        // is always a bare table name (aliasing goes through the ['table'=>'alias']
        // array form in join_count()/join_sum()/etc.), so validate the full string
        // directly instead of extracting a token from it.
        if (!$is_subquery_relation && !$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("join_{$type}: invalid relation table name '" . htmlspecialchars($relation) . "'");
        }

        $foreign_keys = $this->process_keys($foreignKey, 'foreign key');
        $local_keys = $this->process_keys($localKey, 'local key');

        if (count($foreign_keys) !== count($local_keys)) {
            throw new InvalidArgumentException("join_{$type}: foreign key count must match local key count.");
        }

        $type_lower = strtolower($type);

        // For subquery relations the "table name" used in agg functions and default
        // aliases is the alias portion of the expression (e.g. "transaction_sub"),
        // not the full subquery string. The subquery body itself is trusted raw SQL
        // (same trust model as CI's own raw from()/query() escape hatches — it's
        // developer-authored, not typically user input), but the extracted alias
        // ends up embedded verbatim in generated identifiers (_quote_agg_column(),
        // the default result alias) and MUST be validated on its own.
        if ($is_subquery_relation) {
            $relation_ref = $this->extract_table_or_alias($relation);
            if (!$this->is_valid_table_name($relation_ref)) {
                throw new InvalidArgumentException("join_{$type}: invalid subquery alias '" . htmlspecialchars($relation_ref) . "'");
            }
        } else {
            $relation_ref = $relation;
        }

        switch ($type_lower) {
            case 'count':
                $agg_func = 'COUNT(*)';
                $default_alias = $relation_ref . '_count';
                break;
            case 'sum':
                if (!$column)
                    throw new InvalidArgumentException('join_sum: $column is required.');
                if (!$this->is_valid_column_name($column))
                    throw new InvalidArgumentException("join_sum: invalid column name '" . htmlspecialchars($column) . "'");
                $agg_func = 'SUM(' . $this->_quote_agg_column($column, $relation_ref) . ')';
                $default_alias = $relation_ref . '_sum';
                break;
            case 'avg':
                if (!$column)
                    throw new InvalidArgumentException('join_avg: $column is required.');
                if (!$this->is_valid_column_name($column))
                    throw new InvalidArgumentException("join_avg: invalid column name '" . htmlspecialchars($column) . "'");
                $agg_func = 'AVG(' . $this->_quote_agg_column($column, $relation_ref) . ')';
                $default_alias = $relation_ref . '_avg';
                break;
            case 'min':
                if (!$column)
                    throw new InvalidArgumentException('join_min: $column is required.');
                if (!$this->is_valid_column_name($column))
                    throw new InvalidArgumentException("join_min: invalid column name '" . htmlspecialchars($column) . "'");
                $agg_func = 'MIN(' . $this->_quote_agg_column($column, $relation_ref) . ')';
                $default_alias = $relation_ref . '_min';
                break;
            case 'max':
                if (!$column)
                    throw new InvalidArgumentException('join_max: $column is required.');
                if (!$this->is_valid_column_name($column))
                    throw new InvalidArgumentException("join_max: invalid column name '" . htmlspecialchars($column) . "'");
                $agg_func = 'MAX(' . $this->_quote_agg_column($column, $relation_ref) . ')';
                $default_alias = $relation_ref . '_max';
                break;
            case 'custom_calculation':
                if (!$column)
                    throw new InvalidArgumentException('join_calculation: $expression is required.');
                if (!$this->is_valid_calculation_expression($column)) {
                    throw new InvalidArgumentException("join_calculation: invalid expression '" . htmlspecialchars($column) . "'");
                }
                $agg_func = $column; // raw expression, e.g. SUM(a) / SUM(b) * 100
                $default_alias = $relation_ref . '_calculation';
                break;
            default:
                throw new InvalidArgumentException("Invalid join aggregate type: {$type}");
        }

        $final_alias = $alias ?: $default_alias;
        if (!$this->is_valid_table_name($final_alias)) {
            throw new InvalidArgumentException("join_{$type}: invalid alias '" . htmlspecialchars($final_alias) . "'");
        }

        $this->pending_join_aggregates[] = [
            'type' => $type_lower,
            'relation' => $relation,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'aggregate' => $agg_func,
            'alias' => $final_alias,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Derived-table JOIN COUNT — lighter DB load than with_count()
     *
     * Scans the relation table once via GROUP BY instead of a correlated subquery per row.
     *
     * Example:
     * $users = $this->db->join_count('orders', 'user_id', 'id')
     *                   ->order_by('orders_count', 'DESC')
     *                   ->get('users');
     * // $user->orders_count
     *
     * // With alias + callback filter
     * $users = $this->db->join_count(['orders' => 'completed_orders'], 'user_id', 'id', function($q) {
     *     $q->where('status', 'completed');
     * })->get('users');
     * // $user->completed_orders
     *
     * @param string|array  $relation   Table name or ['table' => 'alias']
     * @param string|array  $foreignKey FK column(s) in relation table
     * @param string|array  $localKey   PK/local column(s) in main table
     * @param callable|null $callback   Optional WHERE conditions inside derived table
     * @return $this
     */
    public function join_count($relation, $foreignKey, $localKey, $callback = null)
    {
        $alias = is_array($relation) ? current($relation) : null;
        $relation_name = is_array($relation) ? key($relation) : $relation;
        return $this->add_join_aggregate('count', $relation_name, $foreignKey, $localKey, null, $alias, $callback);
    }

    /**
     * Derived-table JOIN SUM — lighter DB load than with_sum()
     *
     * Scans the relation table once via GROUP BY instead of a correlated subquery per row.
     *
     * Example:
     * $users = $this->db->join_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->order_by('orders_sum', 'DESC')
     *                   ->get('users');
     * // $user->orders_sum
     *
     * // With alias
     * $users = $this->db->join_sum(['orders' => 'total_spent'], 'user_id', 'id', 'total_amount')
     *                   ->get('users');
     * // $user->total_spent
     *
     * // With callback
     * $users = $this->db->join_sum('orders', 'user_id', 'id', 'total_amount', null, function($q) {
     *     $q->where('status', 'completed');
     * })->get('users');
     *
     * @param string|array  $relation   Table name or ['table' => 'alias']
     * @param string|array  $foreignKey FK column(s) in relation table
     * @param string|array  $localKey   PK/local column(s) in main table
     * @param string        $column     Column to SUM
     * @param string|null   $alias      Override result alias (optional when using array syntax)
     * @param callable|null $callback   Optional WHERE conditions inside derived table
     * @return $this
     */
    public function join_sum($relation, $foreignKey, $localKey, $column, $callback = null)
    {
        $resolved_alias = is_array($relation) ? current($relation) : null;
        $relation_name = is_array($relation) ? key($relation) : $relation;
        return $this->add_join_aggregate('sum', $relation_name, $foreignKey, $localKey, $column, $resolved_alias, $callback);
    }

    /**
     * Derived-table JOIN AVG — lighter DB load than with_avg()
     *
     * Example:
     * $users = $this->db->join_avg('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // $user->orders_avg
     *
     * @param string|array  $relation   Table name or ['table' => 'alias']
     * @param string|array  $foreignKey FK column(s) in relation table
     * @param string|array  $localKey   PK/local column(s) in main table
     * @param string        $column     Column to AVG
     * @param string|null   $alias      Override result alias
     * @param callable|null $callback   Optional WHERE conditions inside derived table
     * @return $this
     */
    public function join_avg($relation, $foreignKey, $localKey, $column, $callback = null)
    {
        $resolved_alias = is_array($relation) ? current($relation) : null;
        $relation_name = is_array($relation) ? key($relation) : $relation;
        return $this->add_join_aggregate('avg', $relation_name, $foreignKey, $localKey, $column, $resolved_alias, $callback);
    }

    /**
     * Derived-table JOIN MIN — lighter DB load than with_min()
     *
     * Example:
     * $users = $this->db->join_min('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // $user->orders_min
     *
     * @param string|array  $relation   Table name or ['table' => 'alias']
     * @param string|array  $foreignKey FK column(s) in relation table
     * @param string|array  $localKey   PK/local column(s) in main table
     * @param string        $column     Column to MIN
     * @param string|null   $alias      Override result alias
     * @param callable|null $callback   Optional WHERE conditions inside derived table
     * @return $this
     */
    public function join_min($relation, $foreignKey, $localKey, $column, $callback = null)
    {
        $resolved_alias = is_array($relation) ? current($relation) : null;
        $relation_name = is_array($relation) ? key($relation) : $relation;
        return $this->add_join_aggregate('min', $relation_name, $foreignKey, $localKey, $column, $resolved_alias, $callback);
    }

    /**
     * Derived-table JOIN MAX — lighter DB load than with_max()
     *
     * Example:
     * $users = $this->db->join_max(['orders' => 'highest_order'], 'user_id', 'id', 'total_amount')
     *                   ->get('users');
     * // $user->highest_order
     *
     * @param string|array  $relation   Table name or ['table' => 'alias']
     * @param string|array  $foreignKey FK column(s) in relation table
     * @param string|array  $localKey   PK/local column(s) in main table
     * @param string        $column     Column to MAX
     * @param string|null   $alias      Override result alias
     * @param callable|null $callback   Optional WHERE conditions inside derived table
     * @return $this
     */
    public function join_max($relation, $foreignKey, $localKey, $column, $callback = null)
    {
        $resolved_alias = is_array($relation) ? current($relation) : null;
        $relation_name = is_array($relation) ? key($relation) : $relation;
        return $this->add_join_aggregate('max', $relation_name, $foreignKey, $localKey, $column, $resolved_alias, $callback);
    }

    /**
     * Derived-table JOIN CALCULATION — lighter DB load than with_calculation()
     *
     * Pre-aggregates the relation table once in a derived-table JOIN using a custom
     * mathematical expression. Scans the relation table once regardless of how many
     * rows the main query returns.
     *
     * Use this instead of with_calculation() when result sets are large.
     *
     * Example:
     * // Efficiency: (finished / total) * 100
     * $orders = $this->db->join_calculation(
     *     ['order_items' => 'efficiency_percentage'],
     *     'order_id', 'id',
     *     '(SUM(finished_qty) / SUM(total_qty)) * 100'
     * )->get('orders');
     * // $order->efficiency_percentage
     *
     * // Profit margin with optional callback filter
     * $products = $this->db->join_calculation(
     *     ['sales' => 'profit_margin'],
     *     'product_id', 'id',
     *     '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100',
     *     function($q) { $q->where('status', 'completed'); }
     * )->get('products');
     * // $product->profit_margin
     *
     * // Production duration using DATEDIFF
     * $transactions = $this->db->join_calculation(
     *     ['transaction_step' => 'production_duration_days'],
     *     'idtransaction_detail', 'idtransaction_detail',
     *     'DATEDIFF(MAX(date), MIN(date))'
     * )->get('transaction_detail');
     *
     * Supported in expression:
     * - Basic math: +, -, *, /, %
     * - Aggregate functions: SUM, AVG, COUNT, MIN, MAX
     * - Date functions: DATEDIFF, TIMESTAMPDIFF
     * - Conditional: CASE WHEN ... THEN ... END
     * - Mathematical functions: ROUND, FLOOR, CEIL, ABS
     *
     * @param string|array  $relation    Relation table name or ['table' => 'alias']
     * @param string|array  $foreignKey  FK column(s) in the relation table
     * @param string|array  $localKey    PK/local column(s) in the main table
     * @param string        $expression  Mathematical expression with aggregate functions
     * @param callable|null $callback    Optional WHERE conditions inside derived table
     * @return $this
     * @throws InvalidArgumentException
     */
    public function join_calculation($relation, $foreignKey, $localKey, $expression, $callback = null)
    {
        $resolved_alias = is_array($relation) ? current($relation) : null;
        $relation_name = is_array($relation) ? key($relation) : $relation;
        return $this->add_join_aggregate('custom_calculation', $relation_name, $foreignKey, $localKey, $expression, $resolved_alias, $callback);
    }

    /**
     * Add calculated field using custom mathematical expression with aggregate functions
     * 
     * This method allows you to create complex calculations using multiple aggregate functions
     * and mathematical operations in a subquery that becomes part of the main SELECT clause.
     * 
     * Example:
     * // Calculate efficiency percentage: (finished_qty / total_qty) * 100
     * $orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'], 
     *     'order_id', 'id', 
     *     '(SUM(finished_qty) / SUM(total_qty)) * 100'
     * )->get('orders');
     * // Result: $order->efficiency_percentage
     * 
     * // Calculate profit margin: ((revenue - cost) / revenue) * 100
     * $products = $this->db->with_calculation(['sales' => 'profit_margin'], 
     *     'product_id', 'id',
     *     '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100'
     * )->get('products');
     * 
     * // Calculate average order value with discount
     * $customers = $this->db->with_calculation(['orders' => 'avg_order_with_discount'], 
     *     'customer_id', 'id',
     *     'AVG(total_amount - discount_amount)'
     * )->get('customers');
     * 
     * // Calculate production duration in days using DATEDIFF
     * $transactions = $this->db->with_calculation(['transaction_step' => 'production_duration_days'], 
     *     'idtransaction_detail', 'idtransaction_detail',
     *     'DATEDIFF(MAX(date), MIN(date))'
     * )->get('transaction_detail');
     * 
     * // Calculate weighted average with callback for conditions
     * $products = $this->db->with_calculation(['reviews' => 'weighted_rating'], 
     *     'product_id', 'id',
     *     'SUM(rating * helpful_votes) / SUM(helpful_votes)',
     *     function($query) {
     *         $query->where('status', 'approved')
     *               ->where('helpful_votes >', 0);
     *     }
     * )->get('products');
     * 
     * // Multiple calculations in one query
     * $orders = $this->db->with_calculation(['order_items' => 'total_revenue'], 'order_id', 'id', 'SUM(price * quantity)')
     *                   ->with_calculation(['order_items' => 'total_cost'], 'order_id', 'id', 'SUM(cost * quantity)')
     *                   ->with_calculation(['order_items' => 'profit'], 'order_id', 'id', 'SUM((price - cost) * quantity)')
     *                   ->get('orders');
     * 
     * Supported mathematical operations:
     * - Basic math: +, -, *, /, %
     * - Aggregate functions: SUM, AVG, COUNT, MIN, MAX
     * - Date functions: DATEDIFF, TIMESTAMPDIFF
     * - Conditional: CASE WHEN ... THEN ... END
     * - Mathematical functions: ROUND, FLOOR, CEIL, ABS
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s) in the relation table
     * @param string|array $localKey Local key(s) in the main table
     * @param string $expression Mathematical expression with aggregate functions
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional WHERE conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function with_calculation($relation, $foreignKey, $localKey, $expression, $callback = null)
    {
        if (!is_callable($callback) && $callback)
            throw new InvalidArgumentException('Callback must be callable');
        if (!$this->is_valid_calculation_expression($expression)) {
            throw new InvalidArgumentException("Invalid calculation expression: {$expression}");
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $calc_alias = is_array($relation) ? current($relation) : $relation_name . '_calculation';

        // VALIDASI KEAMANAN: this previously had NO validation at all on $relation,
        // the alias, or the foreign/local keys, even though $relation is concatenated
        // directly into the generated aggregate subquery's FROM clause.
        if (!$this->is_valid_table_name($relation_name)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation_name}. Only alphanumeric characters and underscores are allowed.");
        }
        if (!$this->is_valid_table_name($calc_alias)) {
            throw new InvalidArgumentException("Invalid calculation alias: {$calc_alias}. Only alphanumeric characters and underscores are allowed.");
        }

        $foreign_keys = $this->process_keys($foreignKey, 'foreign key');
        $local_keys = $this->process_keys($localKey, 'local key');
        $this->validate_key_count_match($foreign_keys, $local_keys);

        $this->pending_aggregates[] = [
            'type' => 'custom_calculation',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $calc_alias,
            'callback' => $callback,
            'column' => $expression,
            'is_custom_expression' => true
        ];

        return $this;
    }

    /**
     * Add WHERE condition based on calculated field alias (simplified syntax)
     *
     * This method provides a simplified way to filter by aggregate fields that were
     * added using with_calculation(), with_sum(), with_avg(), with_min(), or with_max().
     * It automatically references the aggregate subquery in the WHERE clause.
     *
     * IMPORTANT: You must call with_calculation() or with_sum()/with_avg()/etc. BEFORE
     * calling where_aggregate() so the alias is registered.
     *
     * Example:
     * // Filter by calculated field
     * $this->db->with_calculation(['transaction_detail' => 'sales_price'], 'idtransaction', 'idtransaction', 'SUM(price)')
     *          ->where_aggregate('sales_price >', 10000)
     *          ->get('transaction');
     *
     * // Filter by sum aggregate
     * $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'amount')
     *          ->where_aggregate('total_spent >=', 5000)
     *          ->get('users');
     *
     * // Multiple conditions
     * $this->db->with_avg(['reviews' => 'avg_rating'], 'product_id', 'id', 'rating')
     *          ->where_aggregate('avg_rating >', 4.5)
     *          ->where_aggregate('avg_rating <', 5.0)
     *          ->get('products');
     *
     * // With OR condition
     * $this->db->with_sum(['orders' => 'total_amount'], 'user_id', 'id', 'amount')
     *          ->where_aggregate('total_amount >', 10000)
     *          ->or_where_aggregate('total_amount =', 0)
     *          ->get('users');
     *
     * @param string $condition Condition string in format "alias operator" (e.g., "sales_price >", "total_count >=")
     * @param mixed $value Value to compare against
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_aggregate($condition, $value)
    {
        return $this->add_where_calculated('AND', $condition, $value);
    }

    /**
     * Add OR WHERE condition based on calculated field alias (simplified syntax)
     *
     * Example:
     * $this->db->with_sum(['orders' => 'total_amount'], 'user_id', 'id', 'amount')
     *          ->where_aggregate('total_amount >', 5000)
     *          ->or_where_aggregate('total_amount =', 0)
     *          ->get('users');
     *
     * @param string $condition Condition string in format "alias operator"
     * @param mixed $value Value to compare against
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_aggregate($condition, $value)
    {
        return $this->add_where_calculated('OR', $condition, $value);
    }

    /**
     * Internal method to add WHERE condition for calculated fields
     *
     * @param string $condition_type 'AND' or 'OR'
     * @param string $condition Condition string with alias and operator
     * @param mixed $value Value to compare against
     * @return $this
     * @throws InvalidArgumentException
     */
    protected function add_where_calculated($condition_type, $condition, $value)
    {
        // Parse condition string to extract alias and operator
        $pattern = '/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(=|>|<|>=|<=|!=|<>|BETWEEN|NOT\\s+BETWEEN)\s*$/i';
        if (!preg_match($pattern, trim($condition), $matches)) {
            throw new InvalidArgumentException("Invalid condition format: '{$condition}'. Expected format: 'alias operator' (e.g., 'sales_price >', 'total_count >=', 'sales_price BETWEEN')");
        }

        $alias = $matches[1];
        // Normalize case and collapse internal whitespace ("not   between" /
        // "Not Between" both become "NOT BETWEEN") — add_where_aggregate()'s
        // $allowed_operators list only contains the uppercase, single-spaced
        // forms, so a lowercase or multi-spaced match here used to be rejected
        // downstream with a confusing "Invalid operator" error.
        $operator = strtoupper(preg_replace('/\s+/', ' ', trim($matches[2])));

        // Find the aggregate configuration by alias
        $aggregate_config = null;
        foreach ($this->pending_aggregates as $config) {
            if ($config['alias'] === $alias) {
                $aggregate_config = $config;
                break;
            }
        }

        if ($aggregate_config === null) {
            throw new InvalidArgumentException("Alias '{$alias}' not found. You must call with_calculation(), with_sum(), with_avg(), with_min(), or with_max() with this alias before using where_calculated().");
        }

        // Map aggregate type from pending_aggregates to where_aggregate type
        $type_mapping = [
            'count' => 'count',
            'sum' => 'sum',
            'avg' => 'avg',
            'min' => 'min',
            'max' => 'max',
            'custom_calculation' => 'custom'
        ];

        $aggregate_type = isset($type_mapping[$aggregate_config['type']])
            ? $type_mapping[$aggregate_config['type']]
            : 'custom';

        // Use the aggregate configuration to build where_aggregate
        return $this->add_where_aggregate(
            $condition_type,
            $aggregate_type,
            $aggregate_config['relation'],
            $aggregate_config['foreign_key'],
            $aggregate_config['local_key'],
            $operator,
            $value,
            $aggregate_config['column'],
            $aggregate_config['is_custom_expression'],
            $aggregate_config['callback']
        );
    }

    /**
     * Internal method to add WHERE aggregate condition
     *
     * @param string $condition_type 'AND' or 'OR'
     * @param string $type Aggregate type
     * @param string $relation Related table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $operator Comparison operator
     * @param mixed $value Value to compare against
     * @param string|null $column Column name or expression
     * @param bool $is_custom_expression Whether column is custom expression
     * @param callable|null $callback Optional callback
     * @return $this
     * @throws InvalidArgumentException
     */
    protected function add_where_aggregate($condition_type, $type, $relation, $foreignKey, $localKey, $operator, $value, $column = null, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: defense in depth — $relation normally arrives here
        // already validated (from add_aggregate()/with_calculation()'s pending
        // config via add_where_calculated(), or directly from where_aggregate()
        // callers), but this method had no validation of its own, so any new or
        // future caller that passes an unvalidated relation would silently skip
        // the check entirely.
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation}. Only alphanumeric characters and underscores are allowed.");
        }

        // Validate aggregate type
        $allowed_types = ['count', 'sum', 'avg', 'min', 'max', 'custom'];
        $type = strtolower($type);
        if (!in_array($type, $allowed_types)) {
            throw new InvalidArgumentException("Invalid aggregate type: {$type}. Allowed types: " . implode(', ', $allowed_types));
        }

        // Validate operator
        $allowed_operators = array_merge(self::$ALLOWED_OPERATORS, ['BETWEEN', 'NOT BETWEEN']);
        if (!in_array($operator, $allowed_operators)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}. Allowed operators: " . implode(', ', $allowed_operators));
        }

        // BUG FIX: BETWEEN/NOT BETWEEN require exactly 2 values. Without this
        // check, process_pending_where_aggregates() indexes $value[0]/$value[1]
        // directly — a 1-element array silently compiled to "BETWEEN 1 AND NULL"
        // (always false, no error) and a 3+ element array silently dropped
        // everything past the second value, instead of failing loudly.
        if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException("Operator '{$operator}' requires \$value to be an array with exactly 2 elements.");
            }
        }

        // Validate column for non-count types
        if ($type !== 'count' && empty($column)) {
            throw new InvalidArgumentException("Column is required for aggregate type: {$type}");
        }

        // Validate is_custom_expression is boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        // Validate column/expression
        if ($column !== null) {
            if ($type === 'custom') {
                if (!$this->is_valid_calculation_expression($column)) {
                    throw new InvalidArgumentException("Invalid calculation expression: {$column}");
                }
            } elseif ($is_custom_expression) {
                if (!$this->is_valid_custom_expression($column)) {
                    throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
                }
            } else {
                if (!$this->is_valid_column_name($column)) {
                    throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
                }
            }
        }

        // Process foreign keys
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        foreach ($foreign_keys_array as $fk) {
            // Don't extract - keep full identifier
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key: {$fk}. Only alphanumeric characters and underscores are allowed.");
            }
            $processed_foreign_keys[] = $fk;  // Keep as is
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];
        foreach ($local_keys_array as $lk) {
            // Don't extract - keep full identifier
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key: {$lk}. Only alphanumeric characters and underscores are allowed.");
            }
            $processed_local_keys[] = $lk;  // Keep as is
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Number of foreign keys must match number of local keys');
        }

        // Store pending where aggregate
        $this->pending_where_aggregates[] = [
            'condition_type' => $condition_type,
            'type' => $type,
            'relation' => $relation,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'operator' => $operator,
            'value' => $value,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression,
            'callback' => $callback,
            '_order' => $this->_capture_call_order()
        ];

        return $this;
    }

    /**
     * Internal helper for WHERE EXISTS/NOT EXISTS relation conditions
     *
     * @param string $condition_type 'AND' or 'OR'
     * @param string $exists_type 'EXISTS' or 'NOT EXISTS'
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable|null $callback Optional callback
     * @param bool $disable_pending_process If true, execute immediately for OR conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    protected function add_where_exists_relation_internal($condition_type, $exists_type, $relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)
    {
        // VALIDASI KEAMANAN: validate the FULL relation string directly — this
        // previously validated only extract_table_name($relation) (the first
        // whitespace token) while storing/using the full $relation string, letting
        // anything after the first space through unchecked. Use is_valid_relation_string()
        // rather than is_valid_table_name() so the documented "table alias" /
        // "table AS alias" syntax (see docblocks below) actually validates —
        // is_valid_table_name() rejects any whitespace at all, which made every
        // aliased $relation throw despite being the advertised usage.
        if (!$this->is_valid_relation_string($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        $processed_local_keys = $this->process_keys($localKey, 'local key column name', false);
        $processed_foreign_keys = $this->process_keys($foreignKey, 'foreign key column name', false);
        $this->validate_key_count_match($processed_foreign_keys, $processed_local_keys, 'Foreign keys and local keys count must match');

        // Create pending operation data
        $pending_item = [
            'type' => $condition_type,
            'exists_type' => $exists_type,
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback,
            '_order' => $this->_capture_call_order()
        ];

        // disable_pending_process, being inside a group() context, or both, all route
        // through pending_where_queue: it preserves this condition's call-order position
        // relative to other where()/or_where() calls while still resolving the parent
        // table lazily at get()-time (via process_pending_where_queue()) — so it works
        // whether the table was set with from() up front or passed to get('table') later.
        // Without either flag, the condition is batched into pending_where_exists instead.
        if ($disable_pending_process || $this->_in_group_context > 0) {
            $this->pending_where_queue[] = [
                'type' => 'where_exists',
                'data' => $pending_item
            ];
        } else {
            $this->pending_where_exists[] = $pending_item;
        }

        return $this;
    }

    /**
     * Add WHERE EXISTS condition with relation support (simplified version)
     * 
     * This method provides a simplified interface for WHERE EXISTS queries
     * by automatically building the JOIN conditions based on foreign/local keys,
     * similar to how where_has() works.
     * 
     * Example:
     * // Users that have orders
     * $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id');
     * 
     * // Users that have active orders  
     * $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id', function($query) {
     *     $query->where('status', 'active');
     * });
     * 
     * // Multiple foreign keys
     * $this->db->from('users')->where_exists_relation('user_roles', ['user_id', 'tenant_id'], ['id', 'tenant_id']);
     * 
     * // Marketing SPK with transactions and delivery
     * $this->db->from('outlet')->where_exists_relation('marketing_spk', 'idspk_workshop', 'idoutlet', function($query) {
     *     $query->join('transaction t', 't.idmarketing_spk = marketing_spk.idmarketing_spk AND t.idoutlet = marketing_spk.idspk_workshop AND t.status = 1', 'inner')
     *           ->join('transaction_delivery td', 'td.idtransaction = t.idtransaction', 'inner')
     *           ->where('marketing_spk.status', 1);
     * });
     * 
     * @param string $relation Target table name (may include optional alias: "table_name alias" or "table_name AS alias")
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->add_where_exists_relation_internal('AND', 'EXISTS', $relation, $foreignKey, $localKey, $callback);
    }

    /**
     * Add OR WHERE EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that have orders OR users that have posts
     * $this->db->from('users')
     *          ->where_exists_relation('orders', 'user_id', 'id')
     *          ->or_where_exists_relation('posts', 'user_id', 'id');
     * 
     * @param string $relation Target table name (may include optional alias: "table_name alias" or "table_name AS alias")
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @param bool $disable_pending_process If true, execute immediately instead of queueing
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_exists_relation($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)
    {
        return $this->add_where_exists_relation_internal('OR', 'EXISTS', $relation, $foreignKey, $localKey, $callback, $disable_pending_process);
    }

    /**
     * Add WHERE NOT EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that don't have any orders
     * $this->db->from('users')->where_not_exists_relation('orders', 'user_id', 'id');
     * 
     * @param string $relation Target table name (may include optional alias: "table_name alias" or "table_name AS alias")
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->add_where_exists_relation_internal('AND', 'NOT EXISTS', $relation, $foreignKey, $localKey, $callback);
    }

    /**
     * Add OR WHERE NOT EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that have orders OR users that don't have cancelled orders
     * $this->db->from('users')
     *          ->where_exists_relation('orders', 'user_id', 'id')
     *          ->or_where_not_exists_relation('orders', 'user_id', 'id', function($query) {
     *              $query->where('status', 'cancelled');
     *          });
     * 
     * @param string $relation Target table name (may include optional alias: "table_name alias" or "table_name AS alias")
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @param bool $disable_pending_process If true, execute immediately instead of queueing
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)
    {
        return $this->add_where_exists_relation_internal('OR', 'NOT EXISTS', $relation, $foreignKey, $localKey, $callback, $disable_pending_process);
    }
}
