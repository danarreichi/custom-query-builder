---
name: custom-query-builder
description: Advanced CodeIgniter 3.x query builder with eager loading, aggregations, and relation support for the Liteprint multi-tenant platform. Use when building database queries that need: eager loading relationships (with_one, with_many), aggregations (with_sum, with_count, with_avg), advanced WHERE conditions (where_exists, where_has), complex filtering with domain/outlet isolation, or any query going beyond basic CodeIgniter Query Builder capabilities. Located in system/core/CustomQueryBuilder.php.
license: Complete terms in LICENSE.txt
---

# Custom Query Builder

Enhanced CodeIgniter Query Builder for the Liteprint multi-tenant platform with eager loading, aggregations, and SQL injection protection.

## Quick Start

CustomQueryBuilder is automatically loaded via `$this->db` in all controllers:

```php
// Basic query
$users = $this->db->select(['id', 'name'])
    ->where('status', 1)
    ->get('users');

// With eager loading
$users = $this->db->select(['id', 'name'])
    ->with_many('orders', 'user_id', 'id')
    ->get('users');
// Result: $user->orders (array of related orders)
```

## Core Features

### 1. Eager Loading Relationships

Load related data efficiently without N+1 queries.

#### One-to-Many Relationships

```php
// Get users with their orders
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');
// Result: $user->orders (array of order objects)

// With alias
$users = $this->db->with_many(['orders' => 'user_orders'], 'user_id', 'id')
    ->get('users');
// Result: $user->user_orders

// With WHERE conditions on relation
$users = $this->db->with_many('orders', 'user_id', 'id', function($query) {
    $query->where('status', 'completed')
          ->where('total >', 1000);
})->get('users');
```

#### One-to-One Relationships

```php
// Get users with their profile
$users = $this->db->with_one('profile', 'user_id', 'id')->get('users');
// Result: $user->profile (single object or null)

// With callback
$users = $this->db->with_one('latest_order', 'user_id', 'id', function($query) {
    $query->order_by('created_at', 'DESC');
})->get('users');
```

#### Nested Relationships

```php
// Load orders with their items
$users = $this->db->with_many('orders', 'user_id', 'id', function($query) {
    $query->with_many('order_items', 'order_id', 'id');
})->get('users');
// Result: $user->orders[0]->order_items
```

### 2. Aggregations

Add aggregate calculations as subqueries in the SELECT clause.

#### Count

```php
// Count related records
$users = $this->db->with_count('orders', 'user_id', 'id')->get('users');
// Result: $user->orders_count

// With alias and conditions
$users = $this->db->with_count(['orders' => 'completed_orders'], 'user_id', 'id', 
    function($query) {
        $query->where('status', 'completed');
    }
)->get('users');
// Result: $user->completed_orders
```

#### Sum

```php
// Sum a column
$users = $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
    ->get('users');
// Result: $user->orders_sum

// With custom expression (mathematical operations)
$invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
    'idinvoice', 'id', 
    '(job_total_price_before_discount - job_discount)', 
    true  // is_custom_expression = true
)->get('invoice');
// Result: $invoice->total_after_discount
```

#### Average, Min, Max

```php
// Average
$users = $this->db->with_avg('orders', 'user_id', 'id', 'total_amount')
    ->get('users');
// Result: $user->orders_avg

// Maximum
$users = $this->db->with_max('orders', 'user_id', 'id', 'total_amount')
    ->get('users');
// Result: $user->orders_max

// Minimum
$users = $this->db->with_min('orders', 'user_id', 'id', 'total_amount')
    ->get('users');
// Result: $user->orders_min
```

#### Complex Calculations

```php
// Calculate efficiency percentage: (finished_qty / total_qty) * 100
$orders = $this->db->with_calculation(
    ['order_items' => 'efficiency_percentage'], 
    'order_id', 
    'id', 
    '(SUM(finished_qty) / SUM(total_qty)) * 100'
)->get('orders');
// Result: $order->efficiency_percentage

// Calculate profit margin with conditions
$products = $this->db->with_calculation(
    ['sales' => 'profit_margin'], 
    'product_id', 
    'id',
    '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100',
    function($query) {
        $query->where('status', 'completed')
              ->where('created_at >=', '2024-01-01');
    }
)->get('products');

// Calculate date differences
$transactions = $this->db->with_calculation(
    ['transaction_step' => 'production_duration_days'], 
    'idtransaction_detail', 
    'idtransaction_detail',
    'DATEDIFF(MAX(date), MIN(date))'
)->get('transaction_detail');
```

### 3. Advanced WHERE Conditions

#### Filter by Aggregate Values

```php
// Filter users with more than 5 orders
$users = $this->db->with_count('orders', 'user_id', 'id')
    ->where_aggregate('orders_count >', 5)
    ->get('users');

// Filter by calculated sum
$users = $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'amount')
    ->where_aggregate('total_spent >', 10000)
    ->get('users');

// Multiple aggregate filters
$products = $this->db->with_sum(['sales' => 'revenue'], 'product_id', 'id', 'amount')
    ->with_count(['sales' => 'sale_count'], 'product_id', 'id')
    ->where_aggregate('revenue >', 50000)
    ->where_aggregate('sale_count >', 100)
    ->get('products');
```

#### WHERE EXISTS

```php
// Check if user has published posts
$users = $this->db->where_exists(function($query) {
    $query->select('1')
          ->from('posts')
          ->where('posts.user_id = users.id')
          ->where('status', 'published');
})->get('users');

// Simplified with where_exists_relation
$users = $this->db->where_exists_relation('posts', 'user_id', 'id', function($query) {
    $query->where('status', 'published');
})->get('users');

// WHERE NOT EXISTS
$users = $this->db->where_not_exists_relation('orders', 'user_id', 'id')
    ->get('users');
// Users without any orders
```

#### WHERE HAS

```php
// Users with at least 3 completed orders
$users = $this->db->where_has('orders', 'user_id', 'id', function($query) {
    $query->where('status', 'completed');
}, '>=', 3)->get('users');

// Users with no failed orders
$users = $this->db->where_doesnt_have('orders', 'user_id', 'id', function($query) {
    $query->where('status', 'failed');
})->get('users');
```

### 4. Search and Filtering

```php
// Search across multiple columns
$products = $this->db->search('printer', ['name', 'description'])
    ->get('product');

// Conditional queries
$query = $this->db->select(['id', 'name'])
    ->from('users')
    ->when($status, function($query) use ($status) {
        $query->where('status', $status);
    })
    ->when($role, function($query) use ($role) {
        $query->where('role', $role);
    });

// Grouped conditions
$users = $this->db->where('status', 1)
    ->group(function($query) {
        $query->where('role', 'admin')
              ->or_where('role', 'moderator');
    })
    ->get('users');
// WHERE status = 1 AND (role = 'admin' OR role = 'moderator')
```

### 5. Additional Methods

```php
// Between conditions
$orders = $this->db->where_between('total', [100, 1000])->get('orders');
$orders = $this->db->where_not_between('total', [100, 1000])->get('orders');

// Null checks
$users = $this->db->where_null('deleted_at')->get('users');
$users = $this->db->where_not_null('email_verified_at')->get('users');

// Latest/Oldest
$users = $this->db->latest('created_at')->get('users');
$users = $this->db->oldest('updated_at')->get('users');

// First record
$user = $this->db->where('email', 'test@example.com')->first('users');

// Check existence
if ($this->db->where('email', 'test@example.com')->exists('users')) {
    // Email exists
}

// Pluck single column
$names = $this->db->pluck('name', 'users');
// Returns: ['John', 'Jane', 'Bob']
```

### 6. Pagination with Total Count

```php
// Get paginated results with total count
$result = $this->db->select(['id', 'name'])
    ->calc_rows()
    ->get('users', 20, 0);

$users = $result->result();        // 20 users
$total = $result->found_rows();    // Total available users (e.g., 1000)

// Calculate pagination
$per_page = 20;
$total_pages = ceil($total / $per_page);
```

### 7. Chunking Large Datasets

```php
// Process 100 records at a time
$this->db->chunk(100, function($users) {
    foreach ($users as $user) {
        // Process each user
        $this->send_email($user->email);
    }
}, 'users');

// Chunk by ID (more memory efficient)
$this->db->chunk_by_id(100, function($users) {
    foreach ($users as $user) {
        $this->update_user($user);
    }
}, 'id', 'users');
```

## Critical Pattern: Domain/Outlet Filtering

**ALWAYS include domain and outlet filters when querying products in multi-tenant context.**

```php
// Standard pattern for product queries
$app_domain = $this->config->item('app_domain');
$app_outlet = $this->config->item('app_outlet');

$query = $this->db->select(['product.*'])
    ->from('product');

// Outlet filter (REQUIRED for tenant isolation)
if (strlen($app_outlet) > 0) {
    $query->where('product_outlet.idoutlet', $app_outlet)
          ->where('product_outlet.status', 1)
          ->join('product_outlet', 'product.idproduct=product_outlet.idproduct');
}

// Domain filter (REQUIRED for tenant isolation)
if ($app_domain != '') {
    $query->where('domain_name', $app_domain)
          ->where('product_domain.status', 1)
          ->where('domain.status', 1)
          ->join('product_domain', 'product.idproduct=product_domain.idproduct')
          ->join('domain', 'product_domain.iddomain=domain.iddomain');
}

$products = $query->get();
```

## SQL Injection Protection

CustomQueryBuilder includes `QueryValidationTrait` that validates:

- Table names (alphanumeric + underscores only)
- Column names (alphanumeric + underscores + dots for table.column)
- SQL expressions (blocks dangerous keywords, validates functions)
- Operators (whitelist of allowed operators)

### Safe Patterns

```php
// ✅ SAFE: Using query builder methods
$this->db->where('name', $user_input);
$this->db->where_in('status', $status_array);

// ✅ SAFE: Escape user input
$this->db->where("name = " . $this->db->escape($user_input));

// ✅ SAFE: Custom expressions validated by trait
$this->db->with_sum('orders', 'user_id', 'id', '(price - discount)', true);
```

### Unsafe Patterns

```php
// ❌ UNSAFE: Raw concatenation
$this->db->where("name = '{$user_input}'");  // SQL injection risk!

// ❌ UNSAFE: Raw query without escaping
$this->db->query("SELECT * FROM users WHERE name = '{$user_input}'");
```

## Common Patterns

### Loading Products with Relations

```php
// Products with categories and images
$products = $this->db->select(['product.*'])
    ->with_one('category', 'idcategory', 'idcategory')
    ->with_many('images', 'idproduct', 'idproduct', function($query) {
        $query->where('status', 1)
              ->order_by('sort_order', 'ASC');
    })
    ->with_count(['orders' => 'total_orders'], 'idproduct', 'idproduct')
    ->where('product.status', 1)
    ->get('product');
```

### Transactions with Jobs

```php
// Transactions with job details and calculations
$transactions = $this->db->select(['transaction.*'])
    ->with_many('job', 'idtransaction', 'idtransaction', function($query) {
        $query->with_calculation(
            ['job_detail' => 'total_finished'], 
            'idjob', 
            'idjob', 
            'SUM(job_detail_qty_finish)'
        );
    })
    ->with_sum(['job' => 'total_amount'], 'idtransaction', 'idtransaction', 
        'job_total_price_after_discount'
    )
    ->where('transaction.status', 1)
    ->get('transaction');
```

### Users with Order Statistics

```php
// Users with order metrics
$users = $this->db->select(['member.*'])
    ->with_count('transaction', 'idmember', 'idmember')
    ->with_sum(['transaction' => 'total_spent'], 'idmember', 'idmember', 
        'transaction_grand_total'
    )
    ->with_avg(['transaction' => 'avg_order'], 'idmember', 'idmember', 
        'transaction_grand_total'
    )
    ->with_one('latest_transaction', 'idmember', 'idmember', function($query) {
        $query->order_by('created', 'DESC');
    })
    ->where('member.status', 1)
    ->get('member');
```

## Troubleshooting

### Aggregates Not Showing in Results

Make sure to call aggregate methods BEFORE filtering by them:

```php
// ✅ Correct order
$users = $this->db->with_count('orders', 'user_id', 'id')  // Add aggregate first
    ->where_aggregate('orders_count >', 5)                  // Then filter
    ->get('users');

// ❌ Wrong order
$users = $this->db->where_aggregate('orders_count >', 5)   // Error: aggregate not defined yet
    ->with_count('orders', 'user_id', 'id')
    ->get('users');
```

### Validation Errors

If you get validation errors about column/table names:

- Ensure names contain only alphanumeric characters and underscores
- Check for typos in column/table names
- Verify the column exists in the table

### Memory Issues with Large Datasets

Use chunking instead of loading all records:

```php
// Instead of:
$products = $this->db->get('product')->result();  // Loads all records

// Use:
$this->db->chunk(500, function($products) {
    // Process 500 at a time
}, 'product');
```

## Performance Tips

1. **Use calc_rows() sparingly** - Only when pagination totals are needed
2. **Limit eager loaded relations** - Only load what you need
3. **Add indexes** - On foreign keys used in eager loading
4. **Use chunking** - For processing large datasets
5. **Filter before aggregating** - Apply WHERE conditions before WITH methods when possible

## Result Access

```php
$result = $this->db->with_many('orders', 'user_id', 'id')->get('users');

// As array
$users = $result->result_array();

// As objects (default)
$users = $result->result();

// Single row
$user = $result->row();      // Object
$user = $result->row_array(); // Array

// Count
$count = $result->num_rows();

// Total count (with calc_rows)
$total = $result->found_rows();
```

## Additional Resources

- **[Advanced Patterns](references/advanced-patterns.md)** - Real-world examples from Liteprint codebase including multi-tenant queries, ERP transactions, member statistics, invoice processing, inventory management, and performance optimization techniques
- **[Quick Reference](references/quick-reference.md)** - Concise cheat sheet with method signatures, common patterns, and troubleshooting tips

## Extending Capabilities

For framework-specific features not covered here, refer to [CodeIgniter Query Builder documentation](https://codeigniter.com/userguide3/database/query_builder.html). CustomQueryBuilder extends CI_DB_query_builder, so all standard methods are available.
