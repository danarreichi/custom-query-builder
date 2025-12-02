# CustomQueryBuilder for CodeIgniter 3

> 🚀 Advanced Query Builder untuk CodeIgniter 3 dengan fitur Eager Loading Relations, Advanced Aggregates, dan SQL Injection Prevention yang Komprehensif

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![CodeIgniter](https://img.shields.io/badge/CodeIgniter-3.x-red)
![PHP](https://img.shields.io/badge/PHP-7.0%2B-blue)

---

## 📋 Table of Contents

- [Fitur Utama](#fitur-utama)
- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Panduan Penggunaan](#panduan-penggunaan)
  - [Basic Query](#basic-query)
  - [Conditional WHERE](#conditional-where)
  - [Eager Loading Relations](#eager-loading-relations)
  - [Aggregates & Calculations](#aggregates--calculations)
  - [Advanced Features](#advanced-features)
- [API Reference](#api-reference)
- [Contoh Implementasi](#contoh-implementasi)
- [Troubleshooting](#troubleshooting)
- [Kontribusi](#kontribusi)
- [Lisensi](#lisensi)

---

## 🎯 Fitur Utama

### ✅ Fitur Dasar
- **Fluent Query Builder**: Interface berantai untuk query building yang elegan
- **All CRUD Operations**: SELECT, INSERT, UPDATE, DELETE, UPSERT
- **Advanced WHERE Conditions**: AND, OR, NOT, BETWEEN, IN, NULL checks
- **Joins**: INNER, LEFT, RIGHT, CROSS joins dengan support table alias
- **ORDER BY & GROUP BY**: Sorting, grouping, dan aggregation
- **Limit & Offset**: Pagination support dengan SQL_CALC_FOUND_ROWS

### ⭐ Fitur Premium
- **Eager Loading Relations**: `with_one()`, `with_many()`, `with_count()`
- **Advanced Aggregates**: `with_sum()`, `with_avg()`, `with_max()`, `with_min()`, `with_calculation()`
- **Custom Expression Support**: Mathematical operations dan conditional expressions
- **WHERE EXISTS/NOT EXISTS**: Simplified syntax dengan `where_exists_relation()`, `where_not_exists_relation()`
- **WHERE HAS/DOESN'T HAVE**: Relationship existence checking dengan count conditions
- **Complex Calculations**: Multi-aggregate calculations dengan `with_calculation()`
- **Chunking**: Process large datasets dengan `chunk()` dan `chunk_by_id()`

### 🔒 Security Features
- **SQL Injection Prevention**: Comprehensive validation untuk column names, table names, dan custom expressions
- **Parameter Binding**: Automatic escaping untuk semua user inputs
- **Expression Validation**: Whitelist untuk SQL functions dan operators
- **Dangerous Pattern Detection**: Detection untuk SQL comments, union attacks, dan injection patterns

---

## 📦 Persyaratan Sistem

```
PHP: >= 7.0. 0
CodeIgniter: 3.x
MySQL: 5.7+ (atau kompatibel)
```

---

## 🔧 Instalasi

### Metode 1: Standard Installation (Recommended)

#### Langkah 1: Download atau Clone Repository

```bash
# Menggunakan Git
git clone https://github.com/danarreichi/custom-query-builder.git

# Atau download ZIP dari GitHub
```

#### Langkah 2: Copy File ke CodeIgniter

```bash
# Copy library file ke CodeIgniter
cp CustomQueryBuilder.php /path/to/your/ci3-project/application/libraries/

# Struktur file:
# application/
# └── libraries/
#     └── CustomQueryBuilder.php
```

#### Langkah 3: Autoload Library (Opsional)

Edit `application/config/autoload.php`:

```php
// OPTION 1: Autoload secara otomatis
$autoload['libraries'] = array('database');

// OPTION 2: Load manual di controller/model
$this->load->library('CustomQueryBuilder');
```

#### Langkah 4: Konfigurasi Database

Edit `application/config/database.php`:

```php
$db['default'] = array(
    'dsn'   => '',
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'your_database',
    'dbdriver' => 'mysqli',
    'dbprefix' => '',
    'pconnect' => FALSE,
    'db_debug' => TRUE,
    'cache_on' => FALSE,
    'cachedir' => '',
    'char_set' => 'utf8',
    'dbcollat' => 'utf8_general_ci',
    'swap_pre' => '',
    'encrypt' => FALSE,
    'compress' => FALSE,
    'stricton' => FALSE,
    'failover' => array(),
    'save_queries' => TRUE
);
```

---

### Metode 2: Direct System Injection (Advanced)

> ⚠️ Metode ini melakukan injeksi langsung ke dalam core system CodeIgniter.  Gunakan jika Anda ingin CustomQueryBuilder otomatis tersedia di seluruh aplikasi tanpa perlu memanggil library secara manual.

#### Langkah 1: Download atau Clone Repository

```bash
# Menggunakan Git
git clone https://github.com/danarreichi/custom-query-builder.git

# Atau download ZIP dari GitHub
```

#### Langkah 2: Injeksi CustomQueryBuilder ke Core System

Salin file `CustomQueryBuilder.php` ke direktori core CodeIgniter Anda:

```bash
# Contoh untuk Laragon:
cp CustomQueryBuilder.php C:\laragon\www\your_project\system\core\CustomQueryBuilder.php

# Atau untuk Linux/Mac:
cp CustomQueryBuilder.php /path/to/your/ci3-project/system/core/CustomQueryBuilder.php

# Struktur file setelah injeksi:
# system/
# └── core/
#     └── CustomQueryBuilder.php
```

#### Langkah 3: Modifikasi File Database Core

Buka file `system/database/DB.php` dan tambahkan require statement di bagian atas file (setelah namespace/class declarations):

```php
// Contoh: Di system/database/DB.php
// Tambahkan di bagian paling atas sebelum class definitions

require_once(__DIR__ . '/../core/CustomQueryBuilder.php');

// Jika menggunakan custom query builder sebagai driver/extension,
// pastikan CustomQueryBuilder di-load sebelum query builder standar
```

Kemudian, modifikasi bagian inisialisasi query builder agar menggunakan CustomQueryBuilder.  Cari bagian yang menginisialisasi query builder dan pastikan CustomQueryBuilder ter-inject:

```php
// Contoh (sesuaikan dengan struktur file DB.php Anda):
// Ganti pemanggilan query builder dengan CustomQueryBuilder
// Sehingga saat $this->db dipanggil di controller/model,
// otomatis menggunakan CustomQueryBuilder
```

#### Langkah 4: Konfigurasi Database

Edit `application/config/database.php` sesuai kebutuhan project Anda (sama seperti Metode 1):

```php
$db['default'] = array(
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'your_database',
    // ... konfigurasi lainnya
);
```

#### Langkah 5: Verifikasi Instalasi

Buat file test untuk memverifikasi bahwa CustomQueryBuilder sudah terintegrasi:

```php
<? php
// Di controller Anda
class Test_controller extends CI_Controller {
    
    public function index()
    {
        // Test menggunakan CustomQueryBuilder methods
        $users = $this->db->from('users')
                          ->with_count('posts', 'user_id', 'id')
                          ->get()
                          ->result();
        
        var_dump($users);  // Jika bekerja, CustomQueryBuilder sudah ter-inject
    }
}
```

---

### Perbedaan Metode 1 vs Metode 2

| Aspek | Metode 1 (Standard) | Metode 2 (Injection) |
|-------|---------------------|----------------------|
| Lokasi File | `application/libraries/` | `system/core/` |
| Autoload | Manual atau via config | Otomatis |
| Kompatibilitas | Lebih aman saat update CI | Rawan jika update CI |
| Setup Complexity | Lebih sederhana | Lebih kompleks |
| Best For | Production environments | Development/Custom projects |

---

## 📚 Panduan Penggunaan

### Basic Query

#### SELECT - Ambil Data

```php
<? php
class User_model extends CI_Model {
    
    public function get_all_users()
    {
        return $this->db->select(['id', 'name', 'email'])
                        ->from('users')
                        ->get()
                        ->result();
    }
    
    public function get_user_by_id($id)
    {
        return $this->db->from('users')
                        ->where('id', $id)
                        ->first();
    }
    
    public function search_users($search)
    {
        return $this->db->from('users')
                        ->search($search, ['name', 'email'], true)
                        ->get()
                        ->result();
    }
}
```

#### INSERT - Tambah Data

```php
public function add_user($data)
{
    return $this->db->insert('users', [
        'name' => $data['name'],
        'email' => $data['email'],
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

// Dengan auto ID
public function add_user_get_id($data)
{
    return $this->db->insertGetId('users', [
        'name' => $data['name'],
        'email' => $data['email']
    ]);
}
```

#### UPDATE - Ubah Data

```php
public function update_user($id, $data)
{
    return $this->db->where('id', $id)
                    ->update('users', [
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
}

// Update dengan increment
public function increment_posts($user_id)
{
    return $this->db->where('id', $user_id)
                    ->increment('users', 'total_posts');
}
```

#### DELETE - Hapus Data

```php
public function delete_user($id)
{
    return $this->db->where('id', $id)
                    ->delete('users');
}

// Soft delete
public function soft_delete_user($id)
{
    return $this->db->where('id', $id)
                    ->update('users', [
                        'deleted_at' => date('Y-m-d H:i:s')
                    ]);
}
```

---

### Conditional WHERE

#### WHERE Variations

```php
// Basic WHERE
$this->db->where('status', 'active');

// WHERE NOT
$this->db->where_not('status', 'deleted');

// WHERE NULL
$this->db->where_null('deleted_at');

// WHERE NOT NULL
$this->db->where_not_null('verified_at');

// WHERE BETWEEN
$this->db->where_between('age', [18, 65]);

// WHERE NOT BETWEEN
$this->db->where_not_between('salary', [10000, 30000]);

// WHERE IN
$this->db->where_in('role', ['admin', 'moderator']);

// WHERE NOT IN
$this->db->where_not_in('status', ['banned', 'deleted']);

// Multiple WHERE (AND)
$this->db->where('status', 'active')
         ->where('verified', 1)
         ->where('role', 'user');

// OR WHERE
$this->db->where('status', 'active')
         ->or_where('status', 'pending');
```

#### Complex WHERE dengan Group

```php
// Nested WHERE dengan parentheses
$this->db->where('status', 'active')
         ->group(function($query) {
             $query->where('role', 'admin')
                   ->or_where('role', 'moderator');
         });
// Hasil: WHERE `status` = 'active' AND (`role` = 'admin' OR `role` = 'moderator')

// OR GROUP
$this->db->where('verified', 1)
         ->or_group(function($query) {
             $query->where('premium', 1)
                   ->where('created_at >=', '2024-01-01');
         });
```

#### Conditional WHERE

```php
// WHEN: execute callback jika condition true
$this->db->when($search_term, function($query) use ($search_term) {
            $query->like('name', $search_term);
         })
         ->when($role != '', function($query) use ($role) {
            $query->where('role', $role);
         });

// UNLESS: execute callback jika condition FALSE
$this->db->unless($user_is_admin, function($query) {
    $query->where('visibility', 'public');
});
```

---

### Eager Loading Relations

#### With One (One-to-One Relationship)

```php
<? php
class Post_model extends CI_Model {
    
    public function get_posts_with_author()
    {
        // Load posts dengan author data
        return $this->db->with_one('users', 'user_id', 'id')
                        ->from('posts')
                        ->get()
                        ->result();
    }
    
    public function get_posts_with_alias()
    {
        // Dengan alias
        return $this->db->with_one(['users' => 'author'], 'user_id', 'id')
                        ->from('posts')
                        ->get()
                        ->result();
    }
    
    public function get_posts_with_conditions()
    {
        // Dengan WHERE conditions pada relation
        return $this->db->with_one('users', 'user_id', 'id', function($query) {
                            $query->select(['id', 'name', 'email'])
                                  ->where('status', 'active');
                        })
                        ->from('posts')
                        ->where('status', 'published')
                        ->get()
                        ->result();
    }
}

// Usage di Controller
public function view()
{
    $this->load->model('Post_model');
    $posts = $this->Post_model->get_posts_with_author();
    
    // Akses relation data
    foreach ($posts as $post) {
        echo $post->title;
        echo $post->author->name;  // Relation loaded automatically
    }
}
```

#### With Many (One-to-Many Relationship)

```php
public function get_users_with_posts()
{
    // Load users dengan semua posts mereka
    return $this->db->with_many('posts', 'user_id', 'id')
                    ->from('users')
                    ->get()
                    ->result();
}

public function get_users_with_recent_posts()
{
    // Posts dengan kondisi tertentu
    return $this->db->with_many('posts', 'user_id', 'id', function($query) {
                        $query->where('status', 'published')
                              ->order_by('created_at', 'DESC')
                              ->limit(5);
                    })
                    ->from('users')
                    ->get()
                    ->result();
}

// Usage di Controller
public function posts_list()
{
    $this->load->model('User_model');
    $users = $this->User_model->get_users_with_posts();
    
    foreach ($users as $user) {
        echo $user->name;
        foreach ($user->posts as $post) {  // Array of posts
            echo $post->title;
        }
    }
}
```

#### Multiple Relations

```php
public function get_posts_full()
{
    return $this->db->with_one('users', 'user_id', 'id')           // Author
                    ->with_many('comments', 'post_id', 'id')       // Comments
                    ->with_one('categories', 'category_id', 'id')  // Category
                    ->from('posts')
                    ->get()
                    ->result();
}

// Usage
$posts = $this->db->get_posts_full();
foreach ($posts as $post) {
    echo $post->title;
    echo $post->users->name;              // Author
    echo $post->categories->name;         // Category
    foreach ($post->comments as $comment) {  // Multiple comments
        echo $comment->content;
    }
}
```

---

### Aggregates & Calculations

#### With Count

```php
public function get_users_with_post_count()
{
    return $this->db->with_count('posts', 'user_id', 'id')
                    ->from('users')
                    ->order_by('posts_count', 'DESC')
                    ->get()
                    ->result();
}

// Usage
$users = $this->db->get_users_with_post_count();
foreach ($users as $user) {
    echo $user->name .   ' - Posts: ' . $user->posts_count;
}
```

#### With Sum

```php
public function get_users_with_total_spent()
{
    return $this->db->with_sum('orders', 'user_id', 'id', 'total_amount')
                    ->from('users')
                    ->order_by('orders_sum', 'DESC')
                    ->get()
                    ->result();
}

// Dengan alias
public function get_users_spending()
{
    return $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'total_amount')
                    ->from('users')
                    ->get()
                    ->result();
}

// Dengan custom expression
public function get_invoices_total_after_discount()
{
    return $this->db->with_sum(
                        ['job' => 'total_after_discount'],
                        'idinvoice',
                        'id',
                        '(job_total_price_before_discount - job_discount)',
                        true  // is_custom_expression
                    )
                    ->from('invoice')
                    ->get()
                    ->result();
}

// Dengan callback filter
public function get_users_completed_orders_total()
{
    return $this->db->with_sum('orders', 'user_id', 'id', 'amount', false, function($query) {
                        $query->where('status', 'completed')
                              ->where('created_at >=', '2024-01-01');
                    })
                    ->from('users')
                    ->get()
                    ->result();
}
```

#### With Average, Min, Max

```php
// Average order value per user
public function get_users_avg_order()
{
    return $this->db->with_avg('orders', 'user_id', 'id', 'amount')
                    ->from('users')
                    ->get()
                    ->result();
}

// Highest order amount per user
public function get_users_max_order()
{
    return $this->db->with_max('orders', 'user_id', 'id', 'amount')
                    ->from('users')
                    ->get()
                    ->result();
}

// Lowest order amount per user
public function get_users_min_order()
{
    return $this->db->with_min('orders', 'user_id', 'id', 'amount')
                    ->from('users')
                    ->get()
                    ->result();
}
```

#### With Custom Calculation

```php
public function get_orders_with_efficiency()
{
    return $this->db->with_calculation(
                        ['order_items' => 'efficiency_percentage'],
                        'order_id',
                        'id',
                        '(SUM(finished_qty) / SUM(total_qty)) * 100'
                    )
                    ->from('orders')
                    ->get()
                    ->result();
}

public function get_products_profit_margin()
{
    return $this->db->with_calculation(
                        ['sales' => 'profit_margin'],
                        'product_id',
                        'id',
                        '((SUM(selling_price * quantity) - SUM(cost_price * quantity)) / SUM(selling_price * quantity)) * 100'
                    )
                    ->from('products')
                    ->get()
                    ->result();
}

// Dengan callback untuk WHERE conditions
public function get_transactions_production_days()
{
    return $this->db->with_calculation(
                        ['transaction_step' => 'production_duration_days'],
                        'idtransaction_detail',
                        'idtransaction_detail',
                        'DATEDIFF(MAX(date), MIN(date))',
                        function($query) {
                            $query->where('status', 'completed');
                        }
                    )
                    ->from('transaction_detail')
                    ->get()
                    ->result();
}
```

#### Filter dengan Where Aggregate

```php
public function get_users_high_spenders()
{
    return $this->db->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'amount')
                    ->where_aggregate('total_spent >=', 10000)
                    ->from('users')
                    ->get()
                    ->result();
}

public function get_products_efficient()
{
    return $this->db->with_calculation(
                        ['order_items' => 'efficiency_percentage'],
                        'product_id',
                        'id',
                        '(SUM(finished_qty) / SUM(total_qty)) * 100'
                    )
                    ->where_aggregate('efficiency_percentage >=', 90)
                    ->from('products')
                    ->get()
                    ->result();
}

// Multiple aggregate conditions
public function get_users_moderate_spenders()
{
    return $this->db->with_sum(['orders' => 'total'], 'user_id', 'id', 'amount')
                    ->where_aggregate('total >', 5000)
                    ->where_aggregate('total <', 50000)
                    ->from('users')
                    ->get()
                    ->result();
}
```

---

### Advanced Features

#### WHERE EXISTS / NOT EXISTS

```php
// Users yang memiliki orders
public function get_users_with_orders()
{
    return $this->db->from('users')
                    ->where_exists_relation('orders', 'user_id', 'id')
                    ->get()
                    ->result();
}

// Users yang memiliki published posts
public function get_users_with_published_posts()
{
    return $this->db->from('users')
                    ->where_exists_relation('posts', 'user_id', 'id', function($query) {
                        $query->where('status', 'published');
                    })
                    ->get()
                    ->result();
}

// Users yang TIDAK memiliki orders
public function get_users_without_orders()
{
    return $this->db->from('users')
                    ->where_not_exists_relation('orders', 'user_id', 'id')
                    ->get()
                    ->result();
}

// OR WHERE EXISTS
public function get_active_or_premium_users()
{
    return $this->db->from('users')
                    ->where('status', 'active')
                    ->or_where_exists_relation('premium_subscriptions', 'user_id', 'id')
                    ->get()
                    ->result();
}
```

#### WHERE HAS (With Count)

```php
// Users yang memiliki minimal 5 posts
public function get_productive_users()
{
    return $this->db->from('users')
                    ->where_has('posts', 'user_id', 'id', null, '>=', 5)
                    ->get()
                    ->result();
}

// Users yang memiliki orders dengan kondisi tertentu
public function get_users_with_big_purchases()
{
    return $this->db->from('users')
                    ->where_has('orders', 'user_id', 'id', function($query) {
                        $query->where('amount >', 10000);
                    }, '>=', 3)  // Minimal 3 purchase > 10000
                    ->get()
                    ->result();
}

// Users yang TIDAK memiliki posts
public function get_inactive_users()
{
    return $this->db->from('users')
                    ->where_doesnt_have('posts', 'user_id', 'id')
                    ->get()
                    ->result();
}
```

#### Chunking (Process Large Datasets)

```php
// Basic chunking
public function process_all_users()
{
    $processed = 0;
    
    $this->db->chunk(1000, function($users) use (&$processed) {
        foreach ($users as $user) {
            // Process user
            $this->send_email($user->email);
            $processed++;
        }
    }, 'users');
    
    echo "Processed: $processed users";
}

// Chunking dengan conditions
public function process_active_users()
{
    $this->db->where('status', 'active')
             ->chunk(500, function($users, $page) {
                 echo "Processing page: $page\n";
                 foreach ($users as $user) {
                     $this->update_last_activity($user->id);
                 }
             }, 'users');
}

// Chunk by ID (lebih efisien untuk dataset besar)
public function process_users_by_id()
{
    $this->db->chunk_by_id(2000, function($users, $page) {
        foreach ($users as $user) {
            $this->process_user($user);
        }
    }, 'id', 'users');
}
```

#### SQL_CALC_FOUND_ROWS untuk Pagination

```php
public function get_users_paginated($page = 1, $perPage = 20)
{
    $offset = ($page - 1) * $perPage;
    
    $result = $this->db->select(['id', 'name', 'email'])
                       ->calc_rows()  // Enable SQL_CALC_FOUND_ROWS
                       ->from('users')
                       ->where('status', 'active')
                       ->order_by('name', 'ASC')
                       ->limit($perPage, $offset)
                       ->get();
    
    return [
        'data' => $result->result(),
        'total' => $result->found_rows(),
        'page' => $page,
        'perPage' => $perPage
    ];
}
```

#### Transactions

```php
public function transfer_funds($from_user, $to_user, $amount)
{
    return $this->db->transaction(function() use ($from_user, $to_user, $amount) {
        // Deduct from sender
        $this->db->where('id', $from_user)
                 ->update('users', [
                     'balance' => $this->db->raw('balance - ' . $amount)
                 ]);
        
        // Add to receiver
        $this->db->where('id', $to_user)
                 ->update('users', [
                     'balance' => $this->db->raw('balance + ' . $amount)
                 ]);
        
        // Log transaction
        $this->db->insert('transaction_logs', [
            'from_user' => $from_user,
            'to_user' => $to_user,
            'amount' => $amount,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return true;
    });
}

// Strict mode (throw exception on failure)
public function critical_operation()
{
    return $this->db->transaction(function() {
        // Your operations
        return true;
    }, true);  // strict = true
}
```

#### Search & Pluck

```php
public function search_users($term)
{
    return $this->db->from('users')
                    ->search($term, ['name', 'email', 'phone'])
                    ->get()
                    ->result();
}

// Get specific column values
public function get_user_emails()
{
    return $this->db->from('users')
                    ->pluck('email');
}

// Pluck dengan nested relation
public function get_author_names()
{
    return $this->db->with_one('users', 'user_id', 'id')
                    ->from('posts')
                    ->pluck('users.name');
}
```

#### First/Exists/Latest/Oldest

```php
// Get first user with conditions
$first_user = $this->db->where('status', 'active')
                       ->first('users');

// Check if record exists
$exists = $this->db->where('email', 'john@example.com')
                   ->exists('users');

if (!  $exists) {
    echo "User not found";
}

// Order by latest/oldest
$latest_posts = $this->db->from('posts')
                         ->latest('created_at')  // DESC
                         ->limit(10)
                         ->get()
                         ->result();

$oldest_posts = $this->db->from('posts')
                        ->oldest('created_at')   // ASC
                        ->limit(10)
                        ->get()
                        ->result();
```

---

## 🔌 API Reference

### Selection Methods
| Method | Description |
|--------|-------------|
| `select($columns)` | Specify columns to select |
| `add_select($columns)` | Add additional columns |
| `distinct()` | Select distinct records |
| `pluck($column)` | Get array of single column values |

### WHERE Conditions
| Method | Description |
|--------|-------------|
| `where($column, $value)` | WHERE clause |
| `or_where($column, $value)` | OR WHERE clause |
| `where_not($column, $value)` | WHERE column != value |
| `where_null($column)` | WHERE column IS NULL |
| `where_not_null($column)` | WHERE column IS NOT NULL |
| `where_in($column, $values)` | WHERE IN clause |
| `where_not_in($column, $values)` | WHERE NOT IN clause |
| `where_between($column, $values)` | WHERE BETWEEN clause |
| `where_not_between($column, $values)` | WHERE NOT BETWEEN clause |
| `search($term, $columns)` | Search across multiple columns |
| `group($callback)` | Group WHERE conditions with AND |
| `or_group($callback)` | Group WHERE conditions with OR |

### Relations & Joins
| Method | Description |
|--------|-------------|
| `with($relation, $fk, $lk, $multiple, $callback)` | Eager load relation |
| `with_one($relation, $fk, $lk, $callback)` | One-to-one relation |
| `with_many($relation, $fk, $lk, $callback)` | One-to-many relation |
| `join($table, $condition)` | INNER JOIN |
| `where_exists_relation($relation, $fk, $lk, $callback)` | WHERE EXISTS |
| `where_not_exists_relation($relation, $fk, $lk, $callback)` | WHERE NOT EXISTS |
| `where_has($relation, $fk, $lk, $callback, $operator, $count)` | WHERE HAS |
| `where_doesnt_have($relation, $fk, $lk, $callback)` | WHERE DOESN'T HAVE |

### Aggregates
| Method | Description |
|--------|-------------|
| `with_count($relation, $fk, $lk, $callback)` | Count related records |
| `with_sum($relation, $fk, $lk, $column, $isCustom, $callback)` | Sum of related column |
| `with_avg($relation, $fk, $lk, $column, $isCustom, $callback)` | Average of column |
| `with_max($relation, $fk, $lk, $column, $isCustom, $callback)` | Maximum value |
| `with_min($relation, $fk, $lk, $column, $isCustom, $callback)` | Minimum value |
| `with_calculation($relation, $fk, $lk, $expression, $callback)` | Complex calculation |
| `where_aggregate($condition, $value)` | Filter by aggregate |
| `or_where_aggregate($condition, $value)` | OR filter by aggregate |

### Sorting & Pagination
| Method | Description |
|--------|-------------|
| `order_by($column, $direction)` | ORDER BY clause |
| `order_by_sequence($column, $array)` | ORDER BY custom sequence |
| `latest($column)` | ORDER BY DESC (default: created_at) |
| `oldest($column)` | ORDER BY ASC (default: created_at) |
| `group_by($column)` | GROUP BY clause |
| `limit($limit, $offset)` | LIMIT with OFFSET |
| `calc_rows()` | Enable SQL_CALC_FOUND_ROWS |
| `first($table)` | Get first result |
| `exists($table)` | Check if records exist |
| `doesnt_exist($table)` | Check if no records exist |

### Execution
| Method | Description |
|--------|-------------|
| `get($table, $limit, $offset)` | Execute SELECT query |
| `get_where($table, $where, $limit, $offset)` | SELECT with WHERE |
| `count_all_results($table, $reset)` | Count results |
| `chunk($size, $callback, $table)` | Process by chunks |
| `chunk_by_id($size, $callback, $column, $table)` | Process by ID chunks |
| `query($sql, $binds)` | Execute raw query |
| `transaction($callback, $strict)` | Execute within transaction |
| `reset_query()` | Reset query builder |

### Conditions & Logic
| Method | Description |
|--------|-------------|
| `when($condition, $callback, $default)` | Conditional query |
| `unless($condition, $callback, $default)` | Opposite of when |

---

## 💡 Contoh Implementasi Lengkap

### Contoh 1: User Management dengan Relations

```php
<? php
class User_model extends CI_Model {
    
    private $table = 'users';
    
    public function get_user_profile($user_id)
    {
        return $this->db->with_one('profiles', 'user_id', 'id')
                        ->with_many('addresses', 'user_id', 'id')
                        ->with_one(['roles' => 'role'], 'role_id', 'id')
                        ->where('id', $user_id)
                        ->first($this->table);
    }
    
    public function get_users_dashboard()
    {
        return $this->db->select(['id', 'name', 'email', 'created_at'])
                        ->with_count('posts', 'user_id', 'id')
                        ->with_count('comments', 'user_id', 'id')
                        ->with_sum('orders', 'user_id', 'id', 'total_amount')
                        ->with_avg('orders', 'user_id', 'id', 'total_amount')
                        ->where('status', 'active')
                        ->where_has('orders', 'user_id', 'id', null, '>=', 1)
                        ->order_by('orders_sum', 'DESC')
                        ->limit(50)
                        ->get($this->table)
                        ->result();
    }
    
    public function search_active_users($search, $page = 1, $perPage = 20)
    {
        $offset = ($page - 1) * $perPage;
        
        $result = $this->db->select(['id', 'name', 'email', 'status'])
                           ->search($search, ['name', 'email'])
                           ->calc_rows()
                           ->where('status', 'active')
                           ->limit($perPage, $offset)
                           ->get($this->table);
        
        return [
            'data' => $result->result(),
            'total' => $result->found_rows(),
            'page' => $page,
            'perPage' => $perPage,
            'pages' => ceil($result->found_rows() / $perPage)
        ];
    }
}
```

### Contoh 2: Advanced Reporting

```php
<?php
class Report_model extends CI_Model {
    
    public function sales_report($start_date, $end_date)
    {
        return $this->db->select(['o.id', 'u.name', 'u.email'])
                        ->with_sum(['order_items' => 'total_items'], 'order_id', 'id', null)
                        ->with_calculation(
                            ['order_items' => 'revenue'],
                            'order_id',
                            'id',
                            'SUM(price * quantity)',
                            function($query) {
                                $query->where('status', 'completed');
                            }
                        )
                        ->from('orders o')
                        ->join('users u', 'u.id = o.user_id')
                        ->where_between('o.created_at', [$start_date, $end_date])
                        ->where_aggregate('revenue >', 0)
                        ->order_by('revenue', 'DESC')
                        ->get()
                        ->result();
    }
    
    public function product_performance()
    {
        return $this->db->select(['p.id', 'p.name'])
                        ->with_count('order_items', 'product_id', 'id')
                        ->with_sum(['order_items' => 'total_revenue'], 'product_id', 'id', 'price * quantity', true)
                        ->with_avg(['order_items' => 'avg_rating'], 'product_id', 'id', null)
                        ->from('products p')
                        ->where_has('order_items', 'product_id', 'id', null, '>=', 10)
                        ->where_aggregate('total_revenue >', 100000)
                        ->order_by('total_revenue', 'DESC')
                        ->limit(100)
                        ->get()
                        ->result();
    }
}
```

### Contoh 3: Data Processing dengan Chunking

```php
<? php
class Batch_processor extends CI_Model {
    
    public function send_monthly_reports()
    {
        $this->db->where('email_notifications', 1)
                 ->chunk(500, function($users) {
                     foreach ($users as $user) {
                         $report = $this->generate_report($user->id);
                         $this->send_email($user->email, $report);
                     }
                     log_message('info', 'Sent reports for ' . count($users) . ' users');
                 }, 'users');
    }
    
    public function cleanup_old_records()
    {
        $cutoff_date = date('Y-m-d', strtotime('-90 days'));
        
        $count = $this->db->where('created_at <', $cutoff_date)
                          ->where('archived', 0)
                          ->chunk_by_id(1000, function($records) {
                              foreach ($records as $record) {
                                  $this->db->where('id', $record->id)
                                          ->update('logs', ['archived' => 1]);
                              }
                          }, 'id', 'logs');
        
        log_message('info', 'Archived ' . $count . ' old records');
    }
}
```

---

## 🐛 Troubleshooting

### Issue: Relations not loading

**Solusi:**
```php
// Pastikan foreign key dan local key sesuai
$this->db->with_one('users', 'user_id', 'id')  // ✅ Correct
         ->from('posts')
         ->get();

// Pastikan callback di akhir parameter
$this->db->with_one('users', 'user_id', 'id', function($query) {
    $query->where('status', 'active');
})
->from('posts')
->get();
```

### Issue: SQL Injection Warning

**Solusi:**
CustomQueryBuilder mengvalidasi semua input.  Jika ada warning, pastikan:
```php
// ✅ Gunakan column names yang valid
$this->db->where('user_id', $id);

// ❌ Jangan gunakan dynamic column names tanpa validasi
$column = $_GET['column'];  // Bahaya!
$this->db->where($column, $value);

// ✅ Validasi terlebih dahulu
$allowed_columns = ['name', 'email', 'status'];
if (in_array($column, $allowed_columns)) {
    $this->db->where($column, $value);
}
```

### Issue: Memory issue dengan large datasets

**Solusi:**
```php
// ✅ Gunakan chunk instead of get()
$this->db->chunk(1000, function($users) {
    // Process in batches
}, 'users');

// ✅ Untuk SELECT hasil besar, gunakan limit
$this->db->limit(1000)->offset(0)->get();
```

### Issue: Aggregate function error

**Solusi:**
```php
// Pastikan custom expression valid
// ✅ Valid
$this->db->with_sum('items', 'order_id', 'id', 'price * quantity', true);

// ❌ Invalid - dangerous pattern
$this->db->with_sum('items', 'order_id', 'id', 'SELECT * FROM...  ', true);

// ✅ Valid - allowed functions
$allowed = ['SUM', 'AVG', 'COUNT', 'MAX', 'MIN', 'ROUND', 'FLOOR', 'DATEDIFF', etc];
```

### Issue: Metode 2 (Injection) tidak berfungsi

**Solusi:**
- Pastikan file `CustomQueryBuilder.php` sudah ada di `system/core/`
- Verifikasi require statement di `system/database/DB.php` sudah ditambahkan
- Cek bahwa tidak ada conflict dengan class definitions lain
- Test dengan membuat controller sederhana untuk verify functionality
- Jika masih error, coba rollback ke Metode 1 (Standard Installation)

---

## 🤝 Kontribusi

Kami menyambut kontribusi!  Silakan:

1. Fork repository
2. Buat branch untuk feature (`git checkout -b feature/AmazingFeature`)
3.  Commit changes (`git commit -m 'Add some AmazingFeature'`)
4. Push ke branch (`git push origin feature/AmazingFeature`)
5.  Buka Pull Request

---

## 📝 Lisensi

CustomQueryBuilder dilisensikan di bawah MIT License.  Lihat file [LICENSE](LICENSE) untuk detail lengkap.

---

## 👤 Author

**Danar Reichi**
- GitHub: [@danarreichi](https://github.com/danarreichi)
- Email: danarreichi@example.com

---

## ❤️ Acknowledgments

- CodeIgniter 3 Community
- Database Query Builder inspirasi: Laravel Query Builder
- Contributors dan issue reporters

---

**Last Updated**: December 2, 2025  
**Version**: 1.0.0  
**Maintained**: Yes ✅
