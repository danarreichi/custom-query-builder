## Custom Query Builder untuk CodeIgniter 3

Library ini memperluas Query Builder CI3 dengan fitur relasi (with/with_count/with_sum/avg/max/min), where exists/has, pencarian, pagination helper, dan utilitas lainnya.

Catatan penting: Integrasi dilakukan dengan mengganti file yang di-include oleh `system/database/DB.php` agar Query Builder bawaan CI3 menggunakan versi kustom ini.

---

## Persyaratan

- CodeIgniter 3.x (3.1.x direkomendasikan)
- PHP 7.2+ (disarankan) — menyesuaikan versi PHP project Anda

---

## Instalasi (Direkomendasikan – simpan di system/core)

1) Backup file sistem CI terlebih dahulu

- Salin file berikut sebagai cadangan agar mudah rollback:
	- `system/database/DB.php`

2) Salin file library

- Salin `CustomQueryBuilder.php` dari repo ini ke folder project Anda pada path:
	- `system/core/CustomQueryBuilder.php`

3) Ubah include di `system/database/DB.php`

- Buka `system/database/DB.php`
- Cari baris:

	`require_once(BASEPATH.'database/DB_query_builder.php');`

- Ganti menjadi:

	`require_once(BASEPATH.'core/CustomQueryBuilder.php');`

4) Simpan perubahan dan deploy

- Hapus cache/opcache bila diperlukan (tergantung konfigurasi server).

---

## Alternatif Penempatan (APPPATH)

Bila Anda ingin menyimpan file di folder aplikasi, Anda bisa menaruhnya di `application/core/CustomQueryBuilder.php`, lalu pada langkah 3 gunakan:

- Ganti baris include menjadi:

	`require_once(APPPATH.'core/CustomQueryBuilder.php');`

Catatan: Mengubah file `system/database/DB.php` tetap diperlukan. Simpan backup agar mudah rollback saat upgrade CI.

---

## Pemakaian Singkat

Contoh-contoh di bawah mengikuti pola CI3 biasa menggunakan `$this->db`.

1) Eager load relasi dan hitung total baris (SQL_CALC_FOUND_ROWS)

```php
// Controller/Model
$result = $this->db
		->select(['id', 'name'])
		->calc_rows() // aktifkan FOUND_ROWS untuk pagination
		->with_many('posts', 'user_id', 'id', function($q) {
            $q->where('status', 'published');
		})
		->with_count('comments', 'post_id', 'id')
		->order_by('comments_count', 'DESC')
		->get('users', 10, 0);

$rows  = $result->result();       // objek
$array = $result->result_array();  // array
$total = $result->found_rows();    // total tanpa LIMIT
```

2) Agregasi sebagai subquery (bisa untuk sorting)

```php
$users = $this->db
		->with_sum('orders', 'user_id', 'id', 'total_amount')
		->order_by('orders_sum', 'DESC')
		->get('users')
		->result();
```

3) Where exists pada relasi

```php
$activeUsers = $this->db
		->where_exists_relation('orders', 'user_id', 'id', function($q) {
            $q->where('status', 'completed');
		})
		->get('users')
		->result();
```

4) Utilitas cepat

```php
// Ambil baris pertama
$user = $this->db->where('email', 'foo@bar.com')->first('users');

// Cek keberadaan data
$exists = $this->db->where('status', 'active')->exists('users');
```

Lihat komentar dan PHPDoc di `CustomQueryBuilder.php` untuk fitur lengkap (with_one/with_many, avg/max/min, where_has/or_where_has, group/or_group, search, latest/oldest, chunk, dll.).

---

## Tips & Catatan

- Mengedit file `system/database/DB.php` artinya setiap upgrade CodeIgniter Anda perlu mengulangi langkah instalasi. Selalu simpan backup/diff.
- Jika server menggunakan OPCache, lakukan restart PHP-FPM atau clear opcache agar perubahan terdeteksi.
- Pastikan path yang digunakan (`BASEPATH` atau `APPPATH`) sesuai dengan lokasi Anda menaruh `CustomQueryBuilder.php`.

---

## Rollback

Untuk kembali ke Query Builder bawaan CI3:

1) Kembalikan baris include di `system/database/DB.php` menjadi:

`require_once(BASEPATH.'database/DB_query_builder.php');`

2) Hapus/abaikan `CustomQueryBuilder.php` jika tidak diperlukan.

---

## Lisensi

Ikuti lisensi project ini dan lisensi CodeIgniter 3.

