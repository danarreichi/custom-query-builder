<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Where Exists/Has Trait
 *
 * where_exists()/where_has()/where_doesnt_have() and their callback/operator
 * variants, plus the pending-queue processors that compile them into
 * EXISTS/NOT EXISTS and COUNT(*) subqueries at get() time.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait WhereExistsHasTrait
{
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
                // Use relation identifier (alias if present, otherwise table name) for foreign key.
                // BUG FIX: this used to unconditionally concatenate $relation_identifier onto
                // $foreign_keys[$i] without checking for an existing dot, unlike every sibling
                // processor (process_single_where_exists(), process_pending_where_has(), etc.)
                // which all use _qualify_key(). An already-qualified foreign key (e.g. 'ms.idspk_workshop')
                // produced invalid double-qualified SQL like "ms.ms.idspk_workshop".
                $foreign_key_with_table = $this->_qualify_key($foreign_keys[$i], $relation_identifier);

                // Local key is prefixed with the parent table identifier only if it doesn't
                // already contain a table reference (contains a dot).
                $local_key_with_table = $this->_qualify_key($local_keys[$i], $parent_table_identifier);

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
}
