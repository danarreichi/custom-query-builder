# CustomQueryBuilder Quick Reference

Quick lookup guide for common CustomQueryBuilder operations.

## Relationships

| Method | Signature | Returns |
|--------|-----------|---------|
| `with_one()` | `with_one($relation, $foreignKey, $localKey, $callback = null)` | Single object or null |
| `with_many()` | `with_many($relation, $foreignKey, $localKey, $callback = null)` | Array of objects |
| `with()` | `with($relation, $foreignKey, $localKey, $multiple = true, $callback = null)` | Array or object |

```php
// One-to-one
->with_one('profile', 'user_id', 'id')

// One-to-many
->with_many('orders', 'user_id', 'id')

// With alias
->with_many(['orders' => 'user_orders'], 'user_id', 'id')

// With conditions
->with_many('orders', 'user_id', 'id', function($q) {
    $q->where('status', 'completed');
})
```

## Aggregations

| Method | Purpose | Result Field |
|--------|---------|--------------|
| `with_count()` | Count records | `{relation}_count` |
| `with_sum()` | Sum column values | `{relation}_sum` |
| `with_avg()` | Average values | `{relation}_avg` |
| `with_max()` | Maximum value | `{relation}_max` |
| `with_min()` | Minimum value | `{relation}_min` |
| `with_calculation()` | Custom expression | Custom alias |

```php
// Count
->with_count('orders', 'user_id', 'id')
// Result: $user->orders_count

// Sum with custom expression
->with_sum('orders', 'user_id', 'id', '(price - discount)', true)
// Result: $user->orders_sum

// Calculation
->with_calculation(['items' => 'total'], 'order_id', 'id', 'SUM(price * qty)')
// Result: $order->total
```

## WHERE Conditions

| Method | Usage |
|--------|-------|
| `where()` | Standard where clause |
| `where_in()` | Value in array |
| `where_not_in()` | Value not in array |
| `where_null()` | IS NULL |
| `where_not_null()` | IS NOT NULL |
| `where_between()` | BETWEEN two values |
| `where_not_between()` | NOT BETWEEN |
| `where_exists()` | EXISTS subquery |
| `where_not_exists()` | NOT EXISTS subquery |
| `where_has()` | Has related records with count |
| `where_doesnt_have()` | Doesn't have related records |
| `where_aggregate()` | Filter by aggregate value |

```php
// Standard
->where('status', 1)
->where_in('role', ['admin', 'editor'])
->where_null('deleted_at')
->where_between('price', [100, 1000])

// Subquery
->where_exists(function($q) {
    $q->select('1')->from('orders')
      ->where('orders.user_id = users.id');
})

// Relation check
->where_has('orders', 'user_id', 'id', null, '>=', 3)

// Aggregate filter
->with_sum(['orders' => 'total'], 'user_id', 'id', 'amount')
->where_aggregate('total >', 10000)
```

## Search & Filtering

```php
// Search multiple columns
->search('keyword', ['name', 'description'])

// Conditional query
->when($status, function($q) use ($status) {
    $q->where('status', $status);
})

// Grouped conditions
->group(function($q) {
    $q->where('role', 'admin')
      ->or_where('role', 'moderator');
})

// Unless (inverse of when)
->unless($show_deleted, function($q) {
    $q->where('deleted_at', null);
})
```

## Ordering & Limits

```php
// Latest/oldest
->latest('created_at')
->oldest('updated_at')

// Custom order
->order_by('name', 'ASC')
->order_by('created_at', 'DESC')

// Order by sequence
->order_by_sequence('status', ['pending', 'processing', 'completed'])

// Limit
->limit(10)
->limit(10, 20)  // offset
```

## Execution Methods

| Method | Returns |
|--------|---------|
| `get()` | CustomQueryBuilderResult |
| `first()` | Single row object or null |
| `exists()` | Boolean |
| `doesnt_exist()` | Boolean |
| `count_all_results()` | Integer |
| `pluck()` | Array of single column |

```php
// Get results
$result = $this->db->get('users');
$users = $result->result();        // Objects
$users = $result->result_array();  // Arrays

// First record
$user = $this->db->first('users');

// Existence check
if ($this->db->where('email', $email)->exists('users')) {
    // ...
}

// Count
$count = $this->db->where('status', 1)->count_all_results('users');

// Pluck column
$names = $this->db->pluck('name', 'users');
// Result: ['John', 'Jane', 'Bob']
```

## Pagination

```php
// With total count
$result = $this->db->calc_rows()
    ->get('users', 20, 0);

$data = $result->result();
$total = $result->found_rows();
$pages = ceil($total / 20);
```

## Chunking

```php
// Process in batches
$this->db->chunk(100, function($users) {
    foreach ($users as $user) {
        // Process each user
    }
}, 'users');

// Chunk by ID (more efficient)
$this->db->chunk_by_id(100, function($users) {
    // Process
}, 'id', 'users');
```

## Domain/Outlet Filtering (Multi-Tenant)

```php
$app_domain = $this->config->item('app_domain');
$app_outlet = $this->config->item('app_outlet');

$query = $this->db->from('product');

// Outlet filter
if (strlen($app_outlet) > 0) {
    $query->where('product_outlet.idoutlet', $app_outlet)
          ->where('product_outlet.status', 1)
          ->join('product_outlet', 'product.idproduct=product_outlet.idproduct');
}

// Domain filter
if ($app_domain != '') {
    $query->where('domain_name', $app_domain)
          ->where('product_domain.status', 1)
          ->join('product_domain', 'product.idproduct=product_domain.idproduct')
          ->join('domain', 'product_domain.iddomain=domain.iddomain');
}
```

## Result Access

```php
$result = $this->db->get('users');

// As objects
$users = $result->result();
$user = $result->row();
$user = $result->row(5);  // 6th row

// As arrays
$users = $result->result_array();
$user = $result->row_array();

// Count
$count = $result->num_rows();
$total = $result->found_rows();  // With calc_rows()
```

## Method Chaining Order

**Recommended order for optimal performance:**

1. `select()` / `from()`
2. `join()` statements
3. Domain/outlet filters (multi-tenant)
4. Regular `where()` conditions
5. `with_*()` relations
6. `where_aggregate()` filters (after with_* methods)
7. `group_by()` / `having()`
8. `order_by()`
9. `limit()`
10. `calc_rows()` (if needed)
11. `get()` / `first()` / etc.

```php
$result = $this->db
    ->select(['users.*'])
    ->from('users')
    ->join('profiles', 'users.id = profiles.user_id', 'left')
    ->where('users.status', 1)
    ->with_count('orders', 'user_id', 'id')
    ->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'amount')
    ->where_aggregate('total_spent >', 1000)
    ->order_by('users.created_at', 'DESC')
    ->limit(20)
    ->calc_rows()
    ->get();
```

## Common Pitfalls

❌ **Wrong:** Filtering before defining aggregate
```php
->where_aggregate('orders_count >', 5)  // Error!
->with_count('orders', 'user_id', 'id')
```

✅ **Correct:** Define aggregate first
```php
->with_count('orders', 'user_id', 'id')
->where_aggregate('orders_count >', 5)
```

❌ **Wrong:** Forgetting domain/outlet filters
```php
$products = $this->db->get('product');  // Exposes all tenants!
```

✅ **Correct:** Always include filters
```php
if ($app_domain != '') {
    $this->db->where('domain_name', $app_domain)
             ->join('product_domain', '...');
}
```

❌ **Wrong:** SQL injection risk
```php
->where("name = '{$user_input}'")
```

✅ **Correct:** Use query builder or escape
```php
->where('name', $user_input)
// or
->where("name = " . $this->db->escape($user_input))
```

## Expression Validation

**Allowed in custom expressions:**
- Math: `+`, `-`, `*`, `/`, `%`
- Functions: `SUM`, `AVG`, `COUNT`, `MIN`, `MAX`, `ROUND`, `FLOOR`, `CEIL`, `ABS`, `COALESCE`, `IFNULL`, `NULLIF`
- Date: `DATEDIFF`, `TIMESTAMPDIFF`, `DAY`, `MONTH`, `YEAR`, `NOW`, `DATE`
- String: `CONCAT`, `SUBSTRING`, `TRIM`, `UPPER`, `LOWER`, `LENGTH`, `REPLACE`
- Logic: `CASE`, `WHEN`, `THEN`, `ELSE`, `END`, `IF`, `AND`, `OR`, `IS`, `NOT`, `NULL`

**Blocked patterns:**
- SQL injection: `INSERT`, `UPDATE`, `DELETE`, `DROP`, `UNION`, `EXEC`, `--`, `/*`, `*/`
- Command execution: `;`, `xp_`, `sp_`

## Performance Tips

1. **Limit eager loaded data** - Use callbacks to filter/limit relations
2. **Use chunking for large datasets** - Prevent memory exhaustion
3. **Add WHERE before WITH** - Filter main records before loading relations
4. **Index foreign keys** - Improve join performance
5. **Use calc_rows() sparingly** - Only when total count is needed
6. **Select specific columns** - Avoid `SELECT *` when possible

```php
// Good: Filter first, then load relations
$users = $this->db->where('status', 1)  // Reduces main query
    ->with_many('orders', 'user_id', 'id', function($q) {
        $q->where('status', 'completed')  // Reduces relation query
          ->limit(10);                     // Limits results per user
    })
    ->get('users');
```
