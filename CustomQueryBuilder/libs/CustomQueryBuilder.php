<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Custom Query Builder Class
 * 
 * Extended CodeIgniter Query Builder with enhanced features including:
 * - Eager loading relationships
 * - Advanced where conditions
 * - Search functionality
 * - Pagination helpers
 * - Aggregation methods
 * - Chunking capabilities
 * 
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 * @extends CI_DB_query_builder
 */
class CustomQueryBuilder extends CI_DB_query_builder
{
    use QueryValidationTrait;
    use RelationAggregateTrait;
    use WhereConditionsTrait;
    use WhereExistsHasTrait;
    use AggregateProcessingTrait;
    use QueryGroupingTrait;
    use EagerLoadingTrait;
    use ConditionalHelpersTrait;
    use ChunkingTrait;

    /**
     * Controls how with_one()/with_many() pick a row when several relation
     * rows match the same local key.
     *
     * true (default, fixed behavior): the FIRST matching row is kept, so an
     * order_by() inside the relation callback is respected — e.g.
     * ->with_one('scores', ..., function ($q) { $q->order_by('value', 'DESC'); })
     * correctly keeps the highest-value row.
     *
     * false (pre-fix behavior): the LAST matching row wins instead, ignoring
     * the intent of order_by(). Only flip this to false to temporarily
     * reproduce the old, order-ignoring behavior (e.g. while auditing code
     * that may have been written against — or accidentally depends on — the
     * old bug). Do not ship with this set to false.
     */
    const FIX_WITH_ONE_ORDER_BY = true;

    /**
     * @var array Array of with relations for eager loading
     */
    protected $with_relations = [];

    /**
     * @var array Array of pending where has conditions
     */
    protected $pending_where_has = [];

    /**
     * @var array Array of pending WHERE EXISTS relations
     */
    protected $pending_where_exists = [];

    /**
     * @var array Array of pending aggregate functions
     */
    protected $pending_aggregates = [];

    /**
     * @var array Array of pending join aggregate functions (derived table JOIN approach)
     */
    protected $pending_join_aggregates = [];

    /**
     * @var array Array of pending WHERE aggregate conditions
     */
    protected $pending_where_aggregates = [];

    /**
     * @var array Queue of pending group() / or_group() callbacks (deferred until get())
     */
    protected $pending_groups = [];

    /**
     * @var bool Debug mode flag
     */
    protected $debug = true;

    /**
     * @var bool Flag to enable SQL_CALC_FOUND_ROWS
     */
    protected $_calc_rows_enabled = false;

    /**
     * @var int|null Cached result of the most recent calc_rows() query, set by
     * get_with_calc_rows(). get_found_rows() reads this instead of re-querying
     * MySQL's FOUND_ROWS() directly — that function only reflects the row
     * count of the immediately preceding SELECT, and get_with_calc_rows()
     * itself already runs a "SELECT FOUND_ROWS()" query internally to build
     * the returned CustomQueryBuilderResult, which "used up" that value
     * before get_found_rows() (the documented backward-compatible way to
     * read it) ever got a chance to query it again.
     */
    protected $_last_found_rows = null;

    /**
     * @var string|null Temporary storage for table name from get() method
     */
    protected $_temp_table_name = null;

    /**
     * @var array Executed queries including eager loading queries.
     *            Populated during get() calls that use eager loading.
     */
    protected $_executed_queries = [];

    /**
     * @var array Stack of bracket-open positions for in-flight raw group_start()
     * calls, used by group_end() to detect and unwind an empty group. A stack
     * (not a single value) because group_start()/group_end() pairs can nest.
     */
    protected $_manual_group_stack = [];

    /**
     * @var array Stack of pending-queue-size snapshots, one per currently-open
     * bracket, pushed by group_start() and popped by group_end() — see
     * _has_unresolved_deferred_conditions() for why this needs to be
     * bracket-scope-aware rather than a single global check.
     */
    protected $_deferred_snapshot_stack = [];

    // =================================================================
    // PARENT METHOD OVERRIDES FOR PROPER TYPE CHAINING IN IDE
    // =================================================================

    /**
     * True if some earlier-registered-but-not-yet-materialized deferred
     * condition (where_has(), where_aggregate(), where_exists_relation(),
     * a deferred group(), etc.) will still land in qb_where once flushed.
     *
     * BUG FIX: where()/or_where()/_safe_in_clause()/group_start() decide
     * whether to omit the leading AND/OR connector by checking whether
     * qb_where (and qb_cache_where) is CURRENTLY empty, and/or whether
     * qb_where_group_started is set (CI's "first condition right after an
     * open bracket" flag). A deferred condition doesn't occupy a slot in
     * qb_where — and doesn't touch qb_where_group_started — until it's
     * actually flushed (at get() time, inside a group()'s callback
     * completion, or by a manual process_where_has()/etc. call). So a plain
     * where()/or_where()/where_in()/group_start() call chained (or nested
     * inside a group() callback) right after one of these, but before it's
     * flushed, wrongly grabs the "I'm first" slot — either because qb_where
     * looks empty, or because qb_where_group_started is still sitting TRUE
     * from an enclosing group_start() — even though the deferred condition
     * will end up sitting in front of it (or, for the group_started case,
     * is the one that should rightfully consume that slot instead). Two
     * consecutive deferred conditions with nothing else in between hit the
     * same trap against each other. See _would_wrongly_suppress_connector().
     *
     * BRACKET-SCOPE AWARENESS: this must only count conditions registered
     * SINCE the current (innermost) bracket was entered, not ones already
     * pending from an OUTER scope — an outer-scope deferred condition's
     * relationship to the bracket as a whole was already resolved by the
     * bracket-open's own connector decision (see group_start()); it must not
     * ALSO suppress the connector for real content added immediately inside
     * the bracket, which is genuinely first relative to that bracket. Compare
     * current pending counts against the snapshot taken when the innermost
     * currently-open bracket started (see $_deferred_snapshot_stack) rather
     * than checking raw non-emptiness.
     *
     * @return bool
     */
    protected function _has_unresolved_deferred_conditions()
    {
        $baseline = end($this->_deferred_snapshot_stack);
        if ($baseline === false) {
            $baseline = $this->_zeroed_deferred_snapshot();
        }

        return count($this->pending_where_has) > $baseline['where_has']
            || count($this->pending_where_aggregates) > $baseline['where_aggregates']
            || count($this->pending_where_queue) > $baseline['where_queue']
            || count($this->pending_where_exists) > $baseline['where_exists']
            || count($this->pending_groups) > $baseline['groups']
            || count($this->_pending_where_reorder_buffer) > $baseline['reorder_buffer'];
    }

    /**
     * @return array
     */
    protected function _zeroed_deferred_snapshot()
    {
        return ['where_has' => 0, 'where_aggregates' => 0, 'where_queue' => 0, 'where_exists' => 0, 'groups' => 0, 'reorder_buffer' => 0];
    }

    /**
     * Snapshot of every pending-deferred-condition queue's current size, plus
     * the reorder buffer's — pushed onto $_deferred_snapshot_stack by
     * group_start() so _has_unresolved_deferred_conditions() can tell "new
     * since entering this bracket" apart from "already pending before it".
     *
     * @return array
     */
    protected function _snapshot_deferred_counts()
    {
        return [
            'where_has' => count($this->pending_where_has),
            'where_aggregates' => count($this->pending_where_aggregates),
            'where_queue' => count($this->pending_where_queue),
            'where_exists' => count($this->pending_where_exists),
            'groups' => count($this->pending_groups),
            'reorder_buffer' => count($this->_pending_where_reorder_buffer),
        ];
    }

    /**
     * True if the NEXT where()/or_where()/where_in()/group_start() call would
     * wrongly have its connector suppressed by one of CI's two independent
     * "am I first?" checks (qb_where/qb_cache_where empty, or
     * qb_where_group_started), when it shouldn't be — because an
     * earlier-registered deferred condition (_has_unresolved_deferred_conditions())
     * hasn't materialized yet and rightfully belongs in front of it.
     *
     * @return bool
     */
    protected function _would_wrongly_suppress_connector()
    {
        return $this->_has_unresolved_deferred_conditions()
            && ((empty($this->qb_where) && empty($this->qb_cache_where)) || $this->qb_where_group_started);
    }

    /**
     * Append a where()/or_where() condition while forcing CI to compute its
     * connector (AND/OR) as if this were not the first condition — used when
     * _would_wrongly_suppress_connector() says CI would otherwise omit it
     * incorrectly. Clears qb_where_group_started up front (CI's
     * _group_get_type(), called inside parent::where()/or_where(), would
     * otherwise treat this as "first right after an open bracket" and
     * suppress the connector regardless of qb_where's size). If qb_where is
     * ALSO genuinely empty, additionally seeds a throwaway placeholder
     * (always at index 0, since qb_where is empty beforehand) so CI's
     * emptiness check doesn't ALSO suppress it, then removes the placeholder
     * immediately, leaving only the real entry/entries (correctly
     * connector-prefixed) behind. Array $key (multi-condition where()) is
     * handled the same way since the placeholder is removed by position, not
     * by count.
     *
     * @param bool $is_or
     * @param mixed $key
     * @param mixed $value
     * @param bool $escape
     * @return $this
     */
    protected function _append_where_reserving_glue($is_or, $key, $value, $escape)
    {
        $needs_placeholder = empty($this->qb_where) && empty($this->qb_cache_where);
        $this->qb_where_group_started = false;

        if ($needs_placeholder) {
            $this->qb_where[] = ['condition' => '(1=1)', 'escape' => false];
        }
        if ($is_or) {
            parent::or_where($key, $value, $escape);
        } else {
            parent::where($key, $value, $escape);
        }
        if ($needs_placeholder) {
            array_splice($this->qb_where, 0, 1);
        }
        return $this;
    }

    /**
     * Override parent where() method to maintain proper return type for IDE
     *
     * @param mixed $key
     * @param mixed $value
     * @param bool $escape
     * @return $this|CustomQueryBuilder
     */
    public function where($key, $value = null, $escape = null)
    {
        if ($this->_would_wrongly_suppress_connector()) {
            return $this->_append_where_reserving_glue(false, $key, $value, $escape);
        }
        return parent::where($key, $value, $escape);
    }

    /**
     * Override parent or_where() method for the same reason as where() above
     * — see _has_unresolved_deferred_conditions().
     *
     * @param mixed $key
     * @param mixed $value
     * @param bool $escape
     * @return $this|CustomQueryBuilder
     */
    public function or_where($key = null, $value = null, $escape = null)
    {
        if ($this->_would_wrongly_suppress_connector()) {
            return $this->_append_where_reserving_glue(true, $key, $value, $escape);
        }
        return parent::or_where($key, $value, $escape);
    }

    // --------------------------------------------------------------------

    /**
     * Override parent select() method to maintain proper return type for IDE
     * 
     * @param string $select
     * @param mixed $escape
     * @return $this|CustomQueryBuilder
     */
    public function select($select = '*', $escape = null)
    {
        return parent::select($select, $escape);
    }

    /**
     * Override parent from() method to maintain proper return type for IDE
     * 
     * @param string $from
     * @return $this|CustomQueryBuilder
     */
    public function from($from)
    {
        // Set temp table name for pending where_exists_relation processing
        if (is_string($from)) {
            $this->_temp_table_name = $from;
            $trimmed_from = trim($from);
            if ($this->is_raw_subquery_from($trimmed_from)) {
                $this->_track_aliases($trimmed_from);
                $this->qb_from[] = $trimmed_from;
                return $this;
            }
        } elseif (is_array($from) && count($from) > 0) {
            $this->_temp_table_name = reset($from);

            $safe_from = [];
            foreach ($from as $item) {
                if (is_string($item)) {
                    $trimmed_item = trim($item);
                    if ($this->is_raw_subquery_from($trimmed_item)) {
                        $this->_track_aliases($trimmed_item);
                        $this->qb_from[] = $trimmed_item;
                        continue;
                    }
                }
                $safe_from[] = $item;
            }

            if (empty($safe_from))
                return $this;
            if (count($safe_from) === 1)
                return parent::from(reset($safe_from));
            return parent::from($safe_from);
        }
        return parent::from($from);
    }

    /**
     * Detect raw subquery FROM expression: (SELECT ...) alias
     *
     * @param string $from
     * @return bool
     */
    protected function is_raw_subquery_from($from)
    {
        return is_string($from)
            && preg_match('/^\(.*\)\s+(?:AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*$/is', $from);
    }

    /**
     * Override parent join() method to maintain proper return type for IDE
     * 
     * @param string $table
     * @param string $cond
     * @param string $type
     * @param bool $escape
     * @return $this|CustomQueryBuilder
     */
    public function join($table, $cond, $type = '', $escape = null)
    {
        return parent::join($table, $cond, $type, $escape);
    }

    /**
     * Override parent limit() method to maintain proper return type for IDE
     * 
     * @param int $value
     * @param int $offset
     * @return $this|CustomQueryBuilder
     */
    public function limit($value, $offset = 0)
    {
        return parent::limit($value, $offset);
    }

    /**
     * Override parent order_by() method to maintain proper return type for IDE
     * 
     * @param string $orderby
     * @param string $direction
     * @param bool $escape
     * @return $this|CustomQueryBuilder
     */
    public function order_by($orderby, $direction = '', $escape = null)
    {
        return parent::order_by($orderby, $direction, $escape);
    }

    /**
     * Override parent group_by() method to maintain proper return type for IDE
     * 
     * @param string $by
     * @param bool $escape
     * @return $this|CustomQueryBuilder
     */
    public function group_by($by, $escape = null)
    {
        return parent::group_by($by, $escape);
    }

    /**
     * Add additional SELECT fields
     * 
     * @param string $select SELECT fields to add
     * @param mixed $escape Whether to escape the fields
     * @return $this
     */
    public function add_select($select = '', $escape = null)
    {
        return $this->select($select, $escape);
    }

    /**
     * Enable SQL_CALC_FOUND_ROWS for the current SELECT statement
     * 
     * This function automatically adds SQL_CALC_FOUND_ROWS to whatever
     * SELECT fields are already defined, allowing you to use arrays
     * and normal select() methods as usual. Works with eager loading too!
     * 
     * When using calc_rows(), the result will include found_rows() method
     * that can be called directly on the result object.
     * 
     * Example:
     * // Use with array select
     * $result = $this->db->select(['idoutlet as id', 'outlet_name as text'])
     *                    ->calc_rows()
     *                    ->get('outlet', 20, 0);
     * 
     * $data = $result->result();      // 20 rows
     * $total = $result->found_rows(); // Total available rows
     * 
     * // Works with eager loading relations too!
     * $result = $this->db->select(['idoutlet as id', 'outlet_name as text'])
     *                    ->with_one('users', 'user_id', 'id')
     *                    ->calc_rows()
     *                    ->get('outlet', 20, 0);
     * 
     * $data = $result->result();      // Data with relations loaded
     * $total = $result->found_rows(); // Total count
     * 
     * @return $this
     */
    public function calc_rows()
    {
        // Mark that we want to use SQL_CALC_FOUND_ROWS
        $this->_calc_rows_enabled = true;
        return $this;
    }

    /**
     * Get the total count from SQL_CALC_FOUND_ROWS
     * 
     * This function should be called after executing a query with calc_rows()
     * to get the total number of rows that would have been returned without LIMIT.
     * Works with both regular queries and queries with eager loading.
     * 
     * NOTE: It's recommended to use $result->found_rows() instead for better API:
     * 
     * New recommended way:
     * $result = $this->db->select(['id', 'name'])
     *                    ->calc_rows()
     *                    ->get('users', 10, 0);
     * $data = $result->result();
     * $total = $result->found_rows(); // Better approach!
     * 
     * Old way (still works for backward compatibility):
     * $data = $this->db->select(['id', 'name'])
     *                  ->calc_rows()
     *                  ->get('users', 10, 0);
     * $total = $this->db->get_found_rows(); // Works but not recommended
     * 
     * @return int Total number of rows found
     */
    public function get_found_rows()
    {
        if ($this->_last_found_rows !== null) {
            return $this->_last_found_rows;
        }

        $query = $this->query("SELECT FOUND_ROWS() as total");
        if ($query && $query->num_rows() > 0) {
            return (int) $query->row()->total;
        }
        return 0;
    }

    /**
     * Order by latest (descending)
     * 
     * @param string $column Column to order by (default: 'created_at')
     * @return $this
     */
    public function latest($column = 'created_at')
    {
        return $this->order_by($column, 'DESC');
    }

    /**
     * Order by custom sequence based on array values
     * 
     * Example:
     * // Order by priority: high, medium, low
     * $this->db->order_by_sequence('priority', ['high', 'medium', 'low']);
     * 
     * // Order by status in specific sequence
     * $this->db->order_by_sequence('status', ['pending', 'processing', 'completed', 'cancelled']);
     * 
     * @param string $column Column to order by
     * @param array $array Array of values defining the order sequence
     * @return $this
     * @throws InvalidArgumentException
     */
    public function order_by_sequence($column, $array)
    {
        if (empty($array) || !is_array($array))
            throw new InvalidArgumentException('Parameter $array must be an array value and cannot be empty.');

        if (!is_string($column) || empty($column)) {
            throw new InvalidArgumentException('Parameter $column must be a non-empty string.');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException('Invalid column name format. Only alphanumeric characters, underscores, and single dot allowed.');
        }

        $safe_column = $this->protect_identifiers($column, true);
        $cases = [];

        foreach ($array as $index => $value) {
            $escaped_value = $this->escape($value);
            $cases[] = "WHEN {$safe_column} = {$escaped_value} THEN {$index}";
        }

        $case_string = "CASE " . implode(' ', $cases) . " ELSE " . count($array) . " END";

        return $this->order_by($case_string, '', FALSE);
    }

    /**
     * Order by a column from a related table using a correlated subquery, without JOIN.
     *
     * Example:
     * // Order quotations by marketing name without joining the user table
     * $this->db->order_by_relation('user', 'iduser', 'quotation.idmarketing', 'name', 'ASC');
     *
     * @param string $table Related table name
     * @param string $foreignKey Column in the related table to match
     * @param string $localKey Column in the main table (use table prefix to avoid ambiguity, e.g. 'quotation.idmarketing')
     * @param string $column Column in the related table to order by
     * @param string $direction ASC or DESC
     * @return $this
     */
    public function order_by_relation($table, $foreignKey, $localKey, $column, $direction = 'ASC')
    {
        // VALIDASI KEAMANAN: this method previously had NO validation at all —
        // $table/$foreignKey/$localKey/$column are concatenated directly into a
        // raw, unescaped ORDER BY subquery in process_pending_order_by_relations()
        // ("(SELECT {$column} FROM {$table} WHERE {$foreignKey} = {$localKey} ...)"),
        // making it the one fully unvalidated SQL-injection vector in this class.
        if (!$this->is_valid_table_name($table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}. Only alphanumeric characters and underscores are allowed.");
        }
        if (!$this->is_valid_column_name($foreignKey)) {
            throw new InvalidArgumentException("Invalid foreign key: {$foreignKey}. Only alphanumeric characters, underscores, and a single dot separator are allowed.");
        }
        if (!$this->is_valid_column_name($localKey)) {
            throw new InvalidArgumentException("Invalid local key: {$localKey}. Only alphanumeric characters, underscores, and a single dot separator are allowed.");
        }
        if (!$this->is_valid_column_name($column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters, underscores, and a single dot separator are allowed.");
        }

        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->pending_order_by_relations[] = [
            'table'      => $table,
            'foreignKey' => $foreignKey,
            'localKey'   => $localKey,
            'column'     => $column,
            'direction'  => $direction,
            'position'   => count($this->qb_orderby), // capture insertion point now
        ];
        return $this;
    }

    /**
     * Resolve and apply all pending order_by_relation() calls.
     * Called at get() time when the parent table name is known.
     * Entries are spliced into qb_orderby at the position they were registered,
     * so interleaved order_by() calls preserve their relative order.
     *
     * @param string $parent_table
     */
    protected function process_pending_order_by_relations($parent_table)
    {
        if (empty($this->pending_order_by_relations)) return;

        // Extract alias/name from parent_table (in case it contains "table_name
        // alias") — every other pending-condition processor does this before
        // qualifying a bare local key; this one didn't, so a bare local key
        // combined with an aliased main table (e.g. from('quotation q')) produced
        // invalid SQL like "quotation q.idmarketing" instead of "q.idmarketing".
        $parent_table_identifier = $this->extract_table_or_alias($parent_table);

        $inserts = [];
        foreach ($this->pending_order_by_relations as $rel) {
            $localKey = strpos($rel['localKey'], '.') !== false
                ? $rel['localKey']
                : "{$parent_table_identifier}.{$rel['localKey']}";

            // Defense-in-depth: backtick-quote every identifier even though
            // is_valid_table_name()/is_valid_column_name() already validated
            // them in order_by_relation() — see _quote_identifier() docblock.
            $quoted_column = $this->_quote_identifier($rel['column']);
            $quoted_table = $this->_quote_identifier($rel['table']);
            $quoted_foreign_key = $this->_quote_identifier($rel['foreignKey']);
            $quoted_local_key = $this->_quote_identifier($localKey);

            $subquery = "(SELECT {$quoted_column} FROM {$quoted_table} WHERE {$quoted_foreign_key} = {$quoted_local_key} LIMIT 1)";
            $inserts[] = [
                'position' => $rel['position'],
                'entry'    => ['field' => "{$subquery} {$rel['direction']}", 'direction' => '', 'escape' => FALSE],
            ];
        }

        // Splice from last position to first so earlier indices stay valid
        usort($inserts, function($a, $b) { return $b['position'] - $a['position']; });
        foreach ($inserts as $insert) {
            array_splice($this->qb_orderby, $insert['position'], 0, [$insert['entry']]);
        }

        $this->pending_order_by_relations = [];
    }

    /**
     * Order by oldest (ascending)
     * 
     * @param string $column Column to order by (default: 'created_at')
     * @return $this
     */
    public function oldest($column = 'created_at')
    {
        return $this->order_by($column, 'ASC');
    }

    /**
     * Get first row from result
     * 
     * Example:
     * $user = $this->db->where('email', 'john@example.com')->first('users');
     * if ($user) {
     *     echo $user->name;
     * }
     * 
     * // With relations
     * $post = $this->db->with_one('user', 'user_id', 'id')->first('posts');
     * 
     * @param string $table Table name (optional)
     * @return object|null First row as object or null if no results
     */
    public function first($table = '')
    {
        $result = $this->limit(1)->get($table);
        return $result->num_rows() > 0 ? $result->row() : null;
    }

    /**
     * Get a single column's value from the first matching row
     *
     * Example:
     * $email = $this->db->where('id', 1)->value('email', 'users');
     * // 'john@example.com', or null if no row matched
     *
     * @param string $column Column name to retrieve
     * @param string $table Table name (optional)
     * @return mixed|null Column value, or null if no rows matched
     */
    public function value($column, $table = '')
    {
        if (!$this->is_valid_column_name($column)) {
            throw new InvalidArgumentException("value: invalid column name '" . htmlspecialchars($column) . "'");
        }

        $row = $this->select($column)->limit(1)->get($table)->row();
        if ($row === null)
            return null;

        $property = strpos($column, '.') !== false ? substr($column, strrpos($column, '.') + 1) : $column;
        return isset($row->$property) ? $row->$property : null;
    }

    /**
     * Check if any rows exist
     * 
     * Example:
     * if ($this->db->where('email', 'john@example.com')->exists('users')) {
     *     echo 'User exists';
     * }
     * 
     * // Check if user has orders
     * if ($this->db->where('user_id', 1)->exists('orders')) {
     *     echo 'User has orders';
     * }
     * 
     * @param string $table Table name (optional)
     * @return bool True if rows exist, false otherwise
     */
    public function exists($table = '')
    {
        $result = $this->limit(1)->get($table);
        return $result->num_rows() > 0;
    }

    /**
     * Check if no rows exist
     * 
     * @param string $table Table name (optional)
     * @return bool True if no rows exist, false otherwise
     */
    public function doesnt_exist($table = '')
    {
        return !$this->exists($table);
    }

    /**
     * Pluck a single column's values from the result set as a flat array.
     * Supports relation columns using dot notation (e.g. 'profile.name').
     *
     * Example:
     * $names = $this->db->from('users')->with_one('profile', 'profile_id', 'id')->pluck('profile.name');
     * $emails = $this->db->from('users')->pluck('email');
     *
     * @param string $column Column name or relation.column (dot notation)
     * @param string $table Table name (optional)
     * @return array
     */
    public function pluck($column, $table = '')
    {
        $result = $this->get($table);
        $array = $result->result_array();
        $values = [];

        $keys = explode('.', $column);

        foreach ($array as $row) {
            $value = $row;
            foreach ($keys as $key) {
                if (is_array($value) && array_key_exists($key, $value)) {
                    $value = $value[$key];
                } else {
                    $value = null;
                    break;
                }
            }
            $values[] = $value;
        }
        return $values;
    }

    /**
     * Flush every pending relation/aggregate/group/order-by-relation queue onto
     * qb_where/qb_orderby/select, in the fixed order every entry-point query
     * method needs.
     *
     * Shared by get(), get_with_calc_rows(), get_compiled_select(), and
     * count_all_results() — previously each of the four independently
     * duplicated this exact 8-line sequence, so a new pending-queue type
     * added later had to be wired into all four or it would silently no-op
     * in whichever one was missed.
     *
     * Callers must set $this->_temp_table_name (via from() or by passing
     * $table into this call) BEFORE calling this, since several processors
     * need the resolved parent table/alias to qualify bare local keys.
     */
    protected function _flush_pending_query_state()
    {
        $this->process_pending_groups();
        $this->process_pending_where_has();
        $this->process_pending_join_aggregates();
        $this->process_pending_aggregates();

        $parent_table = $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_queue($parent_table);
            $this->process_pending_where_exists($parent_table);
            $this->process_pending_where_aggregates($parent_table);
            $this->process_pending_order_by_relations($parent_table);
        }
        $this->_flush_where_reorder_buffer();
    }

    /**
     * Execute query and get results with eager loading support
     *
     * @param string $table Table name (optional)
     * @param int|null $limit Limit number of results
     * @param int|null $offset Offset for results
     * @return CustomQueryBuilderResult Query result
     */
    public function get($table = '', $limit = null, $offset = null)
    {
        $original_debug = $this->db_debug;
        $this->db_debug = FALSE;

        // Reset executed queries log for this new call
        $this->_executed_queries = [];

        // Store table name temporarily for where_exists_relation to use
        if (!empty($table)) {
            $this->_temp_table_name = $table;
            $this->from($table);
        }

        // Handle SQL_CALC_FOUND_ROWS separately using compiled query
        if ($this->_calc_rows_enabled)
            return $this->get_with_calc_rows($limit, $offset);

        $this->_flush_pending_query_state();

        if (!empty($this->with_relations))
            return $this->get_with_eager_loading('', $limit, $offset, null);

        // parent::get() calls $this->query() internally, which already returns a
        // CustomQueryBuilderResult — wrapping it again here would double-wrap.
        $result = parent::get('', $limit, $offset);

        $error = $this->error();
        // BUG FIX: strict `!== 0` treats ANY non-int error code as an error —
        // but CI3's own PDO driver reports "no error" as the STRING '00000'
        // (not int 0), so every query against a pdo:* connection (mysql, pgsql,
        // sqlite, ...) used to be flagged as failed even on success. Also
        // covers the sqlite3 driver's own stale-101 quirk — see
        // _is_real_database_error() docblock.
        if ($this->_is_real_database_error($error))
            $this->handle_database_error($error);

        $this->db_debug = $original_debug;

        // Clear temporary table name
        $this->_temp_table_name = null;

        return $result;
    }

    /**
     * Execute query with SQL_CALC_FOUND_ROWS using compiled query approach
     * Now supports eager loading by checking for relations first
     * 
     * @param int|null $limit Limit number of results
     * @param int|null $offset Offset for results
     * @return CustomQueryBuilderResult Query result with found_rows included
     */
    protected function get_with_calc_rows($limit = null, $offset = null)
    {
        $this->_flush_pending_query_state();

        // Check if we have eager loading relations
        if (!empty($this->with_relations)) {
            // For queries with eager loading, we need to use a different approach
            // We'll temporarily disable calc_rows, get the data with eager loading,
            // then run a separate count query with SQL_CALC_FOUND_ROWS

            // BACKUP: Simpan with_relations sebelum melakukan query count
            $backup_with_relations = $this->with_relations;
            $backup_pending_aggregates = $this->pending_aggregates;
            $backup_pending_where_has = $this->pending_where_has;
            $backup_pending_where_exists = $this->pending_where_exists;
            $backup_pending_where_queue = $this->pending_where_queue;
            $backup_pending_where_aggregates = $this->pending_where_aggregates;

            // First, get the compiled query for counting (without eager loading)
            $count_query = clone $this;
            $count_query->with_relations = []; // Remove relations for count query
            $count_query->pending_aggregates = []; // Remove aggregates for count query
            $compiled_count_query = $count_query->get_compiled_select('', false);

            if ($this->dbdriver === 'mysqli') {
                // Add LIMIT for the count query if specified
                if ($limit !== null) {
                    $compiled_count_query .= ' LIMIT ' . (int) $limit;
                    if ($offset !== null && $offset > 0)
                        $compiled_count_query .= ' OFFSET ' . (int) $offset;
                }

                // Execute count query with SQL_CALC_FOUND_ROWS
                $count_query_with_calc_rows = preg_replace('/^SELECT\s+/i', 'SELECT SQL_CALC_FOUND_ROWS ', $compiled_count_query);
                $this->query($count_query_with_calc_rows); // This sets FOUND_ROWS() for later use

                // Store the count query before executing FOUND_ROWS()
                $main_count_query = $this->last_query();

                // Get the found_rows count
                $found_rows_query = $this->query("SELECT FOUND_ROWS() as total");
                $found_rows = 0;
                if ($found_rows_query && $found_rows_query->num_rows() > 0)
                    $found_rows = (int) $found_rows_query->row()->total;
                $this->_last_found_rows = $found_rows;

                // Restore the count query as last_query for debugging purposes
                $this->queries[] = $main_count_query;
            } else {
                // Portable fallback: SQL_CALC_FOUND_ROWS/FOUND_ROWS() are
                // MySQL-only. Wrap the unlimited compiled query in a
                // COUNT(*) subquery instead — works on every CI3 driver.
                $found_rows = $this->_portable_found_rows($compiled_count_query);
                $this->_last_found_rows = $found_rows;
                $this->queries[] = $compiled_count_query;
            }

            // RESTORE: Kembalikan with_relations setelah query count selesai
            $this->with_relations = $backup_with_relations;
            $this->pending_aggregates = $backup_pending_aggregates;
            $this->pending_where_has = $backup_pending_where_has;
            $this->pending_where_exists = $backup_pending_where_exists;
            $this->pending_where_queue = $backup_pending_where_queue;
            $this->pending_where_aggregates = $backup_pending_where_aggregates;

            // Reset calc_rows flag before eager loading
            $this->_calc_rows_enabled = false;

            // Now get the actual data with eager loading
            $eager_result = $this->get_with_eager_loading('', $limit, $offset, $found_rows);

            // Return the eager result (already has found_rows)
            return $eager_result;
        }

        // For queries without eager loading, use the original approach
        // Get the compiled SELECT query without LIMIT first
        $compiled_query = $this->get_compiled_select('', false); // false = don't reset

        // Add LIMIT if specified
        $limited_query = $compiled_query;
        if ($limit !== null) {
            $limited_query .= ' LIMIT ' . (int) $limit;
            if ($offset !== null && $offset > 0)
                $limited_query .= ' OFFSET ' . (int) $offset;
        }

        // Reset calc_rows flag and query state
        $this->_calc_rows_enabled = false;
        $this->reset_query();

        if ($this->dbdriver === 'mysqli') {
            // Replace SELECT with SELECT SQL_CALC_FOUND_ROWS
            $final_query = preg_replace('/^SELECT\s+/i', 'SELECT SQL_CALC_FOUND_ROWS ', $limited_query);

            // Execute the raw query
            $result = $this->query($final_query);

            // Store the main query before executing FOUND_ROWS()
            $main_query = $this->last_query();

            // Get the found_rows count
            $found_rows_query = $this->query("SELECT FOUND_ROWS() as total");
            $found_rows = 0;
            if ($found_rows_query && $found_rows_query->num_rows() > 0)
                $found_rows = (int) $found_rows_query->row()->total;
            $this->_last_found_rows = $found_rows;

            // Restore the main query as last_query for debugging purposes
            $this->queries[] = $main_query;
        } else {
            // Portable fallback: SQL_CALC_FOUND_ROWS/FOUND_ROWS() are
            // MySQL-only. Run the limited query for the actual result set,
            // then compute the true (unlimited) total via a COUNT(*)
            // subquery over $compiled_query — works on every CI3 driver.
            $result = $this->query($limited_query);
            $main_query = $this->last_query();

            $found_rows = $this->_portable_found_rows($compiled_query);
            $this->_last_found_rows = $found_rows;

            $this->queries[] = $main_query;
        }

        // Return CustomQueryBuilderResult with found_rows
        return new CustomQueryBuilderResult($result->result_array(), $found_rows);
    }

    /**
     * Execute query with WHERE conditions
     * 
     * @param string $table Table name (optional)
     * @param array|null $where WHERE conditions
     * @param int|null $limit Limit number of results
     * @param int|null $offset Offset for results
     * @return CustomQueryBuilderResult Query result
     */
    public function get_where($table = '', $where = null, $limit = null, $offset = null)
    {
        if ($table !== '')
            $this->from($table);

        if ($where !== null && is_array($where))
            $this->where($where);

        if ($limit !== null)
            $this->limit($limit, $offset);

        if (
            !empty($this->with_relations) ||
            !empty($this->pending_where_has) ||
            !empty($this->pending_aggregates) ||
            !empty($this->pending_join_aggregates) ||
            !empty($this->pending_where_exists) ||
            !empty($this->pending_groups)
        )
            return $this->get();

        $original_debug = $this->db_debug;
        $this->db_debug = FALSE;

        // parent::get_where() calls $this->query() internally, which already
        // returns a CustomQueryBuilderResult — wrapping it again here would double-wrap.
        $result = parent::get_where('', null, null, null);

        $error = $this->error();
        // BUG FIX: strict `!== 0` treats ANY non-int error code as an error —
        // but CI3's own PDO driver reports "no error" as the STRING '00000'
        // (not int 0), so every query against a pdo:* connection (mysql, pgsql,
        // sqlite, ...) used to be flagged as failed even on success. Also
        // covers the sqlite3 driver's own stale-101 quirk — see
        // _is_real_database_error() docblock.
        if ($this->_is_real_database_error($error))
            $this->handle_database_error($error);

        $this->db_debug = $original_debug;

        // Clear temporary table name (parent::get_where resets qb_from but not our custom property)
        $this->_temp_table_name = null;

        return $result;
    }

    /**
     * Count all results without relations
     * 
     * @param string $table Table name (optional)
     * @param bool $reset Whether to reset query after counting
     * @return int Number of results
     */
    public function count_all_results($table = '', $reset = true)
    {
        if ($table !== '') {
            $this->_temp_table_name = $table;
            $this->from($table);
        }

        $this->_flush_pending_query_state();

        $original_relations = $this->with_relations;
        $this->with_relations = [];

        $result = parent::count_all_results('', $reset);

        if (!$reset) {
            $this->with_relations = $original_relations;
        } else {
            // Unlike get_compiled_select()/get(), this method never called
            // reset_query() (which is what normally clears _temp_table_name),
            // so it used to leak the table name from this call into whatever
            // pending relation condition the next call on this same instance
            // resolved without an explicit from()/get(table).
            $this->_temp_table_name = null;
        }

        return $result;
    }

    /**
     * Get compiled SELECT statement without relations
     * 
     * @param string $table Table name (optional)
     * @param bool $reset Whether to reset query after compiling
     * @return string Compiled SQL query
     */
    public function get_compiled_select($table = '', $reset = true)
    {
        if ($table !== '') {
            $this->_temp_table_name = $table;
            $this->from($table);
        }

        $this->_flush_pending_query_state();

        $original_relations = $this->with_relations;
        $this->with_relations = [];

        $result = parent::get_compiled_select('', $reset);
        if (!$reset)
            $this->with_relations = $original_relations;
        if ($reset)
            $this->reset_query();

        return $result;
    }

    /**
     * Portable replacement for MySQL's SQL_CALC_FOUND_ROWS/FOUND_ROWS(), used
     * by get_with_calc_rows() on every driver except mysqli: wraps the given
     * (unlimited) compiled SELECT in a COUNT(*) subquery and executes it.
     *
     * @param string $compiled_query_no_limit Compiled SELECT, without LIMIT/OFFSET
     * @return int
     */
    protected function _portable_found_rows($compiled_query_no_limit)
    {
        $count_result = $this->query('SELECT COUNT(*) AS total FROM (' . $compiled_query_no_limit . ') AS _cqb_count_wrap');
        if ($count_result && $count_result->num_rows() > 0)
            return (int) $count_result->row()->total;
        return 0;
    }

    /**
     * True if $error (from $this->error()) represents a genuine failure.
     *
     * Not a bare `code != 0`: PDO reports "no error" as the string '00000'
     * (loose `!=` already treats that as zero), and CI3's sqlite3 driver's
     * error() unconditionally calls SQLite3::lastErrorCode()/lastErrorMsg()
     * even after a fully successful query — by the time we call it, the
     * connection has already stepped through the result set, leaving a
     * stale 101 (SQLITE_DONE, "no more rows available") in place. That's
     * never a real failure code for sqlite3, so it's excluded here the same
     * way the PDO '00000' quirk is handled at the call sites below.
     *
     * @param array $error Error information array with 'code' and 'message'
     * @return bool
     */
    protected function _is_real_database_error($error)
    {
        if ($error['code'] == 0) return false;
        if ($this->dbdriver === 'sqlite3' && (int) $error['code'] === 101) return false;
        return true;
    }

    /**
     * Handle database errors and display formatted error messages
     *
     * @param array $error Error information array with 'code' and 'message'
     * @return void
     */
    protected function handle_database_error($error)
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $caller = null;

        foreach ($backtrace as $trace) {
            if (
                isset($trace['file']) &&
                !strpos($trace['file'], 'CustomQueryBuilder.php') &&
                !strpos($trace['file'], 'system' . DIRECTORY_SEPARATOR) &&
                !strpos($trace['file'], 'DB_query_builder.php')
            ) {
                $caller = $trace;
                break;
            }
        }

        $error_message = "Error Number: " . $error['code'] . "\n\n" .
            $error['message'] . "\n\n";

        if ($this->debug) {
            $last_query = $this->last_query();
            $error_message .= "SQL Query: " . $last_query . "\n\n";

            if ($caller) {
                $error_message .= "Filename: " . basename($caller['file']) . "\n" .
                    "Line Number: " . $caller['line'];
            }
        }

        show_error($error_message);
    }

    /**
     * Flush every kind of pending relation/aggregate state that may have been
     * queued via with()/with_count()/with_sum()/with_avg()/with_min()/with_max()/
     * with_calculation(), where_aggregate()/or_where_aggregate(),
     * where_exists_relation()/where_not_exists_relation() (and their or_
     * variants)/where_has()/or_where_has(), and join_count()/join_sum()/etc.
     *
     * Those methods only ever queue their pending_* state on the instance they
     * were called on — nothing flushes it automatically once a callback that
     * received that instance returns. Every place in this class that runs a
     * user-supplied callback against a cloned/wrapped query builder must call
     * this afterwards, or whatever the callback queued is silently dropped
     * from the compiled query with no error. Safe to call unconditionally —
     * each check below is a no-op when that particular queue is empty.
     *
     * @param string|null $context_table Table/alias bare local keys should resolve against
     * @param bool $flush_select_aggregates Whether to flush pending_aggregates (with_count()/
     *        with_sum()/with_calculation()/etc.) as extra SELECT columns. Pass false when $this
     *        is itself a single-column scalar subquery (a where_has()/with_sum()/where_exists()
     *        callback's own subquery, shaped `SELECT COUNT(*)`/`SELECT SUM(x)`/`SELECT 1`) — an
     *        aggregate call made there exists only to register an alias for a paired
     *        where_aggregate()/or_where_aggregate() lookup (which already ran synchronously at
     *        call time, independent of this flush); actually adding it as a second SELECT
     *        column would turn that single-column subquery into a 2-column one and break the
     *        comparison it's used in (e.g. MySQL error 1241 "Operand should contain 1 column(s)").
     * @return $this
     */

    /**
     * Execute callback within a database transaction
     * 
     * @param callable $callback Callback function to execute within transaction
     * @param bool $strict If true, throws exception on transaction failure (default: false)
     * @return mixed Return value from callback, or false if transaction failed (when not in strict mode)
     * @throws InvalidArgumentException If callback is not callable
     * @throws Exception If transaction fails and strict mode is enabled
     */
    public function transaction($callback, $strict = false)
    {
        if (!is_callable($callback))
            throw new InvalidArgumentException('Callback must be callable');

        // Set error reporting untuk catch semua error
        $old_error_reporting = error_reporting();
        error_reporting(E_ALL);

        // Set error handler yang agresif
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }, E_ALL);

        $this->trans_begin();

        $result = null;
        $exception = null;

        try {
            // BUG FIX: pass $this through so a callback declaring the
            // documented `function ($db) { ... }` signature (see README's
            // Transactions section) actually receives it instead of throwing
            // ArgumentCountError. Callbacks that ignore the parameter (e.g.
            // via `use ($db)` closures) are unaffected.
            $result = call_user_func($callback, $this);
        } catch (Exception $e) {
            $exception = $e;
        }

        // Restore error handler dan error reporting
        error_reporting($old_error_reporting);

        // BUG FIX: set_error_handler() always PUSHES a new handler onto PHP's
        // internal handler stack — it never replaces the previous one in place.
        // Calling set_error_handler($old_error_handler) here (the previous code)
        // pushed yet another frame instead of popping back to it, leaving our
        // aggressive handler one level further down the stack. The next
        // *unrelated* restore_error_handler() call anywhere else in the process
        // (e.g. PHPUnit's own per-test handler teardown) would then pop back
        // onto our handler, silently turning ordinary deprecation notices
        // elsewhere into thrown ErrorExceptions. restore_error_handler() is the
        // only call that correctly pops back to whatever was active before.
        restore_error_handler();

        // Handle PHP Exception yang tertangkap
        if ($exception !== null) {
            $this->trans_rollback();

            // Get caller origin menggunakan backtrace
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $caller = null;
            foreach ($backtrace as $trace) {
                if (
                    isset($trace['file']) &&
                    strpos($trace['file'], 'CustomQueryBuilder.php') === false &&
                    strpos($trace['file'], 'system' . DIRECTORY_SEPARATOR) === false &&
                    strpos($trace['file'], 'DB_query_builder.php') === false
                ) {
                    $caller = $trace;
                    break;
                }
            }

            // Build detailed error message untuk PHP exception
            $error_details = sprintf(
                "Transaction failed (PHP Exception) - Type: %s | Message: %s | File: %s | Line: %d | Origin: %s:%d",
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                isset($caller['file']) ? $caller['file'] : 'unknown',
                isset($caller['line']) ? $caller['line'] : 0
            );

            if ($strict)
                throw new Exception($error_details, 0, $exception);

            // Log error
            if (function_exists('log_message')) {
                log_message('error', $error_details);
            }

            return false;
        }

        // Handle database transaction failure
        if ($this->trans_status() === false) {
            $this->trans_rollback();

            // Capture database error details
            $error = $this->error();
            $last_query = $this->last_query();

            // Get caller origin menggunakan backtrace
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $caller = null;
            foreach ($backtrace as $trace) {
                if (
                    isset($trace['file']) &&
                    strpos($trace['file'], 'CustomQueryBuilder.php') === false &&
                    strpos($trace['file'], 'system' . DIRECTORY_SEPARATOR) === false &&
                    strpos($trace['file'], 'DB_query_builder.php') === false
                ) {
                    $caller = $trace;
                    break;
                }
            }

            // Build detailed error message untuk database error
            $error_details = sprintf(
                "Transaction failed (Database Error) - Code: %s | Message: %s | Query: %s | File: %s | Line: %d",
                isset($error['code']) ? $error['code'] : 'N/A',
                isset($error['message']) ? $error['message'] : 'Unknown error',
                $last_query ? $last_query : 'N/A',
                isset($caller['file']) ? $caller['file'] : 'unknown',
                isset($caller['line']) ? $caller['line'] : 0
            );

            if ($strict) {
                throw new Exception($error_details);
            }

            if (function_exists('log_message')) {
                log_message('error', $error_details);
            }

            return false;
        }

        $this->trans_commit();

        // Return hasil callback
        return $result;
    }

    /**
     * Get last executed query or all executed queries (including eager loading).
     *
     * Returns a plain string when no eager loading queries were tracked,
     * or an indexed array when eager loading was used:
     *   [0] => 'SELECT ... FROM main_table ...',    // main query
     *   [1] => 'SELECT ... FROM relation_table ...', // first relation
     *   ...
     *
     * Example:
     *   // Plain query — returns string
     *   $this->db->where('id', 1)->get('users');
     *   $sql = $this->db->last_query(); // string
     *
     *   // With eager loading — returns array
     *   $this->db->with_many('posts', 'user_id', 'id')->get('users');
     *   $queries = $this->db->last_query(); // array
     *
     * @return string|array Last query string, or array of all executed queries
     */
    public function all_last_query()
    {
        if (!empty($this->_executed_queries))
            return $this->_executed_queries;
        return [parent::last_query()];
    }

    /**
     * Reset query builder state including relations and pending conditions
     * 
     * @return CI_DB_query_builder Query builder instance
     */
    public function reset_query()
    {
        $this->with_relations = [];
        $this->pending_where_has = [];
        $this->pending_aggregates = [];
        $this->pending_join_aggregates = [];
        $this->pending_where_exists = [];
        $this->pending_where_aggregates = [];
        $this->pending_where_queue = [];
        $this->pending_groups = [];
        $this->pending_order_by_relations = [];
        $this->_in_group_context = 0;
        $this->_calc_rows_enabled = false;
        $this->_last_found_rows = null;
        $this->_temp_table_name = null;
        // A freshly reset instance (typically a clone used to build a relation/aggregate
        // subquery) must not inherit the outer query's in-flight reorder bookkeeping —
        // otherwise a stale buffered entry from an already-processed pending condition
        // gets spliced into the subquery's own qb_where instead of the outer one's.
        $this->_pending_where_reorder_buffer = [];
        $this->_call_order_seq = 0;
        $this->_manual_group_stack = [];
        $this->_deferred_snapshot_stack = [];
        return parent::reset_query();
    }

    /**
     * Override query() method to support eager loading with custom raw SQL
     *
     * This override lets you use with_one()/with_many() (and relations nested inside
     * their callbacks, e.g. with_count()/with_sum() called INSIDE a with_many()
     * callback) with a raw SQL string. with_relations works here because it is
     * always resolved as a completely separate follow-up query — the relation rows
     * are fetched on their own and matched back onto $data in PHP, so it doesn't
     * matter that the main query's SQL was handed in as a fixed string.
     *
     * NOT supported here — and impossible to support without changing how they
     * work: with_count()/with_sum()/with_avg()/with_min()/with_max()/with_calculation()
     * called at the TOP LEVEL (not nested inside a with_one()/with_many() callback),
     * where_has()/where_exists_relation()/where_aggregate(), and join_count()/join_sum()/etc.
     * All of these work by splicing an extra SELECT column or WHERE/JOIN fragment into
     * *this* query's own qb_select/qb_where/qb_join *before* it is compiled and run.
     * By the time this method runs, $sql has already been executed as-is by
     * parent::query() above — there is no SELECT/WHERE clause left to splice
     * anything into. If any of those were queued before calling query(), that queued
     * state is silently discarded below (not applied, and not left to leak into
     * whatever get()/query() call comes next on this same instance) rather than
     * silently doing nothing while still being carried forward.
     *
     * If no with_relations are queued, this behaves exactly like the parent query() method.
     *
     * Example:
     * // Without relations - works like normal query()
     * $query = $this->db->query("SELECT * FROM users");
     * $users = $query->result();
     *
     * // With relations - automatically processes eager loading
     * $this->db->with_one('marketing_spk', 'idmarketing_spk', 'idmarketing_spk');
     * $query = $this->db->query("SELECT * FROM transaction WHERE status = 1");
     * $data = $query->result(); // Relations are loaded automatically
     *
     * // Relations nested inside a with_many()/with_one() callback also work,
     * // since the whole relation (including its own nested aggregates) is still
     * // resolved as a separate follow-up query:
     * $this->db->with_many('posts', 'user_id', 'id', function ($q) {
     *     $q->with_count('comments', 'post_id', 'id');
     * });
     * $query = $this->db->query("SELECT * FROM users");
     * $users = $query->result(); // each post has ->comments_count
     *
     * // NOT supported: top-level with_sum()/with_count()/where_has()/join_sum()/etc.
     * // are silently ignored (their queued state is discarded, not applied) when
     * // combined with a raw query() call — use get()/get_compiled_select() instead
     * // if you need those.
     * $this->db->with_sum(['marketing_spk' => 'jobsum'], 'idmarketing_spk', 'idmarketing_spk', 'total_job');
     * $query = $this->db->query("SELECT * FROM transaction WHERE status = 1");
     * $data = $query->result(); // rows will NOT have ->jobsum
     *
     * @param string $sql SQL query string
     * @param mixed $binds Query bindings (optional)
     * @param bool $return_object Whether to return as object (default: true for backwards compatibility)
     * @return bool|CustomQueryBuilderResult CustomQueryBuilderResult for SELECT queries, bool for write queries
     */
    public function query($sql, $binds = FALSE, $return_object = NULL)
    {
        // Execute the parent query() method
        $query = parent::query($sql, $binds, $return_object);

        // Check if we need to process relations
        $has_relations = !empty($this->with_relations);

        // BUG FIX: none of these pending_* queues can ever be applied to a raw SQL
        // string that parent::query() has already executed by this point (see the
        // docblock above for why) — only with_relations (handled below) can. They
        // used to be left untouched here, so anything queued via with_sum()/
        // where_has()/where_exists_relation()/where_aggregate()/join_sum()/etc.
        // before calling query() silently never applied to THIS call, AND stayed
        // queued afterwards — leaking into and corrupting whatever unrelated
        // get()/query() call came next on this same instance. Clear all of them
        // unconditionally so nothing survives past this call either way.
        $this->pending_aggregates = [];
        $this->pending_where_has = [];
        $this->pending_where_exists = [];
        $this->pending_where_aggregates = [];
        $this->pending_join_aggregates = [];
        $this->pending_groups = [];
        $this->pending_where_queue = [];
        $this->pending_order_by_relations = [];

        if (!$has_relations) {
            // Wrap SELECT results so ->key_by() etc. are available. Write queries
            // (INSERT/UPDATE/DELETE) return bool from parent::query() — pass through as-is.
            if (is_object($query) && method_exists($query, 'result_array')) {
                return new CustomQueryBuilderResult($query->result_array(), null, $query);
            }
            return $query;
        }

        // BUG FIX: a write statement (INSERT/UPDATE/DELETE) makes parent::query()
        // return a plain bool, not a result object — with_relations pending from
        // before this call can't apply to it (there are no rows to attach
        // relations to). Without this check, ->result_array() below was called
        // directly on that bool, throwing instead of just passing the bool through.
        if (!is_object($query) || !method_exists($query, 'result_array')) {
            $this->with_relations = [];
            return $query;
        }

        // If query failed, return standard result
        if (!$query)
            return $query;

        // Store relations
        $relations = $this->with_relations;

        // Reset for next query
        $this->with_relations = [];

        // Get result as array
        $data = $query->result_array();

        // If no data, return empty CustomQueryBuilderResult
        if (empty($data))
            return new CustomQueryBuilderResult([]);

        // Process eager loading relations (with_one, with_many)
        if ($has_relations)
            $data = $this->load_relations($data, $relations);

        // Return wrapped result with relations
        return new CustomQueryBuilderResult($data);
    }
}
