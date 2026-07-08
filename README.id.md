# CustomQueryBuilder

Ekstensi drop-in dan backward-compatible untuk Query Builder CodeIgniter 3 yang menambahkan fitur-fitur bergaya Laravel: eager loading, subquery agregat, kondisi `WHERE EXISTS`/`WHERE HAS`, chunking, dan lainnya — sambil tetap 100% kompatibel dengan setiap pemanggilan `$this->db->...` yang sudah ada di aplikasimu.

*(English version: [README.md](README.md))*

## Daftar Isi

1. [Instalasi](#instalasi)
2. [Mencoba Proyek Ini (Clone & Jalankan)](#mencoba-proyek-ini-clone--jalankan)
3. [Mulai Cepat](#mulai-cepat)
4. [Objek Result](#objek-result)
5. [Method Dasar yang Diperluas](#method-dasar-yang-diperluas)
6. [Eager Loading Relasi](#eager-loading-relasi)
7. [Subquery Agregat](#subquery-agregat)
8. [Agregat Berbasis JOIN (Alternatif Lebih Ringan)](#agregat-berbasis-join-alternatif-lebih-ringan)
9. [WHERE EXISTS / WHERE HAS](#where-exists--where-has)
10. [Query Kondisional](#query-kondisional)
11. [Search](#search)
12. [Pagination dengan calc_rows()](#pagination-dengan-calc_rows)
13. [Query Grouping](#query-grouping)
14. [Chunking Dataset Besar](#chunking-dataset-besar)
15. [pluck()](#pluck)
16. [Transaksi](#transaksi)
17. [query() Mentah](#query-mentah)
18. [Keamanan](#keamanan)
19. [Praktik Terbaik](#praktik-terbaik)
20. [Contoh Lengkap](#contoh-lengkap)
21. [Catatan & Hal yang Perlu Diperhatikan](#catatan--hal-yang-perlu-diperhatikan)

---

## Instalasi

### Prasyarat

- **CodeIgniter 3.x** terpasang dan berjalan normal
- **PHP 5.6+** (library ini ditulis agar kompatibel PHP 5 — tidak memakai `??`, `static::class`, dll.)
- Akses untuk memodifikasi file core sistem
- Database sudah dikonfigurasi dan berfungsi

### Langkah 1: Salin file

```
proyek-anda/
├── application/
├── system/
│   ├── core/
│   │   ├── CustomQueryBuilder/        ← salin seluruh folder ini ke sini
│   │   │   ├── main.php
│   │   │   └── libs/
│   │   ├── CodeIgniter.php
│   │   └── ...
│   └── database/
```

### Langkah 2: Ubah `system/database/DB.php`

**Cari kode ini** (sekitar baris 154–185 di CI 3.1.x standar):

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

**Ganti dengan:**

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

> `main.php` sudah melakukan `require_once(BASEPATH.'database/DB_query_builder.php')`-nya sendiri secara internal, jadi `require_once` untuk file ini aman ditaruh di mana saja dalam blok ini — PHP tidak akan memuat class induk dua kali baik bagaimana pun urutannya.

### Langkah 3: Bersihkan cache

```bash
php -r "opcache_reset();"
# atau restart web server / php-fpm Anda
```

### Langkah 4: Verifikasi

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

| Gejala | Penyebab | Solusi |
|---|---|---|
| `Class 'CustomQueryBuilder' not found` | File belum ada atau belum di-require | Pastikan `system/core/CustomQueryBuilder/main.php` ada dan baris `require_once` di Langkah 2 sudah ditambahkan |
| `Call to undefined method CI_DB::with_one()` | `CI_DB` masih extends `CI_DB_query_builder` | Cek ulang Langkah 2, lalu bersihkan OPcache |
| Tidak ada perubahan setelah edit | OPcache masih menyajikan bytecode lama | `php -r "opcache_reset();"` atau restart PHP |

### Kompatibilitas mundur

Setiap pemanggilan Query Builder CI standar tetap berfungsi tanpa modifikasi:

```php
$this->db->select('*')->where('status', 1)->get('users');
$this->db->insert('users', $data);
$this->db->update('users', $data, ['id' => 1]);
```

Adopsi fitur baru bisa dilakukan bertahap, satu pemanggilan pada satu waktu.

---

## Mencoba Proyek Ini (Clone & Jalankan)

Bagian di atas untuk memasang library ini ke aplikasi CodeIgniter 3 milik **kamu sendiri**. Kalau kamu baru saja meng-clone repo ini dan ingin mencoba library-nya langsung — jalankan test suite-nya, coba-coba interaktif, lihat SQL yang dihasilkan — repo ini sudah menyediakan semuanya tanpa perlu proyek terpisah.

Ada dua sandbox, keduanya memakai file konfigurasi database yang **sama**:

- **`tests/`** — test suite PHPUnit otomatis (49 test) yang memverifikasi string SQL hasil compile secara persis dan hasil query sungguhan. Ini cara tercepat dan paling cocok untuk CI.
- **`test-ci3/`** — instalasi CodeIgniter 3 lengkap dengan controller smoke-test manual (`Test_custom_qb`) yang menjalankan ~68 skenario dan mencetak output teks polos beserta anotasi `(expect ...)`, bisa dilihat lewat browser.

### Prasyarat

- **PHP 8.1+** dan **Composer** (khusus untuk `tests/` — library-nya sendiri tetap kompatibel PHP 5.6; hanya tooling PHPUnit yang butuh PHP modern)
- **MySQL/MariaDB** yang bisa diakses secara lokal

### 1. Clone dan arahkan ke database

```bash
git clone <repo-url>
cd custom-query-builder
```

Buat database kosong, lalu edit **`test-ci3/application/config/database.php`** — file ini dipakai bersama oleh kedua sandbox, jadi kamu cuma perlu konfigurasi koneksi di satu tempat:

```php
$db['default'] = array(
    'hostname' => '127.0.0.1',
    'username' => 'root',
    'password' => '',            // <- password MySQL kamu
    'database' => 'cqb_test',    // <- nama database kamu
    'dbdriver' => 'mysqli',
    // ...
);
```

Tidak perlu setup skema manual: tabel fixture `scores`, `category_scores`, dan `profiles` otomatis dibuat & di-seed ulang setiap kali test dijalankan; tabel `users` (id, name, email, category) hanya di-seed dengan 3 baris contoh kalau kosong, jadi data yang sudah ada tidak akan tertimpa.

### 2. Jalankan test suite otomatis (disarankan)

```bash
cd tests
composer install
vendor/bin/phpunit
```

```
PHPUnit 9.6.35 by Sebastian Bergmann and contributors.
.................................................                 49 / 49 (100%)
OK (49 tests, 60 assertions)
```

Lihat [`tests/CompiledSqlTest.php`](tests/CompiledSqlTest.php) (assertion string SQL persis) dan [`tests/ExecutionTest.php`](tests/ExecutionTest.php) (assertion hasil query sungguhan) untuk detail cakupannya.

> Kalau di environment kamu ada banyak versi PHP terpasang (mis. Laragon/XAMPP) dan `php`/`composer` di `PATH` mengarah ke versi lebih lama dari 8.1, panggil binary yang benar secara eksplisit: `/path/ke/php8.1 /path/ke/composer.phar install` dan `/path/ke/php8.1 vendor/bin/phpunit`.

### 3. Atau coba secara interaktif lewat sandbox CI3

```bash
cd test-ci3
php -S 127.0.0.1:8080 index.php
```

Lalu buka `http://127.0.0.1:8080/Test_custom_qb` di browser — akan menjalankan eager loading, `WHERE EXISTS`/`WHERE HAS`, agregat, grouping, penolakan SQL injection, dan lainnya, mencetak SQL hasil compile dan/atau hasilnya beserta komentar `(expect ...)` supaya kamu bisa cek kebenarannya langsung.

---

## Mulai Cepat

```php
$users = $this->db->select(['id', 'name'])
    ->with_many('orders', 'user_id', 'id')   // eager-load orders
    ->with_count('orders', 'user_id', 'id')  // + kolom jumlah
    ->where('status', 'active')
    ->order_by('name', 'ASC')
    ->get('users');

foreach ($users->result() as $user) {
    echo "{$user->name}: {$user->orders_count} pesanan\n";
    foreach ($user->orders as $order) {
        echo "  - #{$order->id}\n";
    }
}
```

---

## Objek Result

`get()`, `get_where()`, dan `query()` mentah (untuk statement `SELECT`) semuanya mengembalikan `CustomQueryBuilderResult` bukan objek result bawaan CI. Objek ini meneruskan (proxy) setiap pemanggilan method yang tidak dikenal ke objek result driver native lewat `__call()`, sehingga apa pun yang biasa kamu lakukan dengan result CI tetap berfungsi.

```php
$result = $this->db->get('users');

$result->result();        // array of objects (sama seperti native)
$result->result_array();  // array of arrays
$result->row();           // baris pertama sebagai object
$result->row_array();     // baris pertama sebagai array
$result->num_rows();      // jumlah baris
$result->num_fields();    // diteruskan langsung ke result driver native
```

### `value($column)` — ambil satu kolom dari baris pertama

```php
$email = $this->db->where('id', 1)->get('users')->value('email');
```

Ada juga versi **sebelum eksekusi** dari `value()` langsung di query builder (sebelum `get()`), yang lebih efisien karena menambahkan `SELECT` implisit + `LIMIT 1` daripada mengambil satu baris penuh yang sebenarnya sudah kamu punya:

```php
$email = $this->db->where('id', 1)->value('email', 'users');
// Setara dengan: SELECT `email` FROM users WHERE id = 1 LIMIT 1
```

### `key_by()` — re-index result set (seperti `keyBy()` Laravel)

```php
$byId = $this->db->get('users')->key_by('id');
// [1 => {...}, 2 => {...}, ...]

$byUpperName = $this->db->get('users')->key_by(function ($row) {
    return strtoupper($row->name);
});

$asArrays = $this->db->get('users')->key_by('id', true); // baris berupa array, bukan object
```

Jika ada key yang duplikat, baris yang cocok terakhir yang menang (perilaku sama seperti `Collection::keyBy()` Laravel).

---

## Method Dasar yang Diperluas

```php
// first() — ambil satu baris langsung
$user = $this->db->where('email', 'john@example.com')->first('users');

// exists() / doesnt_exist()
if ($this->db->where('email', $email)->exists('users')) { /* ... */ }
if ($this->db->where('status', 'deleted')->doesnt_exist('users')) { /* ... */ }

// latest() / oldest() — shortcut pengurutan
$this->db->latest('created_at')->get('posts');   // ORDER BY created_at DESC
$this->db->oldest('created_at')->get('posts');   // ORDER BY created_at ASC

// where_not() / where_null() / where_not_null()
$this->db->where_not('status', 'deleted');       // status != 'deleted'
$this->db->where_null('deleted_at');
$this->db->where_not_null('email_verified_at');

// where_between() / where_not_between() (+ varian or_)
$this->db->where_between('age', [18, 65]);
$this->db->where_not_between('price', [100, 500]);
$this->db->or_where_between('age', [18, 25]);

// order_by_sequence() — pengurutan manual custom
$this->db->order_by_sequence('priority', ['high', 'medium', 'low']);
// ORDER BY CASE WHEN priority='high' THEN 0 WHEN priority='medium' THEN 1 ...
```

---

## Eager Loading Relasi

### `with_one()` — satu record relasi

```php
$posts = $this->db->with_one('users', 'id', 'user_id')->get('posts');
// $post->users

$posts = $this->db->with_one(['users' => 'author'], 'id', 'user_id')->get('posts');
// $post->author

$posts = $this->db->with_one('users', 'id', 'user_id', function ($q) {
    $q->where('status', 'active')->select('id, name, email');
})->get('posts');
```

### `with_many()` — banyak record relasi

```php
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');
// $user->orders (array)

$users = $this->db->with_many('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active')->order_by('created_at', 'DESC');
})->get('users');
```

`$relation` menerima string biasa, atau array satu-elemen `['table' => 'alias']` (bentuk array lain akan melempar `InvalidArgumentException`). `$foreignKey`/`$localKey` menerima satu string kolom atau array kolom untuk composite key — kedua array harus punya jumlah elemen yang sama.

### Relasi bertingkat (nested)

```php
$posts = $this->db->with_many('comments', 'id', 'post_id', function ($q) {
    $q->with_one('users', 'id', 'user_id')->where('approved', 1);
})->get('posts');
// $post->comments[0]->users
```

### Banyak relasi sekaligus

```php
$posts = $this->db->with_one('users', 'id', 'user_id')
    ->with_many('comments', 'id', 'post_id')
    ->with_count('likes', 'id', 'post_id')
    ->get('posts');
```

---

## Subquery Agregat

Method-method ini menambahkan subquery berkorelasi ke `SELECT` — satu eksekusi subquery tambahan **per baris** di result set utama. Lihat [Agregat Berbasis JOIN](#agregat-berbasis-join-alternatif-lebih-ringan) di bawah untuk alternatif yang lebih ringan pada result set besar.

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

// Ekspresi custom (operasi matematika) — beri true di argumen ke-5
$this->db->with_sum(['job' => 'total_after_discount'],
    'id', 'idinvoice', '(job_total_price_before_discount - job_discount)', true);

// Callback untuk kondisi WHERE tambahan di dalam subquery
$this->db->with_avg('orders', 'id', 'user_id', 'total_amount', false, function ($q) {
    $q->where('status', 'completed');
});
```

### `with_calculation()` — ekspresi bebas

```php
$orders = $this->db->with_calculation(['order_items' => 'efficiency_percentage'],
    'order_id', 'id',
    '(SUM(finished_qty) / SUM(total_qty)) * 100'
)->get('orders');
// $order->efficiency_percentage

// Dengan callback untuk memfilter baris di dalam subquery
$products = $this->db->with_calculation(['reviews' => 'weighted_rating'],
    'product_id', 'id',
    'SUM(rating * helpful_votes) / SUM(helpful_votes)',
    function ($q) { $q->where('status', 'approved'); }
)->get('products');
```

**Yang diperbolehkan dalam ekspresi:** `+ - * / %`, `SUM AVG COUNT MIN MAX`, `DATEDIFF TIMESTAMPDIFF`, `CASE WHEN ... THEN ... END`, `ROUND FLOOR CEIL ABS`, dan beberapa fungsi lain yang di-whitelist — lihat [Keamanan](#keamanan). Selain itu akan ditolak dengan `InvalidArgumentException` sebelum sempat mencapai database.

### `where_aggregate()` / `or_where_aggregate()` — filter berdasarkan agregat

Gunakan method ini **setelah** pemanggilan `with_sum`/`with_avg`/`with_calculation` yang mendefinisikan alias yang ingin difilter:

```php
$this->db->with_sum(['orders' => 'total_spent'], 'id', 'user_id', 'amount')
    ->where_aggregate('total_spent >=', 5000)
    ->get('users');

// BETWEEN juga didukung
$this->db->with_calculation(['scores' => 'score_total'], 'user_id', 'id', 'SUM(value)')
    ->where_aggregate('score_total BETWEEN', [1, 100])
    ->get('users');

// Dikombinasikan dengan OR
$this->db->with_sum(['orders' => 'total_amount'], 'id', 'user_id', 'amount')
    ->where_aggregate('total_amount >', 10000)
    ->or_where_aggregate('total_amount =', 0)
    ->get('users');
```

`where_aggregate()`/`or_where_aggregate()` menjaga posisi pemanggilannya secara tepat relatif terhadap `where()`/`or_where()`/`group()` lain dalam chain yang sama — mencampurnya bebas dengan kondisi biasa akan menghasilkan SQL sesuai urutan chain dibaca dari atas ke bawah.

---

## Agregat Berbasis JOIN (Alternatif Lebih Ringan)

Agregat `with_*` menjalankan satu subquery berkorelasi **per baris**. Agregat `join_*` sebaliknya memindai tabel relasi **satu kali** lewat derived table `GROUP BY`, lalu `LEFT JOIN` — jauh lebih ringan pada result set besar.

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

Bentuk parameter sama seperti versi `with_*`-nya (`$relation`, `$foreignKey`, `$localKey`, `$column`/`$expression`, `$callback` opsional). Gunakan `join_*` sebagai pengganti `with_*` saat result set besar dan biaya subquery-per-baris mulai signifikan.

---

## WHERE EXISTS / WHERE HAS

### Keluarga `where_exists()` mentah — kontrol penuh via callback

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

### Keluarga `where_exists_relation()` — versi sederhana, auto-join berdasarkan key

```php
$this->db->from('users')->where_exists_relation('orders', 'user_id', 'id');
// WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)

$this->db->from('users')->where_exists_relation('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active');
});

// Composite key
$this->db->from('users')->where_exists_relation('user_roles', ['user_id', 'tenant_id'], ['id', 'tenant_id']);

$this->db->from('users')
    ->where_exists_relation('orders', 'user_id', 'id')
    ->or_where_exists_relation('posts', 'user_id', 'id');

$this->db->from('users')->where_not_exists_relation('orders', 'user_id', 'id');
```

Bekerja sama baiknya baik dipanggil dengan `->from('table')` di awal maupun `->get('table')` di akhir chain — nama tabel induk selalu diresolusi belakangan (lazily) dalam kedua kasus.

`or_where_exists_relation()` / `or_where_not_exists_relation()` menerima argumen ke-5 opsional `$disable_pending_process`. Biasanya tidak perlu dipakai; argumen ini ada untuk kasus khusus ketika kamu ingin kondisi tersebut dijadwalkan lewat mekanisme penjaga-urutan yang sama seperti yang dipakai di dalam `group()`, meskipun di luar `group()`.

### `where_has()` / `or_where_has()` — keberadaan dengan ambang jumlah (count)

```php
$this->db->from('users')->where_has('orders', 'user_id', 'id');
// sama seperti where_exists_relation() ketika count default-nya >= 1

$this->db->from('users')->where_has('orders', 'user_id', 'id', function ($q) {
    $q->where('status', 'active');
});

// Users dengan minimal 5 pesanan
$this->db->from('users')->where_has('orders', 'user_id', 'id', null, '>=', 5);

// Shorthand: berikan operator langsung di argumen ke-4 kalau tidak butuh callback
$this->db->from('users')->where_has('orders', 'user_id', 'id', '>=', 5);
```

`where_doesnt_have()` / `or_where_doesnt_have()` adalah shorthand untuk versi "tidak ada"-nya.

Semua varian di atas — `where_exists()` mentah, `where_exists_relation()`, dan `where_has()` — menjaga urutan pemanggilan dengan benar relatif satu sama lain, terhadap `group()`, dan terhadap `where()`/`or_where()` biasa dalam chain yang sama, apa pun urutan pencampurannya.

---

## Query Kondisional

```php
// when() — jalankan callback hanya jika kondisinya truthy
$this->db->when($search_term, function ($q) use ($search_term) {
    $q->like('name', $search_term);
});

// dengan callback else
$this->db->when($user_role == 'admin', function ($q) {
    $q->select('*');
}, function ($q) {
    $q->select('id, name, email');
});

// unless() — kebalikan dari when()
$this->db->unless($user_role == 'admin', function ($q) {
    $q->where('status', 'published');
});
```

Sangat berguna untuk membangun chain filter dinamis tanpa deretan `if`:

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

$this->db->search('admin', ['role', 'title'], false); // AND, bukan OR
// WHERE (`role` LIKE '%admin%' AND `title` LIKE '%admin%')
```

---

## Pagination dengan calc_rows()

```php
$result = $this->db->select(['id', 'name'])
    ->calc_rows()
    ->get('users', 20, 0);

$data  = $result->result();      // 20 baris
$total = $result->found_rows();  // total baris tanpa LIMIT

// Bekerja juga dengan eager loading
$result = $this->db->select(['id', 'name'])
    ->with_one('profile', 'user_id', 'id')
    ->calc_rows()
    ->get('users', 20, 0);
```

Menambahkan `SQL_CALC_FOUND_ROWS` di baliknya — satu query memberikan data halaman sekaligus total jumlahnya.

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

`group()`/`or_group()` bekerja dengan benar baik ketika tabel sudah diketahui (`->from('table')` dipanggil dulu) maupun disuplai belakangan (`->get('table')` di akhir chain), dan menjaga posisinya relatif terhadap `where_has()`/`where_exists_relation()`/`where_aggregate()`/`where()` biasa sebelum atau sesudahnya — termasuk saat beberapa di antaranya dicampur dalam query yang sama.

---

## Chunking Dataset Besar

```php
// Berbasis offset
$this->db->where('status', 'active')->chunk(500, function ($users, $page) {
    foreach ($users as $user) { /* ... */ }
    if ($page > 10) return false; // hentikan lebih awal
}, 'users');

// Berbasis ID (tidak ada gap/duplikat kalau ada baris insert/delete di tengah proses)
$this->db->chunk_by_id(1000, function ($users) {
    foreach ($users as $user) { $this->send_email($user->email); }
}, 'id', 'users');
```

Keduanya mengembalikan total record yang diproses. Callback menerima **array of objects** (bukan array biasa) — `$user->name`, bukan `$user['name']`.

---

## pluck()

```php
$emails = $this->db->from('users')->pluck('email');
// ['a@x.com', 'b@x.com', ...]

// Dot notation bisa menjangkau kolom dari relasi hasil eager loading
$names = $this->db->from('users')->with_one('profile', 'profile_id', 'id')->pluck('profile.name');
```

---

## Transaksi

```php
$result = $this->db->transaction(function ($db) {
    $db->insert('users', $data);
    $id = $db->insert_id();
    $db->insert('profiles', ['user_id' => $id]);
    return $id;
});

// Mode strict akan melempar ulang exception saat gagal, bukan return false
$this->db->transaction(function ($db) { /* ... */ }, true);
```

---

## query() Mentah

`query()` di-override sehingga SQL mentah tetap berfungsi seperti CI native, sementara statement `SELECT` masih dibungkus dalam `CustomQueryBuilderResult`:

```php
$result = $this->db->query("SELECT * FROM transaction WHERE status = 1");
$result->result(); // berfungsi, sama seperti get()

$ok = $this->db->query("UPDATE users SET status = 'inactive' WHERE id = 1");
// mengembalikan bool biasa, sama seperti CI native, untuk statement tulis
```

Jika `with_relations`/`pending_aggregates` sudah diset sebelumnya, `query()` otomatis menjalankan eager loading terhadap hasil SQL mentah tersebut juga.

---

## Keamanan

`QueryValidationTrait` memvalidasi setiap identifier dan ekspresi yang melewati method helper milik library ini sendiri (tidak secara retroaktif membersihkan string mentah yang kamu berikan langsung ke method CI native seperti `where("...", null, false)` — itu tetap tanggung jawabmu, sama seperti CI standar).

- **Nama kolom/tabel**: alfanumerik + underscore (+ titik untuk `table.column`), maks 64 karakter, kata kunci SQL ditolak.
- **Operator**: hanya whitelist — `= > < >= <= != <> BETWEEN "NOT BETWEEN"`.
- **Ekspresi** (`with_calculation`, ekspresi custom `with_sum`/`with_avg`, dll.): whitelist fungsi (`SUM AVG COUNT MAX MIN ROUND FLOOR CEIL ABS COALESCE IFNULL NULLIF CASE DATEDIFF TIMESTAMPDIFF CONCAT`, …), kata kunci berbahaya diblokir (`INSERT UPDATE DELETE DROP UNION EXEC CREATE ALTER TRUNCATE SLEEP BENCHMARK GET_LOCK`, …), kurung harus seimbang, maks 500 karakter.
- **Array relasi `with()`/`with_one()`/`with_many()`**: harus tepat `['relation_name' => 'alias']` — bentuk lain (array kosong, 2+ elemen) akan melempar `InvalidArgumentException` daripada diam-diam menebak.

```php
// ✅ Aman — divalidasi
$this->db->where('status', $user_input);
$this->db->with_calculation(['sales' => 'profit'], 'product_id', 'id', 'SUM(price * quantity) - SUM(cost * quantity)');

// ❌ Tidak aman — konkatenasi mentah melewati validasi sama sekali
$this->db->where("status = '{$user_input}'", null, false);

// ✅ Aman — escape eksplisit kalau harus membangun fragment mentah
$this->db->where("status = " . $this->db->escape($user_input), null, false);
```

---

## Praktik Terbaik

**Eager-load daripada looping** — menghindari masalah N+1:

```php
// Baik
$users = $this->db->with_many('orders', 'user_id', 'id')->get('users');

// Hindari
foreach ($this->db->get('users')->result() as $user) {
    $user->orders = $this->db->where('user_id', $user->id)->get('orders')->result();
}
```

**Gunakan agregat daripada query per-baris:**

```php
$users = $this->db->with_count('orders', 'user_id', 'id')
    ->with_sum('orders', 'user_id', 'id', 'total_amount')
    ->get('users');
```

**Gunakan `where_exists_relation()`/`where_has()` daripada `JOIN + GROUP BY` untuk cek keberadaan murni** — maksud lebih jelas, performa sama:

```php
$users = $this->db->from('users')->where_exists_relation('orders', 'user_id', 'id')->get();
```

**Gunakan `calc_rows()` untuk pagination** daripada memanggil `count_all_results()` secara terpisah.

**Prioritaskan `join_*` daripada agregat `with_*` pada result set besar** — satu scan `GROUP BY` daripada N subquery berkorelasi.

**Jangan pernah mengonkatenasi input user langsung ke string WHERE mentah** — gunakan method yang sudah divalidasi, atau `escape()` secara eksplisit kalau harus membangun fragment secara manual.

---

## Contoh Lengkap

```php
$page      = 1;
$per_page  = 20;
$offset    = ($page - 1) * $per_page;
$search    = $this->input->get('search');
$status    = $this->input->get('status');
$min_orders = $this->input->get('min_orders');

$result = $this->db->select(['id', 'name', 'email', 'created_at'])
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
    ->where_not('status', 'deleted')
    ->order_by('total_spent', 'DESC')
    ->calc_rows()
    ->get('users', $per_page, $offset);

$data  = $result->result();
$total = $result->found_rows();

foreach ($data as $user) {
    echo "{$user->name}: {$user->orders_count} pesanan, {$user->total_spent} dibelanjakan\n";
    foreach ($user->orders as $order) {
        echo "  - pesanan #{$order->id}\n";
    }
}
```

---

## Catatan & Hal yang Perlu Diperhatikan

- **`result()` vs `result_array()`**: object vs. array biasa — pilih sesuai kebutuhan kode pemanggil.
- **Callback `chunk()`/`chunk_by_id()` menerima object**, bukan array.
- **Bentuk array `with()`/`with_one()`/`with_many()` bersifat ketat**: `['relation' => 'alias']` dengan tepat satu elemen, atau string biasa. Bentuk lain akan melempar exception.
- **Urutan antar tipe kondisi yang dicampur**: `where()`, `or_where()`, `group()`, `where_exists_relation()`, `where_has()`, dan `where_aggregate()` bisa dicampur bebas dalam urutan apa pun (termasuk bersarang satu sama lain) — clause `WHERE` akhir akan merefleksikan urutan pemanggilannya.
- **`get_compiled_select()` dan `count_all_results()` mengabaikan eager-loading (`with()`/`with_one()`/`with_many()`)** secara sengaja — keduanya hanya mengompilasi/menghitung query dasar. Subquery agregat (`with_count`, `with_sum`, `with_calculation`, dll.) *tetap muncul*, karena itu berada di `SELECT`/`WHERE`, bukan di pipeline eager-loading yang terpisah.
- **Kompatibel PHP 5.6+**: tidak ada `??`, `static::class`, atau sintaks khusus 7+/8+ lainnya — aman dipasang di instalasi CodeIgniter 3 yang lebih lama.

---

## Informasi Versi

- **File**: `system/core/CustomQueryBuilder/main.php`
- **Framework**: CodeIgniter 3.x
- **PHP**: 5.6+
