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

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add EXISTS condition
        $this->db->where("EXISTS ({$compiled_subquery})", null, false);

        return $this;
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

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add NOT EXISTS condition
        $this->db->where("NOT EXISTS ({$compiled_subquery})", null, false);

        return $this;
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

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add OR EXISTS condition
        $this->db->or_where("EXISTS ({$compiled_subquery})", null, false);

        return $this;
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

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add OR NOT EXISTS condition
        $this->db->or_where("NOT EXISTS ({$compiled_subquery})", null, false);

        return $this;
    }


}
