# CustomQueryBuilder — Complete API Reference

A drop-in, backward-compatible extension of CodeIgniter 3's Query Builder that adds Laravel-inspired features: eager loading, aggregate subqueries, WHERE EXISTS/WHERE HAS conditions, chunking, pagination, and more — while remaining 100% compatible with every existing $this->db->... call. PHP 5.6+ compatible. Identifier quoting is driver-aware (verified on mysqli and sqlite3).

> Generated from `mcp-server/docs/api-reference.json` — the same data an AI assistant sees through the companion MCP server (see `mcp-server/README.md`). Do not hand-edit this file; edit the JSON and regenerate with `node mcp-server/scripts/generate-usage-md.mjs`.

## Table of Contents

- [Core Query Building](#core-query-building)
- [Query Execution & Results](#query-execution-results)
- [Eager Loading Relations](#eager-loading-relations)
- [Aggregate Subqueries](#aggregate-subqueries)
- [JOIN-Based Aggregates](#join-based-aggregates)
- [WHERE EXISTS / WHERE HAS](#where-exists-where-has)
- [Conditional Queries](#conditional-queries)
- [Search](#search)
- [Pagination](#pagination)
- [Query Grouping](#query-grouping)
- [Chunking Large Datasets](#chunking-large-datasets)
- [Transactions](#transactions)
- [Advanced: Manual Flush](#advanced-manual-flush)
- [The Result Object (CustomQueryBuilderResult)](#the-result-object-customquerybuilderresult)

---

## Core Query Building

select/where/order/limit and related basics, mostly thin CI3-compatible overrides plus a handful of new shorthand helpers.

### `select($select = '*', $escape = null)`

Thin override of CI3's native select() (kept mainly for IDE return-type chaining). Accepts a comma-separated string or an array of column names/expressions, exactly like stock CodeIgniter.

**Parameters:**

- `select` (string|array) (default: `'*'`) — Column(s) to select.
- `escape` (bool|null) (default: `null`) — Pass false to insert a raw expression (e.g. a subquery or SQL function) without identifier-escaping.

**Returns:** `$this` — For chaining.

_Array select_
```php
$this->db->select(['id', 'name'])->get('users');
```

_Raw expression (escape=false)_
```php
$this->db->select('COUNT(*) as total', false)->get('orders');
```

> **Note:** Calling select() again appends fields rather than replacing (native CI3 behavior).

**See also:** `add_select`

### `add_select($select = '', $escape = null)`

Alias for select() — documented separately for readability when you want to signal "add more columns to whatever's already selected", but is functionally identical (CI3's select() already appends).

**Parameters:**

- `select` (string|array) (default: `''`) — Additional column(s) to select.
- `escape` (bool|null) (default: `null`) — Pass false for a raw expression.

**Returns:** `$this` — For chaining.

_Add a column after an initial select_
```php
$this->db->select('id')->add_select('name')->get('users');
```

**See also:** `select`

### `from($from)`

Override of CI3's from(). Detects a raw subquery given as a string in the form "(SELECT ...) alias" and injects it directly into the internal FROM list (bypassing CI's identifier escaping, which would otherwise mangle the already-compiled SQL). Also records the table name internally for use by pending where_exists_relation()/relation processors. Array input mixing subqueries and plain table names is handled per-element.

**Parameters:**

- `from` (string|array) — Table name, "table alias", or a raw "(SELECT ...) alias" subquery string (or an array of these).

**Returns:** `$this` — For chaining.

_Plain table_
```php
$this->db->from('users')->where('status', 'active')->get();
```

**See also:** `join`

### `join($table, $cond, $type = '', $escape = null)`

Thin passthrough override of CI3's native join() (kept for IDE return-type chaining only — no added behavior).

**Parameters:**

- `table` (string) — Table to join.
- `cond` (string) — ON condition.
- `type` (string) (default: `''`) — 'left', 'right', 'inner', etc.
- `escape` (bool|null) (default: `null`) — Pass false for a raw ON condition.

**Returns:** `$this` — For chaining.

_Left join_
```php
$this->db->select(['users.id'])->join('scores', 'scores.user_id = users.id')->get('users');
```

**See also:** `from`

### `limit($value, $offset = 0)`

Thin passthrough override of CI3's native limit() (IDE return-type chaining only).

**Parameters:**

- `value` (int) — Row limit.
- `offset` (int) (default: `0`) — Rows to skip.

**Returns:** `$this` — For chaining.

_Limit with offset_
```php
$this->db->limit(20, 40)->get('users'); // rows 41-60
```

> **Note:** Usually you pass limit/offset directly to get($table, $limit, $offset) instead of calling this separately.

**See also:** `get`, `paginate`

### `order_by($orderby, $direction = '', $escape = null)`

Thin passthrough override of CI3's native order_by() (IDE return-type chaining only).

**Parameters:**

- `orderby` (string) — Column name, or a raw expression.
- `direction` (string) (default: `''`) — 'ASC', 'DESC', or 'RANDOM'.
- `escape` (bool|null) (default: `null`) — Pass false for a raw expression.

**Returns:** `$this` — For chaining.

_Basic ordering_
```php
$this->db->order_by('created_at', 'DESC')->get('posts');
```

**See also:** `order_by_sequence`, `order_by_relation`, `latest`, `oldest`

### `group_by($by, $escape = null)`

Thin passthrough override of CI3's native group_by() (IDE return-type chaining only).

**Parameters:**

- `by` (string|array) — Column(s) to group by.
- `escape` (bool|null) (default: `null`) — Pass false for a raw expression.

**Returns:** `$this` — For chaining.

_Group by category_
```php
$this->db->select('category, COUNT(*) as total')->group_by('category')->get('users');
```

### `latest($column = 'created_at')`

Shorthand for order_by($column, 'DESC').

**Parameters:**

- `column` (string) (default: `'created_at'`) — Column to order by.

**Returns:** `$this` — For chaining.

_Newest first_
```php
$this->db->latest('created_at')->get('posts');
```

**See also:** `oldest`, `order_by`

### `oldest($column = 'created_at')`

Shorthand for order_by($column, 'ASC').

**Parameters:**

- `column` (string) (default: `'created_at'`) — Column to order by.

**Returns:** `$this` — For chaining.

_Oldest first_
```php
$this->db->oldest('created_at')->get('posts');
```

**See also:** `latest`, `order_by`

### `where_not($column, $value)`

Adds a `column != value` condition (value is escaped; column is protected/quoted).

**Parameters:**

- `column` (string) — Column name.
- `value` (mixed) — Value to exclude.

**Returns:** `$this` — For chaining.

_Exclude deleted rows_
```php
$this->db->where_not('status', 'deleted');
// WHERE `status` != 'deleted'
```

**See also:** `or_where_not`

### `or_where_not($column, $value)`

OR-variant of where_not() — adds `OR column != value`.

**Parameters:**

- `column` (string) — Column name.
- `value` (mixed) — Value to exclude.

**Returns:** `$this` — For chaining.

_OR condition_
```php
$this->db->where('id', 1)->or_where_not('category', 'A');
```

**See also:** `where_not`

### `where_null($column)`

Adds a `column IS NULL` condition.

**Parameters:**

- `column` (string) — Column name.

**Returns:** `$this` — For chaining.

_Not-yet-deleted rows_
```php
$this->db->where_null('deleted_at')->get('posts');
```

**See also:** `where_not_null`, `or_where_null`

### `or_where_null($column)`

OR-variant of where_null().

**Parameters:**

- `column` (string) — Column name.

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 1)->or_where_null('deleted_at');
```

**See also:** `where_null`

### `where_not_null($column)`

Adds a `column IS NOT NULL` condition.

**Parameters:**

- `column` (string) — Column name.

**Returns:** `$this` — For chaining.

_Verified emails only_
```php
$this->db->where_not_null('email_verified_at')->get('users');
```

**See also:** `where_null`, `or_where_not_null`

### `or_where_not_null($column)`

OR-variant of where_not_null().

**Parameters:**

- `column` (string) — Column name.

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 1)->or_where_not_null('email_verified_at');
```

**See also:** `where_not_null`

### `where_between($column, array $values)`

Adds a `column BETWEEN v1 AND v2` condition. Both bounds are escaped individually. Throws InvalidArgumentException if $values doesn't have exactly 2 elements.

**Parameters:**

- `column` (string) — Column name.
- `values` (array) — Exactly [min, max].

**Returns:** `$this` — For chaining.

_Age range_
```php
$this->db->where_between('age', [18, 65]);
// WHERE `age` BETWEEN 18 AND 65
```

> **Note:** Throws InvalidArgumentException if the array doesn't have exactly 2 elements.

**See also:** `where_not_between`, `or_where_between`

### `or_where_between($column, array $values)`

OR-variant of where_between().

**Parameters:**

- `column` (string) — Column name.
- `values` (array) — Exactly [min, max].

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 1)->or_where_between('age', [18, 25]);
```

**See also:** `where_between`

### `where_not_between($column, array $values)`

Adds a `column NOT BETWEEN v1 AND v2` condition. Same validation as where_between().

**Parameters:**

- `column` (string) — Column name.
- `values` (array) — Exactly [min, max].

**Returns:** `$this` — For chaining.

_Exclude a price range_
```php
$this->db->where_not_between('price', [100, 500]);
```

**See also:** `where_between`, `or_where_not_between`

### `or_where_not_between($column, array $values)`

OR-variant of where_not_between().

**Parameters:**

- `column` (string) — Column name.
- `values` (array) — Exactly [min, max].

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 1)->or_where_not_between('price', [100, 500]);
```

**See also:** `where_not_between`

### `where_in($key = null, $values = null, $escape = null)`

Override of CI3's native where_in(). Numeric arrays are cast via intval() (unquoted); string arrays are escaped individually. Manually replicates CI's AND/OR glue-prefix logic, correctly accounting for this library's deferred-condition machinery. Avoids a PCRE "regular expression is too large" failure CI3's own regex-based validation can throw on very large value lists.

**Parameters:**

- `key` (string) (default: `null`) — Column name.
- `values` (array) (default: `null`) — Values for the IN list.
- `escape` (bool|null) (default: `null`) — Pass false to insert values as-is (caller's responsibility to sanitize).

**Returns:** `$this` — For chaining.

_Basic IN_
```php
$this->db->where_in('id', [1, 2, 3])->get('users');
```

> **Note:** Throws InvalidArgumentException if $key fails column-name validation.
> **Note:** If $values isn't an array or is empty, delegates straight to CI3's native where_in() (same error/behavior as stock CI3).

**See also:** `or_where_in`, `where_not_in`, `or_where_not_in`

### `or_where_in($key = null, $values = null, $escape = null)`

OR-variant of where_in().

**Parameters:**

- `key` (string) (default: `null`) — Column name.
- `values` (array) (default: `null`) — Values for the IN list.
- `escape` (bool|null) (default: `null`) — Pass false to insert values as-is.

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 1)->or_where_in('id', [2, 3]);
```

**See also:** `where_in`

### `where_not_in($key = null, $values = null, $escape = null)`

Adds a `column NOT IN (...)` condition. Same escaping/validation approach as where_in().

**Parameters:**

- `key` (string) (default: `null`) — Column name.
- `values` (array) (default: `null`) — Values to exclude.
- `escape` (bool|null) (default: `null`) — Pass false to insert values as-is.

**Returns:** `$this` — For chaining.

```php
$this->db->where_not_in('id', [1, 2]);
```

**See also:** `where_in`, `or_where_not_in`

### `or_where_not_in($key = null, $values = null, $escape = null)`

OR-variant of where_not_in().

**Parameters:**

- `key` (string) (default: `null`) — Column name.
- `values` (array) (default: `null`) — Values to exclude.
- `escape` (bool|null) (default: `null`) — Pass false to insert values as-is.

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 1)->or_where_not_in('id', [2, 3]);
```

**See also:** `where_not_in`

### `order_by_sequence($column, array $array)`

Custom manual ordering: builds a `CASE WHEN col = 'v0' THEN 0 WHEN col = 'v1' THEN 1 ... ELSE N END` expression internally and passes it to order_by(). Rows whose value isn't in $array sort last (ELSE branch).

**Parameters:**

- `column` (string) — Column to order by (word chars + one optional dot).
- `array` (array) — Ordered list of values defining the desired sequence.

**Returns:** `$this` — For chaining.

_Priority ordering_
```php
$this->db->order_by_sequence('priority', ['high', 'medium', 'low']);
```

> **Note:** Throws InvalidArgumentException if $array is empty/not an array or $column fails validation.

**See also:** `order_by`, `order_by_relation`

### `order_by_relation($table, $foreignKey, $localKey, $column, $direction = 'ASC')`

Order by a column from a related table using a correlated subquery, without a JOIN. Registers a deferred ORDER BY resolved at get()/get_compiled_select() time, spliced back into the ORDER BY clause at its original call-order position (so it interleaves correctly with plain order_by() calls). All identifier arguments are validated.

**Parameters:**

- `table` (string) — Related table name.
- `foreignKey` (string) — Column in the related table to match.
- `localKey` (string) — Column in the main table (qualify with a table prefix to avoid ambiguity, e.g. 'quotation.idmarketing').
- `column` (string) — Column in the related table to order by.
- `direction` (string) (default: `'ASC'`) — 'ASC' or 'DESC' (anything else normalizes to ASC).

**Returns:** `$this` — For chaining.

_Order by a related user's name, without a JOIN_
```php
$this->db->order_by_relation('user', 'iduser', 'quotation.idmarketing', 'name', 'ASC');
```

> **Note:** Underlying subquery uses a bare LIMIT 1 (not portable to SQL Server/Oracle syntax — fine on MySQL/SQLite/Postgres).

**See also:** `order_by`, `order_by_sequence`

---

## Query Execution & Results

Methods that actually run a query (get/get_where/first/value/exists/count_all_results/query) and utility accessors (all_last_query/reset_query/pluck).

### `get($table = '', $limit = null, $offset = null)`

Executes the query and returns a CustomQueryBuilderResult. Automatically resolves any pending eager-loading relations, aggregate subqueries, WHERE EXISTS/HAS conditions, groups, and calc_rows()/paginate() found-rows counting before compiling. This is the terminal call for almost every query chain in this library.

**Parameters:**

- `table` (string) (default: `''`) — Table name (optional if from() was already called).
- `limit` (int|null) (default: `null`) — Row limit.
- `offset` (int|null) (default: `null`) — Rows to skip.

**Returns:** `CustomQueryBuilderResult` — See the Result Object category.

_Basic get_
```php
$result = $this->db->select(['id', 'name'])->where('status', 'active')->get('users');
```

> **Note:** If paginate() was called and $limit is left null here, get() computes LIMIT/OFFSET from the page/per_page you passed to paginate() — an explicit $limit/$offset here always overrides that.

**See also:** `get_where`, `paginate`, `calc_rows`

### `get_where($table = '', $where = null, $limit = null, $offset = null)`

Convenience wrapper: applies from(), where($where) (if an array is given), limit() (if given), then delegates to get() if any relation/aggregate/group state is pending, or runs a lightweight direct query otherwise.

**Parameters:**

- `table` (string) (default: `''`) — Table name.
- `where` (array|null) (default: `null`) — Associative array of column => value conditions.
- `limit` (int|null) (default: `null`) — Row limit.
- `offset` (int|null) (default: `null`) — Rows to skip.

**Returns:** `CustomQueryBuilderResult`

_Direct array conditions_
```php
$this->db->get_where('users', ['category' => 'A']);
```

**See also:** `get`

### `first($table = '')`

limit(1)->get($table), then returns row() (an object) if any row matched, otherwise null.

**Parameters:**

- `table` (string) (default: `''`) — Table name.

**Returns:** `object|null` — The first matching row, or null.

```php
$user = $this->db->where('email', 'john@example.com')->first('users');
if ($user) { echo $user->name; }
```

**See also:** `value`, `exists`

### `value($column, $table = '')`

Pre-execution shortcut: runs select($column)->limit(1)->get($table)->row() and pulls just that one column's value, more efficient than fetching a full row you only need one field from (adds an implicit LIMIT 1). Handles dotted table.column by taking the part after the last dot.

**Parameters:**

- `column` (string) — Column to fetch.
- `table` (string) (default: `''`) — Table name.

**Returns:** `mixed|null` — The column's value, or null if no row matched.

```php
$email = $this->db->where('id', 1)->value('email', 'users');
// Equivalent to: SELECT `email` FROM users WHERE id = 1 LIMIT 1
```

> **Note:** Throws InvalidArgumentException if $column fails column-name validation.
> **Note:** There is also a post-execution $result->value($column) on CustomQueryBuilderResult, which pulls from an already-fetched row instead of running a new query.

**See also:** `first`

### `exists($table = '')`

limit(1)->get($table), returns whether any row matched.

**Parameters:**

- `table` (string) (default: `''`) — Table name.

**Returns:** `bool` — True if at least one row matches.

```php
if ($this->db->where('email', $email)->exists('users')) { /* ... */ }
```

**See also:** `doesnt_exist`

### `doesnt_exist($table = '')`

Inverse of exists() — !exists($table).

**Parameters:**

- `table` (string) (default: `''`) — Table name.

**Returns:** `bool` — True if no row matches.

```php
if ($this->db->where('status', 'deleted')->doesnt_exist('users')) { /* ... */ }
```

**See also:** `exists`

### `count_all_results($table = '', $reset = true)`

Flushes all pending query state, temporarily strips eager-loading relations (a count doesn't need them), and delegates to CI3's native count_all_results(). Ignores eager-loading by design — counts the base query only; aggregate subqueries (with_count/with_sum/etc.) still apply since those live in SELECT/WHERE, not the separate eager-loading pipeline.

**Parameters:**

- `table` (string) (default: `''`) — Table name.
- `reset` (bool) (default: `true`) — Whether to reset query state afterward. Pass false to keep the current WHERE conditions for a following get() call on the same builder (e.g. building a paginated total + page from the same filters).

**Returns:** `int` — Row count.

_Total + page from the same filters_
```php
$total = $query->count_all_results('', false); // keep conditions
$result = $query->get('', 20, 0);
```

**See also:** `get_compiled_select`, `calc_rows`, `paginate`

### `get_compiled_select($table = '', $reset = true)`

Flushes pending query state (same as count_all_results()), strips eager-loading relations, and delegates to CI3's native get_compiled_select() to return the raw SQL string without executing it. Ignores eager-loading by design (same rationale as count_all_results()) — aggregate subqueries still show up since they live in SELECT/WHERE.

**Parameters:**

- `table` (string) (default: `''`) — Table name.
- `reset` (bool) (default: `true`) — Whether to reset query state afterward.

**Returns:** `string` — Compiled SQL string.

_Inspect the SQL a chain would produce_
```php
$sql = $this->db->where('status', 'active')->get_compiled_select('users');
```

**See also:** `count_all_results`

### `query($sql, $binds = false, $return_object = null)`

Override so raw SQL keeps working like native CI, while SELECT results are still wrapped in a CustomQueryBuilderResult. If with_one()/with_many() relations are queued beforehand, they're still resolved as separate follow-up queries matched back onto the raw SQL's results in PHP. Does NOT support anything that needs to splice into THIS query's SELECT/WHERE/JOIN before compilation — top-level with_count()/with_sum()/etc., where_has(), where_exists_relation(), where_aggregate(), join_count()/join_sum()/etc. queued beforehand are silently discarded (and cleared, so they don't leak into the next call either).

**Parameters:**

- `sql` (string) — Raw SQL statement.
- `binds` (mixed) (default: `false`) — CI3-native query bindings.
- `return_object` (bool|string|null) (default: `null`) — CI3-native result object override.

**Returns:** `CustomQueryBuilderResult|bool` — Wrapped result for SELECT; plain bool for write statements (INSERT/UPDATE/DELETE), same as native CI3.

_Raw SELECT_
```php
$result = $this->db->query("SELECT * FROM transaction WHERE status = 1");
$result->result();
```

_Raw SELECT + eager loading_
```php
$this->db->with_one('marketing_spk', 'idmarketing_spk', 'idmarketing_spk');
$q = $this->db->query("SELECT * FROM transaction WHERE status = 1");
$data = $q->result();
```

_Write statement_
```php
$ok = $this->db->query("UPDATE users SET status = 'inactive' WHERE id = 1"); // returns bool
```

**See also:** `get`

### `all_last_query()`

Returns every query executed by the most recent get() call, as an array of SQL strings — the main query plus one per eager-loaded relation, in execution order. Falls back to a single-element array (native last_query()) for plain queries with no eager loading.

**Returns:** `array` — Array of SQL strings.

```php
$this->db->with_many('posts', 'user_id', 'id')->get('users');
$queries = $this->db->all_last_query(); // [main query, relation query]
```

### `reset_query()`

Clears every custom queue this library maintains (eager-loading relations, all pending_* condition/aggregate queues, calc_rows/pagination state, group-tracking stacks, temp table name, call-order bookkeeping) in addition to CI3's own native reset. Important when cloning a builder instance to construct a subquery.

**Returns:** `$this` — For chaining (native CI3 return value).

```php
$this->db->reset_query();
```

> **Note:** Called automatically by get()/get_where() as part of their internal flow — you rarely need to call this yourself, except between independent query chains reusing the same $this->db instance in the same request.

### `pluck($column, $table = '')`

Returns a flat array of a single column's values across every matching row. Dot notation can reach into an eager-loaded relation.

**Parameters:**

- `column` (string) — Column to pluck (supports 'relation.column' dot notation for a loaded relation).
- `table` (string) (default: `''`) — Table name.

**Returns:** `array` — Flat array of values.

_Plain column_
```php
$emails = $this->db->from('users')->pluck('email');
// ['a@x.com', 'b@x.com', ...]
```

_Through an eager-loaded relation_
```php
$names = $this->db->from('users')->with_one('profile', 'profile_id', 'id')->pluck('profile.name');
```

---

## Eager Loading Relations

Load related-table rows onto each result row without N+1 queries (Laravel-style with()).

### `with($relation, $foreignKey, $localKey, $multiple = true, $callback = null)`

The generic engine behind with_one()/with_many() — both are one-line wrappers around this with $multiple fixed to false/true respectively. Prefer with_one()/with_many() for readability; with() is identical functionality with multiplicity spelled out as a boolean.

**Parameters:**

- `relation` (string|array) — Table name, or a single-element ['table' => 'alias'] array.
- `foreignKey` (string|array) — Column(s) on the related table (array for composite keys).
- `localKey` (string|array) — Column(s) on the main table (must match foreignKey's cardinality).
- `multiple` (bool) (default: `true`) — true = array of matching rows (with_many style), false = single row (with_one style).
- `callback` (callable|null) (default: `null`) — callable(CustomQueryBuilder): void — add WHERE/order_by/nested relations scoped to this relation's subquery.

**Returns:** `$this` — For chaining.

_Equivalent to with_many()_
```php
$this->db->with('comments', 'post_id', 'id', true, function ($q) {
    $q->where('approved', 1);
});
```

> **Note:** Throws InvalidArgumentException if the relation array doesn't have exactly one element, if $multiple isn't a strict bool, or if foreignKey/localKey cardinalities don't match.

**See also:** `with_one`, `with_many`

### `with_one($relation, $foreignKey, $localKey, $callback = null)`

Eager-load a single related record per row (belongsTo/hasOne-style). When multiple relation rows match the same local key, the FIRST matching row is kept by default (respects an order_by() inside the callback) — see CustomQueryBuilder::FIX_WITH_ONE_ORDER_BY.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — callable(CustomQueryBuilder): void — scope the relation subquery (where/order_by/nested with_*).

**Returns:** `$this` — For chaining.

_Basic_
```php
$posts = $this->db->with_one('users', 'id', 'user_id')->get('posts');
// $post->users
```

_Aliased_
```php
$posts = $this->db->with_one(['users' => 'author'], 'id', 'user_id')->get('posts');
// $post->author
```

_Scoped + order-dependent pick_
```php
$this->db->with_one('scores', 'user_id', 'id', function ($q) {
    $q->order_by('value', 'DESC'); // keeps the highest-value row
})->where('id', 1)->get('users');
```

**See also:** `with_many`, `with`

### `with_many($relation, $foreignKey, $localKey, $callback = null)`

Eager-load an array of related records per row (hasMany-style).

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — callable(CustomQueryBuilder): void — scope the relation subquery.

**Returns:** `$this` — For chaining.

_Basic_
```php
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');
// $user->orders (array)
```

_Scoped + nested relation_
```php
$posts = $this->db->with_many('comments', 'id', 'post_id', function ($q) {
    $q->with_one('users', 'id', 'user_id')->where('approved', 1);
})->get('posts');
// $post->comments[0]->users
```

_Composite keys_
```php
$this->db->with_many('category_scores', ['user_id', 'category'], ['id', 'category'])->get('users');
```

> **Note:** $relation accepts a plain string or a single-element ['table' => 'alias'] array — any other array shape throws InvalidArgumentException.
> **Note:** foreignKey/localKey accept either a single column string or an equal-length array for composite keys.

**See also:** `with_one`, `with`

---

## Aggregate Subqueries

Add a correlated subquery column (COUNT/SUM/AVG/MIN/MAX/custom expression) per row, and filter on the result with where_aggregate().

### `with_count($relation, $foreignKey, $localKey, $alias = null, $callback = null)`

Adds a correlated COUNT(*) subquery column per row.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `alias` (string|null) (default: `null`) — Result column name; defaults to "{relation}_count".
- `callback` (callable|null) (default: `null`) — Extra WHERE conditions inside the subquery.

**Returns:** `$this` — For chaining.

_Default alias_
```php
$this->db->with_count('orders', 'id', 'user_id')->order_by('orders_count', 'DESC')->get('users');
// $user->orders_count
```

_Custom alias_
```php
$this->db->with_count(['orders' => 'total_orders'], 'id', 'user_id');
// $user->total_orders
```

> **Note:** Runs one extra correlated subquery per row in the result set — see JOIN-Based Aggregates (join_count) for a lighter alternative on large result sets.

**See also:** `join_count`, `where_aggregate`

### `with_sum($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)`

Adds a correlated SUM(column) subquery column per row. $column can be a plain column name, or (with $is_custom_expression = true) a validated custom SQL expression.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column name, or a custom expression (see $is_custom_expression).
- `is_custom_expression` (bool) (default: `false`) — Pass true to allow a whitelisted mathematical expression instead of a plain column name — see Security.
- `callback` (callable|null) (default: `null`) — Extra WHERE conditions inside the subquery.

**Returns:** `$this` — For chaining.

_Plain column_
```php
$this->db->with_sum('orders', 'id', 'user_id', 'total_amount');
// $user->orders_sum
```

_Custom expression_
```php
$this->db->with_sum(['job' => 'total_after_discount'], 'id', 'idinvoice',
    '(job_total_price_before_discount - job_discount)', true);
```

_Scoped_
```php
$this->db->with_avg('orders', 'id', 'user_id', 'total_amount', false, function ($q) {
    $q->where('status', 'completed');
});
```

> **Note:** See with_calculation for arbitrary aggregate expressions with a dedicated alias-first signature.

**See also:** `with_avg`, `with_calculation`, `join_sum`

### `with_avg($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)`

Same shape as with_sum(), using AVG(column) instead of SUM(column).

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column name, or a custom expression.
- `is_custom_expression` (bool) (default: `false`) — Pass true for a whitelisted custom expression.
- `callback` (callable|null) (default: `null`) — Extra WHERE conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$this->db->with_avg(['orders' => 'avg_order_value'], 'id', 'user_id', 'total_amount');
// $user->avg_order_value
```

**See also:** `with_sum`, `join_avg`

### `with_max($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)`

Same shape as with_sum(), using MAX(column).

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column name, or a custom expression.
- `is_custom_expression` (bool) (default: `false`) — Pass true for a whitelisted custom expression.
- `callback` (callable|null) (default: `null`) — Extra WHERE conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$this->db->with_max(['orders' => 'highest_order'], 'id', 'user_id', 'total_amount');
```

**See also:** `with_min`, `join_max`

### `with_min($relation, $foreignKey, $localKey, $column, $is_custom_expression = false, $callback = null)`

Same shape as with_sum(), using MIN(column).

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column name, or a custom expression.
- `is_custom_expression` (bool) (default: `false`) — Pass true for a whitelisted custom expression.
- `callback` (callable|null) (default: `null`) — Extra WHERE conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$this->db->with_min(['orders' => 'lowest_order'], 'id', 'user_id', 'total_amount');
```

**See also:** `with_max`, `join_min`

### `with_calculation($relation, $foreignKey, $localKey, $expression, $callback = null)`

Add an arbitrary aggregate expression (not limited to a single SUM/AVG/etc.) as a correlated subquery column. $expression goes through the same custom-expression security validation as with_sum(..., true).

**Parameters:**

- `relation` (array) — ['table' => 'alias'] — alias is required here (used as the result column name).
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `expression` (string) — Whitelisted SQL expression, e.g. "(SUM(a) / SUM(b)) * 100".
- `callback` (callable|null) (default: `null`) — Extra WHERE conditions inside the subquery.

**Returns:** `$this` — For chaining.

_Ratio expression_
```php
$orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'],
    'order_id', 'id',
    '(SUM(finished_qty) / SUM(total_qty)) * 100'
)->get('orders');
// $order->efficiency_percentage
```

_Scoped_
```php
$products = $this->db->with_calculation(['reviews' => 'weighted_rating'],
    'product_id', 'id',
    'SUM(rating * helpful_votes) / SUM(helpful_votes)',
    function ($q) { $q->where('status', 'approved'); }
)->get('products');
```

> **Note:** Allowed in expressions: + - * / %, SUM AVG COUNT MIN MAX, DATEDIFF TIMESTAMPDIFF (MySQL-only — not yet translated for other drivers), CASE WHEN...THEN...END, ROUND FLOOR CEIL ABS, and a handful of other whitelisted functions — see Security. Dangerous keywords/expressions are rejected before ever reaching the database.

**See also:** `with_sum`, `where_aggregate`, `join_calculation`

### `where_aggregate($condition, $value)`

Filter by a previously-registered with_sum()/with_avg()/with_calculation()/etc. alias. Wraps the underlying subquery in COALESCE(subquery, 0) so a row with zero related rows (NULL aggregate) still evaluates correctly instead of silently being excluded. Preserves the exact call-order position relative to other where()/or_where()/group() calls in the same chain.

**Parameters:**

- `condition` (string) — "alias operator" e.g. "total_spent >=", or "alias BETWEEN"/"alias NOT BETWEEN".
- `value` (mixed|array) — Comparison value, or a 2-element [min, max] array for BETWEEN.

**Returns:** `$this` — For chaining.

_Basic threshold_
```php
$this->db->with_sum(['orders' => 'total_spent'], 'id', 'user_id', 'amount')
    ->where_aggregate('total_spent >=', 5000)
    ->get('users');
```

_BETWEEN_
```php
$this->db->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
    ->where_aggregate('score_total BETWEEN', [1, 100])
    ->get('users');
```

> **Note:** Throws InvalidArgumentException if the alias was never registered via a with_*() call, or if a BETWEEN value array doesn't have exactly 2 elements.

**See also:** `or_where_aggregate`, `with_sum`, `with_calculation`

### `or_where_aggregate($condition, $value)`

OR-variant of where_aggregate().

**Parameters:**

- `condition` (string) — "alias operator", e.g. "total_amount =".
- `value` (mixed|array) — Comparison value.

**Returns:** `$this` — For chaining.

```php
$this->db->with_sum(['orders' => 'total_amount'], 'id', 'user_id', 'amount')
    ->where_aggregate('total_amount >', 10000)
    ->or_where_aggregate('total_amount =', 0)
    ->get('users');
```

**See also:** `where_aggregate`

---

## JOIN-Based Aggregates

Lighter alternative to aggregates: one GROUP BY derived-table JOIN instead of one correlated subquery per row.

### `join_count($relation, $foreignKey, $localKey, $callback = null)`

Lighter alternative to with_count(): scans the relation table ONCE via a GROUP BY derived table, then LEFT JOINs it in — instead of one correlated subquery per row. Prefer this over with_count() on large result sets.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the derived-table subquery.

**Returns:** `$this` — For chaining.

```php
$users = $this->db->join_count('orders', 'user_id', 'id')->get('users');
// $user->orders_count
```

> **Note:** Throws InvalidArgumentException if $relation is empty/whitespace-only.

**See also:** `with_count`, `join_sum`

### `join_sum($relation, $foreignKey, $localKey, $column, $callback = null)`

join_count()'s SUM(column) counterpart — same derived-table-JOIN mechanism.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column to sum (required).
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$users = $this->db->join_sum('orders', 'user_id', 'id', 'total_amount')
    ->order_by('orders_sum', 'DESC')->get('users');
// $user->orders_sum
```

> **Note:** Throws InvalidArgumentException if $column is missing or fails column-name validation.

**See also:** `with_sum`, `join_count`

### `join_avg($relation, $foreignKey, $localKey, $column, $callback = null)`

join_count()'s AVG(column) counterpart.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column to average.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$this->db->join_avg('orders', 'user_id', 'id', 'total_amount');
```

**See also:** `with_avg`, `join_count`

### `join_min($relation, $foreignKey, $localKey, $column, $callback = null)`

join_count()'s MIN(column) counterpart.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column to find the minimum of.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$this->db->join_min('orders', 'user_id', 'id', 'total_amount');
```

**See also:** `with_min`, `join_count`

### `join_max($relation, $foreignKey, $localKey, $column, $callback = null)`

join_count()'s MAX(column) counterpart.

**Parameters:**

- `relation` (string|array) — Table name, or ['table' => 'alias'].
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `column` (string) — Column to find the maximum of.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.

**Returns:** `$this` — For chaining.

```php
$this->db->join_max(['orders' => 'highest_order'], 'user_id', 'id', 'total_amount');
// $user->highest_order
```

**See also:** `with_max`, `join_count`

### `join_calculation($relation, $foreignKey, $localKey, $expression, $callback = null)`

join_count()'s arbitrary-expression counterpart — same validated-expression rules as with_calculation(), but via a derived-table JOIN instead of a correlated subquery.

**Parameters:**

- `relation` (array) — ['table' => 'alias'] — alias used as the result column name.
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `expression` (string) — Whitelisted SQL expression.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.

**Returns:** `$this` — For chaining.

_Profit margin_
```php
$this->db->join_calculation(
    ['sales' => 'profit_margin'],
    'product_id', 'id',
    '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100',
    function ($q) { $q->where('status', 'completed'); }
);
```

**See also:** `with_calculation`

---

## WHERE EXISTS / WHERE HAS

Existence and count-threshold conditions against a related table, with call-order-preserving AND/OR glue.

### `where_exists($callback)`

Raw EXISTS(...) subquery with full manual control — you build the entire subquery yourself in the callback.

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder): void — build the correlated subquery (select/from/where).

**Returns:** `$this` — For chaining.

```php
$this->db->where_exists(function ($q) {
    $q->select('1')->from('posts')
      ->where('posts.user_id = users.id')
      ->where('status', 'published');
});
```

> **Note:** Throws InvalidArgumentException if $callback isn't callable.
> **Note:** For the common "just check a related table has any/N matching rows" case, where_exists_relation()/where_has() are simpler — this raw form is for when you need arbitrary subquery logic.

**See also:** `where_not_exists`, `or_where_exists`, `or_where_not_exists`, `where_exists_relation`

### `where_not_exists($callback)`

NOT EXISTS(...) counterpart to where_exists().

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder): void.

**Returns:** `$this` — For chaining.

```php
$this->db->where_not_exists(function ($q) { /* ... */ });
```

**See also:** `where_exists`

### `or_where_exists($callback)`

OR-variant of where_exists().

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder): void.

**Returns:** `$this` — For chaining.

```php
$this->db->or_where_exists(function ($q) { /* ... */ });
```

**See also:** `where_exists`

### `or_where_not_exists($callback)`

OR-variant of where_not_exists().

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder): void.

**Returns:** `$this` — For chaining.

```php
$this->db->or_where_not_exists(function ($q) { /* ... */ });
```

**See also:** `where_not_exists`

### `where_exists_relation($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)`

Simplified WHERE EXISTS that auto-joins on the given keys instead of writing the subquery by hand. Works equally well whether from('table') was called up front or get('table') at the end — the parent table is resolved lazily either way. Supports composite keys via arrays.

**Parameters:**

- `relation` (string) — Related table name, optionally "table alias".
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the EXISTS subquery.
- `disable_pending_process` (bool) (default: `false`) — Advanced/rarely needed: skip queuing through the ordering-preserving pending mechanism. Exists for edge cases needing the same reordering used inside group() even outside one.

**Returns:** `$this` — For chaining.

_Basic_
```php
$this->db->from('users')->where_exists_relation('orders', 'user_id', 'id');
// WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)
```

_Scoped_
```php
$this->db->from('users')->where_exists_relation('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active');
});
```

_Composite keys_
```php
$this->db->from('users')->where_exists_relation('user_roles', ['user_id', 'tenant_id'], ['id', 'tenant_id']);
```

_Combined with OR_
```php
$this->db->from('users')
    ->where_exists_relation('orders', 'user_id', 'id')
    ->or_where_exists_relation('posts', 'user_id', 'id');
```

**See also:** `where_not_exists_relation`, `where_has`

### `where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)`

NOT EXISTS counterpart to where_exists_relation(). Also aliased as where_doesnt_have().

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.
- `disable_pending_process` (bool) (default: `false`) — Advanced: see where_exists_relation().

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->where_not_exists_relation('orders', 'user_id', 'id');
```

**See also:** `where_exists_relation`, `where_doesnt_have`

### `or_where_exists_relation($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)`

OR-variant of where_exists_relation().

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.
- `disable_pending_process` (bool) (default: `false`) — Advanced: see where_exists_relation().

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->or_where_exists_relation('posts', 'user_id', 'id');
```

**See also:** `where_exists_relation`

### `or_where_not_exists_relation($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)`

OR-variant of where_not_exists_relation().

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.
- `disable_pending_process` (bool) (default: `false`) — Advanced: see where_exists_relation().

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->or_where_not_exists_relation('orders', 'user_id', 'id');
```

**See also:** `where_not_exists_relation`

### `where_has($relation, $foreignKey, $localKey, $callback = null, $operator = '>=', $count = 1)`

Existence check with a count threshold. With the default count (>=1), compiles to the same plain EXISTS shape as where_exists_relation() (no COUNT(*) overhead). A 4th-argument shorthand lets you pass just the operator when you don't need a callback.

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `callback` (callable|string|null) (default: `null`) — Extra conditions inside the subquery, OR (shorthand) the operator string when you don't need a callback.
- `operator` (string) (default: `'>='`) — Comparison operator for the count.
- `count` (int) (default: `1`) — Threshold to compare the related row count against.

**Returns:** `$this` — For chaining.

_Default (>=1), same as where_exists_relation()_
```php
$this->db->from('users')->where_has('orders', 'user_id', 'id');
```

_Scoped_
```php
$this->db->from('users')->where_has('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active');
});
```

_Count threshold_
```php
$this->db->from('users')->where_has('orders', 'user_id', 'id', null, '>=', 5);
```

_Shorthand operator (no callback)_
```php
$this->db->from('users')->where_has('orders', 'user_id', 'id', '>=', 5);
```

**See also:** `or_where_has`, `where_doesnt_have`, `where_exists_relation`

### `or_where_has($relation, $foreignKey, $localKey, $callback = null, $operator = '>=', $count = 1)`

OR-variant of where_has(). Same parameter shape.

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string) — Column on the related table.
- `localKey` (string) — Column on the main table.
- `callback` (callable|string|null) (default: `null`) — Callback or shorthand operator string.
- `operator` (string) (default: `'>='`) — Comparison operator.
- `count` (int) (default: `1`) — Count threshold.

**Returns:** `$this` — For chaining.

```php
$this->db->where('id', 999)->or_where_has('scores', 'user_id', 'id', null, '>=', 2)->get('users');
```

**See also:** `where_has`

### `where_doesnt_have($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)`

Documented alias of where_not_exists_relation() — same NOT EXISTS subquery shape, more Laravel-familiar name.

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.
- `disable_pending_process` (bool) (default: `false`) — Advanced: see where_exists_relation().

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->where_doesnt_have('orders', 'user_id', 'id');
```

**See also:** `where_not_exists_relation`, `or_where_doesnt_have`

### `or_where_doesnt_have($relation, $foreignKey, $localKey, $callback = null, $disable_pending_process = false)`

OR-variant / alias of or_where_not_exists_relation().

**Parameters:**

- `relation` (string) — Related table name.
- `foreignKey` (string|array) — Column(s) on the related table.
- `localKey` (string|array) — Column(s) on the main table.
- `callback` (callable|null) (default: `null`) — Extra conditions inside the subquery.
- `disable_pending_process` (bool) (default: `false`) — Advanced: see where_exists_relation().

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->or_where_doesnt_have('orders', 'user_id', 'id');
```

**See also:** `where_doesnt_have`

---

## Conditional Queries

Build dynamic filter chains without a wall of if-statements.

### `when($condition, $callback, $default = null)`

Run a callback only if $condition is truthy, with an optional else-callback. Great for building dynamic filter chains without a wall of if-statements.

**Parameters:**

- `condition` (mixed) — Evaluated as a boolean.
- `callback` (callable) — callable(CustomQueryBuilder): void, run when $condition is truthy.
- `default` (callable|null) (default: `null`) — callable(CustomQueryBuilder): void, run when $condition is falsy.

**Returns:** `$this` — For chaining.

_Simple_
```php
$this->db->when($search_term, function ($q) use ($search_term) {
    $q->like('name', $search_term);
});
```

_With else_
```php
$this->db->when($user_role == 'admin', function ($q) {
    $q->select('*');
}, function ($q) {
    $q->select('id, name, email');
});
```

_Chained dynamic filters_
```php
$this->db->from('users')
    ->when($search, function ($q) use ($search) { $q->search($search, ['name', 'email']); })
    ->when($status, function ($q) use ($status) { $q->where('status', $status); })
    ->get();
```

**See also:** `unless`

### `unless($condition, $callback, $default = null)`

Inverse of when() — runs the callback when $condition is falsy.

**Parameters:**

- `condition` (mixed) — Evaluated as a boolean.
- `callback` (callable) — callable(CustomQueryBuilder): void, run when $condition is falsy.
- `default` (callable|null) (default: `null`) — callable(CustomQueryBuilder): void, run when $condition is truthy.

**Returns:** `$this` — For chaining.

```php
$this->db->unless($user_role == 'admin', function ($q) {
    $q->where('status', 'published');
});
```

**See also:** `when`

---

## Search

Multi-column LIKE search shorthand.

### `search($term, array $columns, $or = true)`

Multi-column LIKE search shorthand, wrapped in its own group().

**Parameters:**

- `term` (string) — Search term.
- `columns` (array) — Columns to search across.
- `or` (bool) (default: `true`) — true = OR the column conditions together; false = AND them.

**Returns:** `$this` — For chaining.

_OR across columns (default)_
```php
$this->db->search('john', ['name', 'email']);
// WHERE (`name` LIKE '%john%' OR `email` LIKE '%john%')
```

_AND across columns_
```php
$this->db->search('admin', ['role', 'title'], false);
// WHERE (`role` LIKE '%admin%' AND `title` LIKE '%admin%')
```

> **Note:** Blank/empty entries in $columns are skipped without producing invalid SQL.
> **Note:** An empty $columns array leaves the query unchanged (no-op, not an error).

**See also:** `when`

---

## Pagination

Page-based pagination (paginate()) and the lower-level found-rows counting it's built on (calc_rows()), both using a portable COUNT(*) subquery on every driver.

### `paginate($per_page = 15, $page = 1)`

Laravel-style page-based pagination, built on calc_rows(). Computes LIMIT/OFFSET from $per_page/$page for you. Argument order deliberately matches Laravel's own paginate($perPage, ...) — a single-argument ->paginate(20) means "20 per page", not "page 20". Uses a portable COUNT(*) subquery on every driver (including MySQL — SQL_CALC_FOUND_ROWS is not used anywhere in this library).

**Parameters:**

- `per_page` (int) (default: `15`) — Rows per page. Must be >= 1.
- `page` (int) (default: `1`) — Page number, 1-indexed. Must be >= 1.

**Returns:** `$this` — For chaining — call get() next.

_Basic_
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
$result->from();            // 21
$result->to();               // 40
```

_Single-arg shorthand (page defaults to 1)_
```php
$this->db->paginate(20)->get('users');
```

_With eager loading_
```php
$this->db->with_one('profile', 'user_id', 'id')->paginate(20)->get('users');
```

_Full JSON-shaped response in one call_
```php
$response = $this->db->where('status', 'active')->paginate(20, 2)->get('users')->to_pagination_array();
```

> **Note:** An explicit $limit/$offset passed to get() overrides paginate()'s computed values.
> **Note:** Throws InvalidArgumentException if $per_page or $page is not an integer >= 1.
> **Note:** There's no next_page_url/prev_page_url — this library sits below the HTTP/routing layer and has no request context to build URLs from. Build links from current_page()/last_page() in your controller.

**See also:** `calc_rows`, `get_found_rows`, `to_pagination_array`

### `calc_rows()`

Lower-level building block behind paginate(): marks the query so the next get() also computes the total row count the query would have matched without LIMIT, via a portable COUNT(*) subquery (used on every driver, including MySQL — never SQL_CALC_FOUND_ROWS, which is deprecated since MySQL 8.0.17 and never actually cheaper). Reach for this directly when you want the found-rows total but not page-number-based pagination (e.g. you already compute your own offset).

**Returns:** `$this` — For chaining — call get($table, $limit, $offset) next.

```php
$result = $this->db->select(['id', 'name'])->calc_rows()->get('users', 20, 0);
$data  = $result->result();      // 20 rows
$total = $result->found_rows();  // total rows without LIMIT
```

_With eager loading_
```php
$result = $this->db->select(['id', 'name'])->with_one('profile', 'user_id', 'id')->calc_rows()->get('users', 20, 0);
```

> **Note:** Runs two queries under the hood — the page of data, and a SELECT COUNT(*) FROM (<your query, no LIMIT>) — same total work as calling count_all_results() and get() separately, just as one method chain.

**See also:** `paginate`, `get_found_rows`, `count_all_results`

### `get_found_rows()`

Old-style (pre-$result->found_rows()) access to the calc_rows()/paginate() total: reads it straight off $this->db right after the query, instead of off the returned result object. Kept for backward compatibility.

**Returns:** `int` — The total from the most recent calc_rows()/paginate() query, or 0 if none was made (or if called without calc_rows()/paginate() first).

```php
$data  = $this->db->select(['id', 'name'])->calc_rows()->get('users', 20, 0);
$total = $this->db->get_found_rows();
```

> **Note:** Prefer $result->found_rows() in new code — it's tied to the specific result object rather than to whatever $this->db was doing most recently, so it can't accidentally read a stale/wrong value if you build another query on the same builder afterward.

**See also:** `calc_rows`, `paginate`

---

## Query Grouping

Parenthesized WHERE groups, callback-based or raw start/end pairs, correctly interleaved with every other condition type.

### `group($callback)`

Wraps conditions built inside the callback in a parenthesized AND-joined group. Preserves position relative to where_has()/where_exists_relation()/where_aggregate()/plain where() calls before or after it, however they're all interleaved.

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder): void.

**Returns:** `$this` — For chaining.

```php
$this->db->where('status', 'active')
    ->group(function ($q) {
        $q->where('name', 'John')->or_where('name', 'Jane');
    });
// WHERE `status` = 'active' AND (`name` = 'John' OR `name` = 'Jane')
```

**See also:** `or_group`, `group_start`

### `or_group($callback)`

OR-variant of group().

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder): void.

**Returns:** `$this` — For chaining.

```php
$this->db->where('status', 'active')
    ->or_group(function ($q) { $q->where('role', 'admin'); });
// WHERE `status` = 'active' OR (`role` = 'admin')
```

**See also:** `group`

### `group_start()`

Raw opening half of a group — reach for this (paired with group_end()) instead of group()/or_group() when you need to open and close the bracket at two different points in your code (e.g. across an if), rather than inside a single callback. If nothing ends up added between group_start() and group_end(), the empty bracket is dropped automatically instead of emitting invalid "( )" SQL.

**Returns:** `$this` — For chaining.

```php
$this->db->where('status', 'active')->group_start();
if ($include_pending) {
    $this->db->or_where('status', 'pending');
}
$this->db->where('archived', 0)->group_end();
// WHERE `status` = 'active' AND ( `status` = 'pending' AND `archived` = 0 )
```

> **Note:** Nests freely with itself, with group()/or_group(), and with where_has()/where_exists_relation()/where_aggregate() in any combination.

**See also:** `group_end`, `group`

### `group_end()`

Closing half of the raw group_start()/group_end() pair.

**Returns:** `$this` — For chaining.

_See group_start() for a full example._
```php
$this->db->group_start()->where('a', 1)->group_end();
```

**See also:** `group_start`

---

## Chunking Large Datasets

Process large tables in pages without loading everything into memory at once.

### `chunk($size, $callback, $table = '')`

Offset-based chunking: processes a large table in pages of $size rows without loading everything into memory. The callback receives an array of objects (not arrays) plus the current page number, and can return false to stop early.

**Parameters:**

- `size` (int) — Rows per page.
- `callback` (callable) — callable(object[] $rows, int $page): bool|void — return false to stop.
- `table` (string) (default: `''`) — Table name (optional if from() was already called).

**Returns:** `int` — Total number of records processed.

```php
$this->db->where('status', 'active')->chunk(500, function ($users, $page) {
    foreach ($users as $user) { /* ... */ }
    if ($page > 10) return false; // stop early
}, 'users');
```

**See also:** `chunk_by_id`

### `chunk_by_id($size, $callback, $column = 'id', $table = '')`

ID-based chunking (no gaps/duplicates if rows are inserted/deleted mid-run, unlike offset-based chunk()). Throws InvalidArgumentException if $column isn't present in a fetched row (e.g. you selected specific columns and forgot to include the ordering column).

**Parameters:**

- `size` (int) — Rows per page.
- `callback` (callable) — callable(object[] $rows): bool|void — return false to stop.
- `column` (string) (default: `'id'`) — Ordering/cursor column (must be present in the selected columns).
- `table` (string) (default: `''`) — Table name.

**Returns:** `int` — Total number of records processed.

```php
$this->db->chunk_by_id(1000, function ($users) {
    foreach ($users as $user) { $this->send_email($user->email); }
}, 'id', 'users');
```

**See also:** `chunk`

---

## Transactions

Callback-based transaction wrapper with automatic commit/rollback.

### `transaction($callback, $strict = false)`

Wraps a callback in a database transaction with automatic commit/rollback. On any thrown exception inside the callback, the transaction is rolled back; by default the exception is swallowed and the method returns false — pass $strict = true to have it re-thrown instead (still after rolling back).

**Parameters:**

- `callback` (callable) — callable(CustomQueryBuilder $db): mixed — receives the builder instance as its argument.
- `strict` (bool) (default: `false`) — If true, re-throws the original exception after rollback instead of returning false.

**Returns:** `mixed|bool` — The callback's return value on success, or false on failure (unless $strict, which re-throws).

_Basic_
```php
$result = $this->db->transaction(function ($db) {
    $db->insert('users', $data);
    $id = $db->insert_id();
    $db->insert('profiles', ['user_id' => $id]);
    return $id;
});
```

_Strict mode_
```php
$this->db->transaction(function ($db) { /* ... */ }, true);
```

> **Note:** Throws InvalidArgumentException if $callback isn't callable.

---

## Advanced: Manual Flush

Force-resolve pending (deferred) conditions early, inside a callback context, instead of waiting for get() time. Rarely needed directly — get()/get_compiled_select() call these automatically.

### `process_where_has()`

Manually force-resolves any queued where_has()/where_doesnt_have() conditions into the query immediately, instead of waiting for get() time. For use inside callback contexts where you need the condition applied before further chaining/inspection within that same callback.

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')
    ->where('id', 1)
    ->where_has('scores', 'user_id', 'id', null, '>=', 2)
    ->process_where_has()
    ->where('category', 'A')
    ->get_compiled_select();
```

> **Note:** get() calls this automatically as part of its normal flow — most code never needs to call it directly.

**See also:** `process_where_exists`

### `process_where_exists($parent_table)`

Manually force-resolves any queued where_exists_relation() conditions into actual EXISTS/NOT EXISTS subqueries immediately. Requires the parent table name explicitly (unlike process_where_has()) so bare local keys can be qualified correctly.

**Parameters:**

- `parent_table` (string) — The main table name to qualify bare local keys against.

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->where('id', 1)
    ->where_exists_relation('scores', 'user_id', 'id')
    ->process_where_exists('users')
    ->where('category', 'A')
    ->get_compiled_select();
```

> **Note:** get() calls this automatically as part of its normal flow.

**See also:** `process_where_has`

### `process_aggregates()`

Manually force-resolves queued with_count()/with_sum()/etc. SELECT-column aggregates immediately instead of at get() time.

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->with_count('scores', 'user_id', 'id')->process_aggregates()->where('id', 1)->get_compiled_select();
```

> **Note:** get() calls this automatically.

**See also:** `process_where_aggregates`, `process_join_aggregates`

### `process_where_aggregates($context_table = null)`

Manually force-resolves queued where_aggregate()/or_where_aggregate() conditions immediately.

**Parameters:**

- `context_table` (string|null) (default: `null`) — Override which table qualifies bare local keys, instead of relying on the current FROM table.

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->where('id', 1)
    ->with_sum(['scores' => 'total'], 'user_id', 'id', 'value')
    ->where_aggregate('total >', 50)
    ->process_where_aggregates()
    ->where('category', 'A')
    ->get_compiled_select();
```

> **Note:** get() calls this automatically.

**See also:** `process_aggregates`

### `process_join_aggregates()`

Manually force-resolves queued join_count()/join_sum()/etc. derived-table JOINs immediately.

**Returns:** `$this` — For chaining.

```php
$this->db->from('users')->join_count('scores', 'user_id', 'id')->process_join_aggregates()->where('id', 1)->get_compiled_select();
```

> **Note:** get() calls this automatically.

**See also:** `process_aggregates`

---

## The Result Object (CustomQueryBuilderResult)

What get()/get_where()/query() return: a CI-result-compatible wrapper with extra methods for relations, found-rows, and pagination.

### `result()`

Returns every row as an array of objects (same shape as native CI). Memoized — computed once, reused on repeat calls.

**Returns:** `object[]`

```php
foreach ($result->result() as $row) { echo $row->name; }
```

**See also:** `result_array`, `row`

### `result_array()`

Returns every row as an array of associative arrays. Memoized.

**Returns:** `array[]`

```php
foreach ($result->result_array() as $row) { echo $row['name']; }
```

**See also:** `result`, `row_array`

### `row($index = 0)`

Returns a single row (by index, default first) as an object, or null if that index doesn't exist. Reuses result()'s cache if already computed.

**Parameters:**

- `index` (int) (default: `0`) — Row index.

**Returns:** `object|null`

```php
$user = $result->row();
echo $user->name;
```

**See also:** `row_array`, `result`

### `row_array($index = 0)`

Array-form counterpart to row().

**Parameters:**

- `index` (int) (default: `0`) — Row index.

**Returns:** `array|null`

```php
$user = $result->row_array();
echo $user['name'];
```

**See also:** `row`

### `num_rows()`

Row count for this result (computed at construction time).

**Returns:** `int`

```php
$count = $this->db->get('users')->num_rows();
```

### `found_rows()`

Total row count without LIMIT, from a preceding calc_rows()/paginate() query.

**Returns:** `int|null` — Null if calc_rows()/paginate() wasn't used for this query.

```php
$total = $this->db->calc_rows()->get('users', 10, 0)->found_rows();
```

**See also:** `calc_rows`, `paginate`, `get_found_rows`

### `current_page()`

The page number passed to paginate().

**Returns:** `int|null` — Null if paginate() wasn't used.

```php
$page = $result->current_page();
```

**See also:** `paginate`, `last_page`

### `per_page()`

The per-page size passed to paginate().

**Returns:** `int|null` — Null if paginate() wasn't used.

```php
$size = $result->per_page();
```

**See also:** `paginate`

### `last_page()`

Total number of pages, computed as max(1, ceil(found_rows / per_page)).

**Returns:** `int|null` — Null if paginate() wasn't used.

```php
$totalPages = $result->last_page();
```

**See also:** `current_page`, `has_more_pages`

### `has_more_pages()`

Whether there's at least one page after the current one (current_page < last_page).

**Returns:** `bool` — False if paginate() wasn't used.

```php
if ($result->has_more_pages()) { /* show a "next" link */ }
```

**See also:** `last_page`

### `from()`

1-indexed position of this page's first row within the full (unpaginated) result set. E.g. page 3 at 20/page -> 41.

**Returns:** `int|null` — Null if paginate() wasn't used, or the page/result is empty.

```php
echo "Showing {$result->from()}-{$result->to()} of {$result->found_rows()}";
```

**See also:** `to`

### `to()`

1-indexed position of this page's last row within the full result set, capped at found_rows() (a partial last page doesn't overshoot the true total).

**Returns:** `int|null` — Null if paginate() wasn't used, or the page/result is empty.

```php
echo "Showing {$result->from()}-{$result->to()} of {$result->found_rows()}";
```

**See also:** `from`

### `to_pagination_array()`

Packages result_array() plus every pagination field into one Laravel-shaped array, ready for e.g. a JSON API response.

**Returns:** `array` — {data, current_page, per_page, total, last_page, from, to, has_more_pages}

```php
return $this->db->paginate(20, 2)->get('users')->to_pagination_array();
// ['data' => [...], 'current_page' => 2, 'per_page' => 20, 'total' => 347,
//  'last_page' => 18, 'from' => 21, 'to' => 40, 'has_more_pages' => true]
```

**See also:** `paginate`

### `key_by($key, $as_array = false)`

Re-indexes the result set by a column value (Laravel Collection::keyBy()-style). $key may be a column name or a callable(row): mixed. A plain string is always treated as a column name even if it happens to collide with a PHP builtin function name.

**Parameters:**

- `key` (string|callable) — Column name, or callable(object|array $row): mixed.
- `as_array` (bool) (default: `false`) — Return rows as arrays instead of objects.

**Returns:** `array` — Keyed by the resolved key value.

_By column_
```php
$byId = $this->db->get('users')->key_by('id');
// [1 => {...}, 2 => {...}, ...]
```

_By callback_
```php
$byUpperName = $this->db->get('users')->key_by(function ($row) {
    return strtoupper($row->name);
});
```

_As arrays_
```php
$asArrays = $this->db->get('users')->key_by('id', true);
```

> **Note:** If a key repeats, the last matching row wins.
> **Note:** Rows missing the key (or where its value is null) are kept under their original numeric index instead of collapsing into a shared null bucket, and trigger an E_USER_WARNING.

### `value($column)`

Post-execution: pulls a single column's value off the first row of an already-fetched result. Handles dotted 'relation.column' by taking the part after the last dot.

**Parameters:**

- `column` (string) — Column (or 'relation.column') to read.

**Returns:** `mixed|null` — Null if there are no rows.

```php
$email = $this->db->where('id', 1)->get('users')->value('email');
```

> **Note:** There is also a pre-execution $this->db->value($column, $table) directly on the query builder, which is more efficient since it adds an implicit SELECT + LIMIT 1 instead of fetching a full row you already have.

---
