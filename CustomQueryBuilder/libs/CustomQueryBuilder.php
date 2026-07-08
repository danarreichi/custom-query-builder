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
     * Get the parent table name or alias from current query
     * 
     * This helper function extracts the main table name/alias from the current query builder state.
     * Used by where_exists_relation() to automatically determine the parent table.
     * Returns the alias if present, otherwise returns the table name.
     * 
     * @param string $table_from_get Optional table name from get() method
     * @return string|null Parent table name/alias or null if not found
     */
    protected function pending_where_exists_relation($table_from_get = null)
    {
        // First priority: table passed to get() method parameter
        if (!empty($table_from_get)) {
            return $this->extract_table_or_alias($table_from_get);
        }

        // Second priority: temporary table name from get() method
        if (!empty($this->_temp_table_name)) {
            return $this->extract_table_or_alias($this->_temp_table_name);
        }

        // Third priority: try to get from compiled select to extract table name/alias
        try {
            $compiled = $this->get_compiled_select('', false); // Don't reset

            // Extract table name and alias from FROM clause using regex
            // Pattern matches: FROM `table` `alias` or FROM table alias or FROM `table` AS `alias`
            if (preg_match('/FROM\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s+(?:AS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?)?/i', $compiled, $matches)) {
                // Return alias if it exists (matches[2]), otherwise return table name (matches[1])
                return !empty($matches[2]) ? $matches[2] : $matches[1];
            }
        } catch (Exception $e) {
            // Fallback: if no FROM is set yet, return null
            return null;
        }

        return null;
    }

    // =================================================================
    // PARENT METHOD OVERRIDES FOR PROPER TYPE CHAINING IN IDE
    // =================================================================

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
        return parent::where($key, $value, $escape);
    }

    /**
     * WHERE IN
     *
     * Generates a WHERE field IN('item', 'item') SQL query,
     * joined with 'AND' if appropriate.
     *
     * @param	string	$key	The field to search
     * @param	array	$values	The values searched on
     * @param	bool	$escape
     * @return	CustomQueryBuilder
     */
    public function where_in($key = NULL, $values = NULL, $escape = NULL)
    {
        if (!is_array($values) || count($values) === 0) {
            return parent::where_in($key, $values, $escape);
        }
        return $this->_safe_in_clause($key, $values, FALSE, 'AND ', $escape);
    }

    /**
     * OR WHERE IN
     *
     * Generates a WHERE field IN('item', 'item') SQL query,
     * joined with 'OR' if appropriate.
     *
     * @param	string	$key	The field to search
     * @param	array	$values	The values searched on
     * @param	bool	$escape
     * @return	CustomQueryBuilder
     */
    public function or_where_in($key = NULL, $values = NULL, $escape = NULL)
    {
        if (!is_array($values) || count($values) === 0) {
            return parent::or_where_in($key, $values, $escape);
        }
        return $this->_safe_in_clause($key, $values, FALSE, 'OR ', $escape);
    }

    /**
     * WHERE NOT IN
     *
     * Generates a WHERE field NOT IN('item', 'item') SQL query,
     * joined with 'AND' if appropriate.
     *
     * @param	string	$key	The field to search
     * @param	array	$values	The values searched on
     * @param	bool	$escape
     * @return	CustomQueryBuilder
     */
    public function where_not_in($key = NULL, $values = NULL, $escape = NULL)
    {
        if (!is_array($values) || count($values) === 0) {
            return parent::where_not_in($key, $values, $escape);
        }
        return $this->_safe_in_clause($key, $values, TRUE, 'AND ', $escape);
    }

    // --------------------------------------------------------------------

    /**
     * OR WHERE NOT IN
     *
     * Generates a WHERE field NOT IN('item', 'item') SQL query,
     * joined with 'OR' if appropriate.
     *
     * @param	string	$key	The field to search
     * @param	array	$values	The values searched on
     * @param	bool	$escape
     * @return	CustomQueryBuilder
     */
    public function or_where_not_in($key = NULL, $values = NULL, $escape = NULL)
    {
        if (!is_array($values) || count($values) === 0) {
            return parent::or_where_not_in($key, $values, $escape);
        }
        return $this->_safe_in_clause($key, $values, TRUE, 'OR ', $escape);
    }

    /**
     * Builds a WHERE [NOT] IN clause directly into qb_where, bypassing
     * CI's internal preg_match validation which fails with a
     * "regular expression is too large" PCRE error when the value list
     * is very large.
     *
     * - Numeric arrays: every value is cast to intval (SQL-safe, no quotes).
     * - String arrays:  every value is run through $this->escape() individually
     *   (proper quoting + special-char escaping without a giant regex).
     * - When $escape === FALSE the values are inserted as-is (caller's
     *   responsibility — same contract as the native CI methods).
     *
     * @param  string      $key     Column name / table.column
     * @param  array       $values  The values to match
     * @param  bool        $not     TRUE for NOT IN
     * @param  string      $type    'AND ' or 'OR '
     * @param  bool|null   $escape  NULL = auto, FALSE = no escaping
     * @return CustomQueryBuilder
     */
    protected function _safe_in_clause($key, array $values, $not = FALSE, $type = 'AND ', $escape = NULL)
    {
        if (!$this->is_valid_column_name($key)) {
            throw new InvalidArgumentException("Invalid column name: {$key}");
        }
        $key = $this->protect_identifiers($key);

        // Mirror CI's _wh() prefix logic exactly:
        // - first condition ever → no prefix
        // - first condition right after group_start() → no prefix (clears qb_where_group_started flag)
        // - all other conditions → 'AND ' or 'OR '
        $prefix = (count($this->qb_where) === 0 && count($this->qb_cache_where) === 0)
            ? $this->_group_get_type('')
            : $this->_group_get_type($type);

        if ($escape === FALSE) {
            $in_list = implode(',', array_values($values));
        } else {
            $all_numeric = true;
            foreach ($values as $v) {
                if (!is_numeric($v)) {
                    $all_numeric = false;
                    break;
                }
            }
            if ($all_numeric) {
                $in_list = implode(',', array_map('intval', $values));
            } else {
                $in_list = implode(',', array_map([$this, 'escape'], $values));
            }
        }

        $not_str = $not ? ' NOT' : '';
        $condition = $prefix . $key . $not_str . ' IN (' . $in_list . ')';

        $this->qb_where[] = ['condition' => $condition, 'escape' => FALSE];

        if ($this->qb_caching === TRUE) {
            $this->qb_cache_where[] = end($this->qb_where);
            $this->qb_cache_exists[] = 'where';
        }

        return $this;
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
     * Execute callback when condition is true
     * 
     * Example:
     * // Conditional WHERE clause
     * $this->db->when($search_term, function($query) use ($search_term) {
     *     $query->like('name', $search_term);
     * });
     * 
     * // With else callback
     * $this->db->when($user_role == 'admin', function($query) {
     *     $query->select('*');
     * }, function($query) {
     *     $query->select('id, name, email');
     * });
     * 
     * @param mixed $condition Condition to check
     * @param callable(CustomQueryBuilder, mixed): void $callback Callback to execute if condition is true
     * @param callable(CustomQueryBuilder, mixed): void|null $default Callback to execute if condition is false
     * @return $this
     */
    public function when($condition, $callback, $default = null)
    {
        if ($condition) {
            $callback($this, $condition);

            // Process pending where_exists if we're NOT inside a group context
            // If we're inside a group, let the group() method handle it
            if ($this->_in_group_context === 0) {
                // Try to get parent table from qb_from if _temp_table_name is not set
                $parent_table = $this->_temp_table_name;
                if (empty($parent_table) && !empty($this->qb_from)) {
                    $parent_table = $this->qb_from[0];
                }

                // Only flush if the parent table is already known (from() was called,
                // or get('table') already ran earlier in the chain). Otherwise leave
                // it pending — resolving it now with an empty parent table produces an
                // unqualified/ambiguous local key (e.g. "scores.user_id = id" instead
                // of "scores.user_id = users.id"). get()/get_compiled_select() will
                // flush it correctly once the table is known.
                if (!empty($this->pending_where_queue) && !empty($parent_table)) {
                    $this->process_pending_where_queue($parent_table);
                }
            }
        } else {
            if ($default) {
                $default($this, $condition);

                // Same processing for default callback
                if ($this->_in_group_context === 0) {
                    $parent_table = $this->_temp_table_name;
                    if (empty($parent_table) && !empty($this->qb_from)) {
                        $parent_table = $this->qb_from[0];
                    }

                    if (!empty($this->pending_where_queue) && !empty($parent_table)) {
                        $this->process_pending_where_queue($parent_table);
                    }
                }
            }
        }
        return $this;
    }

    /**
     * Add NOT condition to WHERE clause
     * 
     * Example:
     * $this->db->where_not('status', 'deleted');
     * // Generates: WHERE `status` != 'deleted'
     * 
     * $this->db->where_not('user_id', 5);
     * // Generates: WHERE `user_id` != 5
     * 
     * @param string $column Column name
     * @param mixed $value Value to compare
     * @return $this
     */
    public function where_not($column, $value)
    {
        $column = $this->protect_identifiers($column, true);
        $escaped = $this->escape($value);
        return $this->where("{$column} != {$escaped}", null, false);
    }

    /**
     * Add IS NULL condition to WHERE clause
     * 
     * Example:
     * $this->db->where_null('deleted_at');
     * // Generates: WHERE `deleted_at` IS NULL
     * 
     * $this->db->where_null('parent_id');
     * // Generates: WHERE `parent_id` IS NULL
     * 
     * @param string $column Column name
     * @return $this
     */
    public function where_null($column)
    {
        $column = $this->protect_identifiers($column, true);
        return $this->where("$column IS NULL", null, false);
    }

    /**
     * Add IS NOT NULL condition to WHERE clause
     * 
     * Example:
     * $this->db->where_not_null('email_verified_at');
     * // Generates: WHERE `email_verified_at` IS NOT NULL
     * 
     * $this->db->where_not_null('profile_image');
     * // Generates: WHERE `profile_image` IS NOT NULL
     * 
     * @param string $column Column name
     * @return $this
     */
    public function where_not_null($column)
    {
        $column = $this->protect_identifiers($column, true);
        return $this->where("$column IS NOT NULL", null, false);
    }

    /**
     * Add BETWEEN condition to WHERE clause
     * 
     * Example:
     * $this->db->where_between('age', [18, 65]);
     * // Generates: WHERE `age` BETWEEN 18 AND 65
     * 
     * $this->db->where_between('created_at', ['2023-01-01', '2023-12-31']);
     * // Generates: WHERE `created_at` BETWEEN '2023-01-01' AND '2023-12-31'
     * 
     * @param string $column Column name
     * @param array $values Array with exactly 2 values for BETWEEN
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_between($column, array $values)
    {
        if (count($values) !== 2)
            throw new InvalidArgumentException('where_between() expects exactly 2 values.');
        $column = $this->protect_identifiers($column, true);
        $this->where("{$column} BETWEEN {$this->escape($values[0])} AND {$this->escape($values[1])}", null, false);
        return $this;
    }

    /**
     * Add NOT BETWEEN condition to WHERE clause
     * 
     * @param string $column Column name
     * @param array $values Array with exactly 2 values for NOT BETWEEN
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_not_between($column, array $values)
    {
        if (count($values) !== 2)
            throw new InvalidArgumentException('where_not_between() expects exactly 2 values.');
        $column = $this->protect_identifiers($column, true);
        $this->where("{$column} NOT BETWEEN {$this->escape($values[0])} AND {$this->escape($values[1])}", null, false);
        return $this;
    }

    /**
     * Add OR BETWEEN condition to WHERE clause
     * 
     * @param string $column Column name
     * @param array $values Array with exactly 2 values for BETWEEN
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_between($column, array $values)
    {
        if (count($values) !== 2)
            throw new InvalidArgumentException('or_where_between() expects exactly 2 values.');
        $column = $this->protect_identifiers($column, true);
        $this->or_where("{$column} BETWEEN {$this->escape($values[0])} AND {$this->escape($values[1])}", null, false);
        return $this;
    }

    /**
     * Add OR NOT BETWEEN condition to WHERE clause
     * 
     * @param string $column Column name
     * @param array $values Array with exactly 2 values for NOT BETWEEN
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_not_between($column, array $values)
    {
        if (count($values) !== 2)
            throw new InvalidArgumentException('or_where_not_between() expects exactly 2 values.');
        $column = $this->protect_identifiers($column, true);
        $this->or_where("{$column} NOT BETWEEN {$this->escape($values[0])} AND {$this->escape($values[1])}", null, false);
        return $this;
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
        $query = $this->query("SELECT FOUND_ROWS() as total");
        if ($query && $query->num_rows() > 0) {
            return (int) $query->row()->total;
        }
        return 0;
    }

    /**
     * Add WHERE EXISTS condition with callback
     * 
     * Example:
     * // Outlets that have marketing SPK with transactions and delivery
     * $this->db->where_exists(function($query) {
     *     $query->select('1')
     *           ->from('marketing_spk ms')
     *           ->join('transaction t', 't.idmarketing_spk = ms.idmarketing_spk AND t.idoutlet = ms.idspk_workshop AND t.status = 1', 'inner')
     *           ->join('transaction_delivery td', 'td.idtransaction = t.idtransaction', 'inner')
     *           ->where('ms.idspk_workshop = outlet.idoutlet')
     *           ->where('ms.status', 1);
     * });
     * 
     * // Users that have published posts
     * $this->db->where_exists(function($query) {
     *     $query->select('1')
     *           ->from('posts')
     *           ->where('posts.user_id = users.id')
     *           ->where('status', 'published');
     * });
     * 
     * @param callable(CustomQueryBuilder): void $callback Callback to build EXISTS subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_exists($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        $subquery = clone $this;
        $subquery->reset_query();

        // Execute callback to build subquery
        $callback($subquery);

        // Process any pending relation/aggregate state queued by the callback
        // (with_count(), where_aggregate(), nested where_exists_relation()/where_has(),
        // join_count(), etc.). Pass no context table — process_pending_where_exists()
        // falls back to $subquery's own qb_from (set by the callback's from() call),
        // which is the only sensible resolution target for a bare local key here since
        // this top-level where_exists() has no relation-style parent table of its own.
        // BUG FIX: this previously only flushed pending_where_exists, using a literal
        // '__parent__' placeholder string that was never a real table — any bare
        // (non-dotted) local key in a nested where_exists_relation() call compiled into
        // invalid SQL referencing a column on a nonexistent `__parent__` table, and
        // pending_where_has/pending_aggregates/pending_where_aggregates/
        // pending_join_aggregates were silently dropped entirely. $subquery is a
        // single-column subquery shape here, so select-aggregates are skipped (false).
        $subquery->flush_pending_relation_state(null, false);

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add EXISTS condition
        $this->where("EXISTS ({$compiled_subquery})", null, false);

        return $this;
    }

    /**
     * Add WHERE EXISTS/NOT EXISTS condition with callback (internal helper)
     * 
     * @param string $condition_type Condition type ('AND' or 'OR')
     * @param string $exists_type EXISTS type ('EXISTS' or 'NOT EXISTS')
     * @param callable(CustomQueryBuilder): void $callback Callback to build subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    protected function add_where_exists_callback($condition_type, $exists_type, $callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        $subquery = clone $this;
        $subquery->reset_query();

        // Execute callback to build subquery
        $callback($subquery);

        // Process any pending relation/aggregate state queued by the callback —
        // see where_exists() above for why no context table is passed and why
        // select-aggregates are skipped (false); same reasoning applies here.
        $subquery->flush_pending_relation_state(null, false);

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();

        // Add condition
        $clause = "{$exists_type} ({$compiled_subquery})";
        return $condition_type === 'OR' ?
            $this->or_where($clause, null, false) :
            $this->where($clause, null, false);
    }

    /**
     * Add WHERE NOT EXISTS condition with callback
     * 
     * Example:
     * // Users that don't have any published posts
     * $this->db->where_not_exists(function($query) {
     *     $query->select('1')
     *           ->from('posts')
     *           ->where('posts.user_id = users.id')
     *           ->where('status', 'published');
     * });
     * 
     * // Outlets without any completed transactions
     * $this->db->where_not_exists(function($query) {
     *     $query->select('1')
     *           ->from('marketing_spk ms')
     *           ->join('transaction t', 't.idmarketing_spk = ms.idmarketing_spk', 'inner')
     *           ->where('ms.idspk_workshop = outlet.idoutlet')
     *           ->where('t.status', 'completed');
     * });
     * 
     * @param callable(CustomQueryBuilder): void $callback Callback to build NOT EXISTS subquery
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
     * @param callable(CustomQueryBuilder): void $callback Callback to build EXISTS subquery
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
     * @param callable(CustomQueryBuilder): void $callback Callback to build NOT EXISTS subquery
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_not_exists($callback)
    {
        return $this->add_where_exists_callback('OR', 'NOT EXISTS', $callback);
    }


    /**
     * Add WHERE HAS condition for relationships
     * 
     * Example:
     * // Users that have orders
     * $this->db->from('users')->where_has('orders', 'user_id', 'id');
     * 
     * // Users that have active orders
     * $this->db->from('users')->where_has('orders', 'user_id', 'id', function($query) {
     *     $query->where('status', 'active');
     * });
     * 
     * // Users with at least 5 orders
     * $this->db->from('users')->where_has('orders', 'user_id', 'id', null, '>=', 5);
     * 
     * @param string $relation Related table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null|string $callback Optional callback to modify relation query.
     *        If provided as a string it will be interpreted as the comparison operator
     *        (see below) and the callback will be set to null. This lets you omit the
     *        callback when you only want to specify operator/count.
     * @param string $operator Comparison operator (>=, =, >, <, <=, !=, <>)
     * @param int $count Count to compare against
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_has($relation, $foreignKey, $localKey, $callback = null, $operator = null, $count = null)
    {
        if (is_string($callback)) {
            $allowed_operators = self::$ALLOWED_OPERATORS;
            if (!in_array($callback, $allowed_operators, true)) {
                throw new InvalidArgumentException("Invalid operator: {$callback}. Allowed operators: " . implode(', ', $allowed_operators));
            }
            // Shorthand calling convention: where_has($rel, $fk, $lk, '>', 5) means
            // operator '>', count 5 — the 4th slot (normally $callback) holds the
            // operator and the 5th slot (normally $operator) holds the count.
            // BUG FIX: this used to do `$count = $operator` unconditionally, so
            // omitting the count (e.g. where_has($rel, $fk, $lk, '>')) picked up
            // $operator's old default '>=' as the count — a non-numeric string —
            // which then failed the is_numeric($count) check below with a
            // confusing error instead of defaulting to count=1 like the
            // no-shorthand call `where_has($rel, $fk, $lk)` does.
            $count = $operator !== null ? $operator : 1;
            $operator = $callback;
            $callback = null;
        } else {
            $operator = $operator !== null ? $operator : '>=';
            $count = $count !== null ? $count : 1;
        }
        // Optimasi: jika hanya cek keberadaan record (>= 1), gunakan WHERE EXISTS yang lebih cepat
        if ($operator === '>=' && (int) $count === 1) {
            return $this->where_exists_relation($relation, $foreignKey, $localKey, $callback);
        }

        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation}. Only alphanumeric characters and underscores are allowed.");
        }

        $processed_local_keys = $this->process_keys($localKey, 'local key');
        $processed_foreign_keys = $this->process_keys($foreignKey, 'foreign key');
        $this->validate_key_count_match($processed_foreign_keys, $processed_local_keys);

        // VALIDASI KEAMANAN: Validasi operator
        $allowed_operators = self::$ALLOWED_OPERATORS;
        if (!in_array($operator, $allowed_operators)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}. Allowed operators: " . implode(', ', $allowed_operators));
        }

        // VALIDASI KEAMANAN: Validasi count
        if (!is_numeric($count) || $count < 0) {
            throw new InvalidArgumentException("Invalid count: {$count}. Count must be a non-negative number.");
        }

        $this->pending_where_has[] = [
            'relation' => $relation,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'callback' => $callback,
            'operator' => $operator,
            'count' => (int) $count,
            '_order' => $this->_capture_call_order()
        ];

        return $this;
    }

    /**
     * Add WHERE DOESN'T HAVE condition for relationships
     * 
     * Example:
     * // Users that don't have any orders
     * $this->db->from('users')->where_doesnt_have('orders', 'user_id', 'id');
     * 
     * // Users that don't have cancelled orders
     * $this->db->from('users')->where_doesnt_have('orders', 'user_id', 'id', function($query) {
     *     $query->where('status', 'cancelled');
     * });
     * 
     * @param string $relation Related table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback to modify relation query
     * @return $this
     */
    public function where_doesnt_have($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->where_not_exists_relation($relation, $foreignKey, $localKey, $callback);
    }

    /**
     * Add OR WHERE HAS condition for relationships
     * 
     * @param string $relation Related table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null|string $callback Optional callback to modify relation query.
     *        When specified as a string it is treated as the comparison operator and
     *        the callback is ignored, enabling succinct operator/count syntax.
     * @param string $operator Comparison operator (>=, =, >, <, <=, !=, <>)
     * @param int $count Count to compare against
     * @return $this
     */
    public function or_where_has($relation, $foreignKey, $localKey, $callback = null, $operator = null, $count = null)
    {
        if (is_string($callback)) {
            $allowed_operators = self::$ALLOWED_OPERATORS;
            if (!in_array($callback, $allowed_operators, true)) {
                throw new InvalidArgumentException("Invalid operator: {$callback}. Allowed operators: " . implode(', ', $allowed_operators));
            }
            // See where_has() for why $count must default to 1 here rather than
            // unconditionally borrowing $operator's old default value.
            $count = $operator !== null ? $operator : 1;
            $operator = $callback;
            $callback = null;
        } else {
            $operator = $operator !== null ? $operator : '>=';
            $count = $count !== null ? $count : 1;
        }
        // Optimasi: jika hanya cek keberadaan record (>= 1), gunakan OR WHERE EXISTS yang lebih cepat
        if ($operator === '>=' && (int) $count === 1) {
            return $this->or_where_exists_relation($relation, $foreignKey, $localKey, $callback);
        }

        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation}. Only alphanumeric characters and underscores are allowed.");
        }

        $processed_local_keys = $this->process_keys($localKey, 'local key');
        $processed_foreign_keys = $this->process_keys($foreignKey, 'foreign key');
        $this->validate_key_count_match($processed_foreign_keys, $processed_local_keys);

        // VALIDASI KEAMANAN: Validasi operator
        $allowed_operators = self::$ALLOWED_OPERATORS;
        if (!in_array($operator, $allowed_operators)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}. Allowed operators: " . implode(', ', $allowed_operators));
        }

        // VALIDASI KEAMANAN: Validasi count
        if (!is_numeric($count) || $count < 0) {
            throw new InvalidArgumentException("Invalid count: {$count}. Count must be a non-negative number.");
        }

        $this->pending_where_has[] = [
            'relation' => $relation,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'callback' => $callback,
            'operator' => $operator,
            'count' => (int) $count,
            'type' => 'OR',
            '_order' => $this->_capture_call_order()
        ];

        return $this;
    }

    /**
     * Add OR WHERE DOESN'T HAVE condition for relationships
     * 
     * @param string $relation Related table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback to modify relation query
     * @return $this
     */
    public function or_where_doesnt_have($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->or_where_not_exists_relation($relation, $foreignKey, $localKey, $callback);
    }

    /**
     * Add OR IS NULL condition to WHERE clause
     * 
     * @param string $column Column name
     * @return $this
     */
    public function or_where_null($column)
    {
        $column = $this->protect_identifiers($column, true);
        return $this->or_where("$column IS NULL", null, false);
    }

    /**
     * Add OR NOT condition to WHERE clause
     * 
     * Example:
     * $this->db->where('status', 'active')->or_where_not('type', 'deleted');
     * // Generates: WHERE `status` = 'active' OR `type` != 'deleted'
     * 
     * $this->db->where('role', 'admin')->or_where_not('user_id', 5);
     * // Generates: WHERE `role` = 'admin' OR `user_id` != 5
     * 
     * @param string $column Column name
     * @param mixed $value Value to compare
     * @return $this
     */
    public function or_where_not($column, $value)
    {
        $column = $this->protect_identifiers($column, true);
        $escaped = $this->escape($value);
        return $this->or_where("{$column} != {$escaped}", null, false);
    }

    /**
     * Add OR IS NOT NULL condition to WHERE clause
     * 
     * @param string $column Column name
     * @return $this
     */
    public function or_where_not_null($column)
    {
        $column = $this->protect_identifiers($column, true);
        return $this->or_where("$column IS NOT NULL", null, false);
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
            $subquery = "(SELECT {$rel['column']} FROM {$rel['table']} WHERE {$rel['foreignKey']} = {$localKey} LIMIT 1)";
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
     * Execute callback unless condition is true (opposite of when)
     * 
     * Example:
     * // Add WHERE clause unless user is admin
     * $this->db->unless($user_role == 'admin', function($query) {
     *     $query->where('status', 'published');
     * });
     * 
     * // With else callback
     * $this->db->unless(empty($search), function($query) use ($search) {
     *     // This runs when $search is NOT empty
     *     $query->like('title', $search);
     * }, function($query) {
     *     // This runs when $search IS empty
     *     $query->order_by('created_at', 'DESC');
     * });
     * 
     * @param mixed $condition Condition to check
     * @param callable(CustomQueryBuilder, mixed): void $callback Callback to execute if condition is false
     * @param callable(CustomQueryBuilder, mixed): void|null $default Callback to execute if condition is true
     * @return $this
     */
    public function unless($condition, $callback, $default = null)
    {
        return $this->when(!$condition, $callback, $default);
    }

    /**
     * Search across multiple columns with LIKE conditions
     * 
     * Example:
     * // Search in name and email columns with OR
     * $this->db->search('john', ['name', 'email']);
     * // Generates: WHERE (`name` LIKE '%john%' OR `email` LIKE '%john%')
     * 
     * // Search with AND conditions
     * $this->db->search('admin', ['role', 'title'], false);
     * // Generates: WHERE (`role` LIKE '%admin%' AND `title` LIKE '%admin%')
     * 
     * @param string $term Search term
     * @param array $columns Array of column names to search in
     * @param bool $or Whether to use OR between columns (default: true)
     * @return $this
     */
    public function search($term, $columns = [], $or = true)
    {
        if (empty($columns))
            return $this;

        $this->group(function ($q) use ($term, $columns, $or) {
            foreach ($columns as $index => $column) {
                if (!is_string($column) || $column === '')
                    continue;

                if ($index === 0) {
                    $q->like($column, $term, 'both');
                } else {
                    if ($or) {
                        $q->or_like($column, $term, 'both');
                    } else {
                        $q->like($column, $term, 'both');
                    }
                }
            }
        });
        return $this;
    }

    /**
     * Override of the native group_start() (and, by extension, or_group_start() /
     * not_group_start() / or_not_group_start(), which all call this internally)
     * to record the bracket-open position for the empty-group protection in
     * group_end() below.
     *
     * @param string $not
     * @param string $type
     * @return $this
     */
    public function group_start($not = '', $type = 'AND ')
    {
        $this->_manual_group_stack[] = count($this->qb_where);
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
     * @return $this
     */
    public function group_end()
    {
        $bracket_open_pos = array_pop($this->_manual_group_stack);

        if ($bracket_open_pos !== null && count($this->qb_where) - 1 === $bracket_open_pos) {
            array_pop($this->qb_where);
            $this->qb_where_group_started = false;
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
     * Add eager loading relation that returns single record
     * 
     * Example:
     * // Load post with its author (user)
     * $posts = $this->db->with_one('users', 'user_id', 'id')->get('posts');
     * // Result: $post->users (single user object)
     * 
     * // Load order with customer details
     * $orders = $this->db->with_one('customers', 'customer_id', 'id')->get('orders');
     * // Result: $order->customers
     * 
     * // With alias
     * $posts = $this->db->with_one(['users' => 'author'], 'user_id', 'id')->get('posts');
     * // Result: $post->author
     * 
     * // With conditions
     * $posts = $this->db->with_one('users', 'user_id', 'id', function($query) {
     *     $query->where('status', 'active')
     *           ->select('id, name, email');
     * })->get('posts');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_one($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->with($relation, $foreignKey, $localKey, false, $callback);
    }

    /**
     * Add eager loading relation that returns multiple records
     * 
     * Example:
     * // Load user's multiple orders
     * $this->db->with_many('orders', 'user_id', 'id');
     * 
     * // Load user's orders with conditions
     * $this->db->with_many('orders', 'user_id', 'id', function($query) {
     *     $query->where('status', 'active')
     *           ->order_by('created_at', 'DESC');
     * });
     * 
     * // With alias
     * $this->db->with_many(['orders' => 'user_orders'], 'user_id', 'id');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_many($relation, $foreignKey, $localKey, $callback = null)
    {
        return $this->with($relation, $foreignKey, $localKey, true, $callback);
    }


    /**
     * Build and apply all pending derived-table JOIN aggregates
     *
     * For each pending config, generates:
     *   LEFT JOIN (
     *       SELECT fk_col, AGG(col) AS alias
     *       FROM relation [WHERE callback conditions] GROUP BY fk_col
     *   ) AS `_jagg_alias` ON `_jagg_alias`.`fk_col` = `main`.`lk_col`
     *
     * @return void
     */
    protected function process_pending_join_aggregates()
    {
        if (empty($this->pending_join_aggregates))
            return;

        if (empty($this->qb_from)) {
            throw new Exception('join_sum/join_count/etc. require a table. Call from() or provide table in get().');
        }

        $pending = $this->pending_join_aggregates;
        $this->pending_join_aggregates = [];

        // Resolve main table alias for ON condition
        $mainTable = $this->qb_from[0];
        $main_alias = $this->extract_table_or_alias($mainTable);

        foreach ($pending as $config) {
            $relation = $config['relation'];
            $foreign_keys = $config['foreign_key'];
            $local_keys = $config['local_key'];
            $agg_func = $config['aggregate'];
            $alias = $config['alias'];
            $callback = $config['callback'];

            // Strip table prefix from FK — only bare column name lives inside derived table
            // (used for ON condition and result-column alias)
            $bare_fks = array_map(function ($fk) {
                return strpos($fk, '.') !== false ? substr(strrchr($fk, '.'), 1) : $fk;
            }, $foreign_keys);

            // Determine relation alias (could be provided via "table alias" syntax)
            // we'll use helper from QueryValidationTrait to keep logic consistent.
            $relation_alias = $this->extract_table_or_alias($relation);

            // Build qualified FK references for SELECT / GROUP BY.
            // When the caller already supplied a table qualifier (e.g. "transaction_sdm.iduser"),
            // honour it so the column resolves correctly inside the derived subquery.
            // Fall back to relation_alias prefix when no qualifier is present.
            $qualified_fks = array_map(function ($fk) use ($relation_alias) {
                if (strpos($fk, '.') !== false) {
                    list($tbl, $col) = explode('.', $fk, 2);
                    return '`' . $tbl . '`.`' . $col . '`';
                }
                if ($relation_alias) {
                    return '`' . $relation_alias . '`.`' . $fk . '`';
                }
                return '`' . $fk . '`';
            }, $foreign_keys);

            // Build derived subquery
            $sub = clone $this;
            $sub->reset_query();

            // prefix foreign key select with qualified reference
            $fk_select = implode(', ', $qualified_fks);

            $select_str = $fk_select . ', ' . $agg_func . ' AS `' . $alias . '`';

            // When relation is a raw subquery e.g. "(SELECT ...) alias", bypass CI's
            // from() escaping — it would double-backtick the already-compiled SQL.
            if (ltrim($relation)[0] === '(') {
                $sub->select($select_str, false);
                $sub->qb_from[] = $relation; // inject raw, no escaping
            } else {
                $sub->select($select_str, false)->from($relation);
            }

            if (is_callable($callback)) {
                $callback($sub);
            }

            // group by also needs alias prefix to avoid ambiguity when the
            // relation table is self‑joined inside the derived query.
            // Use the same qualified references built for SELECT so that
            // cross-table FKs (e.g. "transaction_sdm.iduser") are resolved correctly.
            foreach ($qualified_fks as $qualified_fk) {
                $sub->group_by($qualified_fk, false);
            }

            $subquery_sql = $sub->get_compiled_select();

            // Unique join alias derived from result alias
            $join_alias = '_jagg_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($alias));

            // Build ON condition
            $on_parts = [];
            for ($i = 0; $i < count($bare_fks); $i++) {
                $bare_lk = strpos($local_keys[$i], '.') !== false
                    ? substr(strrchr($local_keys[$i], '.'), 1)
                    : $local_keys[$i];
                $on_parts[] = "`{$join_alias}`.`{$bare_fks[$i]}` = `{$main_alias}`.`{$bare_lk}`";
            }
            $on_condition = implode(' AND ', $on_parts);

            // LEFT JOIN derived table (escape=false preserves subquery syntax)
            $this->join("({$subquery_sql}) `{$join_alias}`", $on_condition, 'left', false);

            // If no explicit SELECT has been set yet, default to main table.*
            // so all main-table columns are preserved alongside the aggregate.
            if (empty($this->qb_select)) {
                $this->select("`{$main_alias}`.*", false);
            }

            // Add result column to SELECT
            $this->add_select("`{$join_alias}`.`{$alias}`", false);
        }
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

        $this->process_pending_groups();
        $this->process_pending_where_has();
        $this->process_pending_join_aggregates();
        $this->process_pending_aggregates();

        // Process pending WHERE queue (handles grouping properly)
        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_queue($parent_table);
            $this->process_pending_where_exists($parent_table);
            $this->process_pending_where_aggregates($parent_table);
            $this->process_pending_order_by_relations($parent_table);
        }
        $this->_flush_where_reorder_buffer();

        if (!empty($this->with_relations))
            return $this->get_with_eager_loading('', $limit, $offset, null);

        // parent::get() calls $this->query() internally, which already returns a
        // CustomQueryBuilderResult — wrapping it again here would double-wrap.
        $result = parent::get('', $limit, $offset);

        $error = $this->error();
        // BUG FIX: strict `!== 0` treats ANY non-int error code as an error —
        // but CI3's own PDO driver reports "no error" as the STRING '00000'
        // (not int 0), so every query against a pdo:* connection (mysql, pgsql,
        // sqlite, ...) used to be flagged as failed even on success. Loose `!=`
        // correctly treats '00000' as equal to 0 (PHP8 numeric-string
        // comparison) while still catching real non-numeric codes like
        // 'HY000/1' or an actual integer error code such as 1064.
        if ($error['code'] != 0)
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
        // Process pending conditions first
        $this->process_pending_groups();
        $this->process_pending_where_has();
        $this->process_pending_join_aggregates();
        $this->process_pending_aggregates();

        // Process pending WHERE queue and WHERE EXISTS relations  
        $parent_table = $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_queue($parent_table);
            $this->process_pending_where_exists($parent_table);
            $this->process_pending_where_aggregates($parent_table);
            $this->process_pending_order_by_relations($parent_table);
        }
        $this->_flush_where_reorder_buffer();

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

            // Restore the count query as last_query for debugging purposes
            $this->queries[] = $main_count_query;

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
        if ($limit !== null) {
            $compiled_query .= ' LIMIT ' . (int) $limit;
            if ($offset !== null && $offset > 0)
                $compiled_query .= ' OFFSET ' . (int) $offset;
        }

        // Replace SELECT with SELECT SQL_CALC_FOUND_ROWS
        $final_query = preg_replace('/^SELECT\s+/i', 'SELECT SQL_CALC_FOUND_ROWS ', $compiled_query);

        // Reset calc_rows flag and query state
        $this->_calc_rows_enabled = false;
        $this->reset_query();

        // Execute the raw query
        $result = $this->query($final_query);

        // Store the main query before executing FOUND_ROWS()
        $main_query = $this->last_query();

        // Get the found_rows count
        $found_rows_query = $this->query("SELECT FOUND_ROWS() as total");
        $found_rows = 0;
        if ($found_rows_query && $found_rows_query->num_rows() > 0)
            $found_rows = (int) $found_rows_query->row()->total;

        // Restore the main query as last_query for debugging purposes
        $this->queries[] = $main_query;

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
        // sqlite, ...) used to be flagged as failed even on success. Loose `!=`
        // correctly treats '00000' as equal to 0 (PHP8 numeric-string
        // comparison) while still catching real non-numeric codes like
        // 'HY000/1' or an actual integer error code such as 1064.
        if ($error['code'] != 0)
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

        $this->process_pending_groups();
        $this->process_pending_where_has();
        $this->process_pending_join_aggregates();
        $this->process_pending_aggregates();

        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_queue($parent_table);
            $this->process_pending_where_exists($parent_table);
            $this->process_pending_where_aggregates($parent_table);
            $this->process_pending_order_by_relations($parent_table);
        }
        $this->_flush_where_reorder_buffer();

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

        $this->process_pending_groups();
        $this->process_pending_where_has();
        $this->process_pending_join_aggregates();
        $this->process_pending_aggregates();

        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_queue($parent_table);
            $this->process_pending_where_exists($parent_table);
            $this->process_pending_where_aggregates($parent_table);
            $this->process_pending_order_by_relations($parent_table);
        }
        $this->_flush_where_reorder_buffer();

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
     * Process large datasets in chunks to avoid memory issues
     * 
     * Example:
     * // Process 1000 records at a time
     * $this->db->chunk(1000, function($users) {
     *     foreach ($users as $user) {
     *         // Process each user
     *         echo $user->name . "\n";
     *     }
     * }, 'users');
     * 
     * // With conditions
     * $this->db->where('status', 'active')
     *          ->chunk(500, function($users, $page) {
     *              echo "Processing page: $page\n";
     *              // Return false to stop processing
     *              if ($page > 10) return false;
     *          }, 'users');
     * 
     * @param int $page_size Number of records per chunk
     * @param callable(array, int): bool|void $callback Callback function to process each chunk
     * @param string $table Table name (optional)
     * @return int Total number of records processed
     * @throws InvalidArgumentException
     */
    public function chunk($page_size, $callback, $table = '')
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        if ($page_size <= 0) {
            throw new InvalidArgumentException('Page size must be greater than 0');
        }

        $offset = 0;
        $page = 1;
        $total_processed = 0;

        do {
            // Clone query untuk setiap chunk
            $chunk_query = clone $this;

            // get() akan otomatis process semua pending operations
            $chunk_result = $chunk_query->get($table, $page_size, $offset);

            $chunk_data = $chunk_result->result();
            $chunk_count = count($chunk_data);

            if ($chunk_count === 0)
                break;

            // Execute callback
            $continue = $callback($chunk_data, $page);

            if ($continue === false)
                break;

            $total_processed += $chunk_count;
            $page++;
            $offset += $page_size;

            // Cleanup untuk mencegah memory leak
            unset($chunk_query, $chunk_result, $chunk_data);

            // Garbage collection setiap 10 page
            if ($page % 10 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Stop jika chunk terakhir tidak penuh
            if ($chunk_count < $page_size)
                break;
        } while (true);

        return $total_processed;
    }

    /**
     * Process large datasets in chunks ordered by ID to avoid memory issues and gaps
     * 
     * Example:
     * // Process users ordered by ID
     * $this->db->chunk_by_id(1000, function($users) {
     *     foreach ($users as $user) {
     *         // Process each user
     *         $this->send_email($user->email);
     *     }
     * }, 'id', 'users');
     * 
     * // With conditions - processes only active users
     * $this->db->where('status', 'active')
     *          ->chunk_by_id(500, function($users, $page) {
     *              echo "Processing page: $page\n";
     *              return true; // Continue processing
     *          }, 'id', 'users');
     * 
     * @param int $page_size Number of records per chunk
     * @param callable(array, int): bool|void $callback Callback function to process each chunk
     * @param string $column Column name for ordering (usually ID)
     * @param string $table Table name (optional)
     * @return int Total number of records processed
     * @throws InvalidArgumentException
     */
    public function chunk_by_id($page_size, $callback, $column, $table = '')
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        if (!$column) {
            throw new InvalidArgumentException('Column is required');
        }

        if ($page_size <= 0) {
            throw new InvalidArgumentException('Page size must be greater than 0');
        }

        $last_id = 0;
        $page = 1;
        $total_processed = 0;

        do {
            // Clone query dan tambahkan kondisi untuk ID
            $chunk_query = clone $this;

            if ($last_id > 0) {
                $chunk_query->where($column . ' >', $last_id);
            }

            // Order by ID dan limit
            $chunk_query->order_by($column, 'ASC');

            // get() akan otomatis process semua pending operations
            $chunk_result = $chunk_query->get($table, $page_size);

            $chunk_data = $chunk_result->result();
            $chunk_count = count($chunk_data);

            if ($chunk_count === 0)
                break;

            // Execute callback
            $continue = $callback($chunk_data, $page);

            if ($continue === false)
                break;

            // Get last ID from chunk
            $last_record = end($chunk_data);
            if (isset($last_record->$column)) {
                $last_id = $last_record->$column;
            } else {
                $last_array = (array) $last_record;
                if (isset($last_array[$column])) {
                    $last_id = $last_array[$column];
                } else {
                    throw new InvalidArgumentException("Column '{$column}' not found in result set");
                }
            }

            $total_processed += $chunk_count;
            $page++;

            // Cleanup untuk mencegah memory leak
            unset($chunk_query, $chunk_result, $chunk_data);

            // Garbage collection setiap 10 page
            if ($page % 10 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Stop jika chunk terakhir tidak penuh
            if ($chunk_count < $page_size)
                break;
        } while (true);

        return $total_processed;
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
     * Process pending where_has conditions by building subqueries
     * 
     * @return void
     * @throws Exception
     */
    protected function process_pending_where_has()
    {
        if (empty($this->pending_where_has))
            return;

        if (empty($this->qb_from)) {
            throw new Exception('where_has() requires a table to be set. Please call from() method or provide table in get() method.');
        }

        $mainTable = $this->qb_from[0];

        // Store pending operations and clear them to prevent infinite recursion
        $pending_operations = $this->pending_where_has;
        $this->pending_where_has = [];

        foreach ($pending_operations as $where_has_config) {
            $subquery = clone $this;
            $subquery->reset_query();

            $relation = $this->protect_identifiers($where_has_config['relation'], true);
            $main_table = $this->protect_identifiers($mainTable, true);

            $subquery->select('COUNT(*)')
                ->from($where_has_config['relation']);

            $foreign_keys = $where_has_config['foreign_key'];
            $local_keys = $where_has_config['local_key'];
            $main_table_identifier = $this->extract_table_or_alias($mainTable);

            for ($i = 0; $i < count($foreign_keys); $i++) {
                $foreign_key_with_table = $this->_qualify_key($foreign_keys[$i], $where_has_config['relation']);
                $local_key_with_table = $this->_qualify_key($local_keys[$i], $main_table_identifier);

                $foreign_key_safe = $this->protect_identifiers($foreign_key_with_table, true);
                $local_key_safe = $this->protect_identifiers($local_key_with_table, true);

                if ($i === 0) {
                    $subquery->where("{$foreign_key_safe} = {$local_key_safe}", null, false);
                } else {
                    $subquery->where("{$foreign_key_safe} = {$local_key_safe}", null, false);
                }
            }

            if (is_callable($where_has_config['callback'])) {
                $where_has_config['callback']($subquery);

                // Process any pending operations in subquery recursively
                // Pass the relation table as context for nested aggregates.
                // $subquery is a single-column `SELECT COUNT(*)` subquery, so any
                // with_count()/with_sum()/with_calculation() queued here exists only
                // to feed a paired where_aggregate() alias lookup — skip flushing it
                // as an extra SELECT column, which would break the COUNT(*) shape.
                $subquery->flush_pending_relation_state($where_has_config['relation'], false);
            }

            $allowed_operators = self::$ALLOWED_OPERATORS;
            $operator = in_array($where_has_config['operator'], $allowed_operators)
                ? $where_has_config['operator']
                : '>=';

            $count = (int) $where_has_config['count'];
            $is_or = isset($where_has_config['type']) && $where_has_config['type'] === 'OR';

            $subquery->_flush_where_reorder_buffer();
            $clause = "({$subquery->get_compiled_select()}) $operator $count";
            $this->_defer_where_append($clause, $is_or, isset($where_has_config['_order']) ? $where_has_config['_order'] : null);
        }

        // pending_where_has already cleared at the beginning to prevent recursion
    }

    /**
     * Build the SQL aggregate function expression (COUNT(*), SUM(...), etc.) for a
     * pending aggregate config.
     *
     * Centralizes a switch-case that used to be duplicated three times —
     * process_pending_aggregates(), process_pending_where_aggregates(), and the
     * nested-aggregate path inside load_single_relation() (with_count()/with_sum()/
     * etc. called INSIDE a with_one()/with_many() relation callback) — and had
     * drifted out of sync between copies. In particular, the nested-aggregate copy
     * used to build the non-custom-expression column reference as
     * protect_identifiers("{$subquery_alias}.{$column}") directly instead of going
     * through _quote_agg_column() like its two siblings: a column that already
     * carried its own table qualifier (e.g. 'other_table.value') produced invalid
     * double-qualified SQL there (`sub`.`other_table`.`value`) instead of correctly
     * using the column's own qualifier. Routing all three call sites through this
     * one method means that class of divergence can't recur.
     *
     * @param string $type Aggregate type: count|sum|avg|max|min|custom|custom_calculation
     *        ('custom' and 'custom_calculation' are synonyms — pending_aggregates
     *        uses 'custom_calculation', pending_where_aggregates uses 'custom'; see
     *        add_where_calculated()'s $type_mapping for why the two queues disagree).
     * @param string|null $column Column name or already-validated custom SQL expression (null for count)
     * @param bool $is_custom_expression Whether $column is a custom SQL expression rather than a bare column name
     * @param string $subquery_alias Table/subquery alias to qualify a bare column with
     * @return string Compiled SQL aggregate function expression
     */
    protected function _build_aggregate_function($type, $column, $is_custom_expression, $subquery_alias)
    {
        switch ($type) {
            case 'count':
                return 'COUNT(*)';
            case 'sum':
            case 'avg':
            case 'max':
            case 'min':
                $sql_func = strtoupper($type);
                if ($is_custom_expression) {
                    $expression = $this->_prefix_bare_identifiers($column, $subquery_alias);
                    return "{$sql_func}({$expression})";
                }
                return "{$sql_func}(" . $this->_quote_agg_column($column, $subquery_alias) . ")";
            case 'custom_calculation':
            case 'custom':
                // Raw expression, already validated by the caller (e.g. "SUM(a) / SUM(b) * 100")
                return $column;
            default:
                return '';
        }
    }

    /**
     * Run an aggregate/relation callback against $subquery with its FROM
     * temporarily swapped to the bare subquery alias (so bare column references
     * inside the callback resolve against the subquery's own table), then flush
     * any relation/aggregate state the callback queued on $subquery itself.
     *
     * Centralizes a block that used to be duplicated across
     * process_pending_aggregates(), process_pending_where_aggregates(), and the
     * nested-aggregate path inside load_single_relation() — the nested-aggregate
     * copy had drifted out of sync and never called flush_pending_relation_state()
     * at all, so a with_count()/with_sum()/where_aggregate() queued inside a
     * callback passed to a with_count()/with_sum() that itself lives inside a
     * with_one()/with_many() relation callback was silently dropped there, unlike
     * the identical nesting one level shallower (which does flush correctly).
     *
     * @param CustomQueryBuilder $subquery Single-column aggregate subquery being built
     * @param string $subquery_alias Alias to expose as $subquery's bare FROM during the callback
     * @param callable(CustomQueryBuilder): void|null $callback
     * @return void
     */
    protected function _run_aggregate_callback($subquery, $subquery_alias, $callback)
    {
        if (!is_callable($callback))
            return;

        // Store original FROM to restore later
        $original_from = $subquery->qb_from;

        // Temporarily replace FROM with aliased version for callback processing
        // This ensures WHERE conditions in callback use the subquery alias
        $subquery->qb_from = [$subquery_alias];

        $callback($subquery);

        // Restore original FROM (which already includes alias)
        $subquery->qb_from = $original_from;

        // Process any pending operations in subquery recursively. $subquery is a
        // single-column aggregate subquery here — see flush_pending_relation_state()'s
        // $flush_select_aggregates doc for why a nested with_count()/with_sum()/etc.
        // must not add a 2nd SELECT column.
        $subquery->flush_pending_relation_state($subquery_alias, false);
    }

    /**
     * Process pending aggregate functions by adding them as subqueries in SELECT
     *
     * @param string|null $context_table Optional context table to use instead of main table (for nested callbacks)
     * @return void
     * @throws Exception
     */
    protected function process_pending_aggregates($context_table = null)
    {
        if (empty($this->pending_aggregates))
            return;

        if (empty($this->qb_from)) {
            throw new Exception('Aggregate functions require a table to be set. Please call from() method or provide table in get() method.');
        }

        // Store pending operations and clear them to prevent infinite recursion
        $pending_operations = $this->pending_aggregates;
        $this->pending_aggregates = [];

        // Ensure we have a proper SELECT clause first
        $current_select = $this->qb_select;
        if (empty($current_select))
            $this->select('*');

        // Use context_table if provided (for nested callbacks), otherwise use qb_from
        $mainTable = $context_table ? $context_table : $this->qb_from[0];

        // Extract table alias from FROM clause
        $main_table_alias = $this->extract_table_or_alias($mainTable);

        foreach ($pending_operations as $aggregate_config) {
            $subquery = clone $this;
            $subquery->reset_query();

            $table_name = $this->extract_table_name($aggregate_config['relation']);
            $relation_table_name = $this->extract_table_or_alias($aggregate_config['relation']);

            // Generate subquery alias to avoid ambiguity in self-joins
            // Format: tablename_sub (e.g., transaction_detail_sub)
            $subquery_alias = $relation_table_name;

            // Detect whether the relation is a raw SQL subquery (e.g. "(SELECT ...) alias")
            $is_subquery_relation = ltrim($aggregate_config['relation'])[0] === '(';

            // Build aggregate function based on type
            $aggregate_function = $this->_build_aggregate_function(
                $aggregate_config['type'],
                $aggregate_config['column'],
                $aggregate_config['is_custom_expression'],
                $subquery_alias
            );

            // Use table alias in FROM clause for subquery
            $subquery->select($aggregate_function);
            if ($is_subquery_relation) {
                // Raw subquery: directly assign to qb_from to prevent CI from backtick-escaping it.
                // The relation string is already well-formed, e.g. "(SELECT ...) transaction_sub".
                $subquery->qb_from[] = $aggregate_config['relation'];
            } else {
                $subquery->from($table_name . ' ' . $subquery_alias);
            }

            $foreign_keys = $aggregate_config['foreign_key'];
            $local_keys = $aggregate_config['local_key'];

            for ($i = 0; $i < count($foreign_keys); $i++) {
                $foreign_key_with_table = $this->_qualify_key($foreign_keys[$i], $subquery_alias);
                $local_key_with_table = $this->_qualify_key($local_keys[$i], $main_table_alias);

                $foreign_key_safe = $this->protect_identifiers($foreign_key_with_table, true);
                $local_key_safe = $this->protect_identifiers($local_key_with_table, true);

                $subquery->where("{$foreign_key_safe} = {$local_key_safe}", null, false);
            }

            $this->_run_aggregate_callback($subquery, $subquery_alias, $aggregate_config['callback']);

            // Add subquery directly to qb_select array to preserve existing SELECT fields
            $compiled_subquery = $subquery->get_compiled_select();
            $result_alias = $aggregate_config['alias'];
            if ($table_name != $subquery_alias)
                $result_alias = $this->extract_table_or_alias($aggregate_config['alias']);
            $subquery_select = "($compiled_subquery) AS " . $this->protect_identifiers($result_alias);

            // Directly add to qb_select array instead of using select() method
            $this->qb_select[] = $subquery_select;
            $this->qb_no_escape[] = null; // Track that this field should not be escaped further
        }

        // pending_aggregates already cleared at the beginning to prevent recursion
    }

    /**
     * Process pending WHERE aggregate conditions by building subqueries in WHERE clause
     *
     * @param string|null $context_table Optional context table to use instead of main table
     * @return void
     * @throws Exception
     */
    protected function process_pending_where_aggregates($context_table = null)
    {
        if (empty($this->pending_where_aggregates))
            return;

        // A table context is required: either qb_from must be set OR a context_table
        // must have been passed in (e.g. from _execute_group_immediately / chunk).
        if (empty($this->qb_from) && empty($context_table)) {
            throw new Exception('WHERE aggregate conditions require a table to be set. Please call from() method or provide table in get() method.');
        }

        // Store pending operations and clear them to prevent infinite recursion
        $pending_operations = $this->pending_where_aggregates;
        $this->pending_where_aggregates = [];

        // Use context_table if provided, otherwise use qb_from
        $mainTable = $context_table ? $context_table : $this->qb_from[0];

        // Extract table alias from FROM clause
        $main_table_alias = $this->extract_table_or_alias($mainTable);

        foreach ($pending_operations as $aggregate_config) {
            $subquery = clone $this;
            $subquery->reset_query();

            $table_name = $this->extract_table_name($aggregate_config['relation']);
            $relation_table_name = $this->extract_table_or_alias($aggregate_config['relation']);

            // Generate subquery alias to avoid ambiguity
            $has_existing_alias = (strpos($aggregate_config['relation'], ' ') !== false) || (stripos($aggregate_config['relation'], ' AS ') !== false);
            $subquery_alias = ($has_existing_alias) ? $relation_table_name : $relation_table_name . '_agg';

            // Detect whether the relation is a raw SQL subquery (e.g. "(SELECT ...) alias")
            $is_subquery_relation = ltrim($aggregate_config['relation'])[0] === '(';

            // Build aggregate function based on type
            $aggregate_function = $this->_build_aggregate_function(
                $aggregate_config['type'],
                $aggregate_config['column'],
                $aggregate_config['is_custom_expression'],
                $subquery_alias
            );

            // Use table alias in FROM clause for subquery
            $subquery->select($aggregate_function);
            if ($is_subquery_relation) {
                $subquery->qb_from[] = $aggregate_config['relation'];
            } else {
                $subquery->from($table_name . ' ' . $subquery_alias);
            }

            $foreign_keys = $aggregate_config['foreign_key'];
            $local_keys = $aggregate_config['local_key'];

            for ($i = 0; $i < count($foreign_keys); $i++) {
                $foreign_key_with_table = $this->_qualify_key($foreign_keys[$i], $subquery_alias);
                $local_key_with_table = $this->_qualify_key($local_keys[$i], $main_table_alias);

                $foreign_key_safe = $this->protect_identifiers($foreign_key_with_table, true);
                $local_key_safe = $this->protect_identifiers($local_key_with_table, true);

                $subquery->where("{$foreign_key_safe} = {$local_key_safe}", null, false);
            }

            $this->_run_aggregate_callback($subquery, $subquery_alias, $aggregate_config['callback']);

            // Build the WHERE condition with subquery
            $subquery->_flush_where_reorder_buffer();
            $compiled_subquery = $subquery->get_compiled_select();
            $operator = $aggregate_config['operator'];
            $value = $aggregate_config['value'];

            // Handle BETWEEN operator specially
            $operator_upper = strtoupper(trim($operator));
            if (in_array($operator_upper, ['BETWEEN', 'NOT BETWEEN'])) {
                $value1 = $this->escape($value[0]);
                $value2 = $this->escape($value[1]);
                $where_condition = "COALESCE(({$compiled_subquery}), 0) {$operator_upper} {$value1} AND {$value2}";
            } else {
                $escaped_value = $this->escape($value);
                // Use COALESCE to handle NULL results (when no matching rows)
                $where_condition = "COALESCE(({$compiled_subquery}), 0) {$operator} {$escaped_value}";
            }

            // Add to WHERE clause based on condition type
            $this->_defer_where_append($where_condition, $aggregate_config['condition_type'] === 'OR', isset($aggregate_config['_order']) ? $aggregate_config['_order'] : null);
        }
    }

    /**
     * Public method to manually process pending where_has conditions
     * This is useful when you need to process where_has in callback contexts
     * 
     * @return $this
     */
    public function process_where_has()
    {
        $this->process_pending_where_has();
        return $this;
    }

    /**
     * Public method to manually process pending aggregate functions
     * This is useful when you need to process aggregates in callback contexts
     * 
     * @return $this
     */
    public function process_aggregates()
    {
        $this->process_pending_aggregates();
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

    /**
     * Process pending WHERE EXISTS relations
     * 
     * This method builds and executes the WHERE EXISTS subqueries based on
     * the stored pending WHERE EXISTS operations.
     * 
     * @param string|null $parent_table Name of the parent table (can be null)
     * @return void
     */
    protected function process_pending_where_queue($parent_table)
    {
        if (empty($this->pending_where_queue))
            return;

        // Extract table alias or name from parent_table (can be null)
        $parent_table_identifier = $parent_table ? $this->extract_table_or_alias($parent_table) : null;

        // Store queue and clear to prevent re-processing
        $queue = $this->pending_where_queue;
        $this->pending_where_queue = [];

        foreach ($queue as $item) {
            if ($item['type'] === 'where_exists') {
                $this->process_single_where_exists($parent_table_identifier, $item['data']);
            }
        }
    }

    /**
     * Process a single WHERE EXISTS operation
     * 
     * @param string|null $parent_table_identifier Parent table name or alias (can be null if local key has table prefix)
     * @param array $exists_config Configuration array for WHERE EXISTS
     * @return void
     */
    protected function process_single_where_exists($parent_table_identifier, $exists_config)
    {
        // Build EXISTS subquery - clone current instance to maintain CustomQueryBuilder type
        $subquery = clone $this;
        $subquery->reset_query();

        // Select 1 for EXISTS
        $subquery->select('1');

        // Extract table name and alias from relation (supports "table_name alias" format)
        $relation_identifier = $this->extract_table_or_alias($exists_config['relation']);

        // Use the relation string as is for FROM clause (may contain alias)
        $subquery->from($exists_config['relation']);

        // Build WHERE conditions for key matching
        $foreign_keys = $exists_config['foreign_keys'];
        $local_keys = $exists_config['local_keys'];

        for ($i = 0; $i < count($foreign_keys); $i++) {
            // Use relation identifier (alias if present, otherwise table name) for foreign key
            $foreign_key_with_table = $this->_qualify_key($foreign_keys[$i], $relation_identifier);

            // Local key is prefixed with the parent table identifier if available;
            // with no parent context, leave a bare column name rather than
            // producing an invalid leading-dot qualifier (might still be
            // ambiguous, but better than breaking).
            $local_key_with_table = empty($parent_table_identifier)
                ? $local_keys[$i]
                : $this->_qualify_key($local_keys[$i], $parent_table_identifier);

            $foreign_key_safe = $this->protect_identifiers($foreign_key_with_table, true);
            $local_key_safe = $this->protect_identifiers($local_key_with_table, true);

            $subquery->where("{$foreign_key_safe} = {$local_key_safe}", null, false);
        }

        // Execute callback if provided
        if ($exists_config['callback'] !== null) {
            if (!is_callable($exists_config['callback'])) {
                throw new InvalidArgumentException('Callback must be callable');
            }
            $exists_config['callback']($subquery);

            // Process any pending WHERE queue operations in the subquery recursively
            if (!empty($subquery->pending_where_queue)) {
                $subquery->process_pending_where_queue($relation_identifier);
            }
            // Process any other pending relation/aggregate state queued by the callback
            // (with_count(), where_aggregate(), nested where_has(), join_count(), etc.).
            // $subquery is a single-column `SELECT 1` EXISTS subquery — skip flushing
            // pending_aggregates as a 2nd SELECT column, which would break that shape.
            $subquery->flush_pending_relation_state($relation_identifier, false);
        }

        // Get the compiled subquery
        $subquery->_flush_where_reorder_buffer();
        $compiled_subquery = $subquery->get_compiled_select();

        // Add EXISTS/NOT EXISTS condition based on type
        $exists_clause = "{$exists_config['exists_type']} ({$compiled_subquery})";

        $this->_defer_where_append($exists_clause, $exists_config['type'] === 'OR', isset($exists_config['_order']) ? $exists_config['_order'] : null);
    }

    /**
     * Process pending WHERE EXISTS operations
     * 
     * This method builds and executes the WHERE EXISTS subqueries based on
     * the stored pending WHERE EXISTS operations.
     * 
     * @param string|null $parent_table Name of the parent table; defaults to qb_from[0] when omitted
     * @return void
     * @throws Exception
     */
    protected function process_pending_where_exists($parent_table = null)
    {
        if (empty($this->pending_where_exists))
            return;

        if ($parent_table === null) {
            if (empty($this->qb_from)) {
                throw new Exception('WHERE EXISTS relation conditions require a table to be set. Please call from() method or provide table in get() method.');
            }
            $parent_table = $this->qb_from[0];
        }

        // Extract table alias or name from parent_table (in case it contains "table_name alias")
        $parent_table_identifier = $this->extract_table_or_alias($parent_table);

        // Store pending operations and clear them to prevent infinite recursion
        $pending_operations = $this->pending_where_exists;
        $this->pending_where_exists = [];

        foreach ($pending_operations as $exists_config) {
            // Build EXISTS subquery - clone current instance to maintain CustomQueryBuilder type
            $subquery = clone $this;
            $subquery->reset_query();

            // Select 1 for EXISTS
            $subquery->select('1');

            // Extract table name and alias from relation (supports "table_name alias" format)
            $relation_identifier = $this->extract_table_or_alias($exists_config['relation']);

            // Use the relation string as is for FROM clause (may contain alias)
            $subquery->from($exists_config['relation']);

            // Build WHERE conditions for key matching
            $foreign_keys = $exists_config['foreign_keys'];
            $local_keys = $exists_config['local_keys'];

            for ($i = 0; $i < count($foreign_keys); $i++) {
                // Use relation identifier (alias if present, otherwise table name) for foreign key
                $foreign_key_with_table = $relation_identifier . '.' . $foreign_keys[$i];

                // Check if local key already has table reference (contains a dot)
                if (strpos($local_keys[$i], '.') !== false) {
                    // Local key already has table reference (e.g., 'msd.iditem'), use as is
                    $local_key_with_table = $local_keys[$i];
                } else {
                    // Local key is just column name, prepend parent table identifier
                    $local_key_with_table = $parent_table_identifier . '.' . $local_keys[$i];
                }

                $foreign_key_safe = $this->protect_identifiers($foreign_key_with_table, true);
                $local_key_safe = $this->protect_identifiers($local_key_with_table, true);

                $subquery->where("{$foreign_key_safe} = {$local_key_safe}", null, false);
            }

            // Execute callback if provided
            if ($exists_config['callback'] !== null) {
                if (!is_callable($exists_config['callback'])) {
                    throw new InvalidArgumentException('Callback must be callable');
                }
                $exists_config['callback']($subquery);

                // Process any pending relation/aggregate state queued by the callback
                // (nested where_exists_relation(), with_count(), where_aggregate(), etc.)
                // Use the relation identifier (alias or table name) as context.
                // $subquery is a single-column `SELECT 1` EXISTS subquery — skip flushing
                // pending_aggregates as a 2nd SELECT column, which would break that shape.
                $subquery->flush_pending_relation_state($relation_identifier, false);
            }

            // Get the compiled subquery
            $subquery->_flush_where_reorder_buffer();
            $compiled_subquery = $subquery->get_compiled_select();

            // Add EXISTS/NOT EXISTS condition based on type
            $exists_clause = "{$exists_config['exists_type']} ({$compiled_subquery})";

            $this->_defer_where_append($exists_clause, $exists_config['type'] === 'OR', isset($exists_config['_order']) ? $exists_config['_order'] : null);
        }

        // Clear pending operations
        $this->pending_where_exists = [];
    }

    /**
     * Public method to manually process pending WHERE EXISTS relations
     * This is useful when you need to process WHERE EXISTS in callback contexts
     * 
     * @param string $parent_table Name of the parent table
     * @return $this
     */
    public function process_where_exists($parent_table)
    {
        $this->process_pending_where_exists($parent_table);
        return $this;
    }

    /**
     * Public method to manually process pending WHERE aggregate conditions
     * This is useful when you need to process where_aggregate()/or_where_aggregate() in callback contexts
     *
     * @param string|null $context_table Optional context table to use instead of qb_from
     * @return $this
     */
    public function process_where_aggregates($context_table = null)
    {
        $this->process_pending_where_aggregates($context_table);
        return $this;
    }

    /**
     * Public method to manually process pending derived-table JOIN aggregates
     * This is useful when you need to process join_count()/join_sum()/etc. in callback contexts
     *
     * @return $this
     */
    public function process_join_aggregates()
    {
        $this->process_pending_join_aggregates();
        return $this;
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
     * Merge pending relation/aggregate state queued on a NestedQueryBuilder wrapper
     * into this instance's own queues.
     *
     * NestedQueryBuilder is a separate class (it wraps a CustomQueryBuilder via
     * composition, it does not extend it), so it has no access to write these
     * protected properties directly — PHP's protected visibility only allows
     * cross-instance access from within a method of the SAME class. This is the
     * only way NestedQueryBuilder can hand its own queued state over for
     * flush_pending_relation_state() to process.
     *
     * @return $this
     */
    public function merge_pending_relation_state(
        $with_relations = [],
        $pending_aggregates = [],
        $pending_where_exists = [],
        $pending_where_aggregates = [],
        $pending_join_aggregates = [],
        $pending_where_has = []
    ) {
        if (!empty($with_relations)) {
            $this->with_relations = array_merge($this->with_relations, $with_relations);
        }
        if (!empty($pending_aggregates)) {
            $this->pending_aggregates = array_merge($this->pending_aggregates, $pending_aggregates);
        }
        if (!empty($pending_where_exists)) {
            $this->pending_where_exists = array_merge($this->pending_where_exists, $pending_where_exists);
        }
        if (!empty($pending_where_aggregates)) {
            $this->pending_where_aggregates = array_merge($this->pending_where_aggregates, $pending_where_aggregates);
        }
        if (!empty($pending_join_aggregates)) {
            $this->pending_join_aggregates = array_merge($this->pending_join_aggregates, $pending_join_aggregates);
        }
        if (!empty($pending_where_has)) {
            $this->pending_where_has = array_merge($this->pending_where_has, $pending_where_has);
        }
        return $this;
    }

    public function flush_pending_relation_state($context_table = null, $flush_select_aggregates = true)
    {
        if (!empty($this->pending_where_exists)) {
            $this->process_pending_where_exists($context_table);
        }
        if (!empty($this->pending_where_has)) {
            $this->process_pending_where_has();
        }
        if ($flush_select_aggregates && !empty($this->pending_aggregates)) {
            $this->process_pending_aggregates($context_table);
        } else {
            // Discard rather than leave dangling: the config was already copied into
            // pending_where_aggregates by where_aggregate() at call time if it was needed.
            $this->pending_aggregates = [];
        }
        if (!empty($this->pending_where_aggregates)) {
            $this->process_pending_where_aggregates($context_table);
        }
        if (!empty($this->pending_join_aggregates)) {
            $this->process_pending_join_aggregates();
        }
        return $this;
    }

    /**
     * Execute query with eager loading relations
     * 
     * @param string $table Table name (optional)
     * @param int|null $limit Limit number of results
     * @param int|null $offset Offset for results
     * @param int|null $found_rows Total found rows from SQL_CALC_FOUND_ROWS
     * @return CustomQueryBuilderResult Query result with loaded relations
     */
    protected function get_with_eager_loading($table = '', $limit = null, $offset = null, $found_rows = null)
    {
        $this->auto_include_relation_keys();

        $original_debug = $this->db_debug;
        $this->db_debug = FALSE;

        // Process pending WHERE queue and WHERE EXISTS relations
        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_queue($parent_table);
            $this->process_pending_where_exists($parent_table);
        }

        // Clear temporary table name — get() (the only caller of this method)
        // returns early into here without ever reaching its own cleanup line,
        // so without this, _temp_table_name from an eager-loading get() call
        // used to leak into whatever the next call on this same instance did.
        $this->_temp_table_name = null;

        $result = parent::get($table, $limit, $offset);

        // Capture main query
        $this->_executed_queries[] = parent::last_query();

        $error = $this->error();
        // BUG FIX: strict `!== 0` treats ANY non-int error code as an error —
        // but CI3's own PDO driver reports "no error" as the STRING '00000'
        // (not int 0), so every query against a pdo:* connection (mysql, pgsql,
        // sqlite, ...) used to be flagged as failed even on success. Loose `!=`
        // correctly treats '00000' as equal to 0 (PHP8 numeric-string
        // comparison) while still catching real non-numeric codes like
        // 'HY000/1' or an actual integer error code such as 1064.
        if ($error['code'] != 0)
            $this->handle_database_error($error);

        $this->db_debug = $original_debug;

        if ($result->num_rows() === 0) {
            $this->with_relations = [];
            return new CustomQueryBuilderResult([], $found_rows);
        }

        $data = $result->result_array();

        $data = $this->load_relations($data, $this->with_relations);

        $this->with_relations = [];

        $custom_result = new CustomQueryBuilderResult($data, $found_rows);

        return $custom_result;
    }

    /**
     * Automatically include required relation keys in SELECT clause
     * 
     * @return void
     */
    protected function auto_include_relation_keys()
    {
        if (empty($this->with_relations))
            return;

        $required_keys = [];

        foreach ($this->with_relations as $relation) {
            $local_keys = $relation['local_key'];
            if (is_array($local_keys)) {
                $required_keys = array_merge($required_keys, $local_keys);
            } else {
                $required_keys[] = $local_keys;
            }
        }

        $required_keys = array_unique($required_keys);

        $current_select = $this->qb_select;

        if (empty($current_select) || (count($current_select) === 1 && $current_select[0] === '*'))
            return;

        // Extract already selected fields including those with table aliases
        $selected_fields = [];
        foreach ($current_select as $select_item) {
            // Improved regex pattern to handle table aliases better
            $field_pattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?(?:\s+AS\s+`?(\w+)`?)?/i';
            if (preg_match($field_pattern, $select_item, $matches)) {
                $field_name = isset($matches[3]) ? $matches[3] : $matches[2];
                $selected_fields[] = $field_name;
            }
        }

        // Get main table alias (with potential alias)
        $table_alias = '';
        if (!empty($this->qb_from)) {
            $from_clause = $this->qb_from[0];
            $table_alias = $this->extract_table_or_alias($from_clause);
        }

        foreach ($required_keys as $key) {
            // Extract column name for validation (remove table prefix if exists)
            $key_for_validation = $key;
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key);
                $key_for_validation = end($parts);
            }

            // Validasi column name untuk mencegah injection
            if (!$this->is_valid_column_name($key_for_validation)) {
                continue; // Skip jika tidak valid
            }

            // Check if key is already selected (including with alias format _auto_rel_key)
            $auto_rel_key = '_auto_rel_' . $key_for_validation;

            if (!in_array($key_for_validation, $selected_fields) && !in_array($auto_rel_key, $selected_fields)) {
                $table_name = $this->protect_identifiers($table_alias, true);
                $column_name = $this->protect_identifiers($key_for_validation, true);
                $alias_name = $this->protect_identifiers($auto_rel_key, true);
                $this->select("{$table_name}.{$column_name} AS {$alias_name}", false);
            }
        }
    }

    /**
     * Load relations for given data
     * 
     * @param array $data Main query result data
     * @param array $relations Array of relation configurations
     * @return array Data with loaded relations
     */
    protected function load_relations($data, $relations)
    {
        if (empty($relations))
            return $data;

        foreach ($relations as $relation_config) {
            $data = $this->load_single_relation($data, $relation_config);
        }

        return $data;
    }

    /**
     * Apply the foreign-key match conditions used to fetch relation rows in
     * load_single_relation() — composite-key values become OR-of-AND groups,
     * a single key becomes a plain WHERE IN.
     *
     * Centralizes a block that used to be built twice, byte-for-byte
     * identically, inside load_single_relation() itself: once for the query
     * that fetches every relation row, and again — rebuilt from scratch — for
     * the separate query used only when a relation callback needs to run.
     *
     * @param CustomQueryBuilder $query Query instance already reset and from()'d to $relation
     * @param string $relation Relation table string (used to derive the default FK table prefix)
     * @param array $foreign_keys Foreign key column(s) in the relation table
     * @param array $composite_values JSON-encoded [key1, key2, ...] tuples (used when count($foreign_keys) > 1)
     * @param array $local_values Distinct local key values (used when count($foreign_keys) === 1)
     * @return void
     */
    protected function _apply_relation_key_filter($query, $relation, $foreign_keys, $composite_values, $local_values)
    {
        if (count($foreign_keys) > 1) {
            $query->group_start();
            $first_condition = true;

            foreach ($composite_values as $composite_value) {
                $key_parts = json_decode($composite_value, true);

                if (!$first_condition) {
                    $query->or_group_start();
                } else {
                    $query->group_start();
                }

                for ($i = 0; $i < count($foreign_keys); $i++) {
                    $query->where($foreign_keys[$i], $key_parts[$i]);
                }

                $query->group_end();
                $first_condition = false;
            }
            $query->group_end();
        } else {
            // Add table prefix to foreign key if not already present
            $foreign_key = $foreign_keys[0];
            if (strpos($foreign_key, '.') === false) {
                // Extract relation table name (without alias if present)
                $relation_table = $this->extract_table_name($relation);
                $foreign_key = $relation_table . '.' . $foreign_key;
            }

            $query->where_in($foreign_key, $local_values);
        }
    }

    /**
     * Load single relation for given data
     *
     * @param array $data Main query result data
     * @param array $config Relation configuration
     * @return array Data with loaded relation
     * @throws InvalidArgumentException
     */
    protected function load_single_relation($data, $config)
    {
        $local_keys = $config['local_key'];
        $foreign_keys = $config['foreign_key'];

        if (count($local_keys) !== count($foreign_keys)) {
            throw new InvalidArgumentException('Local and foreign key count mismatch. Local keys: ' . count($local_keys) . ', Foreign keys: ' . count($foreign_keys));
        }

        if (count($local_keys) > 1) {
            $composite_values = [];
            $composite_hashes = [];

            foreach ($data as $item) {
                $composite_key = [];
                foreach ($local_keys as $key) {
                    // Extract actual column name from key (remove table prefix if exists)
                    $actual_column = $key;
                    if (strpos($key, '.') !== false) {
                        $parts = explode('.', $key);
                        $actual_column = end($parts);
                    }

                    $aliased_key = "_auto_rel_{$actual_column}";
                    if (isset($item[$aliased_key])) {
                        $composite_key[] = $item[$aliased_key];
                    } elseif (isset($item[$actual_column])) {
                        $composite_key[] = $item[$actual_column];
                    } else {
                        $composite_key[] = null;
                    }
                }

                $key_hash = md5(json_encode($composite_key));
                if (!isset($composite_hashes[$key_hash])) {
                    $composite_hashes[$key_hash] = true;
                    $composite_values[] = json_encode($composite_key);
                }
            }

            $composite_values = array_filter($composite_values, function ($val) {
                $parts = json_decode($val, true);
                return is_array($parts) && !in_array('', $parts, true) && !in_array(null, $parts, true);
            });
        } else {
            $local_key = $local_keys[0];

            // Extract actual column name from local_key (remove table prefix if exists)
            $actual_column = $local_key;
            if (strpos($local_key, '.') !== false) {
                $parts = explode('.', $local_key);
                $actual_column = end($parts);
            }

            $aliased_key = "_auto_rel_{$actual_column}";

            $local_values = [];
            foreach ($data as $item) {
                if (isset($item[$aliased_key])) {
                    $local_values[] = $item[$aliased_key];
                } elseif (isset($item[$actual_column])) {
                    $local_values[] = $item[$actual_column];
                } else {
                    $local_values[] = null;
                }
            }

            $local_values = array_unique($local_values);
            $local_values = array_filter($local_values, function ($val) {
                return $val !== '' && $val !== null;
            });
        }

        // Only one of these is ever populated by the branch above, depending on
        // whether this is a composite (multi-column) or single-column key — default
        // the other to an empty array so passing both unconditionally into
        // _apply_relation_key_filter() below doesn't trigger an undefined-variable
        // notice (empty($undefined) is notice-safe, but a bare undefined variable
        // passed as a function argument is not).
        $composite_values = isset($composite_values) ? $composite_values : [];
        $local_values = isset($local_values) ? $local_values : [];

        if (
            (count($local_keys) > 1 && empty($composite_values)) ||
            (count($local_keys) === 1 && empty($local_values))
        ) {
            foreach ($data as &$item) {
                $item[$config['alias']] = $config['multiple'] ? [] : null;
            }
            return $data;
        }

        $relation_query = clone $this;
        $relation_query->_executed_queries = [];
        $relation_query->reset_query();
        $relation_query->from($config['relation']);
        $this->_apply_relation_key_filter($relation_query, $config['relation'], $foreign_keys, $composite_values, $local_values);

        if (is_callable($config['callback'])) {
            $base_db = clone $this;
            $base_db->_executed_queries = [];
            $base_db->reset_query();
            $base_db->from($config['relation']);
            $this->_apply_relation_key_filter($base_db, $config['relation'], $foreign_keys, $composite_values, $local_values);

            $relation_builder = new NestedQueryBuilder($base_db);

            $config['callback']($relation_builder);

            // Process pending WHERE EXISTS from NestedQueryBuilder
            if (!empty($relation_builder->pending_where_exists)) {
                // Transfer pending_where_exists to base_db for processing
                $relation_builder->db->pending_where_exists = $relation_builder->pending_where_exists;
                // Process them using the relation table name as parent
                $relation_builder->db->process_pending_where_exists($config['relation']);
                // Clear the pending operations
                $relation_builder->pending_where_exists = [];
            }

            // Process pending aggregates from NestedQueryBuilder
            if (!empty($relation_builder->pending_aggregates)) {
                // Ensure we have a proper SELECT clause first
                $current_select = $relation_builder->db->qb_select;
                if (empty($current_select)) {
                    // If no SELECT is specified, add * to get all columns
                    $relation_builder->db->select('*');
                }

                foreach ($relation_builder->pending_aggregates as $aggregate_config) {
                    $subquery = clone $this;
                    $subquery->reset_query();

                    $table_name = $this->extract_table_name($aggregate_config['relation']);
                    $relation_table_name = $this->extract_table_or_alias($aggregate_config['relation']);
                    // Generate subquery alias to avoid ambiguity in self-joins
                    // Format: tablename_sub (e.g., transaction_detail_sub)
                    $subquery_alias = $relation_table_name;

                    // Detect whether the relation is a raw SQL subquery (e.g. "(SELECT ...) alias").
                    // BUG FIX: this nested-aggregate path (with_count()/with_sum()/etc. called
                    // inside a with_one()/with_many() relation callback) previously never checked
                    // this, unlike its top-level counterpart process_pending_aggregates() — a raw
                    // subquery relation here fell through to extract_table_name()'s naive
                    // explode(' ', ...)[0], which mangles "(SELECT col FROM t) alias" into the
                    // broken token "(SELECT", producing an invalid FROM clause below.
                    $is_subquery_relation = ltrim($aggregate_config['relation'])[0] === '(';

                    // Build aggregate function based on type
                    $aggregate_function = $this->_build_aggregate_function(
                        $aggregate_config['type'],
                        $aggregate_config['column'],
                        $aggregate_config['is_custom_expression'],
                        $subquery_alias
                    );

                    // Use table alias in FROM clause for subquery
                    $subquery->select($aggregate_function);
                    if ($is_subquery_relation) {
                        // Raw subquery: directly assign to qb_from to prevent CI from
                        // backtick-escaping it. The relation string is already well-formed,
                        // e.g. "(SELECT ...) transaction_sub".
                        $subquery->qb_from[] = $aggregate_config['relation'];
                    } else {
                        $subquery->from($table_name . ' ' . $subquery_alias);
                    }

                    $aggregate_foreign_keys = $aggregate_config['foreign_key'];
                    $aggregate_local_keys = $aggregate_config['local_key'];

                    for ($i = 0; $i < count($aggregate_foreign_keys); $i++) {
                        // Foreign key qualifies against the aggregate subquery's own alias;
                        // local key qualifies against the PARENT relation's table (config['relation']
                        // is always a bare, alias-free table name here — with()/with_one()/with_many()
                        // validate $relation via is_valid_table_name(), which rejects any whitespace).
                        $aggregate_foreign_key_with_table = $this->_qualify_key($aggregate_foreign_keys[$i], $subquery_alias);
                        $aggregate_local_key_with_table = $this->_qualify_key($aggregate_local_keys[$i], $config['relation']);

                        $aggregate_foreign_key_safe = $relation_builder->db->protect_identifiers($aggregate_foreign_key_with_table, true);
                        $aggregate_local_key_safe = $relation_builder->db->protect_identifiers($aggregate_local_key_with_table, true);

                        $subquery->where("$aggregate_foreign_key_safe = $aggregate_local_key_safe", null, false);
                    }

                    // BUG FIX: this call site used to inline just the FROM-swap half of this
                    // pattern and never called flush_pending_relation_state() afterwards — a
                    // with_count()/with_sum()/where_aggregate() queued inside THIS callback
                    // (i.e. an aggregate nested two levels deep: with_one/with_many -> with_sum
                    // callback -> another with_count/where_aggregate) was silently dropped here,
                    // unlike the identical nesting one level shallower in
                    // process_pending_aggregates()/process_pending_where_aggregates(), which did
                    // flush correctly. Routing through the same shared helper fixes that gap.
                    $this->_run_aggregate_callback($subquery, $subquery_alias, $aggregate_config['callback']);

                    // Add subquery to main query SELECT (append to existing SELECT, not replace)
                    $compiled_subquery = $subquery->get_compiled_select();
                    $result_alias = $aggregate_config['alias'];
                    if ($table_name != $subquery_alias)
                        $result_alias = $this->extract_table_or_alias($aggregate_config['alias']);
                    $relation_builder->db->select("($compiled_subquery) as {$result_alias}", false);
                }
            }

            // Process pending WHERE HAS conditions from NestedQueryBuilder
            if (!empty($relation_builder->pending_where_has)) {
                // Transfer pending_where_has to base_db for processing
                $relation_builder->db->pending_where_has = $relation_builder->pending_where_has;
                $relation_builder->db->process_pending_where_has();
                // Clear the pending operations
                $relation_builder->pending_where_has = [];
            }

            // Process pending WHERE aggregates from NestedQueryBuilder
            if (!empty($relation_builder->pending_where_aggregates)) {
                // Transfer pending_where_aggregates to base_db for processing
                $relation_builder->db->pending_where_aggregates = $relation_builder->pending_where_aggregates;
                // Process them using the relation table name as parent
                $relation_builder->db->process_pending_where_aggregates($config['relation']);
                // Clear the pending operations
                $relation_builder->pending_where_aggregates = [];
            }

            // Process pending JOIN aggregates from NestedQueryBuilder
            if (!empty($relation_builder->pending_join_aggregates)) {
                // Transfer pending_join_aggregates to base_db for processing
                $relation_builder->db->pending_join_aggregates = $relation_builder->pending_join_aggregates;
                // process_pending_join_aggregates() uses qb_from which is already set to the relation table
                $relation_builder->db->process_pending_join_aggregates();
                // Clear the pending operations
                $relation_builder->pending_join_aggregates = [];
            }

            $auto_added_fk_columns = [];
            foreach ($foreign_keys as $fk) {
                // Extract actual column name without table prefix for auto_include
                $fk_column = $fk;
                if (strpos($fk, '.') !== false) {
                    $parts = explode('.', $fk);
                    $fk_column = end($parts);
                }
                if ($this->auto_include_foreign_key($relation_builder->db, $fk_column)) {
                    $auto_added_fk_columns[] = $fk_column;
                }
            }

            $this->auto_include_nested_keys($relation_builder);

            if (!empty($relation_builder->with_relations)) {
                $relation_result = $relation_builder->db->get();
                // Capture relation queries (including any nested eager loading sub-queries)
                if (!empty($relation_builder->db->_executed_queries)) {
                    $this->_executed_queries = array_merge($this->_executed_queries, $relation_builder->db->_executed_queries);
                } else {
                    $sql = $relation_builder->db->last_query();
                    if ($sql)
                        $this->_executed_queries[] = $sql;
                }
                $relation_data = [];
                if ($relation_result->num_rows() > 0) {
                    $relation_data = $relation_result->result_array();
                    $relation_data = $this->load_relations($relation_data, $relation_builder->with_relations);
                }
            } else {
                $relation_result = $relation_builder->db->get();
                // Capture relation queries (including any nested eager loading sub-queries)
                if (!empty($relation_builder->db->_executed_queries)) {
                    $this->_executed_queries = array_merge($this->_executed_queries, $relation_builder->db->_executed_queries);
                } else {
                    $sql = $relation_builder->db->last_query();
                    if ($sql)
                        $this->_executed_queries[] = $sql;
                }
                $relation_data = $relation_result->result_array();
            }
        } else {
            $auto_added_fk_columns = [];
            foreach ($foreign_keys as $fk) {
                // Extract actual column name for auto_include
                $fk_column = $fk;
                if (strpos($fk, '.') !== false) {
                    $parts = explode('.', $fk);
                    $fk_column = end($parts);
                }
                if ($this->auto_include_foreign_key($relation_query, $fk_column)) {
                    $auto_added_fk_columns[] = $fk_column;
                }
            }

            $relation_result = $relation_query->get();
            // Capture relation query (simple path — no callback)
            $sql = $relation_query->last_query();
            if ($sql)
                $this->_executed_queries[] = $sql;
            $relation_data = $relation_result->result_array();
        }

        $grouped_relations = [];
        foreach ($relation_data as $relation_item) {
            if (count($foreign_keys) > 1) {
                $composite_key = [];
                foreach ($foreign_keys as $fk) {
                    // Extract actual column name for array access
                    $fk_column = $fk;
                    if (strpos($fk, '.') !== false) {
                        $parts = explode('.', $fk);
                        $fk_column = end($parts);
                    }
                    $composite_key[] = isset($relation_item[$fk_column]) ? $relation_item[$fk_column] : null;
                }
                $key = json_encode($composite_key);
            } else {
                // Extract actual column name for array access
                $fk_column = $foreign_keys[0];
                if (strpos($fk_column, '.') !== false) {
                    $parts = explode('.', $fk_column);
                    $fk_column = end($parts);
                }
                $key = isset($relation_item[$fk_column]) ? $relation_item[$fk_column] : null;
            }

            if ($config['multiple']) {
                if (!isset($grouped_relations[$key]))
                    $grouped_relations[$key] = [];
                $grouped_relations[$key][] = $relation_item;
            } else {
                if (self::FIX_WITH_ONE_ORDER_BY) {
                    // Only store the first occurrence so that the ORDER BY from the
                    // callback is respected (e.g. DESC keeps the highest value row).
                    if (!isset($grouped_relations[$key])) {
                        $grouped_relations[$key] = is_array($relation_item) ? (object) $relation_item : $relation_item;
                    }
                } else {
                    // Pre-fix behavior: the last matching row always wins,
                    // regardless of any order_by() in the relation callback.
                    $grouped_relations[$key] = is_array($relation_item) ? (object) $relation_item : $relation_item;
                }
            }
        }

        // Strip FK columns that were auto-added solely for grouping/matching.
        // The user did not select them; they should not appear in the final relation items.
        if (!empty($auto_added_fk_columns)) {
            foreach ($grouped_relations as &$rel_group) {
                if ($config['multiple']) {
                    foreach ($rel_group as &$rel_item) {
                        if (is_array($rel_item)) {
                            foreach ($auto_added_fk_columns as $fk_col)
                                unset($rel_item[$fk_col]);
                        }
                    }
                    unset($rel_item);
                } else {
                    if (is_object($rel_group)) {
                        foreach ($auto_added_fk_columns as $fk_col)
                            unset($rel_group->$fk_col);
                    } elseif (is_array($rel_group)) {
                        foreach ($auto_added_fk_columns as $fk_col)
                            unset($rel_group[$fk_col]);
                    }
                }
            }
            unset($rel_group);
        }

        foreach ($data as &$item) {
            if (count($local_keys) > 1) {
                $composite_key = [];
                foreach ($local_keys as $lk) {
                    // Extract actual column name
                    $lk_column = $lk;
                    if (strpos($lk, '.') !== false) {
                        $parts = explode('.', $lk);
                        $lk_column = end($parts);
                    }

                    $aliased_key = "_auto_rel_{$lk_column}";
                    if (isset($item[$aliased_key])) {
                        $composite_key[] = $item[$aliased_key];
                    } elseif (isset($item[$lk_column])) {
                        $composite_key[] = $item[$lk_column];
                    } else {
                        $composite_key[] = null;
                    }
                }
                $local_value = json_encode($composite_key);
            } else {
                // Extract actual column name
                $local_key = $local_keys[0];
                $lk_column = $local_key;
                if (strpos($local_key, '.') !== false) {
                    $parts = explode('.', $local_key);
                    $lk_column = end($parts);
                }

                $aliased_key = "_auto_rel_{$lk_column}";
                if (isset($item[$aliased_key])) {
                    $local_value = $item[$aliased_key];
                } elseif (isset($item[$lk_column])) {
                    $local_value = $item[$lk_column];
                } else {
                    $local_value = null;
                }
            }

            // BUG FIX: this used to guess "is this relation actually an aggregate
            // result?" purely from whether $config['alias'] happened to END IN
            // "_count"/"_sum"/"_avg"/"_max"/"_min" (e.g. an alias the caller chose
            // for an ordinary with_one()/with_many() relation, like 'top_score_sum').
            // load_single_relation() only ever runs for with()/with_one()/with_many()
            // configs — with_count()/with_sum()/etc. (top-level AND nested inside a
            // relation callback) are compiled as SELECT subquery columns elsewhere and
            // never reach this matching code, so there is no legitimate case here
            // where $relation_data is actually a {'value': ...} wrapper to unwrap.
            // The guess produced two kinds of silent corruption: a real relation row
            // that happened to have a column literally named "value" (e.g. the alias
            // 'top_score_sum' matching a scores row {id, user_id, value}) got collapsed
            // down to that bare scalar, discarding the rest of the row; and a genuinely
            // unmatched with_one() relation aliased e.g. 'top_score_count' silently
            // renamed itself to 'top_score' and defaulted to 0 instead of the documented
            // with_one() no-match default of null. Always use $config['alias'] as-is.
            if (isset($grouped_relations[$local_value])) {
                $item[$config['alias']] = $grouped_relations[$local_value];
            } else {
                $item[$config['alias']] = $config['multiple'] ? [] : null;
            }
        }

        return $data;
    }

    /**
     * Automatically include foreign key in relation query SELECT
     * 
     * @param object $query_instance Database query instance
     * @param string $foreign_key Foreign key column name (may include table prefix)
     * @return void
     */
    protected function auto_include_foreign_key($query_instance, $foreign_key)
    {
        $current_select = $query_instance->qb_select;

        if (empty($current_select) || (count($current_select) === 1 && $current_select[0] === '*'))
            return;

        // Extract actual column name from foreign_key (remove table prefix if exists)
        $actual_column = $foreign_key;
        if (strpos($foreign_key, '.') !== false) {
            $parts = explode('.', $foreign_key);
            $actual_column = end($parts);
        }

        // If any select item is a table-wildcard (e.g. `table`.*), the FK is already
        // covered by that wildcard.  Adding a bare unqualified column on top of a JOIN
        // that also has the same column name in its derived table causes
        // "Column … in SELECT is ambiguous".  The wildcard already returns the FK,
        // so we can safely skip adding it again.
        foreach ($current_select as $select_item) {
            $clean = trim(str_replace('`', '', $select_item));
            if (preg_match('/^(\w+)\.\*$/', $clean))
                return false; // FK already covered by table.* wildcard
        }

        $selected_fields = [];
        foreach ($current_select as $select_item) {
            $field_pattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?(?:\s+AS\s+`?\w+`?)?/i';
            if (preg_match($field_pattern, $select_item, $matches)) {
                $selected_fields[] = $matches[2];
            }
        }

        if (!in_array($actual_column, $selected_fields)) {
            // Always qualify the FK with the main table name so it is never ambiguous
            // when a join_* derived sub-table also selects the same column for its GROUP BY.
            $main_table = '';
            if (!empty($query_instance->qb_from)) {
                $raw = trim(str_replace('`', '', $query_instance->qb_from[0]));
                // qb_from entry may be "table_name" or "table_name alias"
                $parts = preg_split('/\s+/', $raw);
                $main_table = end($parts); // last token = alias if present, otherwise table name
            }
            if ($main_table !== '') {
                $query_instance->select('`' . $main_table . '`.`' . $actual_column . '`', false);
            } else {
                $column_name = $query_instance->protect_identifiers($actual_column, true);
                $query_instance->select($column_name, false);
            }
            return true; // column was auto-added
        }
        return false; // column was already in user's SELECT
    }

    /**
     * Automatically include nested relation keys in SELECT
     * 
     * @param NestedQueryBuilder $relation_builder Nested query builder instance
     * @return void
     */
    protected function auto_include_nested_keys($relation_builder)
    {
        if (empty($relation_builder->with_relations))
            return;

        $required_keys = [];

        foreach ($relation_builder->with_relations as $nested_relation) {
            $local_keys = $nested_relation['local_key'];
            if (is_array($local_keys)) {
                $required_keys = array_merge($required_keys, $local_keys);
            } else {
                $required_keys[] = $local_keys;
            }
        }

        $required_keys = array_unique($required_keys);

        $current_select = $relation_builder->db->qb_select;

        if (empty($current_select) || (count($current_select) === 1 && $current_select[0] === '*'))
            return;

        // Extract selected fields — capture AS alias when present (mirrors auto_include_relation_keys)
        $selected_fields = [];
        foreach ($current_select as $select_item) {
            $field_pattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?(?:\s+AS\s+`?(\w+)`?)?/i';
            if (preg_match($field_pattern, $select_item, $matches)) {
                $field_name = isset($matches[3]) && $matches[3] !== '' ? $matches[3] : $matches[2];
                $selected_fields[] = $field_name;
            }
        }

        // Resolve relation table alias for column qualification
        $table_alias = '';
        if (!empty($relation_builder->db->qb_from)) {
            $table_alias = $this->extract_table_or_alias($relation_builder->db->qb_from[0]);
        }

        foreach ($required_keys as $key) {
            // Extract actual column name from key (remove table prefix if exists)
            $actual_column = $key;
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key);
                $actual_column = end($parts);
            }

            // Bug fix #3: validate column name to prevent injection (was missing entirely)
            if (!$this->is_valid_column_name($actual_column)) {
                continue;
            }

            // Bug fix #2: also check for the _auto_rel_ alias so we don't add duplicates
            $auto_rel_key = '_auto_rel_' . $actual_column;

            if (!in_array($actual_column, $selected_fields) && !in_array($auto_rel_key, $selected_fields)) {
                // Bug fix #1 & #4: qualify with table alias and add _auto_rel_ alias so the
                // column is tracked and cleaned up by remove_auto_relation_keys() later
                $tbl_name = $relation_builder->db->protect_identifiers($table_alias, true);
                $column_name = $relation_builder->db->protect_identifiers($actual_column, true);
                $alias_name = $relation_builder->db->protect_identifiers($auto_rel_key, true);
                if ($table_alias !== '') {
                    $relation_builder->db->select("{$tbl_name}.{$column_name} AS {$alias_name}", false);
                } else {
                    $relation_builder->db->select("{$column_name} AS {$alias_name}", false);
                }
            }
        }
    }

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
        $old_error_handler = set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }, E_ALL);

        $this->trans_begin();

        $result = null;
        $exception = null;

        try {
            // Execute callback
            $result = call_user_func($callback);
        } catch (Exception $e) {
            $exception = $e;
        }

        // Restore error handler dan error reporting
        error_reporting($old_error_reporting);

        if ($old_error_handler !== null) {
            set_error_handler($old_error_handler);
        } else {
            restore_error_handler();
        }

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
        $this->_temp_table_name = null;
        // A freshly reset instance (typically a clone used to build a relation/aggregate
        // subquery) must not inherit the outer query's in-flight reorder bookkeeping —
        // otherwise a stale buffered entry from an already-processed pending condition
        // gets spliced into the subquery's own qb_where instead of the outer one's.
        $this->_pending_where_reorder_buffer = [];
        $this->_call_order_seq = 0;
        $this->_manual_group_stack = [];
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
