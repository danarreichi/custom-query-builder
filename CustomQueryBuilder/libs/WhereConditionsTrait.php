<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Where Conditions Trait
 *
 * Extra WHERE-clause helpers layered on top of CI's native where_in/where_not_in
 * (safe large-IN-list handling), plus not/null/between/or-variants shorthand.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait WhereConditionsTrait
{
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
        // BUG FIX: qb_where/qb_cache_where being empty (or qb_where_group_started
        // being set) doesn't mean this is really first — an earlier-registered
        // deferred condition (see _has_unresolved_deferred_conditions()) may
        // still land in front of it once flushed, in which case omitting the
        // prefix here produces invalid SQL once that condition is spliced back
        // in. _group_get_type() below would otherwise consume/reset
        // qb_where_group_started on its own regardless of which branch runs,
        // wrongly suppressing this call's connector — clear it first so that
        // doesn't happen.
        $has_unresolved = $this->_has_unresolved_deferred_conditions();
        if ($has_unresolved) {
            $this->qb_where_group_started = false;
        }
        $treat_as_first = count($this->qb_where) === 0
            && count($this->qb_cache_where) === 0
            && !$has_unresolved;
        $prefix = $treat_as_first
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
}
