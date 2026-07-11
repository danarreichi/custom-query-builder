<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Eager Loading Trait
 *
 * with_one()/with_many() and the load_relations()/load_single_relation()
 * machinery that executes queued relation queries and attaches results to
 * the parent row set, including nested/auto-included key handling.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait EagerLoadingTrait
{
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
            // Strip either quote style (backtick for mysqli, double-quote for
            // sqlite3/most others) — these entries were built by our own
            // driver-aware quoting, so either could show up here.
            $clean = trim(str_replace(['`', '"'], '', $select_item));
            if (preg_match('/^(\w+)\.\*$/', $clean))
                return false; // FK already covered by table.* wildcard
        }

        $selected_fields = [];
        foreach ($current_select as $select_item) {
            $field_pattern = '/(?:["`]?(\w+)["`]?\.)?["`]?(\w+)["`]?(?:\s+AS\s+["`]?\w+["`]?)?/i';
            if (preg_match($field_pattern, $select_item, $matches)) {
                $selected_fields[] = $matches[2];
            }
        }

        if (!in_array($actual_column, $selected_fields)) {
            // Always qualify the FK with the main table name so it is never ambiguous
            // when a join_* derived sub-table also selects the same column for its GROUP BY.
            $main_table = '';
            if (!empty($query_instance->qb_from)) {
                $raw = trim(str_replace(['`', '"'], '', $query_instance->qb_from[0]));
                // qb_from entry may be "table_name" or "table_name alias"
                $parts = preg_split('/\s+/', $raw);
                $main_table = end($parts); // last token = alias if present, otherwise table name
            }
            if ($main_table !== '') {
                $query_instance->select($this->_qi($main_table) . '.' . $this->_qi($actual_column), false);
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
}
