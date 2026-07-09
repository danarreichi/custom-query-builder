<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Conditional Helpers Trait
 *
 * when()/unless() conditional query-building callbacks, and search() for
 * multi-column LIKE conditions.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait ConditionalHelpersTrait
{
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
}
