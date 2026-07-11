<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Custom Query Builder Result Class
 * 
 * Handles result data from custom query builder with relation support
 * 
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
class CustomQueryBuilderResult
{
    /**
     * @var array Result data
     */
    private $_data;

    /**
     * @var int Number of rows
     */
    private $_num_rows;

    /**
     * @var int|null Total found rows computed by calc_rows() (see CustomQueryBuilder::_count_found_rows())
     */
    private $_found_rows;

    /**
     * @var object|null Original driver result object (CI_DB_result), kept only
     * so uncommon methods (num_fields(), free_result(), etc.) still work when
     * this wrapper is used in place of the native result.
     */
    private $_original_result;

    /**
     * @var int|null Page number set by paginate(), null if paginate() wasn't used
     */
    private $_page;

    /**
     * @var int|null Per-page size set by paginate(), null if paginate() wasn't used
     */
    private $_per_page;

    /**
     * @var array|null Memoized result() output. $_data is set once in the
     * constructor and never mutated afterward, so it's safe to compute the
     * object-conversion once and reuse it on every subsequent call instead of
     * re-walking the whole result set each time.
     */
    private $_cached_object_result = null;

    /**
     * @var array|null Memoized result_array() output. See $_cached_object_result.
     */
    private $_cached_array_result = null;

    /**
     * Constructor
     *
     * @param array $data Result data
     * @param int|null $found_rows Total found rows computed by calc_rows() (see CustomQueryBuilder::_count_found_rows())
     * @param object|null $original_result Original driver result object to proxy uncommon calls to
     * @param int|null $page Page number set by paginate(), null if paginate() wasn't used
     * @param int|null $per_page Per-page size set by paginate(), null if paginate() wasn't used
     */
    public function __construct($data, $found_rows = null, $original_result = null, $page = null, $per_page = null)
    {
        $this->_data = is_array($data) ? $data : [];
        $this->_num_rows = count($this->_data);
        $this->_found_rows = $found_rows;
        $this->_original_result = $original_result;
        $this->_page = $page;
        $this->_per_page = $per_page;
    }

    /**
     * Proxy uncommon driver result methods (num_fields(), free_result(), etc.)
     * to the original result object, if available.
     *
     * @param string $name Method name
     * @param array $arguments Method arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if ($this->_original_result !== null && method_exists($this->_original_result, $name)) {
            return call_user_func_array([$this->_original_result, $name], $arguments);
        }

        throw new BadMethodCallException('Call to undefined method ' . get_class($this) . '::' . $name . '()');
    }

    /**
     * Key the result set by a column value, similar to Laravel's Collection::keyBy().
     *
     * Example:
     * $usersById = $this->db->get('users')->key_by('id');
     * // [1 => {...}, 2 => {...}, ...]
     *
     * If a key value repeats, the last matching row wins (same as Laravel).
     *
     * If a row is missing the key (or the key's value is null), the row is kept
     * under its original numeric index instead of being merged under a shared
     * `null` bucket — see the BUG FIX note below.
     *
     * @param string|callable $key Column name, or callback($row) returning the key
     * @param bool $as_array Return array rows instead of objects (default: false)
     * @return array Result data indexed by the given key
     */
    public function key_by($key, $as_array = false)
    {
        $rows = $as_array ? $this->result_array() : $this->result();

        // BUG FIX: is_callable('count') is TRUE — it returns true for any string
        // naming an existing function, not just callbacks the caller intended as
        // such. A perfectly ordinary aliased column like `COUNT(*) AS count`
        // used to be silently misdetected as a callback and invoked as count($row),
        // corrupting the whole result set instead of grouping by that column. A
        // plain string $key always means "column name" per this method's contract;
        // only a Closure or an array/object callable is treated as a callback.
        $is_callback = !is_string($key) && is_callable($key);

        $keyed = [];
        foreach ($rows as $index => $row) {
            if ($is_callback) {
                $key_value = $key($row);
            } elseif (is_object($row)) {
                $key_value = isset($row->$key) ? $row->$key : null;
            } else {
                $key_value = isset($row[$key]) ? $row[$key] : null;
            }

            if ($key_value === null) {
                // BUG FIX: every row missing the key used to fall back to the
                // same `null` bucket ($keyed[null] = $row), so each subsequent
                // missing-key row silently overwrote the previous one — a typo'd
                // or absent column collapsed the whole result set down to one
                // row with no error. Keep it under its original index instead,
                // and surface a warning so a genuinely missing column is noticed.
                trigger_error(
                    "CustomQueryBuilderResult::key_by(): key '" . ($is_callback ? '(callback)' : $key) . "' is missing/null on row {$index}; keeping it under its original index instead of merging it with other rows.",
                    E_USER_WARNING
                );
                $keyed[$index] = $row;
                continue;
            }

            $keyed[$key_value] = $row;
        }

        return $keyed;
    }

    /**
     * Get number of rows in result
     * 
     * @return int Number of rows
     */
    public function num_rows()
    {
        return $this->_num_rows;
    }

    /**
     * Get total found rows computed by a preceding calc_rows() query
     *
     * This method returns the total number of rows that would have been
     * returned without LIMIT when calc_rows() was used.
     * 
     * Example:
     * $result = $this->db->select(['id', 'name'])
     *                    ->calc_rows()
     *                    ->get('users', 10, 0);
     * 
     * $data = $result->result(); // 10 rows
     * $total = $result->found_rows(); // Total available rows (e.g., 1000)
     * 
     * @return int|null Total found rows, or null if calc_rows() was not used
     */
    public function found_rows()
    {
        return $this->_found_rows;
    }

    /**
     * Current page number, as passed to paginate() — see CustomQueryBuilder::paginate().
     *
     * @return int|null Null if paginate() wasn't used for this query
     */
    public function current_page()
    {
        return $this->_page;
    }

    /**
     * Per-page size, as passed to paginate().
     *
     * @return int|null Null if paginate() wasn't used for this query
     */
    public function per_page()
    {
        return $this->_per_page;
    }

    /**
     * Total number of pages, derived from found_rows()/per_page().
     *
     * @return int|null Null if paginate() wasn't used for this query
     */
    public function last_page()
    {
        if ($this->_page === null || $this->_per_page === null || $this->_found_rows === null)
            return null;

        return (int) max(1, (int) ceil($this->_found_rows / $this->_per_page));
    }

    /**
     * Whether there's at least one more page after the current one.
     *
     * @return bool False if paginate() wasn't used for this query
     */
    public function has_more_pages()
    {
        $last_page = $this->last_page();
        if ($last_page === null)
            return false;

        return $this->_page < $last_page;
    }

    /**
     * 1-indexed position of this page's first row within the full result set
     * (e.g. page 3 at 20 per page -> 41), or null on an empty page/result.
     *
     * @return int|null
     */
    public function from()
    {
        if ($this->_page === null || $this->_per_page === null || empty($this->_found_rows))
            return null;

        return (($this->_page - 1) * $this->_per_page) + 1;
    }

    /**
     * 1-indexed position of this page's last row within the full result set
     * (e.g. page 3 at 20 per page, 47 total rows -> 47, not 60), or null on an
     * empty page/result.
     *
     * @return int|null
     */
    public function to()
    {
        if ($this->_page === null || $this->_per_page === null || empty($this->_found_rows))
            return null;

        return min($this->_page * $this->_per_page, $this->_found_rows);
    }

    /**
     * Laravel-shaped pagination array — data plus every pagination field
     * above, packaged for e.g. a JSON API response. Prefer the individual
     * methods (current_page(), last_page(), etc.) when you only need one or
     * two fields; use this when you want the whole shape at once.
     *
     * @return array{data: array, current_page: int|null, per_page: int|null, total: int|null, last_page: int|null, from: int|null, to: int|null, has_more_pages: bool}
     */
    public function to_pagination_array()
    {
        return [
            'data' => $this->result_array(),
            'current_page' => $this->current_page(),
            'per_page' => $this->per_page(),
            'total' => $this->found_rows(),
            'last_page' => $this->last_page(),
            'from' => $this->from(),
            'to' => $this->to(),
            'has_more_pages' => $this->has_more_pages(),
        ];
    }

    /**
     * Get result as array
     *
     * Memoized — repeated calls reuse the same converted array instead of
     * re-walking $_data each time.
     *
     * @return array Result data as array
     */
    public function result_array()
    {
        if ($this->_cached_array_result === null) {
            $this->_cached_array_result = $this->convert_relations_to_array($this->_data);
        }
        return $this->_cached_array_result;
    }

    /**
     * Get result as objects
     *
     * Memoized — repeated calls reuse the same converted array instead of
     * re-walking $_data each time.
     *
     * @return array Result data as objects
     */
    public function result()
    {
        if ($this->_cached_object_result === null) {
            $this->_cached_object_result = $this->convert_relations_to_object($this->_data);
        }
        return $this->_cached_object_result;
    }

    /**
     * Get single row as array
     *
     * Reuses result_array()'s cache if it's already been computed; otherwise
     * converts only this one row, so calling row_array() alone stays cheap.
     *
     * @param int $index Row index (default: 0)
     * @return array|null Single row data as array or null if not found
     */
    public function row_array($index = 0)
    {
        if ($this->_cached_array_result !== null) {
            return isset($this->_cached_array_result[$index]) ? $this->_cached_array_result[$index] : null;
        }
        if (empty($this->_data) || !isset($this->_data[$index]))
            return null;
        $converted = $this->convert_relations_to_array([$this->_data[$index]]);
        return isset($converted[0]) ? $converted[0] : null;
    }

    /**
     * Get single row as object
     *
     * Reuses result()'s cache if it's already been computed; otherwise
     * converts only this one row, so calling row() alone stays cheap.
     *
     * @param int $index Row index (default: 0)
     * @return object|null Single row data as object or null if not found
     */
    public function row($index = 0)
    {
        if ($this->_cached_object_result !== null) {
            return isset($this->_cached_object_result[$index]) ? $this->_cached_object_result[$index] : null;
        }
        if (!isset($this->_data[$index]))
            return null;
        $converted = $this->convert_relations_to_object([$this->_data[$index]]);
        return $converted[0];
    }

    /**
     * Get a single column's value from the first row already fetched
     *
     * Example:
     * $email = $this->db->where('id', 1)->get('users')->value('email');
     *
     * @param string $column Column name to retrieve
     * @return mixed|null Column value, or null if there are no rows
     */
    public function value($column)
    {
        $row = $this->row();
        if ($row === null)
            return null;

        $property = strpos($column, '.') !== false ? substr($column, strrpos($column, '.') + 1) : $column;
        return isset($row->$property) ? $row->$property : null;
    }

    /**
     * Convert relations to array format recursively
     * 
     * @param array $data Data to convert
     * @return array Converted data
     */
    private function convert_relations_to_array($data)
    {
        return array_map(function ($item) {
            if (is_object($item))
                $item = (array) $item;
            foreach ($item as $k => $v) {
                if (is_object($v)) {
                    $item[$k] = $this->deep_object_to_array($v);
                } elseif (is_array($v)) {
                    if ($this->is_array_list($v)) {
                        $item[$k] = array_map(function ($child) {
                            return is_object($child) || is_array($child) ? $this->deep_object_to_array($child) : $child;
                        }, $v);
                    } else {
                        $item[$k] = $this->deep_object_to_array($v);
                    }
                }
            }
            $item = $this->remove_auto_relation_keys($item);
            return $item;
        }, $data);
    }

    /**
     * Convert relations to object format recursively
     * 
     * @param array $data Data to convert
     * @return array Converted data as objects
     */
    private function convert_relations_to_object($data)
    {
        return array_map(function ($item) {
            if (is_object($item))
                $item = (array) $item;
            foreach ($item as $k => $v) {
                if (is_array($v)) {
                    if ($this->is_array_list($v)) {
                        $item[$k] = array_map(function ($child) {
                            return is_array($child) ? $this->deep_array_to_object($child) : $child;
                        }, $v);
                    } else {
                        $item[$k] = $this->deep_array_to_object($v);
                    }
                } elseif (is_object($v)) {
                    $item[$k] = $this->deep_array_to_object((array) $v);
                }
            }
            $item = $this->remove_auto_relation_keys($item);
            return (object) $item;
        }, $data);
    }

    /**
     * Deep convert data recursively
     * 
     * @param mixed $data Data to convert
     * @param bool $to_object Whether to convert associative arrays to objects
     * @param int $depth Current recursion depth
     * @param int $maxDepth Maximum allowed recursion depth
     * @return mixed Converted data
     */
    private function deep_convert($data, $to_object = false, $depth = 0, $maxDepth = 20)
    {
        if ($depth > $maxDepth) {
            // Truncating here (instead of recursing further) protects against
            // unbounded/circular structures, but doing it silently means real
            // data loss at this depth would go completely unnoticed — surface
            // it as a warning so it shows up in logs instead of just vanishing.
            trigger_error(
                "CustomQueryBuilderResult: data truncated at depth > {$maxDepth} while converting result data. " .
                "This usually means an unexpectedly deep/circular nested structure — some fields were dropped.",
                E_USER_WARNING
            );
            return null; // Prevent infinite recursion
        }

        if (is_object($data))
            $data = (array) $data;
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_object($v) || is_array($v)) {
                    $data[$k] = $this->deep_convert($v, $to_object, $depth + 1, $maxDepth);
                }
            }

            // BUG FIX: convert_relations_to_array()/convert_relations_to_object()
            // only strip "_auto_rel_*" bookkeeping keys off the TOP-level row —
            // a nested relation (e.g. users -> posts -> comments, where loading
            // "comments" needs "posts" to auto-select its own join column) never
            // went through that strip, so the internal "_auto_rel_*" key leaked
            // into every nested relation row returned to the caller. A "list"
            // (sequential array of sibling relation rows) has no such keys of
            // its own — only its individual associative row items do, and those
            // get stripped here on their own recursive pass.
            if (!$this->is_array_list($data)) {
                $data = $this->remove_auto_relation_keys($data);
            }

            if ($to_object && !$this->is_array_list($data)) {
                return (object) $data;
            }
        }

        return $data;
    }

    /**
     * Deep convert object to array recursively
     * 
     * @param mixed $data Data to convert
     * @param int $depth Current recursion depth
     * @param int $maxDepth Maximum allowed recursion depth
     * @return mixed Converted data
     */
    private function deep_object_to_array($data, $depth = 0, $maxDepth = 20)
    {
        return $this->deep_convert($data, false, $depth, $maxDepth);
    }

    /**
     * Deep convert array to object recursively
     * 
     * @param mixed $data Data to convert
     * @param int $depth Current recursion depth
     * @param int $maxDepth Maximum allowed recursion depth
     * @return mixed Converted data
     */
    private function deep_array_to_object($data, $depth = 0, $maxDepth = 20)
    {
        return $this->deep_convert($data, true, $depth, $maxDepth);
    }

    /**
     * Check if array is indexed (list) array
     * 
     * @param array $arr Array to check
     * @return bool True if indexed array, false if associative
     */
    private function is_array_list(array $arr)
    {
        if (empty($arr))
            return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Remove auto-generated relation keys from item
     * 
     * @param mixed $item Item to process
     * @return mixed Processed item
     */
    private function remove_auto_relation_keys($item)
    {
        if (is_array($item)) {
            foreach ($item as $key => $value) {
                if (is_string($key) && strpos($key, '_auto_rel_') === 0)
                    unset($item[$key]);
            }
        }
        return $item;
    }
}
