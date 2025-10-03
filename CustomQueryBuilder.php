<?php
defined('BASEPATH') or exit('No direct script access allowed');

require BASEPATH . 'database/DB_query_builder.php';

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
     * @var int|null Total found rows from SQL_CALC_FOUND_ROWS
     */
    private $_found_rows;

    /**
     * Constructor
     * 
     * @param array $data Result data
     * @param int|null $found_rows Total found rows from SQL_CALC_FOUND_ROWS
     */
    public function __construct($data, $found_rows = null)
    {
        $this->_data = is_array($data) ? $data : [];
        $this->_num_rows = count($this->_data);
        $this->_found_rows = $found_rows;
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
     * Get total found rows from SQL_CALC_FOUND_ROWS
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
     * Get result as array
     * 
     * @return array Result data as array
     */
    public function result_array()
    {
        return $this->convert_relations_to_array($this->_data);
    }

    /**
     * Get result as objects
     * 
     * @return array Result data as objects
     */
    public function result()
    {
        return $this->convert_relations_to_object($this->_data);
    }

    /**
     * Get single row as array
     * 
     * @param int $index Row index (default: 0)
     * @return array|null Single row data as array or null if not found
     */
    public function row_array($index = 0)
    {
        if (empty($this->_data) || !isset($this->_data[$index])) return null;
        $converted = $this->convert_relations_to_array([$this->_data[$index]]);
        return isset($converted[0]) ? $converted[0] : null;
    }

    /**
     * Get single row as object
     * 
     * @param int $index Row index (default: 0)
     * @return object|null Single row data as object or null if not found
     */
    public function row($index = 0)
    {
        if (!isset($this->_data[$index])) return null;
        $converted = $this->convert_relations_to_object([$this->_data[$index]]);
        return $converted[0];
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
            if (is_object($item)) $item = (array) $item;
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
            if (is_object($item)) $item = (array) $item;
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
     * Deep convert object to array recursively
     * 
     * @param mixed $data Data to convert
     * @param int $depth Current recursion depth
     * @param int $maxDepth Maximum allowed recursion depth
     * @return mixed Converted data
     */
    private function deep_object_to_array($data, $depth = 0, $maxDepth = 20)
    {
        if ($depth > $maxDepth) return null; // Prevent infinite recursion

        if (is_object($data)) $data = (array) $data;
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_object($v) || is_array($v)) $data[$k] = $this->deep_object_to_array($v, $depth + 1, $maxDepth);
            }
        }

        return $data;
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
        if ($depth > $maxDepth) return null; // Prevent infinite recursion

        if (is_object($data)) $data = (array) $data;
        if (is_array($data)) {
            if ($this->is_array_list($data)) {
                foreach ($data as $k => $v) {
                    if (is_array($v) || is_object($v)) $data[$k] = $this->deep_array_to_object($v, $depth + 1, $maxDepth);
                }
                return $data;
            } else {
                foreach ($data as $k => $v) {
                    if (is_array($v) || is_object($v)) $data[$k] = $this->deep_array_to_object($v, $depth + 1, $maxDepth);
                }
                return (object) $data;
            }
        }

        return $data;
    }

    /**
     * Check if array is indexed (list) array
     * 
     * @param array $arr Array to check
     * @return bool True if indexed array, false if associative
     */
    private function is_array_list(array $arr)
    {
        if (empty($arr)) return true;
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
                if (is_string($key) && strpos($key, '_auto_rel_') === 0) unset($item[$key]);
            }
        }
        return $item;
    }
}

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
        return call_user_func_array([$this->db, $method], $args);
    }

    /**
     * Add eager loading relation
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param bool $multiple Whether relation returns multiple records
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     * @throws InvalidArgumentException
     */
    public function with($relation, $foreignKey, $localKey, $multiple = true, $callback = null)
    {
        $relation_name = '';
        $alias = '';

        if (is_array($relation)) {
            if (count($relation) === 1) {
                $relation_name = key($relation);
                $alias = current($relation);
            } else {
                $relation_name = reset($relation);
                $alias = $relation_name;
            }
        } else {
            $relation_name = $relation;
            $alias = $relation;
        }

        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (strpos($lk, '.') !== false) {
                $parts = explode('.', $lk);
                $processed_local_keys[] = end($parts);
            } else {
                $processed_local_keys[] = $lk;
            }
        }

        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (strpos($fk, '.') !== false) {
                $parts = explode('.', $fk);
                $processed_foreign_keys[] = end($parts);
            } else {
                $processed_foreign_keys[] = $fk;
            }
        }

        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Number of foreign keys must match number of local keys');
        }

        $this->with_relations[] = [
            'relation' => $relation_name,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'multiple' => $multiple,
            'callback' => $callback,
            'alias' => $alias
        ];
        return $this;
    }

    /**
     * Add eager loading relation with count aggregation
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_count($relation, $foreignKey, $localKey, $callback = null)
    {
        $relation_name = is_array($relation) ? key($relation) : $relation;
        $count_alias = is_array($relation) ? current($relation) : $relation_name . '_count';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'count',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $count_alias,
            'callback' => $callback,
            'column' => null,
            'is_custom_expression' => false
        ];

        return $this;
    }

    /**
     * Add eager loading relation with sum aggregation
     * 
     * Now works as subquery in main SELECT clause for better sorting capability.
     * 
     * Example:
     * // Get users with total order amount (can be sorted)
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->order_by('orders_sum', 'DESC')
     *                   ->get('users');
     * // Result: $user->orders_sum
     * 
     * // With alias
     * $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'total_amount');
     * // Result: $user->total_spent
     * 
     * // With custom expression (mathematical operations)
     * $invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
     *     'idinvoice', 'id', '(job_total_price_before_discount - job_discount)', true);
     * // Result: $invoice->total_after_discount
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('created_at >=', '2023-01-01');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
     *     'idinvoice', 'id', '(job_total_price_before_discount - job_discount)', true, 
     *     function($query) {
     *         $query->where('status', 'active');
     *     }
     * );
     * 
     * // Can be used with with_many() and with_one()
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->with_many('posts', 'user_id', 'id')
     *                   ->get('users');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to sum or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_sum($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $sum_alias = is_array($relation) ? current($relation) : $relation_name . '_sum';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'sum',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $sum_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with average aggregation
     * 
     * Example:
     * // Get users with average order amount
     * $users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_avg
     * 
     * // With alias
     * $this->db->with_avg(['orders' => 'avg_order_value'], 'user_id', 'id', 'total_amount');
     * // Result: $user->avg_order_value
     * 
     * // With custom expression (mathematical operations)
     * $orders = $this->db->with_avg('items', 'order_id', 'id', '(price * quantity)', true);
     * // Result: $order->items_avg (average of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where_between('created_at', ['2023-01-01', '2023-12-31']);
     * })->get('users');
     * 
     * // With custom expression and callback
     * $orders = $this->db->with_avg('items', 'order_id', 'id', '(price * quantity)', true,
     *     function($query) {
     *         $query->where('is_active', 1);
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to calculate average or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_avg($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $avg_alias = is_array($relation) ? current($relation) : $relation_name . '_avg';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'avg',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $avg_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with maximum value aggregation
     * 
     * Example:
     * // Get users with their highest order amount
     * $users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_max
     * 
     * // Get posts with latest comment date
     * $this->db->with_max(['comments' => 'latest_comment'], 'post_id', 'id', 'created_at');
     * // Result: $post->latest_comment
     * 
     * // With custom expression (mathematical operations)
     * $products = $this->db->with_max('sales', 'product_id', 'id', '(base_price + tax)', true);
     * // Result: $product->sales_max (maximum of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('payment_status', 'paid');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $products = $this->db->with_max('sales', 'product_id', 'id', '(base_price + tax)', true,
     *     function($query) {
     *         $query->where('sale_date >=', '2023-01-01');
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to find maximum value or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_max($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $max_alias = is_array($relation) ? current($relation) : $relation_name . '_max';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'max',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $max_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with minimum value aggregation
     * 
     * Example:
     * // Get users with their lowest order amount
     * $users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_min
     * 
     * // Get posts with earliest comment date
     * $this->db->with_min(['comments' => 'earliest_comment'], 'post_id', 'id', 'created_at');
     * // Result: $post->earliest_comment
     * 
     * // With custom expression (mathematical operations)
     * $products = $this->db->with_min('sales', 'product_id', 'id', '(base_price - discount)', true);
     * // Result: $product->sales_min (minimum of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('payment_status', 'paid');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $products = $this->db->with_min('sales', 'product_id', 'id', '(base_price - discount)', true,
     *     function($query) {
     *         $query->where('sale_date >=', '2023-01-01');
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to find minimum value or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_min($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $min_alias = is_array($relation) ? current($relation) : $relation_name . '_min';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'min',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $min_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add calculated field using custom mathematical expression with aggregate functions
     * 
     * This method allows you to create complex calculations using multiple aggregate functions
     * and mathematical operations in a subquery that becomes part of the main SELECT clause.
     * 
     * Example:
     * // Calculate efficiency percentage: (finished_qty / total_qty) * 100
     * $orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'], 
     *     'order_id', 'id', 
     *     '(SUM(finished_qty) / SUM(total_qty)) * 100'
     * )->get('orders');
     * // Result: $order->efficiency_percentage
     * 
     * // Calculate profit margin: ((revenue - cost) / revenue) * 100
     * $products = $this->db->with_calculation(['sales' => 'profit_margin'], 
     *     'product_id', 'id',
     *     '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100'
     * )->get('products');
     * 
     * // Calculate average order value with discount
     * $customers = $this->db->with_calculation(['orders' => 'avg_order_with_discount'], 
     *     'customer_id', 'id',
     *     'AVG(total_amount - discount_amount)'
     * )->get('customers');
     * 
     * // Calculate production duration in days using DATEDIFF
     * $transactions = $this->db->with_calculation(['transaction_step' => 'production_duration_days'], 
     *     'idtransaction_detail', 'idtransaction_detail',
     *     'DATEDIFF(MAX(date), MIN(date))'
     * )->get('transaction_detail');
     * 
     * // Calculate weighted average with callback for conditions
     * $products = $this->db->with_calculation(['reviews' => 'weighted_rating'], 
     *     'product_id', 'id',
     *     'SUM(rating * helpful_votes) / SUM(helpful_votes)',
     *     function($query) {
     *         $query->where('status', 'approved')
     *               ->where('helpful_votes >', 0);
     *     }
     * )->get('products');
     * 
     * // Multiple calculations in one query
     * $orders = $this->db->with_calculation(['order_items' => 'total_revenue'], 'order_id', 'id', 'SUM(price * quantity)')
     *                   ->with_calculation(['order_items' => 'total_cost'], 'order_id', 'id', 'SUM(cost * quantity)')
     *                   ->with_calculation(['order_items' => 'profit'], 'order_id', 'id', 'SUM((price - cost) * quantity)')
     *                   ->get('orders');
     * 
     * Supported mathematical operations:
     * - Basic math: +, -, *, /, %
     * - Aggregate functions: SUM, AVG, COUNT, MIN, MAX
     * - Date functions: DATEDIFF, TIMESTAMPDIFF
     * - Conditional: CASE WHEN ... THEN ... END
     * - Mathematical functions: ROUND, FLOOR, CEIL, ABS
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s) in the relation table
     * @param string|array $localKey Local key(s) in the main table
     * @param string $expression Mathematical expression with aggregate functions
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional WHERE conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function with_calculation($relation, $foreignKey, $localKey, $expression, $callback = null)
    {
        if (!is_callable($callback) && $callback) throw new InvalidArgumentException('Callback must be callable');
        if (!$this->is_valid_calculation_expression($expression)) {
            throw new InvalidArgumentException("Invalid calculation expression: {$expression}");
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $calc_alias = is_array($relation) ? current($relation) : $relation_name . '_calculation';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'custom_calculation',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $calc_alias,
            'callback' => $callback,
            'column' => $expression,
            'is_custom_expression' => true
        ];

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
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        $subquery = new NestedQueryBuilder($this->db);

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

        $subquery = new NestedQueryBuilder($this->db);

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

        $subquery = new NestedQueryBuilder($this->db);

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

        $subquery = new NestedQueryBuilder($this->db);

        // Execute callback to build subquery
        $callback($subquery);

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();
        
        // Add OR NOT EXISTS condition
        $this->db->or_where("NOT EXISTS ({$compiled_subquery})", null, false);

        return $this;
    }

    /**
     * Add WHERE EXISTS condition with relation support (simplified version)
     * 
     * This method provides a simplified interface for WHERE EXISTS queries
     * by automatically building the JOIN conditions based on foreign/local keys,
     * similar to how where_has() works.
     * 
     * Example:
     * // Users that have orders
     * $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id');
     * 
     * // Users that have active orders  
     * $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id', function($query) {
     *     $query->where('status', 'active');
     * });
     * 
     * // Multiple foreign keys
     * $this->db->from('users')->where_exists_relation('user_roles', ['user_id', 'tenant_id'], ['id', 'tenant_id']);
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'AND',
            'exists_type' => 'EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Add OR WHERE EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that have orders OR users that have posts
     * $this->db->from('users')
     *          ->where_exists_relation('orders', 'user_id', 'id')
     *          ->or_where_exists_relation('posts', 'user_id', 'id');
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'OR',
            'exists_type' => 'EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Add WHERE NOT EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that don't have any orders
     * $this->db->from('users')->where_not_exists_relation('orders', 'user_id', 'id');
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'AND',
            'exists_type' => 'NOT EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Add OR WHERE NOT EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that have orders OR users that don't have cancelled orders
     * $this->db->from('users')
     *          ->where_exists_relation('orders', 'user_id', 'id')
     *          ->or_where_not_exists_relation('orders', 'user_id', 'id', function($query) {
     *              $query->where('status', 'cancelled');
     *          });
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(NestedQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'OR',
            'exists_type' => 'NOT EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Validate column name to prevent SQL injection
     * 
     * @param string $column_name Column name to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_column_name($column_name)
    {
        // Check if column name is string and not empty
        if (!is_string($column_name) || empty($column_name)) {
            return false;
        }

        // Allow only alphanumeric characters, underscores, and dots
        // NO DASHES allowed to prevent SQL comment injection
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';

        if (!preg_match($pattern, $column_name)) {
            return false;
        }

        // Check against common SQL injection patterns including dashes
        $dangerous_patterns = [
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND|WHERE|FROM|JOIN|INTO|VALUES|SET|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i',
            '/[\'";-]/',        // ADDED: Dash character blocked
            '/--/',             // SQL comment pattern
            '/\/\*/',           // Multi-line comment start
            '/\*\//',           // Multi-line comment end
            '/\bxp_/',          // Extended stored procedures
            '/\bsp_/',          // Stored procedures
            '/\|\|/',           // OR operator in some SQL dialects
            '/&&/',             // AND operator in some SQL dialects
            '/\s+--/',          // Space followed by comment
            '/-{2,}/',          // Multiple dashes
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $column_name)) {
                return false;
            }
        }

        // Check length to prevent buffer overflow attacks
        if (strlen($column_name) > 64) {
            return false;
        }

        return true;
    }

    /**
     * Validate calculation expression for mathematical operations with aggregates
     * 
     * This method validates expressions that can contain:
     * - Aggregate functions (SUM, AVG, COUNT, MIN, MAX)
     * - Mathematical operations (+, -, *, /, %)
     * - Date functions (DATEDIFF, TIMESTAMPDIFF)
     * - Mathematical functions (ROUND, FLOOR, CEIL, ABS)
     * - Conditional expressions (CASE WHEN)
     * 
     * @param string $expression Mathematical expression to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_calculation_expression($expression)
    {
        // Check if expression is string and not empty
        if (!is_string($expression) || empty($expression)) {
            return false;
        }

        // Allow more extensive pattern for calculation expressions
        $allowed_pattern = '/^[\w\s\(\)\+\-\*\/\.,`%<>=]+$/';

        if (!preg_match($allowed_pattern, $expression)) {
            return false;
        }

        // Block dangerous SQL patterns but allow more functions for calculations
        $dangerous_patterns = [
            '/\b(INSERT|UPDATE|DELETE|DROP|UNION|EXEC|EXECUTE|CREATE|ALTER|TRUNCATE)\b/i',
            '/[\'";]/',           // Quotes and semicolons
            '/--/',               // SQL comments
            '/\/\*/',             // Multi-line comment start
            '/\*\//',             // Multi-line comment end
            '/\|\|/',             // String concatenation
            '/&&/',               // Logical AND
            '/\bxp_/',            // Extended stored procedures
            '/\bsp_/',            // Stored procedures
            '/\bINTO\b/i',        // INTO keyword
            '/\bVALUES\b/i',      // VALUES keyword
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $expression)) {
                return false;
            }
        }

        // Validate that parentheses are balanced
        if (!$this->are_parentheses_balanced($expression)) {
            return false;
        }

        // Check length to prevent buffer overflow
        if (strlen($expression) > 500) {
            return false;
        }

        // Validate tokens - allow aggregate functions and mathematical operations
        $tokens = preg_split('/[\s\(\)\+\-\*\/,%<>=]+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            // Skip numeric values
            if (is_numeric($token)) {
                continue;
            }

            // Allow aggregate functions
            $allowed_aggregates = ['SUM', 'AVG', 'COUNT', 'MIN', 'MAX'];
            if (in_array(strtoupper($token), $allowed_aggregates)) {
                continue;
            }

            // Allow date functions
            $allowed_date_functions = ['DATEDIFF', 'TIMESTAMPDIFF', 'DATE', 'NOW', 'CURDATE', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND'];
            if (in_array(strtoupper($token), $allowed_date_functions)) {
                continue;
            }

            // Allow mathematical functions
            $allowed_math_functions = ['ROUND', 'FLOOR', 'CEIL', 'ABS', 'POW', 'SQRT', 'MOD'];
            if (in_array(strtoupper($token), $allowed_math_functions)) {
                continue;
            }

            // Allow conditional functions
            $allowed_conditional = ['CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'IF', 'IFNULL', 'COALESCE', 'NULLIF'];
            if (in_array(strtoupper($token), $allowed_conditional)) {
                continue;
            }

            // Allow comparison operators
            $allowed_operators = ['AND', 'OR', 'NOT', 'IS', 'NULL', 'TRUE', 'FALSE'];
            if (in_array(strtoupper($token), $allowed_operators)) {
                continue;
            }

            // Check if it's a valid column name (also allows table.column format)
            if (!$this->is_valid_column_name($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate table name to prevent SQL injection
     * 
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_table_name($table_name)
    {
        // Check if table name is string and not empty
        if (!is_string($table_name) || empty($table_name)) {
            return false;
        }

        // Allow only alphanumeric characters and underscores
        // NO DASHES allowed to prevent SQL comment injection
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

        if (!preg_match($pattern, $table_name)) {
            return false;
        }

        // Check against common SQL injection patterns including dashes
        $dangerous_patterns = [
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND|WHERE|FROM|JOIN|INTO|VALUES|SET|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i',
            '/[\'";-]/',        // ADDED: Dash character blocked
            '/--/',             // SQL comment pattern
            '/\/\*/',           // Multi-line comment start
            '/\*\//',           // Multi-line comment end
            '/\s+--/',          // Space followed by comment
            '/-{2,}/',          // Multiple dashes
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $table_name)) {
                return false;
            }
        }

        // Check length to prevent buffer overflow attacks
        if (strlen($table_name) > 64) {
            return false;
        }

        return true;
    }

    /**
     * Validate custom SQL expression for aggregation functions
     * 
     * @param string $expression Custom SQL expression to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_custom_expression($expression)
    {
        // Check if expression is string and not empty
        if (!is_string($expression) || empty($expression)) {
            return false;
        }

        // Allow mathematical operations, column names, parentheses, and common SQL functions
        $allowed_pattern = '/^[\w\s\(\)\+\-\*\/\.,`]+$/';

        if (!preg_match($allowed_pattern, $expression)) {
            return false;
        }

        // Block dangerous SQL patterns
        $dangerous_patterns = [
            '/\b(INSERT|UPDATE|DELETE|DROP|UNION|EXEC|EXECUTE|CREATE|ALTER|TRUNCATE)\b/i',
            '/[\'";]/',           // Quotes and semicolons
            '/--/',               // SQL comments
            '/\/\*/',             // Multi-line comment start
            '/\*\//',             // Multi-line comment end
            '/\|\|/',             // OR operator
            '/&&/',               // AND operator
            '/\bxp_/',            // Extended stored procedures
            '/\bsp_/',            // Stored procedures
            '/\s+(AND|OR)\s+/i',  // AND/OR operators
            '/\bSELECT\b/i',      // Nested SELECT
            '/\bFROM\b/i',        // FROM keyword
            '/\bWHERE\b/i',       // WHERE keyword
            '/\bJOIN\b/i',        // JOIN keyword
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $expression)) {
                return false;
            }
        }

        // Validate that parentheses are balanced
        if (!$this->are_parentheses_balanced($expression)) {
            return false;
        }

        // Check length to prevent buffer overflow
        if (strlen($expression) > 200) {
            return false;
        }

        // Validate that expression contains only allowed column names and operators
        $tokens = preg_split('/[\s\(\)\+\-\*\/,]+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            // Skip numeric values
            if (is_numeric($token)) {
                continue;
            }

            // Skip common SQL functions
            $allowed_functions = ['COALESCE', 'IFNULL', 'IF', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END'];
            if (in_array(strtoupper($token), $allowed_functions)) {
                continue;
            }

            // Check if it's a valid column name
            if (!$this->is_valid_column_name($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if parentheses are balanced in expression
     * 
     * @param string $expression Expression to check
     * @return bool True if balanced, false otherwise
     */
    private function are_parentheses_balanced($expression)
    {
        $count = 0;
        $length = strlen($expression);
        
        for ($i = 0; $i < $length; $i++) {
            if ($expression[$i] === '(') {
                $count++;
            } elseif ($expression[$i] === ')') {
                $count--;
                // If we have more closing than opening, it's unbalanced
                if ($count < 0) {
                    return false;
                }
            }
        }
        
        // Should be zero if balanced
        return $count === 0;
    }
}

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
     * Get the parent table name from current query
     * 
     * This helper function extracts the main table name from the current query builder state.
     * Used by where_exists_relation() to automatically determine the parent table.
     * 
     * @param string $table_from_get Optional table name from get() method
     * @return string|null Parent table name or null if not found
     */
    protected function pending_where_exists_relation($table_from_get = null)
    {
        // First priority: table passed to get() method parameter
        if (!empty($table_from_get)) {
            return $this->extract_table_name($table_from_get);
        }
        
        // Second priority: temporary table name from get() method
        if (!empty($this->_temp_table_name)) {
            return $this->extract_table_name($this->_temp_table_name);
        }
        
        // Third priority: try to get from compiled select to extract table name
        try {
            $compiled = $this->get_compiled_select('', false); // Don't reset
            
            // Extract table name from FROM clause using regex
            if (preg_match('/FROM\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s+(?:AS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?)?/i', $compiled, $matches)) {
                return $matches[1]; // Return the table name without alias
            }
        } catch (Exception $e) {
            // Fallback: if no FROM is set yet, return null
            return null;
        }
        
        return null;
    }

    /**
     * Extract clean table name from table string (removes alias and backticks)
     * 
     * @param string $table_string Table string that may contain alias and backticks
     * @return string Clean table name
     */
    protected function extract_table_name($table_string)
    {
        // Remove any alias by splitting on space and taking first part
        $table_parts = explode(' ', trim($table_string));
        $table_name = trim($table_parts[0]);
        
        // Remove backticks if present
        $table_name = trim($table_name, '`');
        
        return $table_name;
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
        return parent::from($from);
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
        } else {
            if ($default) $default($this, $condition);
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
        if (count($values) !== 2) throw new InvalidArgumentException('where_between() expects exactly 2 values.');
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
        if (count($values) !== 2) throw new InvalidArgumentException('where_not_between() expects exactly 2 values.');
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
        if (count($values) !== 2) throw new InvalidArgumentException('or_where_between() expects exactly 2 values.');
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
        if (count($values) !== 2) throw new InvalidArgumentException('or_where_not_between() expects exactly 2 values.');
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

        // Process any pending operations in subquery
        if (!empty($subquery->pending_where_exists)) {
            // For standard where_exists, we need to determine parent table context
            // Since there's no explicit parent table, we'll use a generic approach
            $subquery->process_pending_where_exists('__parent__');
        }

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();
        
        // Add EXISTS condition
        $this->where("EXISTS ({$compiled_subquery})", null, false);

        return $this;
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
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        $subquery = clone $this;
        $subquery->reset_query();

        // Execute callback to build subquery
        $callback($subquery);

        // Process any pending operations in subquery
        if (!empty($subquery->pending_where_exists)) {
            $subquery->process_pending_where_exists('__parent__');
        }

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();
        
        // Add NOT EXISTS condition
        $this->where("NOT EXISTS ({$compiled_subquery})", null, false);

        return $this;
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
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        $subquery = clone $this;
        $subquery->reset_query();

        // Execute callback to build subquery
        $callback($subquery);

        // Process any pending operations in subquery
        if (!empty($subquery->pending_where_exists)) {
            $subquery->process_pending_where_exists('__parent__');
        }

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();
        
        // Add OR EXISTS condition
        $this->or_where("EXISTS ({$compiled_subquery})", null, false);

        return $this;
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
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        $subquery = clone $this;
        $subquery->reset_query();

        // Execute callback to build subquery
        $callback($subquery);

        // Process any pending operations in subquery
        if (!empty($subquery->pending_where_exists)) {
            $subquery->process_pending_where_exists('__parent__');
        }

        // Get the compiled subquery
        $compiled_subquery = $subquery->get_compiled_select();
        
        // Add OR NOT EXISTS condition
        $this->or_where("NOT EXISTS ({$compiled_subquery})", null, false);

        return $this;
    }

    /**
     * Add WHERE EXISTS condition with relation support (simplified version)
     * 
     * This method provides a simplified interface for WHERE EXISTS queries
     * by automatically building the JOIN conditions based on foreign/local keys,
     * similar to how where_has() works.
     * 
     * Example:
     * // Users that have orders
     * $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id');
     * 
     * // Users that have active orders  
     * $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id', function($query) {
     *     $query->where('status', 'active');
     * });
     * 
     * // Multiple foreign keys
     * $this->db->from('users')->where_exists_relation('user_roles', ['user_id', 'tenant_id'], ['id', 'tenant_id']);
     * 
     * // Marketing SPK with transactions and delivery
     * $this->db->from('outlet')->where_exists_relation('marketing_spk', 'idspk_workshop', 'idoutlet', function($query) {
     *     $query->join('transaction t', 't.idmarketing_spk = marketing_spk.idmarketing_spk AND t.idoutlet = marketing_spk.idspk_workshop AND t.status = 1', 'inner')
     *           ->join('transaction_delivery td', 'td.idtransaction = t.idtransaction', 'inner')
     *           ->where('marketing_spk.status', 1);
     * });
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'AND',
            'exists_type' => 'EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Add OR WHERE EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that have orders OR users that have posts
     * $this->db->from('users')
     *          ->where_exists_relation('orders', 'user_id', 'id')
     *          ->or_where_exists_relation('posts', 'user_id', 'id');
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'OR',
            'exists_type' => 'EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Add WHERE NOT EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that don't have any orders
     * $this->db->from('users')->where_not_exists_relation('orders', 'user_id', 'id');
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'AND',
            'exists_type' => 'NOT EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Add OR WHERE NOT EXISTS condition with relation support (simplified version)
     * 
     * Example:
     * // Users that have orders OR users that don't have cancelled orders
     * $this->db->from('users')
     *          ->where_exists_relation('orders', 'user_id', 'id')
     *          ->or_where_not_exists_relation('orders', 'user_id', 'id', function($query) {
     *              $query->where('status', 'cancelled');
     *          });
     * 
     * @param string $relation Target table name
     * @param string|array $foreignKey Foreign key(s) in the target table
     * @param string|array $localKey Local key(s) in the parent table  
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function or_where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation table name: {$relation}");
        }

        // Process local keys
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (!$this->is_valid_column_name($lk)) {
                throw new InvalidArgumentException("Invalid local key column name: {$lk}");
            }
            $processed_local_keys[] = $lk;
        }

        // Process foreign keys  
        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (!$this->is_valid_column_name($fk)) {
                throw new InvalidArgumentException("Invalid foreign key column name: {$fk}");
            }
            $processed_foreign_keys[] = $fk;
        }

        // Validate key count match
        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Foreign keys and local keys count must match');
        }

        // Store as pending operation to be processed later
        $this->pending_where_exists[] = [
            'type' => 'OR',
            'exists_type' => 'NOT EXISTS',
            'relation' => $relation,
            'foreign_keys' => $processed_foreign_keys,
            'local_keys' => $processed_local_keys,
            'callback' => $callback
        ];

        return $this;
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
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback to modify relation query
     * @param string $operator Comparison operator (>=, =, >, <, <=, !=, <>)
     * @param int $count Count to compare against
     * @return $this
     * @throws InvalidArgumentException
     */
    public function where_has($relation, $foreignKey, $localKey, $callback = null, $operator = '>=', $count = 1)
    {
        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation}. Only alphanumeric characters and underscores are allowed.");
        }

        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            // VALIDASI KEAMANAN: Validasi local key
            if (strpos($lk, '.') !== false) {
                $parts = explode('.', $lk);
                $key_name = end($parts);
            } else {
                $key_name = $lk;
            }

            if (!$this->is_valid_column_name($key_name)) {
                throw new InvalidArgumentException("Invalid local key: {$lk}. Only alphanumeric characters and underscores are allowed.");
            }

            $processed_local_keys[] = $key_name;
        }

        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            // VALIDASI KEAMANAN: Validasi foreign key
            if (strpos($fk, '.') !== false) {
                $parts = explode('.', $fk);
                $key_name = end($parts);
            } else {
                $key_name = $fk;
            }

            if (!$this->is_valid_column_name($key_name)) {
                throw new InvalidArgumentException("Invalid foreign key: {$fk}. Only alphanumeric characters and underscores are allowed.");
            }

            $processed_foreign_keys[] = $key_name;
        }

        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Number of foreign keys must match number of local keys');
        }

        // VALIDASI KEAMANAN: Validasi operator
        $allowed_operators = ['=', '>', '<', '>=', '<=', '!=', '<>'];
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
            'count' => (int) $count
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
        return $this->where_has($relation, $foreignKey, $localKey, $callback, '=', 0);
    }

    /**
     * Add OR WHERE HAS condition for relationships
     * 
     * @param string $relation Related table name
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback to modify relation query
     * @param string $operator Comparison operator (>=, =, >, <, <=, !=, <>)
     * @param int $count Count to compare against
     * @return $this
     */
    public function or_where_has($relation, $foreignKey, $localKey, $callback = null, $operator = '>=', $count = 1)
    {
        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            if (strpos($lk, '.') !== false) {
                $parts = explode('.', $lk);
                $processed_local_keys[] = end($parts);
            } else {
                $processed_local_keys[] = $lk;
            }
        }

        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            if (strpos($fk, '.') !== false) {
                $parts = explode('.', $fk);
                $processed_foreign_keys[] = end($parts);
            } else {
                $processed_foreign_keys[] = $fk;
            }
        }

        $this->pending_where_has[] = [
            'relation' => $relation,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'callback' => $callback,
            'operator' => $operator,
            'count' => $count,
            'type' => 'OR'
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
        return $this->or_where_has($relation, $foreignKey, $localKey, $callback, '=', 0);
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
        if (empty($array) || !is_array($array)) throw new InvalidArgumentException('Parameter $array must be an array value and cannot be empty.');

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
        if (empty($columns)) return $this;

        $this->group(function ($q) use ($term, $columns, $or) {
            foreach ($columns as $index => $column) {
                if (!is_string($column) || $column === '') continue;

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
        if (!is_callable($callback)) throw new InvalidArgumentException('Callback must be callable');

        $this->group_start();

        try {
            $callback($this);
        } catch (Exception $e) {
            $this->group_end();
            throw $e;
        }

        $this->group_end();
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
        if (!is_callable($callback)) throw new InvalidArgumentException('Callback must be callable');

        $this->or_group_start();

        try {
            $callback($this);
        } catch (Exception $e) {
            $this->group_end();
            throw $e;
        }

        $this->group_end();
        return $this;
    }

    /**
     * Add eager loading relation
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param bool $multiple Whether relation returns multiple records
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     * @throws InvalidArgumentException
     */
    public function with($relation, $foreignKey, $localKey, $multiple = true, $callback = null)
    {
        $relation_name = '';
        $alias = '';

        if (!is_bool($multiple)) throw new InvalidArgumentException('Parameter $multiple must be a boolean value (true or false).');

        if (is_array($relation)) {
            if (count($relation) === 1) {
                $relation_name = key($relation);
                $alias = current($relation);
            } else {
                $relation_name = reset($relation);
                $alias = $relation_name;
            }
        } else {
            $relation_name = $relation;
            $alias = $relation;
        }

        // VALIDASI KEAMANAN: Validasi relation name
        if (!$this->is_valid_table_name($relation_name)) {
            throw new InvalidArgumentException("Invalid relation name: {$relation_name}. Only alphanumeric characters and underscores are allowed.");
        }

        $processed_local_keys = [];
        $local_keys_array = is_array($localKey) ? $localKey : [$localKey];

        foreach ($local_keys_array as $lk) {
            // VALIDASI KEAMANAN: Validasi local key
            if (strpos($lk, '.') !== false) {
                $parts = explode('.', $lk);
                $key_name = end($parts);
            } else {
                $key_name = $lk;
            }

            if (!$this->is_valid_column_name($key_name)) {
                throw new InvalidArgumentException("Invalid local key: {$lk}. Only alphanumeric characters and underscores are allowed.");
            }

            $processed_local_keys[] = $key_name;
        }

        $processed_foreign_keys = [];
        $foreign_keys_array = is_array($foreignKey) ? $foreignKey : [$foreignKey];

        foreach ($foreign_keys_array as $fk) {
            // VALIDASI KEAMANAN: Validasi foreign key
            if (strpos($fk, '.') !== false) {
                $parts = explode('.', $fk);
                $key_name = end($parts);
            } else {
                $key_name = $fk;
            }

            if (!$this->is_valid_column_name($key_name)) {
                throw new InvalidArgumentException("Invalid foreign key: {$fk}. Only alphanumeric characters and underscores are allowed.");
            }

            $processed_foreign_keys[] = $key_name;
        }

        if (count($processed_foreign_keys) !== count($processed_local_keys)) {
            throw new InvalidArgumentException('Number of foreign keys must match number of local keys');
        }

        $this->with_relations[] = [
            'relation' => $relation_name,
            'foreign_key' => $processed_foreign_keys,
            'local_key' => $processed_local_keys,
            'multiple' => $multiple,
            'callback' => $callback,
            'alias' => $alias
        ];
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
     * Add eager loading relation with count aggregation
     * 
     * Now works as subquery in main SELECT clause for better sorting capability.
     * 
     * Example:
     * // Get users with their order count (can be sorted)
     * $users = $this->db->with_count('orders', 'user_id', 'id')
     *                   ->order_by('orders_count', 'DESC')
     *                   ->get('users');
     * // Result: $user->orders_count
     * 
     * // With alias
     * $this->db->with_count(['orders' => 'total_orders'], 'user_id', 'id');
     * // Result: $user->total_orders
     * 
     * // Can be used with with_many() and with_one()
     * $users = $this->db->with_count('orders', 'user_id', 'id')
     *                   ->with_many('posts', 'user_id', 'id')
     *                   ->get('users');
     * 
     * // Can be used in callbacks (for relation subqueries)
     * $posts = $this->db->with_many('comments', 'post_id', 'id', function($query) {
     *     $query->with_count('likes', 'comment_id', 'id');
     * })->get('posts');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_count($relation, $foreignKey, $localKey, $callback = null)
    {
        $relation_name = is_array($relation) ? key($relation) : $relation;
        $count_alias = is_array($relation) ? current($relation) : $relation_name . '_count';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'count',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $count_alias,
            'callback' => $callback,
            'column' => null,
            'is_custom_expression' => false
        ];

        return $this;
    }

    /**
     * Add eager loading relation with sum aggregation
     * 
     * Now works as subquery in main SELECT clause for better sorting capability.
     * 
     * Example:
     * // Get users with total order amount (can be sorted)
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->order_by('orders_sum', 'DESC')
     *                   ->get('users');
     * // Result: $user->orders_sum
     * 
     * // With alias
     * $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'total_amount');
     * // Result: $user->total_spent
     * 
     * // With custom expression (mathematical operations)
     * $invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
     *     'idinvoice', 'id', '(job_total_price_before_discount - job_discount)', true);
     * // Result: $invoice->total_after_discount
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('created_at >=', '2023-01-01');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
     *     'idinvoice', 'id', '(job_total_price_before_discount - job_discount)', true, 
     *     function($query) {
     *         $query->where('status', 'active');
     *     }
     * );
     * 
     * // Can be used with with_many() and with_one()
     * $users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
     *                   ->with_many('posts', 'user_id', 'id')
     *                   ->get('users');
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to sum or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_sum($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $sum_alias = is_array($relation) ? current($relation) : $relation_name . '_sum';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'sum',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $sum_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with average aggregation
     * 
     * Example:
     * // Get users with average order amount
     * $users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_avg
     * 
     * // With alias
     * $this->db->with_avg(['orders' => 'avg_order_value'], 'user_id', 'id', 'total_amount');
     * // Result: $user->avg_order_value
     * 
     * // With custom expression (mathematical operations)
     * $orders = $this->db->with_avg('items', 'order_id', 'id', '(price * quantity)', true);
     * // Result: $order->items_avg (average of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where_between('created_at', ['2023-01-01', '2023-12-31']);
     * })->get('users');
     * 
     * // With custom expression and callback
     * $orders = $this->db->with_avg('items', 'order_id', 'id', '(price * quantity)', true,
     *     function($query) {
     *         $query->where('is_active', 1);
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to calculate average or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_avg($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $avg_alias = is_array($relation) ? current($relation) : $relation_name . '_avg';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'avg',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $avg_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with maximum value aggregation
     * 
     * Example:
     * // Get users with their highest order amount
     * $users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_max
     * 
     * // Get posts with latest comment date
     * $this->db->with_max(['comments' => 'latest_comment'], 'post_id', 'id', 'created_at');
     * // Result: $post->latest_comment
     * 
     * // With custom expression (mathematical operations)
     * $products = $this->db->with_max('sales', 'product_id', 'id', '(base_price + tax)', true);
     * // Result: $product->sales_max (maximum of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('payment_status', 'paid');
     * })->get('users');
     * 
     * // With custom expression and callback
     * $products = $this->db->with_max('sales', 'product_id', 'id', '(base_price + tax)', true,
     *     function($query) {
     *         $query->where('sale_date >=', '2023-01-01');
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to find maximum value or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_max($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $max_alias = is_array($relation) ? current($relation) : $relation_name . '_max';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'max',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $max_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add eager loading relation with minimum value aggregation
     * 
     * Example:
     * // Get users with their lowest order amount
     * $users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount')->get('users');
     * // Result: $user->orders_min
     * 
     * // Get categories with earliest post date
     * $this->db->with_min(['posts' => 'first_post'], 'category_id', 'id', 'created_at');
     * // Result: $category->first_post
     * 
     * // With custom expression (mathematical operations)
     * $transactions = $this->db->with_min('payments', 'transaction_id', 'id', '(amount - discount)', true);
     * // Result: $transaction->payments_min (minimum of calculated values)
     * 
     * // With callback for WHERE conditions
     * $users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount', false, function($query) {
     *     $query->where('status', 'completed')
     *           ->where('discount', 0);  // Orders without discount
     * })->get('users');
     * 
     * // With custom expression and callback
     * $transactions = $this->db->with_min('payments', 'transaction_id', 'id', '(amount - discount)', true,
     *     function($query) {
     *         $query->where('payment_method', 'cash')
     *               ->where('is_verified', 1);
     *     }
     * );
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s)
     * @param string|array $localKey Local key(s)
     * @param string $column Column to find minimum value or custom expression
     * @param bool $is_custom_expression Whether $column is a custom SQL expression (default: false)
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for relation query
     * @return $this
     */
    public function with_min($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)
    {
        // VALIDASI KEAMANAN: Pastikan $is_custom_expression adalah boolean
        if (!is_bool($is_custom_expression)) {
            throw new InvalidArgumentException("Parameter is_custom_expression must be boolean, " . gettype($is_custom_expression) . " given.");
        }

        if ($is_custom_expression) {
            //  VALIDASI KEAMANAN untuk custom expression
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Expression contains potentially dangerous characters or patterns.");
            }
        } else {
            //  VALIDASI KEAMANAN untuk column name biasa
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Only alphanumeric characters and underscores are allowed.");
            }
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $min_alias = is_array($relation) ? current($relation) : $relation_name . '_min';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'min',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $min_alias,
            'callback' => $callback,
            'column' => $column,
            'is_custom_expression' => $is_custom_expression
        ];

        return $this;
    }

    /**
     * Add calculated field using custom mathematical expression with aggregate functions
     * 
     * This method allows you to create complex calculations using multiple aggregate functions
     * and mathematical operations in a subquery that becomes part of the main SELECT clause.
     * 
     * Example:
     * // Calculate efficiency percentage: (finished_qty / total_qty) * 100
     * $orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'], 
     *     'order_id', 'id', 
     *     '(SUM(finished_qty) / SUM(total_qty)) * 100'
     * )->get('orders');
     * // Result: $order->efficiency_percentage
     * 
     * // Calculate profit margin: ((revenue - cost) / revenue) * 100
     * $products = $this->db->with_calculation(['sales' => 'profit_margin'], 
     *     'product_id', 'id',
     *     '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100'
     * )->get('products');
     * 
     * // Calculate average order value with discount
     * $customers = $this->db->with_calculation(['orders' => 'avg_order_with_discount'], 
     *     'customer_id', 'id',
     *     'AVG(total_amount - discount_amount)'
     * )->get('customers');
     * 
     * // Calculate production duration in days using DATEDIFF
     * $transactions = $this->db->with_calculation(['transaction_step' => 'production_duration_days'], 
     *     'idtransaction_detail', 'idtransaction_detail',
     *     'DATEDIFF(MAX(date), MIN(date))'
     * )->get('transaction_detail');
     * 
     * // Calculate weighted average with callback for conditions
     * $products = $this->db->with_calculation(['reviews' => 'weighted_rating'], 
     *     'product_id', 'id',
     *     'SUM(rating * helpful_votes) / SUM(helpful_votes)',
     *     function($query) {
     *         $query->where('status', 'approved')
     *               ->where('helpful_votes >', 0);
     *     }
     * )->get('products');
     * 
     * // Multiple calculations in one query
     * $orders = $this->db->with_calculation(['order_items' => 'total_revenue'], 'order_id', 'id', 'SUM(price * quantity)')
     *                   ->with_calculation(['order_items' => 'total_cost'], 'order_id', 'id', 'SUM(cost * quantity)')
     *                   ->with_calculation(['order_items' => 'profit'], 'order_id', 'id', 'SUM((price - cost) * quantity)')
     *                   ->get('orders');
     * 
     * Supported mathematical operations:
     * - Basic math: +, -, *, /, %
     * - Aggregate functions: SUM, AVG, COUNT, MIN, MAX
     * - Date functions: DATEDIFF, TIMESTAMPDIFF
     * - Conditional: CASE WHEN ... THEN ... END
     * - Mathematical functions: ROUND, FLOOR, CEIL, ABS
     * 
     * @param string|array $relation Relation name or array with alias
     * @param string|array $foreignKey Foreign key(s) in the relation table
     * @param string|array $localKey Local key(s) in the main table
     * @param string $expression Mathematical expression with aggregate functions
     * @param callable(CustomQueryBuilder): void|null $callback Optional callback for additional WHERE conditions
     * @return $this
     * @throws InvalidArgumentException
     */
    public function with_calculation($relation, $foreignKey, $localKey, $expression, $callback = null)
    {
        if (!is_callable($callback) && $callback) throw new InvalidArgumentException('Callback must be callable');
        if (!$this->is_valid_calculation_expression($expression)) {
            throw new InvalidArgumentException("Invalid calculation expression: {$expression}");
        }

        $relation_name = is_array($relation) ? key($relation) : $relation;
        $calc_alias = is_array($relation) ? current($relation) : $relation_name . '_calculation';

        $foreign_keys = is_array($foreignKey) ? $foreignKey : [$foreignKey];
        $local_keys = is_array($localKey) ? $localKey : [$localKey];

        $this->pending_aggregates[] = [
            'type' => 'custom_calculation',
            'relation' => $relation_name,
            'foreign_key' => $foreign_keys,
            'local_key' => $local_keys,
            'alias' => $calc_alias,
            'callback' => $callback,
            'column' => $expression,
            'is_custom_expression' => true
        ];

        return $this;
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
     * @return CI_DB_result|CustomQueryBuilderResult Query result
     */
    public function get($table = '', $limit = null, $offset = null)
    {
        $original_debug = $this->db_debug;
        $this->db_debug = FALSE;

        // Store table name temporarily for where_exists_relation to use
        if (!empty($table)) {
            $this->_temp_table_name = $table;
            $this->from($table);
        }

        // Handle SQL_CALC_FOUND_ROWS separately using compiled query
        if ($this->_calc_rows_enabled) return $this->get_with_calc_rows($limit, $offset);

        $this->process_pending_where_has();
        $this->process_pending_aggregates();
        
        // Process pending WHERE EXISTS relations
        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        if (!empty($this->with_relations)) return $this->get_with_eager_loading('', $limit, $offset, null);

        $result = parent::get('', $limit, $offset);

        $error = $this->error();
        if ($error['code'] !== 0) $this->handle_database_error($error);

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
        $this->process_pending_where_has();
        $this->process_pending_aggregates();
        
        // Process pending WHERE EXISTS relations  
        $parent_table = $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        // Check if we have eager loading relations
        if (!empty($this->with_relations)) {
            // For queries with eager loading, we need to use a different approach
            // We'll temporarily disable calc_rows, get the data with eager loading,
            // then run a separate count query with SQL_CALC_FOUND_ROWS
            
            // First, get the compiled query for counting (without eager loading)
            $count_query = clone $this;
            $count_query->with_relations = []; // Remove relations for count query
            $compiled_count_query = $count_query->get_compiled_select('', false);
            
            // Add LIMIT for the count query if specified
            if ($limit !== null) {
                $compiled_count_query .= ' LIMIT ' . (int)$limit;
                if ($offset !== null && $offset > 0) $compiled_count_query .= ' OFFSET ' . (int)$offset;
            }
            
            // Execute count query with SQL_CALC_FOUND_ROWS
            $count_query_with_calc_rows = preg_replace('/^SELECT\s+/i', 'SELECT SQL_CALC_FOUND_ROWS ', $compiled_count_query);
            $this->query($count_query_with_calc_rows); // This sets FOUND_ROWS() for later use
            
            // Store the count query before executing FOUND_ROWS()
            $main_count_query = $this->last_query();
            
            // Get the found_rows count
            $found_rows_query = $this->query("SELECT FOUND_ROWS() as total");
            $found_rows = 0;
            if ($found_rows_query && $found_rows_query->num_rows() > 0) $found_rows = (int) $found_rows_query->row()->total;
            
            // Restore the count query as last_query for debugging purposes
            $this->queries[] = $main_count_query;
            
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
            $compiled_query .= ' LIMIT ' . (int)$limit;
            if ($offset !== null && $offset > 0) $compiled_query .= ' OFFSET ' . (int)$offset;
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
        if ($found_rows_query && $found_rows_query->num_rows() > 0) $found_rows = (int) $found_rows_query->row()->total;
        
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
     * @return CI_DB_result|CustomQueryBuilderResult Query result
     */
    public function get_where($table = '', $where = null, $limit = null, $offset = null)
    {
        if ($table !== '') $this->from($table);

        if ($where !== null && is_array($where)) $this->where($where);

        if ($limit !== null) $this->limit($limit, $offset);

        if (
                !empty($this->with_relations)       || 
                !empty($this->pending_where_has)    || 
                !empty($this->pending_aggregates)   ||
                !empty($this->pending_where_exists)
        ) return $this->get();

        $original_debug = $this->db_debug;
        $this->db_debug = FALSE;

        $result = parent::get_where('', null, null, null);

        $error = $this->error();
        if ($error['code'] !== 0) $this->handle_database_error($error);

        $this->db_debug = $original_debug;

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
        if ($table !== '') $this->from($table);

        $this->process_pending_where_has();
        $this->process_pending_aggregates();

        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        $original_relations = $this->with_relations;
        $this->with_relations = [];

        $result = parent::count_all_results('', $reset);

        if (!$reset) $this->with_relations = $original_relations;

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
        if ($table !== '') $this->from($table);

        $this->process_pending_where_has();
        $this->process_pending_aggregates();

        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        $original_relations = $this->with_relations;
        $this->with_relations = [];

        $result = parent::get_compiled_select('', $reset);

        if (!$reset) $this->with_relations = $original_relations;

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

        if (!empty($table)) $this->from($table);

        $this->process_pending_where_has();
        $this->process_pending_aggregates();

        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        $original_limit = $this->qb_limit;
        $original_offset = $this->qb_offset;
        $original_debug = $this->db_debug;

        $this->db_debug = FALSE;

        $offset = 0;
        $page = 1;
        $total_processed = 0;

        try {
            do {
                $chunk_query = clone $this;

                if (!empty($this->with_relations)) {
                    $chunk_result = $chunk_query->get_with_eager_loading('', $page_size, $offset, null);
                } else {
                    $chunk_query->qb_limit = $page_size;
                    $chunk_query->qb_offset = $offset;

                    $chunk_result = $chunk_query->get('');

                    $error = $chunk_query->error();
                    if ($error['code'] !== 0) {
                        $chunk_query->handle_database_error($error);
                    }
                }

                $chunk_data = $chunk_result->result();
                $chunk_count = count($chunk_data);

                if ($chunk_count === 0) {
                    unset($chunk_query, $chunk_result, $chunk_data);
                    break;
                }

                $continue = $callback($chunk_data, $page);

                if ($continue === false) {
                    unset($chunk_query, $chunk_result, $chunk_data);
                    break;
                }

                $total_processed += $chunk_count;
                $page++;
                $offset += $page_size;

                unset($chunk_query, $chunk_result, $chunk_data);

                if ($page % 10 === 0) {
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }

                if ($chunk_count < $page_size) {
                    break;
                }
            } while (true);
        } finally {
            $this->qb_limit = $original_limit;
            $this->qb_offset = $original_offset;
            $this->db_debug = $original_debug;
        }

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

        if (!empty($table)) $this->from($table);

        $this->process_pending_where_has();
        $this->process_pending_aggregates();

        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        $original_order_by = $this->qb_orderby;
        $original_limit = $this->qb_limit;
        $original_offset = $this->qb_offset;
        $original_debug = $this->db_debug;

        $this->db_debug = FALSE;

        $this->qb_orderby = [];
        $this->order_by($column, 'ASC');

        $last_id = 0;
        $page = 1;
        $total_processed = 0;

        try {
            do {
                $chunk_query = clone $this;

                if ($last_id > 0) {
                    $chunk_query->where($column . ' >', $last_id);
                }

                $chunk_query->qb_limit = $page_size;
                $chunk_query->qb_offset = FALSE;

                if (!empty($this->with_relations)) {
                    $chunk_result = $chunk_query->get_with_eager_loading('', $page_size, 0, null);
                } else {
                    $chunk_result = $chunk_query->get('');

                    $error = $chunk_query->error();
                    if ($error['code'] !== 0) {
                        $chunk_query->handle_database_error($error);
                    }
                }

                $chunk_data = $chunk_result->result();
                $chunk_count = count($chunk_data);

                if ($chunk_count === 0) {
                    unset($chunk_query, $chunk_result, $chunk_data);
                    break;
                }

                $continue = $callback($chunk_data, $page);

                if ($continue === false) {
                    unset($chunk_query, $chunk_result, $chunk_data);
                    break;
                }

                $last_record = end($chunk_data);
                if (isset($last_record->$column)) {
                    $last_id = $last_record->$column;
                } else {
                    $last_array = (array) $last_record;
                    if (isset($last_array[$column])) {
                        $last_id = $last_array[$column];
                    } else {
                        unset($chunk_query, $chunk_result, $chunk_data);
                        throw new InvalidArgumentException("Column '{$column}' not found in result set");
                    }
                }

                $total_processed += $chunk_count;
                $page++;

                unset($chunk_query, $chunk_result, $chunk_data);

                if ($page % 10 === 0) {
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }

                if ($chunk_count < $page_size) {
                    break;
                }
            } while (true);
        } finally {
            $this->qb_orderby = $original_order_by;
            $this->qb_limit = $original_limit;
            $this->qb_offset = $original_offset;
            $this->db_debug = $original_debug;
        }

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
        if (empty($this->pending_where_has)) return;

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

            for ($i = 0; $i < count($foreign_keys); $i++) {
                $foreign_key = $this->protect_identifiers($foreign_keys[$i], true);
                $local_key = $this->protect_identifiers($local_keys[$i], true);

                if ($i === 0) {
                    $subquery->where("$relation.$foreign_key = $main_table.$local_key", null, false);
                } else {
                    $subquery->where("$relation.$foreign_key = $main_table.$local_key", null, false);
                }
            }

            if (is_callable($where_has_config['callback'])) {
                $where_has_config['callback']($subquery);
                
                // Process any pending operations in subquery recursively
                if (!empty($subquery->pending_where_exists)) {
                    $subquery->process_pending_where_exists($where_has_config['relation']);
                }
                if (!empty($subquery->pending_where_has)) {
                    $subquery->process_pending_where_has();
                }
                if (!empty($subquery->pending_aggregates)) {
                    $subquery->process_pending_aggregates();
                }
            }

            $allowed_operators = ['=', '>', '<', '>=', '<=', '!=', '<>'];
            $operator = in_array($where_has_config['operator'], $allowed_operators)
                ? $where_has_config['operator']
                : '>=';

            $count = (int) $where_has_config['count'];
            $condition_type = isset($where_has_config['type']) && $where_has_config['type'] === 'OR' ? 'or_where' : 'where';

            if ($condition_type === 'or_where') {
                $this->or_where("({$subquery->get_compiled_select()}) $operator $count", null, false);
            } else {
                $this->where("({$subquery->get_compiled_select()}) $operator $count", null, false);
            }
        }

        // pending_where_has already cleared at the beginning to prevent recursion
        $this->pending_aggregates = [];
    }

    /**
     * Process pending aggregate functions by adding them as subqueries in SELECT
     * 
     * @return void
     * @throws Exception
     */
    protected function process_pending_aggregates()
    {
        if (empty($this->pending_aggregates)) return;

        if (empty($this->qb_from)) {
            throw new Exception('Aggregate functions require a table to be set. Please call from() method or provide table in get() method.');
        }

        // Store pending operations and clear them to prevent infinite recursion
        $pending_operations = $this->pending_aggregates;
        $this->pending_aggregates = [];

        // Ensure we have a proper SELECT clause first
        $current_select = $this->qb_select;
        if (empty($current_select)) $this->select('*');

        $mainTable = $this->qb_from[0];
        
        // Extract table name and alias from FROM clause
        $main_table_name = '';
        $main_table_alias = '';
        
        if (preg_match('/^`?(\w+)`?(?:\s+(?:as\s+)?`?(\w+)`?)?$/i', $mainTable, $matches)) {
            $main_table_name = $matches[1];
            $main_table_alias = isset($matches[2]) ? $matches[2] : $main_table_name;
        } else {
            $main_table_name = $mainTable;
            $main_table_alias = $mainTable;
        }

        foreach ($pending_operations as $aggregate_config) {
            $subquery = clone $this;
            $subquery->reset_query();

            $relation = $this->protect_identifiers($aggregate_config['relation'], true);
            $main_table = $this->protect_identifiers($main_table_alias, true); // Use alias instead of full name

            // Build aggregate function based on type
            $aggregate_function = '';

            switch ($aggregate_config['type']) {
                case 'count':
                    $aggregate_function = 'COUNT(*)';
                    break;
                case 'sum':
                    if ($aggregate_config['is_custom_expression']) {
                        $aggregate_function = "SUM({$aggregate_config['column']})";
                    } else {
                        $column = $this->protect_identifiers($aggregate_config['column'], true);
                        $aggregate_function = "SUM($column)";
                    }
                    break;
                case 'avg':
                    if ($aggregate_config['is_custom_expression']) {
                        $aggregate_function = "AVG({$aggregate_config['column']})";
                    } else {
                        $column = $this->protect_identifiers($aggregate_config['column'], true);
                        $aggregate_function = "AVG($column)";
                    }
                    break;
                case 'max':
                    if ($aggregate_config['is_custom_expression']) {
                        $aggregate_function = "MAX({$aggregate_config['column']})";
                    } else {
                        $column = $this->protect_identifiers($aggregate_config['column'], true);
                        $aggregate_function = "MAX($column)";
                    }
                    break;
                case 'min':
                    if ($aggregate_config['is_custom_expression']) {
                        $aggregate_function = "MIN({$aggregate_config['column']})";
                    } else {
                        $column = $this->protect_identifiers($aggregate_config['column'], true);
                        $aggregate_function = "MIN($column)";
                    }
                    break;
                case 'custom_calculation':
                    $aggregate_function = $aggregate_config['column'];
                    break;
            }

            $subquery->select($aggregate_function)
                ->from($aggregate_config['relation']);

            $foreign_keys = $aggregate_config['foreign_key'];
            $local_keys = $aggregate_config['local_key'];

            for ($i = 0; $i < count($foreign_keys); $i++) {
                $foreign_key = $this->protect_identifiers($foreign_keys[$i], true);
                $local_key = $this->protect_identifiers($local_keys[$i], true);

                if ($i === 0) {
                    $subquery->where("$relation.$foreign_key = $main_table.$local_key", null, false);
                } else {
                    $subquery->where("$relation.$foreign_key = $main_table.$local_key", null, false);
                }
            }

            if (is_callable($aggregate_config['callback'])) {
                $aggregate_config['callback']($subquery);
                
                // Process any pending operations in subquery recursively
                if (!empty($subquery->pending_where_exists)) {
                    $subquery->process_pending_where_exists($aggregate_config['relation']);
                }
                if (!empty($subquery->pending_where_has)) {
                    $subquery->process_pending_where_has();
                }
                if (!empty($subquery->pending_aggregates)) {
                    $subquery->process_pending_aggregates();
                }
            }

            // Add subquery directly to qb_select array to preserve existing SELECT fields
            $compiled_subquery = $subquery->get_compiled_select();
            $subquery_select = "($compiled_subquery) AS " . $this->protect_identifiers($aggregate_config['alias']);
            
            // Directly add to qb_select array instead of using select() method
            $this->qb_select[] = $subquery_select;
            $this->qb_no_escape[] = null; // Track that this field should not be escaped further
        }

        // pending_aggregates already cleared at the beginning to prevent recursion
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
     * Process pending WHERE EXISTS relations
     * 
     * This method builds and executes the WHERE EXISTS subqueries based on
     * the stored pending WHERE EXISTS operations.
     * 
     * @param string $parent_table Name of the parent table
     * @return void
     */
    protected function process_pending_where_exists($parent_table)
    {
        if (empty($this->pending_where_exists)) return;

        // Store pending operations and clear them to prevent infinite recursion
        $pending_operations = $this->pending_where_exists;
        $this->pending_where_exists = [];

        foreach ($pending_operations as $exists_config) {
            // Build EXISTS subquery - clone current instance to maintain CustomQueryBuilder type
            $subquery = clone $this;
            $subquery->reset_query();

            // Select 1 for EXISTS
            $subquery->select('1');
            $subquery->from($exists_config['relation']);

            // Build WHERE conditions for key matching
            $foreign_keys = $exists_config['foreign_keys'];
            $local_keys = $exists_config['local_keys'];

            for ($i = 0; $i < count($foreign_keys); $i++) {
                // Add table prefix to keys
                $foreign_key_with_table = $exists_config['relation'] . '.' . $foreign_keys[$i];
                $local_key_with_table = $parent_table . '.' . $local_keys[$i];

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
                
                // Process any pending WHERE EXISTS operations in the subquery recursively
                // This allows nested where_exists_relation calls to work properly
                if (!empty($subquery->pending_where_exists)) {
                    $subquery->process_pending_where_exists($exists_config['relation']);
                }
            }

            // Get the compiled subquery
            $compiled_subquery = $subquery->get_compiled_select();
            
            // Add EXISTS/NOT EXISTS condition based on type
            $exists_clause = "{$exists_config['exists_type']} ({$compiled_subquery})";
            
            if ($exists_config['type'] === 'OR') {
                $this->or_where($exists_clause, null, false);
            } else {
                $this->where($exists_clause, null, false);
            }
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

        // Process pending WHERE EXISTS relations
        $parent_table = !empty($table) ? $table : $this->_temp_table_name;
        if (!empty($parent_table)) {
            $this->process_pending_where_exists($parent_table);
        }

        $result = parent::get($table, $limit, $offset);

        $error = $this->error();
        if ($error['code'] !== 0) $this->handle_database_error($error);

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
        if (empty($this->with_relations)) return;

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

        if (empty($current_select) || (count($current_select) === 1 && $current_select[0] === '*')) return;

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

        // Get main table info (with potential alias)
        $main_table = '';
        $table_alias = '';
        if (!empty($this->qb_from)) {
            $from_clause = $this->qb_from[0];
            // Check if there's an alias (e.g., "transaction t" or "transaction as t")
            if (preg_match('/^`?(\w+)`?(?:\s+(?:as\s+)?`?(\w+)`?)?$/i', $from_clause, $matches)) {
                $main_table = $matches[1];
                $table_alias = isset($matches[2]) ? $matches[2] : $main_table;
            } else {
                $main_table = $from_clause;
                $table_alias = $main_table;
            }
        }

        foreach ($required_keys as $key) {
            //  TAMBAHAN VALIDASI KEAMANAN
            // Validasi column name untuk mencegah injection
            if (!$this->is_valid_column_name($key)) {
                continue; // Skip jika tidak valid
            }

            // Check if key is already selected (including with alias format _auto_rel_key)
            $auto_rel_key = '_auto_rel_' . $key;

            if (!in_array($key, $selected_fields) && !in_array($auto_rel_key, $selected_fields)) {
                // Use table alias if available
                $table_name = $this->protect_identifiers($table_alias, true);
                $column_name = $this->protect_identifiers($key, true);
                $alias_name = $this->protect_identifiers($auto_rel_key, true);
                $this->select("{$table_name}.{$column_name} AS {$alias_name}", false);
            }
        }
    }

    /**
     * Validate column name to prevent SQL injection
     * 
     * @param string $column_name Column name to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_column_name($column_name)
    {
        // Check if column name is string and not empty
        if (!is_string($column_name) || empty($column_name)) {
            return false;
        }

        // Allow only alphanumeric characters, underscores, and dots
        // NO DASHES allowed to prevent SQL comment injection
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';

        if (!preg_match($pattern, $column_name)) {
            return false;
        }

        // Check against common SQL injection patterns including dashes
        $dangerous_patterns = [
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND|WHERE|FROM|JOIN|INTO|VALUES|SET|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i',
            '/[\'";-]/',        // ADDED: Dash character blocked
            '/--/',             // SQL comment pattern
            '/\/\*/',           // Multi-line comment start
            '/\*\//',           // Multi-line comment end
            '/\bxp_/',          // Extended stored procedures
            '/\bsp_/',          // Stored procedures
            '/\|\|/',           // OR operator in some SQL dialects
            '/&&/',             // AND operator in some SQL dialects
            '/\s+--/',          // Space followed by comment
            '/-{2,}/',          // Multiple dashes
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $column_name)) {
                return false;
            }
        }

        // Check length to prevent buffer overflow attacks
        if (strlen($column_name) > 64) {
            return false;
        }

        return true;
    }

    /**
     * Validate table name to prevent SQL injection
     * 
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_table_name($table_name)
    {
        // Check if table name is string and not empty
        if (!is_string($table_name) || empty($table_name)) {
            return false;
        }

        // Allow only alphanumeric characters and underscores
        // NO DASHES allowed to prevent SQL comment injection
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

        if (!preg_match($pattern, $table_name)) {
            return false;
        }

        // Check against common SQL injection patterns including dashes
        $dangerous_patterns = [
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND|WHERE|FROM|JOIN|INTO|VALUES|SET|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i',
            '/[\'";-]/',        // ADDED: Dash character blocked
            '/--/',             // SQL comment pattern
            '/\/\*/',           // Multi-line comment start
            '/\*\//',           // Multi-line comment end
            '/\s+--/',          // Space followed by comment
            '/-{2,}/',          // Multiple dashes
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $table_name)) {
                return false;
            }
        }

        // Check length to prevent buffer overflow attacks
        if (strlen($table_name) > 64) {
            return false;
        }

        return true;
    }

    /**
     * Validate custom SQL expression for aggregation functions
     * 
     * @param string $expression Custom SQL expression to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_custom_expression($expression)
    {
        // Check if expression is string and not empty
        if (!is_string($expression) || empty($expression)) {
            return false;
        }

        // Allow mathematical operations, column names, parentheses, and common SQL functions
        $allowed_pattern = '/^[\w\s\(\)\+\-\*\/\.,`]+$/';

        if (!preg_match($allowed_pattern, $expression)) {
            return false;
        }

        // Block dangerous SQL patterns
        $dangerous_patterns = [
            '/\b(INSERT|UPDATE|DELETE|DROP|UNION|EXEC|EXECUTE|CREATE|ALTER|TRUNCATE)\b/i',
            '/[\'";]/',           // Quotes and semicolons
            '/--/',               // SQL comments
            '/\/\*/',             // Multi-line comment start
            '/\*\//',             // Multi-line comment end
            '/\|\|/',             // OR operator
            '/&&/',               // AND operator
            '/\bxp_/',            // Extended stored procedures
            '/\bsp_/',            // Stored procedures
            '/\s+(AND|OR)\s+/i',  // AND/OR operators
            '/\bSELECT\b/i',      // Nested SELECT
            '/\bFROM\b/i',        // FROM keyword
            '/\bWHERE\b/i',       // WHERE keyword
            '/\bJOIN\b/i',        // JOIN keyword
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $expression)) {
                return false;
            }
        }

        // Validate that parentheses are balanced
        if (!$this->are_parentheses_balanced($expression)) {
            return false;
        }

        // Check length to prevent buffer overflow
        if (strlen($expression) > 200) {
            return false;
        }

        // Validate that expression contains only allowed column names and operators
        $tokens = preg_split('/[\s\(\)\+\-\*\/,]+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            // Skip numeric values
            if (is_numeric($token)) {
                continue;
            }

            // Skip common SQL functions
            $allowed_functions = ['COALESCE', 'IFNULL', 'IF', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END'];
            if (in_array(strtoupper($token), $allowed_functions)) {
                continue;
            }

            // Check if it's a valid column name
            if (!$this->is_valid_column_name($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if parentheses are balanced in expression
     * 
     * @param string $expression Expression to check
     * @return bool True if balanced, false otherwise
     */
    private function are_parentheses_balanced($expression)
    {
        $count = 0;
        $chars = str_split($expression);

        foreach ($chars as $char) {
            if ($char === '(') {
                $count++;
            } elseif ($char === ')') {
                $count--;
                if ($count < 0) {
                    return false; // More closing than opening
                }
            }
        }

        return $count === 0; // Should be balanced
    }

    /**
     * Validate calculation expression for mathematical operations with aggregates
     * 
     * This method validates expressions that can contain:
     * - Aggregate functions (SUM, AVG, COUNT, MIN, MAX)
     * - Mathematical operations (+, -, *, /, %)
     * - Date functions (DATEDIFF, TIMESTAMPDIFF)
     * - Mathematical functions (ROUND, FLOOR, CEIL, ABS)
     * - Conditional expressions (CASE WHEN)
     * 
     * @param string $expression Mathematical expression to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_calculation_expression($expression)
    {
        // Check if expression is string and not empty
        if (!is_string($expression) || empty($expression)) {
            return false;
        }

        // Allow more extensive pattern for calculation expressions
        $allowed_pattern = '/^[\w\s\(\)\+\-\*\/\.,`%<>=]+$/';

        if (!preg_match($allowed_pattern, $expression)) {
            return false;
        }

        // Block dangerous SQL patterns but allow more functions for calculations
        $dangerous_patterns = [
            '/\b(INSERT|UPDATE|DELETE|DROP|UNION|EXEC|EXECUTE|CREATE|ALTER|TRUNCATE)\b/i',
            '/[\'";]/',           // Quotes and semicolons
            '/--/',               // SQL comments
            '/\/\*/',             // Multi-line comment start
            '/\*\//',             // Multi-line comment end
            '/\|\|/',             // String concatenation
            '/&&/',               // Logical AND
            '/\bxp_/',            // Extended stored procedures
            '/\bsp_/',            // Stored procedures
            '/\bINTO\b/i',        // INTO keyword
            '/\bVALUES\b/i',      // VALUES keyword
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $expression)) {
                return false;
            }
        }

        // Validate that parentheses are balanced
        if (!$this->are_parentheses_balanced($expression)) {
            return false;
        }

        // Check length to prevent buffer overflow
        if (strlen($expression) > 500) {
            return false;
        }

        // Validate tokens - allow aggregate functions and mathematical operations
        $tokens = preg_split('/[\s\(\)\+\-\*\/,%<>=]+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            // Skip numeric values
            if (is_numeric($token)) {
                continue;
            }

            // Allow aggregate functions
            $allowed_aggregates = ['SUM', 'AVG', 'COUNT', 'MIN', 'MAX'];
            if (in_array(strtoupper($token), $allowed_aggregates)) {
                continue;
            }

            // Allow date functions
            $allowed_date_functions = ['DATEDIFF', 'TIMESTAMPDIFF', 'DATE', 'NOW', 'CURDATE', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND'];
            if (in_array(strtoupper($token), $allowed_date_functions)) {
                continue;
            }

            // Allow mathematical functions
            $allowed_math_functions = ['ROUND', 'FLOOR', 'CEIL', 'ABS', 'POW', 'SQRT', 'MOD'];
            if (in_array(strtoupper($token), $allowed_math_functions)) {
                continue;
            }

            // Allow conditional functions
            $allowed_conditional = ['CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'IF', 'IFNULL', 'COALESCE', 'NULLIF'];
            if (in_array(strtoupper($token), $allowed_conditional)) {
                continue;
            }

            // Allow comparison operators
            $allowed_operators = ['AND', 'OR', 'NOT', 'IS', 'NULL', 'TRUE', 'FALSE'];
            if (in_array(strtoupper($token), $allowed_operators)) {
                continue;
            }

            // Check if it's a valid column name (also allows table.column format)
            if (!$this->is_valid_column_name($token)) {
                return false;
            }
        }

        return true;
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
        if (empty($relations)) return $data;

        foreach ($relations as $relation_config) {
            $data = $this->load_single_relation($data, $relation_config);
        }

        return $data;
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
                    $aliased_key = "_auto_rel_{$key}";
                    if (isset($item[$aliased_key])) {
                        $composite_key[] = $item[$aliased_key];
                    } elseif (isset($item[$key])) {
                        $composite_key[] = $item[$key];
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
            $aliased_key = "_auto_rel_{$local_key}";

            $local_values = [];
            foreach ($data as $item) {
                if (isset($item[$aliased_key])) {
                    $local_values[] = $item[$aliased_key];
                } elseif (isset($item[$local_key])) {
                    $local_values[] = $item[$local_key];
                } else {
                    $local_values[] = null;
                }
            }

            $local_values = array_unique($local_values);
            $local_values = array_filter($local_values, function ($val) {
                return $val !== '' && $val !== null;
            });
        }

        if ((count($local_keys) > 1 && empty($composite_values)) ||
            (count($local_keys) === 1 && empty($local_values))
        ) {
            foreach ($data as &$item) {
                $item[$config['alias']] = $config['multiple'] ? [] : null;
            }
            return $data;
        }

        $relation_query = clone $this;
        $relation_query->reset_query();
        $relation_query->from($config['relation']);

        if (count($foreign_keys) > 1) {
            $relation_query->group_start();
            $first_condition = true;

            foreach ($composite_values as $composite_value) {
                $key_parts = json_decode($composite_value, true);

                if (!$first_condition) {
                    $relation_query->or_group_start();
                } else {
                    $relation_query->group_start();
                }

                for ($i = 0; $i < count($foreign_keys); $i++) {
                    $relation_query->where($foreign_keys[$i], $key_parts[$i]);
                }

                $relation_query->group_end();
                $first_condition = false;
            }
            $relation_query->group_end();
        } else {
            $relation_query->where_in($foreign_keys[0], $local_values);
        }

        if (is_callable($config['callback'])) {
            $base_db = clone $this;
            $base_db->reset_query();
            $base_db->from($config['relation']);

            if (count($foreign_keys) > 1) {
                $base_db->group_start();
                $first_condition = true;

                foreach ($composite_values as $composite_value) {
                    $key_parts = json_decode($composite_value, true);

                    if (!$first_condition) {
                        $base_db->or_group_start();
                    } else {
                        $base_db->group_start();
                    }

                    for ($i = 0; $i < count($foreign_keys); $i++) {
                        $base_db->where($foreign_keys[$i], $key_parts[$i]);
                    }

                    $base_db->group_end();
                    $first_condition = false;
                }
                $base_db->group_end();
            } else {
                $base_db->where_in($foreign_keys[0], $local_values);
            }

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

                    $aggregate_relation = $relation_builder->db->protect_identifiers($aggregate_config['relation'], true);
                    $main_table = $relation_builder->db->protect_identifiers($config['relation'], true);

                    // Build aggregate function based on type
                    $aggregate_function = '';
                    switch ($aggregate_config['type']) {
                        case 'count':
                            $aggregate_function = 'COUNT(*)';
                            break;
                        case 'sum':
                            if ($aggregate_config['is_custom_expression']) {
                                $aggregate_function = "SUM({$aggregate_config['column']})";
                            } else {
                                $column = $relation_builder->db->protect_identifiers($aggregate_config['column'], true);
                                $aggregate_function = "SUM($column)";
                            }
                            break;
                        case 'avg':
                            if ($aggregate_config['is_custom_expression']) {
                                $aggregate_function = "AVG({$aggregate_config['column']})";
                            } else {
                                $column = $relation_builder->db->protect_identifiers($aggregate_config['column'], true);
                                $aggregate_function = "AVG($column)";
                            }
                            break;
                        case 'max':
                            if ($aggregate_config['is_custom_expression']) {
                                $aggregate_function = "MAX({$aggregate_config['column']})";
                            } else {
                                $column = $relation_builder->db->protect_identifiers($aggregate_config['column'], true);
                                $aggregate_function = "MAX($column)";
                            }
                            break;
                        case 'min':
                            if ($aggregate_config['is_custom_expression']) {
                                $aggregate_function = "MIN({$aggregate_config['column']})";
                            } else {
                                $column = $relation_builder->db->protect_identifiers($aggregate_config['column'], true);
                                $aggregate_function = "MIN($column)";
                            }
                            break;
                    }

                    $subquery->select($aggregate_function)
                        ->from($aggregate_config['relation']);

                    $aggregate_foreign_keys = $aggregate_config['foreign_key'];
                    $aggregate_local_keys = $aggregate_config['local_key'];

                    for ($i = 0; $i < count($aggregate_foreign_keys); $i++) {
                        $aggregate_foreign_key = $relation_builder->db->protect_identifiers($aggregate_foreign_keys[$i], true);
                        $aggregate_local_key = $relation_builder->db->protect_identifiers($aggregate_local_keys[$i], true);

                        $subquery->where("$aggregate_relation.$aggregate_foreign_key = $main_table.$aggregate_local_key", null, false);
                    }

                    if (is_callable($aggregate_config['callback'])) {
                        $aggregate_config['callback']($subquery);
                    }

                    // Add subquery to main query SELECT (append to existing SELECT, not replace)
                    $compiled_subquery = $subquery->get_compiled_select();
                    $relation_builder->db->select("($compiled_subquery) as {$aggregate_config['alias']}", false);
                }
            }

            foreach ($foreign_keys as $fk) {
                $this->auto_include_foreign_key($relation_builder->db, $fk);
            }

            $this->auto_include_nested_keys($relation_builder);

            if (!empty($relation_builder->with_relations)) {
                $relation_result = $relation_builder->db->get();
                $relation_data = [];
                if ($relation_result->num_rows() > 0) {
                    $relation_data = $relation_result->result_array();
                    $relation_data = $this->load_relations($relation_data, $relation_builder->with_relations);
                }
            } else {
                $relation_result = $relation_builder->db->get();
                $relation_data = $relation_result->result_array();
            }
        } else {
            foreach ($foreign_keys as $fk) {
                $this->auto_include_foreign_key($relation_query, $fk);
            }

            $relation_result = $relation_query->get();
            $relation_data = $relation_result->result_array();
        }

        $grouped_relations = [];
        foreach ($relation_data as $relation_item) {
            if (count($foreign_keys) > 1) {
                $composite_key = [];
                foreach ($foreign_keys as $fk) {
                    $composite_key[] = isset($relation_item[$fk]) ? $relation_item[$fk] : null;
                }
                $key = json_encode($composite_key);
            } else {
                $key = isset($relation_item[$foreign_keys[0]]) ? $relation_item[$foreign_keys[0]] : null;
            }

            if ($config['multiple']) {
                if (!isset($grouped_relations[$key])) $grouped_relations[$key] = [];
                $grouped_relations[$key][] = $relation_item;
            } else {
                $grouped_relations[$key] = is_array($relation_item) ? (object)$relation_item : $relation_item;
            }
        }

        foreach ($data as &$item) {
            if (count($local_keys) > 1) {
                $composite_key = [];
                foreach ($local_keys as $lk) {
                    $aliased_key = "_auto_rel_{$lk}";
                    if (isset($item[$aliased_key])) {
                        $composite_key[] = $item[$aliased_key];
                    } elseif (isset($item[$lk])) {
                        $composite_key[] = $item[$lk];
                    } else {
                        $composite_key[] = null;
                    }
                }
                $local_value = json_encode($composite_key);
            } else {
                $local_key = $local_keys[0];
                $aliased_key = "_auto_rel_{$local_key}";
                if (isset($item[$aliased_key])) {
                    $local_value = $item[$aliased_key];
                } elseif (isset($item[$local_key])) {
                    $local_value = $item[$local_key];
                } else {
                    $local_value = null;
                }
            }

            if (isset($grouped_relations[$local_value])) {
                $relation_data = $grouped_relations[$local_value];
                $is_aggregation = preg_match('/_(count|sum|avg|max|min)$/', $config['alias']);

                if ($is_aggregation && is_array($relation_data) && isset($relation_data['value'])) {
                    $new_alias = preg_replace('/_(count|sum|avg|max|min)$/', '', $config['alias']);
                    $item[$new_alias] = $relation_data['value'];
                } else if ($is_aggregation && is_object($relation_data) && isset($relation_data->value)) {
                    $new_alias = preg_replace('/_(count|sum|avg|max|min)$/', '', $config['alias']);
                    $item[$new_alias] = $relation_data->value;
                } else {
                    $item[$config['alias']] = $relation_data;
                }
            } else {
                $is_aggregation = preg_match('/_(count|sum|avg|max|min)$/', $config['alias']);
                if ($is_aggregation) {
                    $new_alias = preg_replace('/_(count|sum|avg|max|min)$/', '', $config['alias']);
                    $item[$new_alias] = preg_match('/_count$/', $config['alias']) ? 0 : null;
                } else {
                    $item[$config['alias']] = $config['multiple'] ? [] : null;
                }
            }
        }

        return $data;
    }

    /**
     * Automatically include foreign key in relation query SELECT
     * 
     * @param object $query_instance Database query instance
     * @param string $foreign_key Foreign key column name
     * @return void
     */
    protected function auto_include_foreign_key($query_instance, $foreign_key)
    {
        $current_select = $query_instance->qb_select;

        if (empty($current_select) || (count($current_select) === 1 && $current_select[0] === '*')) return;

        $selected_fields = [];
        foreach ($current_select as $select_item) {
            $field_pattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?(?:\s+AS\s+`?\w+`?)?/i';
            if (preg_match($field_pattern, $select_item, $matches)) {
                $selected_fields[] = $matches[2];
            }
        }

        if (!in_array($foreign_key, $selected_fields)) {
            $column_name = $query_instance->protect_identifiers($foreign_key, true);
            $query_instance->select($column_name, false);
        }
    }

    /**
     * Automatically include nested relation keys in SELECT
     * 
     * @param NestedQueryBuilder $relation_builder Nested query builder instance
     * @return void
     */
    protected function auto_include_nested_keys($relation_builder)
    {
        if (empty($relation_builder->with_relations)) return;

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

        if (empty($current_select) || (count($current_select) === 1 && $current_select[0] === '*')) return;

        $selected_fields = [];
        foreach ($current_select as $select_item) {
            $field_pattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?(?:\s+AS\s+`?\w+`?)?/i';
            if (preg_match($field_pattern, $select_item, $matches)) {
                $selected_fields[] = $matches[2];
            }
        }

        foreach ($required_keys as $key) {
            if (!in_array($key, $selected_fields)) {
                $column_name = $relation_builder->db->protect_identifiers($key, true);
                $relation_builder->db->select($column_name, false);
            }
        }
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
        $this->pending_where_exists = [];
        $this->_calc_rows_enabled = false;
        return parent::reset_query();
    }
}
