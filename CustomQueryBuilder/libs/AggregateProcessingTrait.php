<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Aggregate Processing Trait
 *
 * Processing counterpart to RelationAggregateTrait (which only handles
 * with_count()/with_sum()/... registration): builds and flushes the pending
 * SELECT-subquery and WHERE-subquery aggregates, and the derived-table JOIN
 * aggregates (join_count()/join_sum()/...), at get() time.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait AggregateProcessingTrait
{
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
}
