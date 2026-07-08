<?php
defined('BASEPATH') or exit('No direct script access allowed');


/**
 * Nested Query Builder Class
 * 
 * Provides nested query capabilities with relation support
 * 
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
class NestedQueryBuilder
{
    use QueryValidationTrait;
    use RelationAggregateTrait;
    /**
     * @var array Array of with relations
     */
    public $with_relations = [];

    /**
     * @var array Array of pending aggregate functions
     */
    public $pending_aggregates = [];

    /**
     * @var array Array of pending WHERE EXISTS relations
     */
    public $pending_where_exists = [];

    /**
     * @var array Array of pending WHERE aggregate conditions
     */
    public $pending_where_aggregates = [];

    /**
     * @var array Array of pending derived-table JOIN aggregates
     */
    public $pending_join_aggregates = [];

    /**
     * @var object Database instance
     */
    public $db;

    /**
     * Constructor
     * 
     * @param object $db_instance Database instance
     */
    public function __construct($db_instance)
    {
        $this->db = $db_instance;
    }

    /**
     * Magic method to call database methods
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed Method result
     */
    public function __call($method, $args)
    {
        $result = call_user_func_array([$this->db, $method], $args);

        // BUG FIX: chainable query-builder methods (select(), from(), where(), ...)
        // return $this->db itself, not this wrapper. Passing that straight back
        // used to silently drop out of the NestedQueryBuilder proxy mid-chain
        // (e.g. $q->select('1')->from('posts')->where_exists(...) would continue
        // the chain on the raw driver object after select()) — currently masked
        // only because CustomQueryBuilder happens to redefine the same
        // where_exists()-family methods itself. Surface $this instead whenever
        // the proxied call returns the wrapped db instance, so the chain keeps
        // going through this proxy.
        return $result === $this->db ? $this : $result;
    }

    /**
     * Transfer this wrapper's own queued relation/aggregate state onto $target_db
     * and process it there.
     *
     * with(), with_count()/with_sum()/with_avg()/with_min()/with_max()/
     * with_calculation(), where_aggregate()/or_where_aggregate(),
     * where_exists_relation()/where_not_exists_relation() (and their or_
     * variants), where_has()/or_where_has(), and join_count()/join_sum()/etc.
     * all come from RelationAggregateTrait, which this class also uses — so
     * calling them on a NestedQueryBuilder instance queues their pending
     * state on the WRAPPER's own properties (declared above), not on the
     * $db it wraps. Anything that only proxies through __call() (like
     * get_compiled_select()) never sees that state. Callers must invoke this
     * before compiling the wrapped query, or the queued conditions/columns
     * are silently dropped.
     *
     * @param CustomQueryBuilder $target_db
     * @return void
     */
    protected function _flush_own_pending_state_into($target_db)
    {
        // NestedQueryBuilder does not extend CustomQueryBuilder (it wraps one via
        // composition), so it cannot write $target_db's protected pending_* properties
        // directly — PHP's protected visibility only allows cross-instance access from
        // within a method of the SAME class. merge_pending_relation_state() is the
        // public hand-off point defined on CustomQueryBuilder for exactly this.
        $target_db->merge_pending_relation_state(
            $this->with_relations,
            $this->pending_aggregates,
            $this->pending_where_exists,
            $this->pending_where_aggregates,
            $this->pending_join_aggregates,
            isset($this->pending_where_has) ? $this->pending_where_has : []
        );

        $this->with_relations = [];
        $this->pending_aggregates = [];
        $this->pending_where_exists = [];
        $this->pending_where_aggregates = [];
        $this->pending_join_aggregates = [];
        $this->pending_where_has = [];

        // $target_db is always a single-column `SELECT 1` EXISTS subquery here — skip
        // flushing pending_aggregates as a 2nd SELECT column, which would break that shape.
        $target_db->flush_pending_relation_state(null, false);
    }

    /**
     * Shared implementation for where_exists()/where_not_exists()/or_where_exists()/
     * or_where_not_exists() below — see CustomQueryBuilder::add_where_exists_callback()
     * for the equivalent consolidation on the non-nested side. All four public
     * methods used to carry an identical ~35-line body, differing only in whether
     * "NOT " prefixes the EXISTS keyword and whether where()/or_where() is used
     * to attach the compiled clause.
     *
     * @param string $condition_type 'AND' or 'OR'
     * @param string $exists_type 'EXISTS' or 'NOT EXISTS'
     * @param callable(NestedQueryBuilder): void $callback Callback to build the subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    protected function add_where_exists_callback($condition_type, $exists_type, $callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        // Clone $this->db before wrapping it — without this, building the subquery
        // (select()/from()/where() calls proxied via __call()) mutates the SAME
        // db instance the caller is still using, and get_compiled_select()'s default
        // $reset=true then wipes it out entirely (qb_from included), producing
        // "Error 1096: No tables used" on whatever query the caller builds next.
        // reset_query() afterwards clears whatever qb_from/qb_where the clone
        // inherited from the caller's in-progress query, so the subquery starts
        // clean instead of e.g. carrying over the caller's own FROM/WHERE.
        $subquery_db = clone $this->db;
        $subquery_db->reset_query();
        $subquery = new NestedQueryBuilder($subquery_db);

        // Execute callback to build subquery
        $callback($subquery);

        // Transfer + flush any relation/aggregate state queued directly on
        // $subquery (with(), with_count()/with_sum()/etc., where_aggregate(),
        // where_exists_relation(), where_has(), join_count()/etc. are all
        // defined on NestedQueryBuilder itself, so calls made on $subquery
        // store their pending state on the WRAPPER's own properties, not on
        // $subquery_db — without this, get_compiled_select() below (proxied
        // straight to $subquery_db) has no idea any of it was queued and
        // silently drops it from the compiled EXISTS subquery.
        $subquery->_flush_own_pending_state_into($subquery_db);

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add [NOT] EXISTS condition
        $clause = "{$exists_type} ({$compiled_subquery})";
        if ($condition_type === 'OR') {
            $this->db->or_where($clause, null, false);
        } else {
            $this->db->where($clause, null, false);
        }

        return $this;
    }

    /**
     * Add WHERE EXISTS condition with callback
     *
     * Example:
     * // Check if user has published posts
     * $users = $this->db->where_exists(function($query) {
     *     $query->select('1')
     *           ->from('posts')
     *           ->where('posts.user_id = users.id')
     *           ->where('status', 'published');
     * });
     *
     * // Check if outlet has transactions with delivery
     * $outlets = $this->db->where_exists(function($query) {
     *     $query->select('1')
     *           ->from('marketing_spk ms')
     *           ->join('transaction t', 't.idmarketing_spk = ms.idmarketing_spk', 'inner')
     *           ->where('ms.idspk_workshop = outlet.idoutlet')
     *           ->where('t.status', 1);
     * });
     *
     * @param callable(NestedQueryBuilder): void $callback Callback to build EXISTS subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_exists($callback)
    {
        return $this->add_where_exists_callback('AND', 'EXISTS', $callback);
    }

    /**
     * Add WHERE NOT EXISTS condition with callback
     *
     * Example:
     * // Users that don't have any published posts
     * $users = $this->db->where_not_exists(function($query) {
     *     $query->select('1')
     *           ->from('posts')
     *           ->where('posts.user_id = users.id')
     *           ->where('status', 'published');
     * });
     *
     * // Outlets without any completed transactions
     * $outlets = $this->db->where_not_exists(function($query) {
     *     $query->select('1')
     *           ->from('marketing_spk ms')
     *           ->join('transaction t', 't.idmarketing_spk = ms.idmarketing_spk', 'inner')
     *           ->where('ms.idspk_workshop = outlet.idoutlet')
     *           ->where('t.status', 'completed');
     * });
     *
     * @param callable(NestedQueryBuilder): void $callback Callback to build NOT EXISTS subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_not_exists($callback)
    {
        return $this->add_where_exists_callback('AND', 'NOT EXISTS', $callback);
    }

    /**
     * Add OR WHERE EXISTS condition with callback
     *
     * Example:
     * // Users that have orders OR have posts
     * $users = $this->db->where_exists(function($query) {
     *     $query->select('1')
     *           ->from('orders')
     *           ->where('orders.user_id = users.id');
     * })->or_where_exists(function($query) {
     *     $query->select('1')
     *           ->from('posts')
     *           ->where('posts.user_id = users.id');
     * });
     *
     * @param callable(NestedQueryBuilder): void $callback Callback to build EXISTS subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_exists($callback)
    {
        return $this->add_where_exists_callback('OR', 'EXISTS', $callback);
    }

    /**
     * Add OR WHERE NOT EXISTS condition with callback
     *
     * Example:
     * // Users that have orders OR don't have cancelled orders
     * $users = $this->db->where_exists(function($query) {
     *     $query->select('1')
     *           ->from('orders')
     *           ->where('orders.user_id = users.id')
     *           ->where('status', 'active');
     * })->or_where_not_exists(function($query) {
     *     $query->select('1')
     *           ->from('orders')
     *           ->where('orders.user_id = users.id')
     *           ->where('status', 'cancelled');
     * });
     *
     * @param callable(NestedQueryBuilder): void $callback Callback to build NOT EXISTS subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_not_exists($callback)
    {
        return $this->add_where_exists_callback('OR', 'NOT EXISTS', $callback);
    }


}
