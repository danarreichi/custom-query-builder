# CustomQueryBuilder Usage Documentation

## Table of Contents

1. [Installation](#installation)
2. [Introduction](#introduction)
3. [Basic Query Methods](#basic-query-methods)
4. [Advanced WHERE Conditions](#advanced-where-conditions)
5. [Eager Loading Relations](#eager-loading-relations)
6. [Aggregate Functions](#aggregate-functions)
7. [Complex Calculations](#complex-calculations)
8. [WHERE EXISTS Queries](#where-exists-queries)
9. [Conditional Queries](#conditional-queries)
10. [Search and Filtering](#search-and-filtering)
11. [Pagination with calc_rows()](#pagination-with-calc_rows)
12. [Query Grouping](#query-grouping)
13. [Security Features](#security-features)
14. [Best Practices](#best-practices)

---

## Installation

This guide walks you through installing CustomQueryBuilder into an existing CodeIgniter 3.x project.

### Prerequisites

- **CodeIgniter 3.x** installed and working
- **PHP 5.6+** (recommended: PHP 7.0+)
- Access to modify system core files
- Database configured and operational

### Installation Steps

#### Step 1: Copy CustomQueryBuilder File

Copy the `CustomQueryBuilder.php` file to your CodeIgniter system core directory:

```
your-project/
├── application/
├── system/
│   ├── core/
│   │   ├── CustomQueryBuilder.php    ← Copy file here
│   │   ├── CodeIgniter.php
│   │   └── ...
│   └── database/
└── ...
```

**File location:**
- **Source:** `system/core/CustomQueryBuilder.php` (from this repository)
- **Destination:** `your-project/system/core/CustomQueryBuilder.php`

#### Step 2: Modify Database Loader (DB.php)

Edit `system/database/DB.php` to load and extend CustomQueryBuilder instead of the default CI_DB_query_builder.

**File to edit:** `system/database/DB.php`

**Find this code** (around line 154-163):

```php
require_once(BASEPATH.'database/DB_driver.php');

if ( ! class_exists('CI_DB', FALSE))
{
    /**
     * CI_DB
     *
     * Acts as an alias for both CI_DB_driver and CI_DB_query_builder.
     *
     * @see	CI_DB_query_builder
     * @see	CI_DB_driver
     */
    class CI_DB extends CI_DB_query_builder {}
}
```

**Replace with:**

```php
require_once(BASEPATH.'database/DB_driver.php');
require_once(BASEPATH.'core/CustomQueryBuilder.php');

if ( ! class_exists('CI_DB', FALSE))
{
    /**
     * CI_DB
     *
     * Acts as an alias for both CI_DB_driver and CI_DB_query_builder.
     *
     * @see	CI_DB_query_builder
     * @see	CI_DB_driver
     */
    class CI_DB extends CustomQueryBuilder {}
}
```

**Key changes:**
1. Added: `require_once(BASEPATH.'core/CustomQueryBuilder.php');`
2. Changed: `class CI_DB extends CI_DB_query_builder {}` → `class CI_DB extends CustomQueryBuilder {}`

#### Step 3: Clear Cache (if applicable)

If your application uses caching or OPcache, clear it:

```bash
# OPcache
php -r "opcache_reset();"

# Or restart your web server
sudo service apache2 restart
# or
sudo service php-fpm restart
```

#### Step 4: Verify Installation

Create a test controller or add to an existing one:

**File:** `application/controllers/Test_custom_qb.php`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Test_custom_qb extends CI_Controller 
{
    public function index()
    {
        // Test basic eager loading
        $users = $this->db->select(['id', 'name'])
                          ->with_count('orders', 'user_id', 'id')
                          ->limit(5)
                          ->get('users');
        
        if ($users->num_rows() > 0) {
            echo '<h3>CustomQueryBuilder is working!</h3>';
            echo '<pre>';
            foreach ($users->result() as $user) {
                echo "User: {$user->name}\n";
                echo "Order Count: " . ($user->orders_count ?? 0) . "\n\n";
            }
            echo '</pre>';
        } else {
            echo '<h3>No users found, but CustomQueryBuilder is loaded!</h3>';
        }
        
        // Test that with_one is available
        if (method_exists($this->db, 'with_one')) {
            echo '<p style="color: green;">✓ with_one() method available</p>';
        }
        
        // Test that with_many is available
        if (method_exists($this->db, 'with_many')) {
            echo '<p style="color: green;">✓ with_many() method available</p>';
        }
        
        // Test that calc_rows is available
        if (method_exists($this->db, 'calc_rows')) {
            echo '<p style="color: green;">✓ calc_rows() method available</p>';
        }
    }
}
```

Visit: `http://your-site.com/index.php/test_custom_qb`

If you see the success messages, CustomQueryBuilder is installed correctly!

### Alternative Verification (Without Database Data)

If you don't have users or orders tables, use this simpler test:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Test_custom_qb extends CI_Controller 
{
    public function index()
    {
        echo '<h2>CustomQueryBuilder Method Check</h2>';
        
        $methods = [
            'with_one',
            'with_many',
            'with_count',
            'with_sum',
            'with_avg',
            'with_calculation',
            'where_exists',
            'where_has',
            'where_aggregate',
            'calc_rows',
            'search',
            'when',
            'latest',
            'first'
        ];
        
        echo '<ul>';
        foreach ($methods as $method) {
            if (method_exists($this->db, $method)) {
                echo "<li style='color: green;'>✓ {$method}() - Available</li>";
            } else {
                echo "<li style='color: red;'>✗ {$method}() - NOT FOUND</li>";
            }
        }
        echo '</ul>';
        
        // Check class inheritance
        $db_class = get_class($this->db);
        $parent_class = get_parent_class($this->db);
        
        echo '<h3>Class Information</h3>';
        echo "<p>Database class: <strong>{$db_class}</strong></p>";
        echo "<p>Parent class: <strong>{$parent_class}</strong></p>";
        
        if (strpos($parent_class, 'CustomQueryBuilder') !== false) {
            echo '<p style="color: green; font-weight: bold;">✓ CustomQueryBuilder is properly extended!</p>';
        } else {
            echo '<p style="color: red; font-weight: bold;">✗ CustomQueryBuilder is NOT being used</p>';
        }
    }
}
```

### Troubleshooting

#### Error: "Class 'CustomQueryBuilder' not found"

**Cause:** The CustomQueryBuilder.php file is not in the correct location or not properly required.

**Solution:**
1. Verify file exists at `system/core/CustomQueryBuilder.php`
2. Check that you added the require line in `system/database/DB.php`:
   ```php
   require_once(BASEPATH.'core/CustomQueryBuilder.php');
   ```
3. Verify BASEPATH constant is defined (it should be by default)

#### Error: "Call to undefined method CI_DB::with_one()"

**Cause:** The CI_DB class is not extending CustomQueryBuilder.

**Solution:**
1. Check `system/database/DB.php` line where CI_DB class is defined
2. Ensure it says: `class CI_DB extends CustomQueryBuilder {}`
3. NOT: `class CI_DB extends CI_DB_query_builder {}`
4. Clear OPcache/restart web server

#### Methods Not Working After Installation

**Solution:**
1. Clear browser cache
2. Clear OPcache: `php -r "opcache_reset();"`
3. Restart web server
4. Verify no syntax errors in CustomQueryBuilder.php

#### Performance Issues

**Note:** CustomQueryBuilder adds minimal overhead to standard queries. However:
- Eager loading (`with_*`) methods may execute additional queries
- Use `calc_rows()` only when needed for pagination
- Profile queries using CodeIgniter's profiler:
  ```php
  $this->output->enable_profiler(TRUE);
  ```

### Migration Notes

#### From Standard CI Query Builder

CustomQueryBuilder is **100% backward compatible** with CodeIgniter's standard Query Builder. All existing queries will continue to work without modification:

```php
// These standard queries work exactly as before
$this->db->select('*')
         ->where('status', 1)
         ->get('users');

$this->db->insert('users', $data);
$this->db->update('users', $data, ['id' => 1]);
```

You can gradually adopt new features:

```php
// Start using new features where they add value
$users = $this->db->select(['id', 'name'])
                  ->with_many('orders', 'user_id', 'id')  // New feature
                  ->where('status', 1)                      // Standard CI
                  ->get('users');                           // Standard CI
```

#### Upgrading CodeIgniter

When upgrading CodeIgniter versions:
1. Back up your modified `system/database/DB.php`
2. After upgrade, reapply the modifications (Steps 2)
3. Test thoroughly

### System Requirements

- **CodeIgniter:** 3.0.0 or higher (tested up to 3.1.13)
- **PHP:** 5.6+ (recommended: 7.0+)
- **MySQL:** 5.5+ or MariaDB 10.0+
- **Memory:** No additional requirements beyond CodeIgniter defaults

### File Checklist

After installation, verify these files exist:

```
✓ system/core/CustomQueryBuilder.php          (New file - copied)
✓ system/database/DB.php                      (Modified - extends CustomQueryBuilder)
```

### Next Steps

Once installation is verified:
1. Read the [Introduction](#introduction) section for an overview
2. Try [Eager Loading Relations](#eager-loading-relations) for immediate benefits
3. Explore [Aggregate Functions](#aggregate-functions) to reduce query counts
4. Review [Security Features](#security-features) to understand SQL injection protection

---

## Introduction

**CustomQueryBuilder** is an enhanced CodeIgniter 3.x Query Builder that extends the native `CI_DB_query_builder` with powerful features including:

- **Eager loading relationships** (similar to Laravel's Eloquent)
- **Advanced WHERE conditions** (WHERE EXISTS, WHERE HAS, etc.)
- **Aggregate functions** with custom expressions
- **Complex calculations** using mathematical operations
- **SQL injection prevention** with comprehensive validation
- **Chunking capabilities** for large datasets
- **Enhanced search functionality**

### Location
- File: `system/core/CustomQueryBuilder.php`
- Size: 5667 lines
- Author: Danar Ardiwinanto
- Version: 1.0.0

### Accessing the Query Builder

```php
// In CodeIgniter controllers/models, use $this->db
$users = $this->db->select('id, name, email')
                  ->where('status', 1)
                  ->get('users');
```

---

## Basic Query Methods

### Standard CodeIgniter Methods

All standard CodeIgniter query builder methods are available:

```php
// SELECT
$this->db->select('id, name, email');
$this->db->select(['id', 'name', 'email']); // Array syntax

// FROM
$this->db->from('users');

// WHERE
$this->db->where('status', 1);
$this->db->where('age >', 18);
$this->db->where(['status' => 1, 'verified' => 1]);

// JOIN
$this->db->join('profiles', 'profiles.user_id = users.id', 'left');

// ORDER BY
$this->db->order_by('created_at', 'DESC');

// LIMIT
$this->db->limit(10, 0);

// GET
$result = $this->db->get('users');
```

### Enhanced Methods

#### `first()` - Get First Row

```php
// Get single user
$user = $this->db->where('email', 'john@example.com')->first('users');
if ($user) {
    echo $user->name;
}

// With relations
$post = $this->db->with_one('user', 'id', 'user_id')->first('posts');
```

#### `exists()` - Check if Rows Exist

```php
// Check if user exists
if ($this->db->where('email', 'john@example.com')->exists('users')) {
    echo 'User exists';
}

// Check if user has orders
if ($this->db->where('user_id', 1)->exists('orders')) {
    echo 'User has orders';
}
```

#### `doesnt_exist()` - Check if No Rows Exist

```php
if ($this->db->where('status', 'deleted')->doesnt_exist('users')) {
    echo 'No deleted users';
}
```

#### `latest()` / `oldest()` - Quick Ordering

```php
// Order by latest (DESC)
$this->db->latest('created_at')->get('posts');
// Generates: ORDER BY created_at DESC

// Order by oldest (ASC)
$this->db->oldest('created_at')->get('posts');
// Generates: ORDER BY created_at ASC
```

---

## Advanced WHERE Conditions

### `where_not()` - Not Equal Condition

```php
$this->db->where_not('status', 'deleted');
// Generates: WHERE `status` != 'deleted'

$this->db->where_not('user_id', 5);
// Generates: WHERE `user_id` != 5
```

### `where_null()` / `where_not_null()` - NULL Checks

```php
// IS NULL
$this->db->where_null('deleted_at');
// Generates: WHERE `deleted_at` IS NULL

// IS NOT NULL
$this->db->where_not_null('email_verified_at');
// Generates: WHERE `email_verified_at` IS NOT NULL
```

### `where_between()` / `where_not_between()` - Range Conditions

```php
// BETWEEN
$this->db->where_between('age', [18, 65]);
// Generates: WHERE `age` BETWEEN 18 AND 65

$this->db->where_between('created_at', ['2023-01-01', '2023-12-31']);
// Generates: WHERE `created_at` BETWEEN '2023-01-01' AND '2023-12-31'

// NOT BETWEEN
$this->db->where_not_between('price', [100, 500]);
// Generates: WHERE `price` NOT BETWEEN 100 AND 500

// OR BETWEEN
$this->db->or_where_between('age', [18, 25]);
// Generates: OR `age` BETWEEN 18 AND 25
```

### `order_by_sequence()` - Custom Sequence Ordering

```php
// Order by priority: high, medium, low
$this->db->order_by_sequence('priority', ['high', 'medium', 'low']);

// Order by status in specific sequence
$this->db->order_by_sequence('status', ['pending', 'processing', 'completed', 'cancelled']);
// Generates: ORDER BY CASE WHEN status='pending' THEN 0 WHEN status='processing' THEN 1 ...
```

---

## Eager Loading Relations

### `with_one()` - Load Single Related Record

```php
// Load post with its author (user)
$posts = $this->db->with_one('users', 'id', 'user_id')->get('posts');
// Result: $post->users (single user object)

// Load order with customer details
$orders = $this->db->with_one('customers', 'id', 'customer_id')->get('orders');
// Result: $order->customers

// With alias
$posts = $this->db->with_one(['users' => 'author'], 'id', 'user_id')->get('posts');
// Result: $post->author

// With conditions
$posts = $this->db->with_one('users', 'id', 'user_id', function($query) {
    $query->where('status', 'active')
          ->select('id, name, email');
})->get('posts');
```

**Parameters:**
- `$relation` (string|array): Relation name or array with alias
- `$foreignKey` (string|array): Foreign key(s) in related table
- `$localKey` (string|array): Local key(s) in main table
- `$callback` (callable|null): Optional callback for additional conditions

### `with_many()` - Load Multiple Related Records

```php
// Load user's multiple orders
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');
// Result: $user->orders (array of order objects)

// Load user's orders with conditions
$users = $this->db->with_many('orders', 'user_id', 'id', function($query) {
    $query->where('status', 'active')
          ->order_by('created_at', 'DESC');
})->get('users');

// With alias
$users = $this->db->with_many(['orders' => 'user_orders'], 'user_id', 'id')->get('users');
// Result: $user->user_orders

// Load posts with comments
$posts = $this->db->with_many('comments', 'post_id', 'id')->get('posts');
// Result: $post->comments (array of comment objects)
```

### Multiple Relations

```php
// Load multiple relations at once
$posts = $this->db->with_one('users', 'id', 'user_id')
                  ->with_many('comments', 'id', 'post_id')
                  ->with_count('likes', 'id', 'post_id')
                  ->get('posts');

// Result:
// $post->users        (single user object)
// $post->comments     (array of comments)
// $post->likes_count  (integer)
```

### Nested Relations

```php
// Load posts with comments, and comments with their users
$posts = $this->db->with_many('comments', 'id', 'post_id', function($query) {
    $query->with_one('users', 'id', 'user_id')
          ->where('approved', 1);
})->get('posts');

// Result: $post->comments[0]->users
```

---

## Aggregate Functions

### `with_count()` - Count Related Records

```php
// Get users with their order count (can be sorted)
$users = $this->db->with_count('orders', 'id', 'user_id')
                  ->order_by('orders_count', 'DESC')
                  ->get('users');
// Result: $user->orders_count

// With alias
$this->db->with_count(['orders' => 'total_orders'], 'id', 'user_id');
// Result: $user->total_orders

// Can be used with other relations
$users = $this->db->with_count('orders', 'id', 'user_id')
                  ->with_many('posts', 'id', 'user_id')
                  ->get('users');

// In callbacks (for relation subqueries)
$posts = $this->db->with_many('comments', 'id', 'post_id', function($query) {
    $query->with_count('likes', 'id', 'comment_id');
})->get('posts');
```

### `with_sum()` - Sum Column Values

```php
// Get users with total order amount (can be sorted)
$users = $this->db->with_sum('orders', 'id', 'user_id', 'total_amount')
                  ->order_by('orders_sum', 'DESC')
                  ->get('users');
// Result: $user->orders_sum

// With alias
$this->db->with_sum(['orders' => 'total_spent'], 'id', 'user_id', 'total_amount');
// Result: $user->total_spent

// With custom expression (mathematical operations)
$invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
    'id', 'idinvoice', '(job_total_price_before_discount - job_discount)', true);
// Result: $invoice->total_after_discount

// With callback for WHERE conditions
$users = $this->db->with_sum('orders', 'id', 'user_id', 'total_amount', false, function($query) {
    $query->where('status', 'completed')
          ->where('created_at >=', '2023-01-01');
})->get('users');

// With custom expression and callback
$invoices = $this->db->with_sum(['job' => 'total_after_discount'], 
    'id', 'idinvoice', '(job_total_price_before_discount - job_discount)', true, 
    function($query) {
        $query->where('status', 'active');
    }
);
```

### `with_avg()` - Calculate Average

```php
// Get users with average order amount
$users = $this->db->with_avg('orders', 'id', 'user_id', 'total_amount')->get('users');
// Result: $user->orders_avg

// With alias
$this->db->with_avg(['orders' => 'avg_order_value'], 'id', 'user_id', 'total_amount');
// Result: $user->avg_order_value

// With custom expression (mathematical operations)
$orders = $this->db->with_avg('items', 'id', 'order_id', '(price * quantity)', true);
// Result: $order->items_avg (average of calculated values)

// With callback for WHERE conditions
$users = $this->db->with_avg('orders', 'id', 'user_id', 'total_amount', false, function($query) {
    $query->where('status', 'completed')
          ->where_between('created_at', ['2023-01-01', '2023-12-31']);
})->get('users');
```

### `with_max()` - Find Maximum Value

```php
// Get users with their highest order amount
$users = $this->db->with_max('orders', 'id', 'user_id', 'total_amount')->get('users');
// Result: $user->orders_max

// Get posts with latest comment date
$this->db->with_max(['comments' => 'latest_comment'], 'id', 'post_id', 'created_at');
// Result: $post->latest_comment

// With custom expression
$products = $this->db->with_max('sales', 'id', 'product_id', '(base_price + tax)', true);
// Result: $product->sales_max
```

### `with_min()` - Find Minimum Value

```php
// Get users with their lowest order amount
$users = $this->db->with_min('orders', 'id', 'user_id', 'total_amount')->get('users');
// Result: $user->orders_min

// Get categories with earliest post date
$this->db->with_min(['posts' => 'first_post'], 'id', 'category_id', 'created_at');
// Result: $category->first_post

// With custom expression
$transactions = $this->db->with_min('payments', 'id', 'transaction_id', '(amount - discount)', true);
// Result: $transaction->payments_min
```

---

## Complex Calculations

### `with_calculation()` - Custom Mathematical Expressions

This method allows you to create complex calculations using multiple aggregate functions and mathematical operations.

```php
// Calculate efficiency percentage: (finished_qty / total_qty) * 100
$orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'], 
    'order_id', 'id', 
    '(SUM(finished_qty) / SUM(total_qty)) * 100'
)->get('orders');
// Result: $order->efficiency_percentage

// Calculate profit margin: ((revenue - cost) / revenue) * 100
$products = $this->db->with_calculation(['sales' => 'profit_margin'], 
    'product_id', 'id',
    '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100'
)->get('products');

// Calculate average order value with discount
$customers = $this->db->with_calculation(['orders' => 'avg_order_with_discount'], 
    'customer_id', 'id',
    'AVG(total_amount - discount_amount)'
)->get('customers');

// Calculate production duration in days using DATEDIFF
$transactions = $this->db->with_calculation(['transaction_step' => 'production_duration_days'], 
    'idtransaction_detail', 'idtransaction_detail',
    'DATEDIFF(MAX(date), MIN(date))'
)->get('transaction_detail');

// Calculate weighted average with callback for conditions
$products = $this->db->with_calculation(['reviews' => 'weighted_rating'], 
    'product_id', 'id',
    'SUM(rating * helpful_votes) / SUM(helpful_votes)',
    function($query) {
        $query->where('status', 'approved')
              ->where('helpful_votes >', 0);
    }
)->get('products');

// Multiple calculations in one query
$orders = $this->db->with_calculation(['order_items' => 'total_revenue'], 'id', 'order_id', 'SUM(price * quantity)')
                  ->with_calculation(['order_items' => 'total_cost'], 'id', 'order_id', 'SUM(cost * quantity)')
                  ->with_calculation(['order_items' => 'profit'], 'id', 'order_id', 'SUM((price - cost) * quantity)')
                  ->get('orders');
```

**Supported Operations:**
- Basic math: `+`, `-`, `*`, `/`, `%`
- Aggregate functions: `SUM`, `AVG`, `COUNT`, `MIN`, `MAX`
- Date functions: `DATEDIFF`, `TIMESTAMPDIFF`
- Conditional: `CASE WHEN ... THEN ... END`
- Mathematical functions: `ROUND`, `FLOOR`, `CEIL`, `ABS`

### `where_aggregate()` - Filter by Aggregate Values

Filter results based on aggregate calculations:

```php
// Filter by calculated field
$this->db->with_calculation(['transaction_detail' => 'sales_price'], 'id', 'idtransaction', 'SUM(price)')
         ->where_aggregate('sales_price >', 10000)
         ->get('transaction');

// Filter by sum aggregate
$this->db->with_sum(['orders' => 'total_spent'], 'id', 'user_id', 'amount')
         ->where_aggregate('total_spent >=', 5000)
         ->get('users');

// Multiple conditions
$this->db->with_avg(['reviews' => 'avg_rating'], 'id', 'product_id', 'rating')
         ->where_aggregate('avg_rating >', 4.5)
         ->where_aggregate('avg_rating <', 5.0)
         ->get('products');

// With OR condition
$this->db->with_sum(['orders' => 'total_amount'], 'id', 'user_id', 'amount')
         ->where_aggregate('total_amount >', 10000)
         ->or_where_aggregate('total_amount =', 0)
         ->get('users');
```

---

## WHERE EXISTS Queries

### `where_exists()` - Check Existence with Callback

```php
// Outlets that have marketing SPK with transactions and delivery
$this->db->where_exists(function($query) {
    $query->select('1')
          ->from('marketing_spk ms')
          ->join('transaction t', 't.idmarketing_spk = ms.idmarketing_spk AND t.idoutlet = ms.idspk_workshop AND t.status = 1', 'inner')
          ->join('transaction_delivery td', 'td.idtransaction = t.idtransaction', 'inner')
          ->where('ms.idspk_workshop = outlet.idoutlet')
          ->where('ms.status', 1);
});

// Users that have published posts
$this->db->where_exists(function($query) {
    $query->select('1')
          ->from('posts')
          ->where('posts.user_id = users.id')
          ->where('status', 'published');
});
```

### `where_not_exists()` - Check Non-Existence

```php
// Users that don't have any published posts
$this->db->where_not_exists(function($query) {
    $query->select('1')
          ->from('posts')
          ->where('posts.user_id = users.id')
          ->where('status', 'published');
});

// Outlets without any completed transactions
$this->db->where_not_exists(function($query) {
    $query->select('1')
          ->from('marketing_spk ms')
          ->join('transaction t', 't.idmarketing_spk = ms.idmarketing_spk', 'inner')
          ->where('ms.idspk_workshop = outlet.idoutlet')
          ->where('t.status', 'completed');
});
```

### `where_exists_relation()` - Simplified Existence Check

```php
// Users that have orders
$this->db->from('users')->where_exists_relation('orders', 'id', 'user_id');

// Users that have active orders  
$this->db->from('users')->where_exists_relation('orders', 'id', 'user_id', function($query) {
    $query->where('status', 'active');
});

// Multiple foreign keys
$this->db->from('users')->where_exists_relation('user_roles', ['id', 'tenant_id'], ['user_id', 'tenant_id']);

// Marketing SPK with transactions and delivery
$this->db->from('outlet')->where_exists_relation('marketing_spk', 'idspk_workshop', 'idoutlet', function($query) {
    $query->join('transaction t', 't.idmarketing_spk = marketing_spk.idmarketing_spk AND t.idoutlet = marketing_spk.idspk_workshop AND t.status = 1', 'inner')
          ->join('transaction_delivery td', 'td.idtransaction = t.idtransaction', 'inner')
          ->where('marketing_spk.status', 1);
});
```

### `where_has()` - Relationship Existence with Count

```php
// Users that have orders
$this->db->from('users')->where_has('orders', 'id', 'user_id');

// Users that have active orders
$this->db->from('users')->where_has('orders', 'id', 'user_id', function($query) {
    $query->where('status', 'active');
});

// Users with at least 5 orders
$this->db->from('users')->where_has('orders', 'id', 'user_id', null, '>=', 5);
```

### `where_doesnt_have()` - Relationship Non-Existence

```php
// Users that don't have any orders
$this->db->from('users')->where_doesnt_have('orders', 'id', 'user_id');

// Users that don't have cancelled orders
$this->db->from('users')->where_doesnt_have('orders', 'id', 'user_id', function($query) {
    $query->where('status', 'cancelled');
});
```

### OR Variants

```php
// OR WHERE EXISTS
$this->db->or_where_exists(function($query) {
    $query->select('1')->from('posts')->where('posts.user_id = users.id');
});

// OR WHERE NOT EXISTS
$this->db->or_where_not_exists(function($query) {
    $query->select('1')->from('orders')->where('orders.user_id = users.id');
});

// OR WHERE EXISTS RELATION
$this->db->from('users')
         ->where_exists_relation('orders', 'id', 'user_id')
         ->or_where_exists_relation('posts', 'id', 'user_id');
```

---

## Conditional Queries

### `when()` - Execute Callback When Condition is True

```php
// Conditional WHERE clause
$this->db->when($search_term, function($query) use ($search_term) {
    $query->like('name', $search_term);
});

// With else callback
$this->db->when($user_role == 'admin', function($query) {
    $query->select('*');
}, function($query) {
    $query->select('id, name, email');
});

// Multiple conditions
$query = $this->db->from('users');

$query->when($filter_status, function($q) use ($filter_status) {
    $q->where('status', $filter_status);
});

$query->when($filter_role, function($q) use ($filter_role) {
    $q->where('role', $filter_role);
});

$query->when($search, function($q) use ($search) {
    $q->search($search, ['name', 'email']);
});

$result = $query->get();
```

### `unless()` - Execute Callback Unless Condition is True

```php
// Add WHERE clause unless user is admin
$this->db->unless($user_role == 'admin', function($query) {
    $query->where('status', 'published');
});

// With else callback
$this->db->unless(empty($search), function($query) use ($search) {
    // This runs when $search is NOT empty
    $query->like('title', $search);
}, function($query) {
    // This runs when $search IS empty
    $query->order_by('created_at', 'DESC');
});
```

---

## Search and Filtering

### `search()` - Multi-Column Search

```php
// Search in name and email columns with OR
$this->db->search('john', ['name', 'email']);
// Generates: WHERE (`name` LIKE '%john%' OR `email` LIKE '%john%')

// Search with AND conditions
$this->db->search('admin', ['role', 'title'], false);
// Generates: WHERE (`role` LIKE '%admin%' AND `title` LIKE '%admin%')

// Complex search with other conditions
$this->db->where('status', 1)
         ->search($search_term, ['name', 'email', 'phone'])
         ->order_by('name', 'ASC')
         ->get('users');
```

---

## Pagination with calc_rows()

### `calc_rows()` - Enable SQL_CALC_FOUND_ROWS

This method automatically adds `SQL_CALC_FOUND_ROWS` to get total count without extra query:

```php
// Use with array select
$result = $this->db->select(['idoutlet as id', 'outlet_name as text'])
                   ->calc_rows()
                   ->get('outlet', 20, 0);

$data = $result->result();      // 20 rows
$total = $result->found_rows(); // Total available rows

// Works with eager loading relations too!
$result = $this->db->select(['idoutlet as id', 'outlet_name as text'])
                   ->with_one('users', 'user_id', 'id')
                   ->calc_rows()
                   ->get('outlet', 20, 0);

$data = $result->result();      // Data with relations loaded
$total = $result->found_rows(); // Total count
```

### `get_found_rows()` - Get Total Count (Legacy)

```php
// Old way (still works for backward compatibility)
$data = $this->db->select(['id', 'name'])
                 ->calc_rows()
                 ->get('users', 10, 0);
$total = $this->db->get_found_rows(); // Works but not recommended

// New recommended way
$result = $this->db->select(['id', 'name'])
                   ->calc_rows()
                   ->get('users', 10, 0);
$total = $result->found_rows(); // Better approach!
```

---

## Query Grouping

### `group()` - Group WHERE Conditions

```php
$this->db->where('status', 'active')
         ->group(function($query) {
             $query->where('name', 'John')
                   ->or_where('name', 'Jane');
         });
// Generates: WHERE `status` = 'active' AND (`name` = 'John' OR `name` = 'Jane')

// Complex grouping
$this->db->where('status', 'active')
         ->group(function($query) {
             $query->where('role', 'admin')
                   ->or_where('role', 'moderator');
         })
         ->group(function($query) {
             $query->where('age >', 18)
                   ->where('verified', 1);
         });
// Generates: WHERE `status` = 'active' 
//            AND (`role` = 'admin' OR `role` = 'moderator') 
//            AND (`age` > 18 AND `verified` = 1)
```

### `or_group()` - Group with OR Operator

```php
$this->db->where('status', 'active')
         ->or_group(function($query) {
             $query->where('name', 'John')
                   ->where('age', '>', 18);
         });
// Generates: WHERE `status` = 'active' OR (`name` = 'John' AND `age` > 18)

// Multiple OR groups
$this->db->where('status', 'active')
         ->or_group(function($query) {
             $query->where('role', 'admin');
         })
         ->or_group(function($query) {
             $query->where('role', 'moderator')
                   ->where('verified', 1);
         });
// Generates: WHERE `status` = 'active' 
//            OR (`role` = 'admin') 
//            OR (`role` = 'moderator' AND `verified` = 1)
```

---

## Security Features

### QueryValidationTrait

CustomQueryBuilder includes comprehensive SQL injection prevention:

#### Validated Elements

1. **Column Names**
   - Alphanumeric, underscores, dots (for table.column)
   - Max length: 64 characters
   - Blocks SQL keywords and dangerous patterns

2. **Table Names**
   - Alphanumeric and underscores only
   - Max length: 64 characters
   - Blocks SQL keywords

3. **Operators**
   - Whitelist: `=`, `>`, `<`, `>=`, `<=`, `!=`, `<>`, `BETWEEN`, `NOT BETWEEN`

4. **Expressions**
   - Allowed SQL functions: `SUM`, `AVG`, `COUNT`, `MAX`, `MIN`, `ROUND`, `FLOOR`, `CEIL`, `ABS`, `COALESCE`, `IFNULL`, `NULLIF`, `CASE`, `WHEN`, `THEN`, `ELSE`, `END`, `DATEDIFF`, `TIMESTAMPDIFF`, `CONCAT`, etc.
   - Blocks dangerous patterns: `INSERT`, `UPDATE`, `DELETE`, `DROP`, `UNION`, `EXEC`, `EXECUTE`, `CREATE`, `ALTER`, `TRUNCATE`
   - Validates parentheses balance
   - Max expression length: 500 characters

#### Dangerous Patterns Blocked

```php
// These patterns are automatically blocked:
- SQL keywords: SELECT, INSERT, UPDATE, DELETE, DROP, UNION, OR, AND, WHERE, FROM, JOIN, etc.
- Comments: --, /*, */
- Multiple statements: semicolons (;)
- String concatenation: ||, &&
- Stored procedures: xp_, sp_
```

### Safe Usage Examples

```php
// ✅ SAFE - Using validated methods
$this->db->where('status', $user_input);
$this->db->where_in('id', $array_input);
$this->db->with_sum('orders', 'user_id', 'id', 'amount');

// ✅ SAFE - Expressions are validated
$this->db->with_calculation(['sales' => 'profit'], 
    'product_id', 'id',
    'SUM(price * quantity) - SUM(cost * quantity)'
);

// ❌ UNSAFE - Don't do this
$this->db->where("status = '{$user_input}'", null, false); // Direct concatenation

// ✅ SAFE - Use escape() method
$this->db->where("status = " . $this->db->escape($user_input), null, false);
```

---

## Best Practices

### 1. Always Use Method Chaining

```php
// ✅ Good
$result = $this->db->select('id, name')
                   ->where('status', 1)
                   ->order_by('name', 'ASC')
                   ->limit(10)
                   ->get('users');

// ❌ Avoid
$this->db->select('id, name');
$this->db->where('status', 1);
$result = $this->db->get('users');
```

### 2. Use Eager Loading for Relations

```php
// ✅ Good - Single query per relation
$users = $this->db->with_many('orders', 'user_id', 'id')
                  ->with_many('posts', 'user_id', 'id')
                  ->get('users');

// ❌ Avoid - N+1 query problem
$users = $this->db->get('users')->result();
foreach ($users as $user) {
    $user->orders = $this->db->where('user_id', $user->id)->get('orders')->result();
    $user->posts = $this->db->where('user_id', $user->id)->get('posts')->result();
}
```

### 3. Use Aggregates Instead of Looping

```php
// ✅ Good - Single query with aggregate
$users = $this->db->with_count('orders', 'user_id', 'id')
                  ->with_sum('orders', 'user_id', 'id', 'total_amount')
                  ->get('users');

// ❌ Avoid - Multiple queries in loop
$users = $this->db->get('users')->result();
foreach ($users as $user) {
    $user->order_count = $this->db->where('user_id', $user->id)->count_all_results('orders');
    $user->order_total = $this->db->select_sum('total_amount')
                                   ->where('user_id', $user->id)
                                   ->get('orders')
                                   ->row()->total_amount;
}
```

### 4. Use calc_rows() for Pagination

```php
// ✅ Good - Single query for data + count
$result = $this->db->select('id, name')
                   ->calc_rows()
                   ->get('users', 10, 0);
$data = $result->result();
$total = $result->found_rows();

// ❌ Avoid - Two separate queries
$data = $this->db->select('id, name')->get('users', 10, 0)->result();
$total = $this->db->count_all_results('users');
```

### 5. Use Conditional Methods for Dynamic Queries

```php
// ✅ Good - Clean conditional logic
$query = $this->db->from('users')
                  ->when($search, function($q) use ($search) {
                      $q->search($search, ['name', 'email']);
                  })
                  ->when($status, function($q) use ($status) {
                      $q->where('status', $status);
                  })
                  ->when($role, function($q) use ($role) {
                      $q->where('role', $role);
                  });

// ❌ Avoid - Nested if statements
if ($search) {
    $this->db->like('name', $search);
    $this->db->or_like('email', $search);
}
if ($status) {
    $this->db->where('status', $status);
}
if ($role) {
    $this->db->where('role', $role);
}
```

### 6. Use where_exists_relation() for Existence Checks

```php
// ✅ Good - Single query with EXISTS
$users = $this->db->from('users')
                  ->where_exists_relation('orders', 'id', 'user_id')
                  ->get();

// ❌ Avoid - Subquery or JOIN
$users = $this->db->select('users.*')
                  ->from('users')
                  ->join('orders', 'orders.user_id = users.id')
                  ->group_by('users.id')
                  ->get();
```

### 7. Always Validate User Input

```php
// ✅ Good - Validated through methods
$this->db->where('status', $user_input);
$this->db->where_in('id', $user_array);

// ✅ Good - Explicit escape
$this->db->where("custom_field = " . $this->db->escape($user_input), null, false);

// ❌ Never do this
$this->db->where("status = '{$user_input}'", null, false);
```

---

## Complete Example

Here's a comprehensive example combining multiple features:

```php
// Get users with their data and related information
$page = 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = $this->input->get('search');
$status = $this->input->get('status');
$role = $this->input->get('role');
$min_orders = $this->input->get('min_orders');

$result = $this->db->select(['id', 'name', 'email', 'created_at'])
                   // Eager load profile (single relation)
                   ->with_one('profiles', 'id', 'user_id', function($query) {
                       $query->select('user_id, avatar, bio');
                   })
                   // Eager load latest orders (multiple relations with conditions)
                   ->with_many('orders', 'id', 'user_id', function($query) {
                       $query->where('status', 'completed')
                             ->order_by('created_at', 'DESC')
                             ->limit(5);
                   })
                   // Count total orders
                   ->with_count('orders', 'id', 'user_id')
                   // Sum total order amount
                   ->with_sum(['orders' => 'total_spent'], 'id', 'user_id', 'total_amount', false, function($query) {
                       $query->where('status', 'completed');
                   })
                   // Calculate average order value
                   ->with_avg(['orders' => 'avg_order_value'], 'id', 'user_id', 'total_amount')
                   // Conditional search
                   ->when($search, function($query) use ($search) {
                       $query->search($search, ['name', 'email']);
                   })
                   // Conditional status filter
                   ->when($status, function($query) use ($status) {
                       $query->where('status', $status);
                   })
                   // Conditional role filter
                   ->when($role, function($query) use ($role) {
                       $query->where('role', $role);
                   })
                   // Filter by minimum orders using aggregate
                   ->when($min_orders, function($query) use ($min_orders) {
                       $query->where_has('orders', 'id', 'user_id', null, '>=', $min_orders);
                   })
                   // Only users that have verified email
                   ->where_not_null('email_verified_at')
                   // Exclude deleted users
                   ->where_not('status', 'deleted')
                   // Order by total spent (from aggregate)
                   ->order_by('total_spent', 'DESC')
                   // Enable pagination with total count
                   ->calc_rows()
                   // Execute query
                   ->get('users', $per_page, $offset);

// Get data and total count
$data = $result->result();
$total = $result->found_rows();

// Calculate pagination
$total_pages = ceil($total / $per_page);

// Access data
foreach ($data as $user) {
    echo $user->name;                    // Name
    echo $user->profiles->avatar;        // Avatar from profile (single)
    echo $user->orders_count;            // Count of orders
    echo $user->total_spent;             // Sum of order amounts
    echo $user->avg_order_value;         // Average order value
    
    // Loop through latest orders
    foreach ($user->orders as $order) {
        echo $order->id;
        echo $order->total_amount;
    }
}
```

---

## Notes

- **Performance**: Eager loading significantly reduces database queries (N+1 problem prevention)
- **Security**: All inputs are validated and escaped automatically
- **Compatibility**: Works with existing CodeIgniter 3.x applications
- **Result Format**: Use `result()` for objects, `result_array()` for arrays
- **Debugging**: Enable debug mode to see generated SQL queries

---

## Version Information

- **Version**: 1.0.0
- **Author**: Danar Ardiwinanto
- **Framework**: CodeIgniter 3.x
- **File**: `system/core/CustomQueryBuilder.php`
- **Lines**: 5667

---

## Support

For issues or questions related to CustomQueryBuilder, contact the development team or refer to the inline documentation within the source file.
