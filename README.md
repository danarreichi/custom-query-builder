# CustomQueryBuilder

A drop-in, backward-compatible extension of CodeIgniter 3's Query Builder that adds Laravel-inspired features: eager loading, aggregate subqueries, `WHERE EXISTS`/`WHERE HAS` conditions, chunking, and more — while remaining 100% compatible with every existing `$this->db->...` call in your app.

*(Versi Bahasa Indonesia: [README.id.md](README.id.md))*

> **Complete API reference:** every one of this library's 99 public methods, fully documented (signature, parameters, return type, examples, notes) — see [`docs/USAGE.md`](docs/USAGE.md). If you use Claude Code, Claude Desktop, or another MCP-compatible AI assistant, [`mcp-server/`](mcp-server/) exposes that same reference as MCP tools (`list_methods`, `get_method`, `search_methods`, ...) so your assistant can look up exact signatures instead of guessing — read-only, it never touches a database. See [`mcp-server/README.md`](mcp-server/README.md) for setup.

## Table of Contents

1. [Installation](#installation)
2. [Trying It Out (Clone & Run)](#trying-it-out-clone--run)
3. [Database Driver Support](#database-driver-support)
4. [Quick Start](#quick-start)
5. [The Result Object](#the-result-object)
6. [Enhanced Basic Methods](#enhanced-basic-methods)
7. [Eager Loading Relations](#eager-loading-relations)
8. [Aggregate Subqueries](#aggregate-subqueries)
9. [JOIN-Based Aggregates (Lighter Alternative)](#join-based-aggregates-lighter-alternative)
10. [WHERE EXISTS / WHERE HAS](#where-exists--where-has)
11. [Conditional Queries](#conditional-queries)
12. [Search](#search)
13. [Pagination](#pagination)
14. [Query Grouping](#query-grouping)
15. [Chunking Large Datasets](#chunking-large-datasets)
16. [pluck()](#pluck)
17. [Transactions](#transactions)
18. [Raw query()](#raw-query)
19. [Security](#security)
20. [Best Practices](#best-practices)
21. [Complete Example](#complete-example)
22. [Notes & Caveats](#notes--caveats)

---

## Installation

### Prerequisites

- **CodeIgniter 3.x** installed and working
- **PHP 5.6+** (the library is written to be PHP 5 compatible — no `??`, no `static::class`, etc.)
- Access to modify system core files
- Database configured and operational

### Step 1: Copy the files

```
your-project/
├── application/
├── system/
│   ├── core/
│   │   ├── CustomQueryBuilder/        ← copy this whole folder here
│   │   │   ├── main.php
│   │   │   └── libs/
│   │   ├── CodeIgniter.php
│   │   └── ...
│   └── database/
```

### Step 2: Modify `system/database/DB.php`

**Find this code** (around line 154–185 in stock CI 3.1.x):

```php
require_once(BASEPATH.'database/DB_driver.php');

if ( ! isset($query_builder) OR $query_builder === TRUE)
{
    require_once(BASEPATH.'database/DB_query_builder.php');
    if ( ! class_exists('CI_DB', FALSE))
    {
        class CI_DB extends CI_DB_query_builder { }
    }
}
```

**Replace with:**

```php
require_once(BASEPATH.'database/DB_driver.php');

if ( ! isset($query_builder) OR $query_builder === TRUE)
{
    require_once(BASEPATH.'database/DB_query_builder.php');
    require_once(BASEPATH.'core/CustomQueryBuilder/main.php');
    if ( ! class_exists('CI_DB', FALSE))
    {
        class CI_DB extends CustomQueryBuilder { }
    }
}
```

> `main.php` already does its own `require_once(BASEPATH.'database/DB_query_builder.php')` internally, so the `require_once` for it is safe to place anywhere in this block — PHP won't load the parent class twice either way.

### Step 3: Clear cache

```bash
php -r "opcache_reset();"
# or restart your web server / php-fpm
```

### Step 4: Verify

```php
class Test_custom_qb extends CI_Controller
{
    public function index()
    {
        echo get_class($this->db) . "\n";
        echo ($this->db instanceof CustomQueryBuilder) ? "OK\n" : "FAIL\n";
    }
}
```

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Class 'CustomQueryBuilder' not found` | File missing or not required | Verify `system/core/CustomQueryBuilder/main.php` exists and Step 2's `require_once` line is present |
| `Call to undefined method CI_DB::with_one()` | `CI_DB` still extends `CI_DB_query_builder` | Re-check Step 2, then clear OPcache |
| Nothing changed after editing | OPcache serving stale bytecode | `php -r "opcache_reset();"` or restart PHP |

### Backward compatibility

Every standard CI Query Builder call keeps working unmodified:

```php
$this->db->select('*')->where('status', 1)->get('users');
$this->db->insert('users', $data);
$this->db->update('users', $data, ['id' => 1]);
```

Adopt new features incrementally, one call at a time.

---

## Trying It Out (Clone & Run)

The section above is for wiring this library into your **own** CodeIgniter 3 app. If you just cloned this repository and want to try the library itself — run its regression suite, poke at it interactively, see the SQL it generates — this repo ships everything needed to do that without touching a separate project.

There are two sandboxes, both driven by the **same** database config file:

- **`tests/`** — an automated PHPUnit suite (118 tests) that asserts exact compiled SQL strings and real query results, run **twice**: once against MySQL, once against an in-memory SQLite connection (same 118 scenarios both times — see [Database Driver Support](#database-driver-support)). This is the fast, CI-friendly way to verify the library works.
- **`test-ci3/`** — a full CodeIgniter 3 install with a manual smoke-test controller (`Test_custom_qb`) that exercises ~68 scenarios against MySQL and prints plain-text output with `(expect ...)` annotations, viewable in a browser.

### Prerequisites

- **PHP 8.1+** and **Composer** (for `tests/` — the *library itself* stays PHP 5.6-compatible; only the PHPUnit tooling needs a modern PHP)
- **MySQL/MariaDB** reachable locally, for the MySQL test suite and `test-ci3` — the SQLite test suite needs nothing beyond PHP's `sqlite3` extension (commonly enabled by default)

### 1. Clone and point it at a database

```bash
git clone <repo-url>
cd custom-query-builder
```

Create an empty database, then edit **`test-ci3/application/config/database.php`** — this one file is shared by both sandboxes, so you only ever configure the connection in one place:

```php
$db['default'] = array(
    'hostname' => '127.0.0.1',
    'username' => 'root',
    'password' => '',            // <- your MySQL password
    'database' => 'cqb_test',    // <- your database name
    'dbdriver' => 'mysqli',
    // ...
);
```

No manual schema setup needed: the `scores`, `category_scores`, and `profiles` fixture tables are created and (re)seeded automatically on every test run; `users` (id, name, email, category) is seeded with 3 sample rows only if it's empty, so it's never clobbered if you've already got data there.

### 2. Run the automated test suite (recommended)

```bash
cd tests
composer install
vendor/bin/phpunit                      # MySQL suite (default) — needs the password set above
vendor/bin/phpunit --testsuite sqlite   # SQLite suite — no password, no server, nothing to configure
```

```
PHPUnit 9.6.35 by Sebastian Bergmann and contributors.

...............................................................  63 / 118 ( 53%)
.......................................................         118 / 118 (100%)

OK (118 tests, 172 assertions)
```

Both commands run the identical 118 scenarios — `CompiledSqlTest`/`EdgeCaseTest`/`ExecutionTest` against MySQL, `SqliteCompiledSqlTest`/`SqliteEdgeCaseTest`/`SqliteExecutionTest` against SQLite. See [`tests/CompiledSqlTest.php`](tests/CompiledSqlTest.php) (exact-SQL-string assertions) and [`tests/ExecutionTest.php`](tests/ExecutionTest.php) (real query-result assertions) for what's covered.

> **Always run the two suites as separate commands, never combined in one `vendor/bin/phpunit` invocation.** CodeIgniter 3's own identifier-escaping code caches its quote character the first time it runs, so mixing a MySQL-backed test and a SQLite-backed one in the same PHP process makes the second driver's exact-SQL-string assertions fail spuriously — a CI3 quirk, not a bug in this library. `tests/phpunit.xml` already defines `mysql`/`sqlite` as two separate named testsuites for exactly this reason.

> If your environment has multiple PHP versions installed (e.g. Laragon/XAMPP) and `php`/`composer` on your `PATH` resolve to something older than 8.1, point at the right binary explicitly: `/path/to/php8.1 /path/to/composer.phar install` and `/path/to/php8.1 vendor/bin/phpunit`.

### 3. Or try it interactively via the CI3 sandbox

```bash
cd test-ci3
php -S 127.0.0.1:8080 index.php
```

Then open `http://127.0.0.1:8080/Test_custom_qb` in a browser — it walks through eager loading, `WHERE EXISTS`/`WHERE HAS`, aggregates, grouping, SQL-injection rejection, and more, printing the compiled SQL and/or result alongside an `(expect ...)` comment so you can eyeball correctness.

---

## Database Driver Support

Built directly on CodeIgniter 3's own driver-agnostic query builder, so most of the library works unmodified across whichever CI3 driver you configure — identifier quoting (`` ` `` vs `"` vs `[...]`) automatically follows the active driver's escape character, no code changes needed on your end.

| Driver | Status |
|---|---|
| MySQL / MariaDB (`mysqli`) | ✅ Fully supported — the primary target, 118 tests |
| SQLite (`sqlite3`) | ✅ Fully supported — same 118 tests, in-memory or file-based |
| PostgreSQL, SQL Server, Oracle, PDO subdrivers | ⚠️ Untested — not yet run against a real connection |

Two things to know if you're on a non-MySQL driver:

- **`paginate()`/`calc_rows()`/`get_found_rows()`** always compute the total via a portable `COUNT(*)` subquery, on every driver including MySQL — see [Pagination](#pagination).
- **MySQL-only date functions** (`DATEDIFF`, `TIMESTAMPDIFF`, `CURDATE`, `CURTIME`) allowed inside `with_calculation()`/custom expressions aren't translated for other drivers' date syntax yet — avoid them outside MySQL/MariaDB for now.

---

## Quick Start

```php
$users = $this->db->select(['id', 'name'])
    ->with_many('orders', 'user_id', 'id')   // eager-load orders
    ->with_count('orders', 'user_id', 'id')  // + a count column
    ->where('status', 'active')
    ->order_by('name', 'ASC')
    ->get('users');

foreach ($users->result() as $user) {
    echo "{$user->name}: {$user->orders_count} orders\n";
    foreach ($user->orders as $order) {
        echo "  - #{$order->id}\n";
    }
}
```

---

## The Result Object

`get()`, `get_where()`, and raw `query()` (for `SELECT` statements) all return a `CustomQueryBuilderResult` instead of CI's native result object. It proxies every unrecognized method call to the underlying native driver result via `__call()`, so nothing you already do with a CI result breaks.

```php
$result = $this->db->get('users');

$result->result();        // array of objects (same as native)
$result->result_array();  // array of arrays
$result->row();           // first row as object
$result->row_array();     // first row as array
$result->num_rows();      // row count
$result->num_fields();    // proxied straight to the native driver result
```

### `value($column)` — grab a single column from the first row

```php
$email = $this->db->where('id', 1)->get('users')->value('email');
```

There is also a **pre-execution** `value()` directly on the query builder (before `get()`), which is more efficient because it adds an implicit `SELECT` + `LIMIT 1` instead of fetching a full row you already have:

```php
$email = $this->db->where('id', 1)->value('email', 'users');
// Equivalent to: SELECT `email` FROM users WHERE id = 1 LIMIT 1
```

### `key_by()` — re-index a result set (Laravel's `keyBy()`)

```php
$byId = $this->db->get('users')->key_by('id');
// [1 => {...}, 2 => {...}, ...]

$byUpperName = $this->db->get('users')->key_by(function ($row) {
    return strtoupper($row->name);
});

$asArrays = $this->db->get('users')->key_by('id', true); // array rows instead of objects
```

If a key repeats, the last matching row wins (same behavior as Laravel's `Collection::keyBy()`).

---

## Enhanced Basic Methods

```php
// first() — get a single row directly
$user = $this->db->where('email', 'john@example.com')->first('users');

// exists() / doesnt_exist()
if ($this->db->where('email', $email)->exists('users')) { /* ... */ }
if ($this->db->where('status', 'deleted')->doesnt_exist('users')) { /* ... */ }

// latest() / oldest() — quick ordering shortcuts
$this->db->latest('created_at')->get('posts');   // ORDER BY created_at DESC
$this->db->oldest('created_at')->get('posts');   // ORDER BY created_at ASC

// where_not() / where_null() / where_not_null()
$this->db->where_not('status', 'deleted');       // status != 'deleted'
$this->db->where_null('deleted_at');
$this->db->where_not_null('email_verified_at');

// where_between() / where_not_between() (+ or_ variants)
$this->db->where_between('age', [18, 65]);
$this->db->where_not_between('price', [100, 500]);
$this->db->or_where_between('age', [18, 25]);

// order_by_sequence() — custom manual ordering
$this->db->order_by_sequence('priority', ['high', 'medium', 'low']);
// ORDER BY CASE WHEN priority='high' THEN 0 WHEN priority='medium' THEN 1 ...
```

---

## Eager Loading Relations

### `with_one()` — single related record

```php
$posts = $this->db->with_one('users', 'id', 'user_id')->get('posts');
// $post->users

$posts = $this->db->with_one(['users' => 'author'], 'id', 'user_id')->get('posts');
// $post->author

$posts = $this->db->with_one('users', 'id', 'user_id', function ($q) {
    $q->where('status', 'active')->select('id, name, email');
})->get('posts');
```

### `with_many()` — multiple related records

```php
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');
// $user->orders (array)

$users = $this->db->with_many('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active')->order_by('created_at', 'DESC');
})->get('users');
```

`$relation` accepts a plain string, or a single-element `['table' => 'alias']` array (any other array shape throws `InvalidArgumentException`). `$foreignKey`/`$localKey` accept either a single column string or an array of columns for composite keys — the arrays must be the same length.

### Nested relations

```php
$posts = $this->db->with_many('comments', 'id', 'post_id', function ($q) {
    $q->with_one('users', 'id', 'user_id')->where('approved', 1);
})->get('posts');
// $post->comments[0]->users
```

### Multiple relations at once

```php
$posts = $this->db->with_one('users', 'id', 'user_id')
    ->with_many('comments', 'id', 'post_id')
    ->with_count('likes', 'id', 'post_id')
    ->get('posts');
```

---

## Aggregate Subqueries

These add a correlated subquery to `SELECT` — one extra subquery execution **per row** in the main result set. See [JOIN-Based Aggregates](#join-based-aggregates-lighter-alternative) below for a lighter alternative on large result sets.

### `with_count()`

```php
$users = $this->db->with_count('orders', 'id', 'user_id')
    ->order_by('orders_count', 'DESC')
    ->get('users');
// $user->orders_count

$this->db->with_count(['orders' => 'total_orders'], 'id', 'user_id');
// $user->total_orders
```

### `with_sum()` / `with_avg()` / `with_max()` / `with_min()`

```php
$this->db->with_sum('orders', 'id', 'user_id', 'total_amount');
// $user->orders_sum

$this->db->with_avg(['orders' => 'avg_order_value'], 'id', 'user_id', 'total_amount');
// $user->avg_order_value

// Custom expression (mathematical operations) — pass true as the 5th arg
$this->db->with_sum(['job' => 'total_after_discount'],
    'id', 'idinvoice', '(job_total_price_before_discount - job_discount)', true);

// Callback for extra WHERE conditions inside the subquery
$this->db->with_avg('orders', 'id', 'user_id', 'total_amount', false, function ($q) {
    $q->where('status', 'completed');
});
```

### `with_calculation()` — arbitrary expressions

```php
$orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'],
    'order_id', 'id',
    '(SUM(finished_qty) / SUM(total_qty)) * 100'
)->get('orders');
// $order->efficiency_percentage

// With a callback for filtering rows inside the subquery
$products = $this->db->with_calculation(['reviews' => 'weighted_rating'],
    'product_id', 'id',
    'SUM(rating * helpful_votes) / SUM(helpful_votes)',
    function ($q) { $q->where('status', 'approved'); }
)->get('products');
```

**Allowed in expressions:** `+ - * / %`, `SUM AVG COUNT MIN MAX`, `DATEDIFF TIMESTAMPDIFF`, `CASE WHEN ... THEN ... END`, `ROUND FLOOR CEIL ABS`, and a handful of other whitelisted functions — see [Security](#security). Anything else is rejected with `InvalidArgumentException` before it ever reaches the database.

### `where_aggregate()` / `or_where_aggregate()` — filter by an aggregate

Use these **after** a `with_sum`/`with_avg`/`with_calculation` call that defines the alias you're filtering on:

```php
$this->db->with_sum(['orders' => 'total_spent'], 'id', 'user_id', 'amount')
    ->where_aggregate('total_spent >=', 5000)
    ->get('users');

// BETWEEN is supported too
$this->db->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
    ->where_aggregate('score_total BETWEEN', [1, 100])
    ->get('users');

// Combine with OR
$this->db->with_sum(['orders' => 'total_amount'], 'id', 'user_id', 'amount')
    ->where_aggregate('total_amount >', 10000)
    ->or_where_aggregate('total_amount =', 0)
    ->get('users');
```

`where_aggregate()`/`or_where_aggregate()` correctly preserve the exact position they were called at relative to any other `where()`/`or_where()`/`group()` calls in the same chain — mixing them freely with plain conditions produces the SQL you'd expect from reading the chain top to bottom.

---

## JOIN-Based Aggregates (Lighter Alternative)

`with_*` aggregates run one correlated subquery **per row**. `join_*` aggregates instead scan the relation table **once** via a `GROUP BY` derived table, then `LEFT JOIN` it in — much lighter on large result sets.

```php
$users = $this->db->join_count('orders', 'user_id', 'id')->get('users');
// $user->orders_count

$users = $this->db->join_sum('orders', 'user_id', 'id', 'total_amount')
    ->order_by('orders_sum', 'DESC')
    ->get('users');
// $user->orders_sum

$this->db->join_avg('orders', 'user_id', 'id', 'total_amount');
$this->db->join_min('orders', 'user_id', 'id', 'total_amount');
$this->db->join_max(['orders' => 'highest_order'], 'user_id', 'id', 'total_amount');
// $user->highest_order

$this->db->join_calculation(
    ['sales' => 'profit_margin'],
    'product_id', 'id',
    '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100',
    function ($q) { $q->where('status', 'completed'); }
);
```

Same signature shape as their `with_*` counterparts (`$relation`, `$foreignKey`, `$localKey`, `$column`/`$expression`, optional `$callback`). Use `join_*` instead of `with_*` when your result set is large and the correlated-subquery cost per row starts to matter.

---

## WHERE EXISTS / WHERE HAS

### Raw `where_exists()` family — full control via callback

```php
$this->db->where_exists(function ($q) {
    $q->select('1')->from('posts')
      ->where('posts.user_id = users.id')
      ->where('status', 'published');
});

$this->db->where_not_exists(function ($q) { /* ... */ });
$this->db->or_where_exists(function ($q) { /* ... */ });
$this->db->or_where_not_exists(function ($q) { /* ... */ });
```

### `where_exists_relation()` family — simplified, auto-joins on keys

```php
$this->db->from('users')->where_exists_relation('orders', 'user_id', 'id');
// WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)

$this->db->from('users')->where_exists_relation('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active');
});

// Composite keys
$this->db->from('users')->where_exists_relation('user_roles', ['user_id', 'tenant_id'], ['id', 'tenant_id']);

$this->db->from('users')
    ->where_exists_relation('orders', 'user_id', 'id')
    ->or_where_exists_relation('posts', 'user_id', 'id');

$this->db->from('users')->where_not_exists_relation('orders', 'user_id', 'id');
```

Works equally well with `->from('table')` called up front or `->get('table')` called at the end — the parent table is resolved lazily either way.

`or_where_exists_relation()` / `or_where_not_exists_relation()` accept an optional 5th `$disable_pending_process` argument. You normally don't need it; it exists for edge cases where you want the condition queued through the same ordering-preserving mechanism used inside `group()` even outside of one.

### `where_has()` / `or_where_has()` — existence with a count threshold

```php
$this->db->from('users')->where_has('orders', 'user_id', 'id');
// same as where_exists_relation() when the count defaults to >= 1

$this->db->from('users')->where_has('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active');
});

// Users with at least 5 orders
$this->db->from('users')->where_has('orders', 'user_id', 'id', null, '>=', 5);

// Shorthand: pass the operator as the 4th arg when you don't need a callback
$this->db->from('users')->where_has('orders', 'user_id', 'id', '>=', 5);
```

`where_doesnt_have()` / `or_where_doesnt_have()` are shorthands for the "not exists" version.

All variants above — plain `where_exists()`, `where_exists_relation()`, and `where_has()` — correctly preserve call order relative to each other, to `group()`, and to plain `where()`/`or_where()` calls in the same chain, however they're interleaved.

---

## Conditional Queries

```php
// when() — run a callback only if the condition is truthy
$this->db->when($search_term, function ($q) use ($search_term) {
    $q->like('name', $search_term);
});

// with an else-callback
$this->db->when($user_role == 'admin', function ($q) {
    $q->select('*');
}, function ($q) {
    $q->select('id, name, email');
});

// unless() — the inverse of when()
$this->db->unless($user_role == 'admin', function ($q) {
    $q->where('status', 'published');
});
```

Great for building dynamic filter chains without a wall of `if` statements:

```php
$result = $this->db->from('users')
    ->when($search, function ($q) use ($search)   { $q->search($search, ['name', 'email']); })
    ->when($status, function ($q) use ($status)   { $q->where('status', $status); })
    ->when($role,   function ($q) use ($role)     { $q->where('role', $role); })
    ->get();
```

---

## Search

```php
$this->db->search('john', ['name', 'email']);
// WHERE (`name` LIKE '%john%' OR `email` LIKE '%john%')

$this->db->search('admin', ['role', 'title'], false); // AND instead of OR
// WHERE (`role` LIKE '%admin%' AND `title` LIKE '%admin%')
```

---

## Pagination

Two ways to paginate, both built on the same portable `COUNT(*)` mechanism (see `calc_rows()` below) — not MySQL's `SQL_CALC_FOUND_ROWS`, which MySQL itself deprecated as of 8.0.17 and never actually made this cheaper (it forced a full scan of every matching row regardless of `LIMIT`, the same cost `COUNT(*)` already has).

### `paginate()` — page-based, Laravel-style (recommended)

`paginate($per_page, $page)` — argument order deliberately matches Laravel's own `paginate($perPage, ...)`, so `->paginate(20)` alone means "20 per page" (page defaults to 1), the same as it would in Laravel. It computes `LIMIT`/`OFFSET` for you and the result carries all the usual pagination metadata:

```php
$result = $this->db->where('status', 'active')
    ->paginate(20, 2)   // 20 per page, page 2
    ->get('users');

$result->result();          // 20 rows for page 2
$result->found_rows();      // total rows across every page, e.g. 347
$result->current_page();    // 2
$result->per_page();        // 20
$result->last_page();       // 18
$result->has_more_pages();  // true
$result->from();            // 21 — 1-indexed position of the first row on this page
$result->to();               // 40 — 1-indexed position of the last row on this page

// Works with eager loading too
$result = $this->db->with_one('profile', 'user_id', 'id')
    ->paginate(20)   // page defaults to 1
    ->get('users');
```

Every other `CustomQueryBuilderResult` method still works exactly as before — `result()`, `result_array()`, `row()`, `row_array()`, etc. are all unaffected; `paginate()` only adds the methods above.

If you pass an explicit `$limit`/`$offset` to `get()` anyway, that wins over `paginate()`'s computed values — `paginate()` only fills them in when `get()` is called without them.

Need the whole shape as one array (e.g. for a JSON API response)?

```php
$result->to_pagination_array();
// [
//   'data' => [...], 'current_page' => 2, 'per_page' => 20, 'total' => 347,
//   'last_page' => 18, 'from' => 21, 'to' => 40, 'has_more_pages' => true,
// ]
```

There's no `next_page_url`/`prev_page_url` — this library sits below the HTTP/routing layer and has no request context to build URLs from. Build links from `current_page()`/`last_page()` in your controller/route layer instead.

### `calc_rows()` — lower-level: just the total, you supply `LIMIT`/`OFFSET`

`paginate()` is built on top of this. Reach for `calc_rows()` directly when you want the found-rows total but don't want page-number-based pagination (e.g. you already compute your own offset):

```php
$result = $this->db->select(['id', 'name'])
    ->calc_rows()
    ->get('users', 20, 0);

$data  = $result->result();      // 20 rows
$total = $result->found_rows();  // total rows without LIMIT

// Works with eager loading too
$result = $this->db->select(['id', 'name'])
    ->with_one('profile', 'user_id', 'id')
    ->calc_rows()
    ->get('users', 20, 0);
```

Under the hood this runs two queries — the page of data, and a `SELECT COUNT(*) AS total FROM (<your query, no LIMIT>) AS ...` — same as if you called `count_all_results()` and `get()` separately (see [Complete Example](#complete-example)), just as one method chain instead of two.

### `get_found_rows()` — old-style access to the total (backward compatible)

Before `$result->found_rows()` existed, the total was read straight off `$this->db` right after the `calc_rows()` query. This still works, for code written against that older API:

```php
$data  = $this->db->select(['id', 'name'])->calc_rows()->get('users', 20, 0);
$total = $this->db->get_found_rows();
```

Prefer `$result->found_rows()` in new code.

---

## Query Grouping

```php
$this->db->where('status', 'active')
    ->group(function ($q) {
        $q->where('name', 'John')->or_where('name', 'Jane');
    });
// WHERE `status` = 'active' AND (`name` = 'John' OR `name` = 'Jane')

$this->db->where('status', 'active')
    ->or_group(function ($q) {
        $q->where('role', 'admin');
    });
// WHERE `status` = 'active' OR (`role` = 'admin')
```

`group()`/`or_group()` work correctly whether the table is already known (`->from('table')` called first) or supplied later (`->get('table')` at the end of the chain), and preserve their position relative to any `where_has()`/`where_exists_relation()`/`where_aggregate()`/plain `where()` calls before or after them — including when several of those are mixed together in the same query.

### `group_start()` / `group_end()` — the raw pair, for manual control

`group()`/`or_group()` above are a callback-based wrapper around this raw pair. Reach for `group_start()`/`group_end()` directly when you need to open and close the bracket at two different points in your code (e.g. across an `if`), instead of inside a single callback:

```php
$this->db->where('status', 'active')->group_start();

if ($include_pending) {
    $this->db->or_where('status', 'pending');
}

$this->db->where('archived', 0)->group_end();
// WHERE `status` = 'active' AND ( `status` = 'pending' AND `archived` = 0 )
```

If nothing ends up added between `group_start()` and `group_end()`, the empty bracket is dropped automatically instead of producing invalid `( )` SQL. Nests freely with itself, with `group()`/`or_group()`, and with `where_has()`/`where_exists_relation()`/`where_aggregate()` in any combination.

---

## Chunking Large Datasets

```php
// Offset-based
$this->db->where('status', 'active')->chunk(500, function ($users, $page) {
    foreach ($users as $user) { /* ... */ }
    if ($page > 10) return false; // stop early
}, 'users');

// ID-based (no gaps/duplicates if rows are inserted/deleted mid-run)
$this->db->chunk_by_id(1000, function ($users) {
    foreach ($users as $user) { $this->send_email($user->email); }
}, 'id', 'users');
```

Both return the total number of records processed. The callback receives an **array of objects** (not arrays) — `$user->name`, not `$user['name']`.

---

## pluck()

```php
$emails = $this->db->from('users')->pluck('email');
// ['a@x.com', 'b@x.com', ...]

// Dot notation reaches into an eager-loaded relation
$names = $this->db->from('users')->with_one('profile', 'profile_id', 'id')->pluck('profile.name');
```

---

## Transactions

```php
$result = $this->db->transaction(function ($db) {
    $db->insert('users', $data);
    $id = $db->insert_id();
    $db->insert('profiles', ['user_id' => $id]);
    return $id;
});

// Strict mode re-throws on failure instead of returning false
$this->db->transaction(function ($db) { /* ... */ }, true);
```

---

## Raw query()

`query()` is overridden so raw SQL keeps working exactly like native CI, while `SELECT` statements are still wrapped in a `CustomQueryBuilderResult`:

```php
$result = $this->db->query("SELECT * FROM transaction WHERE status = 1");
$result->result(); // works, same as get()

$ok = $this->db->query("UPDATE users SET status = 'inactive' WHERE id = 1");
// returns a plain bool, same as native CI, for write statements
```

If `with_relations`/`pending_aggregates` are set beforehand, `query()` automatically runs eager loading against the raw SQL's results too.

---

## Security

`QueryValidationTrait` validates every identifier and expression that flows through the library's own helper methods (it does not retroactively sanitize raw strings you pass to native CI methods like `where("...", null, false)` — that's on you, same as stock CI).

- **Column/table names**: alphanumeric + underscore (+ dot for `table.column`), max 64 chars, SQL keywords rejected.
- **Operators**: whitelist only — `= > < >= <= != <> BETWEEN "NOT BETWEEN"`.
- **Expressions** (`with_calculation`, custom `with_sum`/`with_avg` expressions, etc.): function-call whitelist (`SUM AVG COUNT MAX MIN ROUND FLOOR CEIL ABS COALESCE IFNULL NULLIF CASE DATEDIFF TIMESTAMPDIFF CONCAT`, …), dangerous keywords blocked (`INSERT UPDATE DELETE DROP UNION EXEC CREATE ALTER TRUNCATE SLEEP BENCHMARK GET_LOCK`, …), balanced parentheses required, max 500 chars.
- **`with()`/`with_one()`/`with_many()` relation arrays**: must be exactly `['relation_name' => 'alias']` — any other shape (empty array, 2+ elements) throws `InvalidArgumentException` instead of silently guessing.

```php
// ✅ Safe — validated
$this->db->where('status', $user_input);
$this->db->with_calculation(['sales' => 'profit'], 'product_id', 'id', 'SUM(price * quantity) - SUM(cost * quantity)');

// ❌ Unsafe — raw concatenation bypasses validation entirely
$this->db->where("status = '{$user_input}'", null, false);

// ✅ Safe — explicit escape when you must build a raw fragment
$this->db->where("status = " . $this->db->escape($user_input), null, false);
```

---

## Best Practices

**Eager-load instead of looping** — avoids the N+1 problem:

```php
// Good
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');

// Avoid
foreach ($this->db->get('users')->result() as $user) {
    $user->orders = $this->db->where('user_id', $user->id)->get('orders')->result();
}
```

**Use aggregates instead of per-row queries:**

```php
$users = $this->db->with_count('orders', 'user_id', 'id')
    ->with_sum('orders', 'user_id', 'id', 'total_amount')
    ->get('users');
```

**Use `where_exists_relation()`/`where_has()` instead of `JOIN + GROUP BY` for pure existence checks** — clearer intent, same performance:

```php
$users = $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id')->get();
```

**Use `paginate()` for page-based pagination** — see [Pagination](#pagination). For lower-level found-rows counting, `calc_rows()` and a separate `count_all_results()` call are equivalent — both run a plain `COUNT(*)` under the hood; use `calc_rows()` for the one-call convenience, or `count_all_results()` when you want the count and the page as two clearly separate steps.

**Prefer `join_*` over `with_*` aggregates on large result sets** — one `GROUP BY` scan instead of N correlated subqueries.

**Never concatenate user input into raw WHERE strings** — use the validated methods, or `escape()` explicitly if you must build a fragment by hand.

---

## Complete Example

```php
$page      = 1;
$per_page  = 20;
$offset    = ($page - 1) * $per_page;
$search    = $this->input->get('search');
$status    = $this->input->get('status');
$min_orders = $this->input->get('min_orders');

$query = $this->db->select(['id', 'name', 'email', 'created_at'])
    ->from('users')
    ->with_one('profiles', 'user_id', 'id', function ($q) {
        $q->select('user_id, avatar, bio');
    })
    ->with_many('orders', 'user_id', 'id', function ($q) {
        $q->where('status', 'completed')->order_by('created_at', 'DESC')->limit(5);
    })
    ->with_count('orders', 'user_id', 'id')
    ->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'total_amount', false, function ($q) {
        $q->where('status', 'completed');
    })
    ->when($search, function ($q) use ($search) { $q->search($search, ['name', 'email']); })
    ->when($status, function ($q) use ($status) { $q->where('status', $status); })
    ->when($min_orders, function ($q) use ($min_orders) {
        $q->where_has('orders', 'user_id', 'id', null, '>=', $min_orders);
    })
    ->where_not_null('email_verified_at')
    ->where_not('status', 'deleted');

// Note: with the table already set via from() above, pass no table name to
// either call below — passing it to both would add `users` to the query twice.
$total  = $query->count_all_results('', false); // false = keep the conditions for the query below
$result = $query->order_by('total_spent', 'DESC')->get('', $per_page, $offset);
$data   = $result->result();

foreach ($data as $user) {
    echo "{$user->name}: {$user->orders_count} orders, {$user->total_spent} spent\n";
    foreach ($user->orders as $order) {
        echo "  - order #{$order->id}\n";
    }
}
```

---

## Notes & Caveats

- **`result()` vs `result_array()`**: objects vs. plain arrays — pick based on what the calling code expects.
- **`chunk()`/`chunk_by_id()` callbacks receive objects**, not arrays.
- **`with()`/`with_one()`/`with_many()` array shape is strict**: `['relation' => 'alias']` with exactly one element, or a plain string. Anything else throws.
- **Ordering across mixed condition types**: `where()`, `or_where()`, `group()`, `where_exists_relation()`, `where_has()`, and `where_aggregate()` can be freely interleaved in any order (including nested inside each other) — the final `WHERE` clause reflects the order you called them in.
- **`get_compiled_select()` and `count_all_results()` ignore eager-loading (`with()`/`with_one()`/`with_many()`)** by design — they compile/count the base query only. Aggregate subqueries (`with_count`, `with_sum`, `with_calculation`, etc.) *do* show up, since those live in `SELECT`/`WHERE`, not in the separate eager-loading pipeline.
- **PHP 5.6+ compatible**: no `??`, no `static::class`, no other 7+/8+-only syntax — safe to drop into older CodeIgniter 3 installs.

---

## Version Information

- **File**: `system/core/CustomQueryBuilder/main.php`
- **Framework**: CodeIgniter 3.x
- **PHP**: 5.6+
