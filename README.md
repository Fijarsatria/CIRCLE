# CIRCLE

Aplikasi web untuk saling berbagi kabar dan lokasi secara sukarela dalam circle orang terdekat. Antarmuka pengguna mengutamakan peta penuh di mobile; admin memakai dashboard desktop.

## Menjalankan

1. Siapkan PHP 8.3+ dengan PDO SQLite, mbstring, fileinfo, dan GD. Aplikasi diuji dengan PHP 8.5.12.
2. Di Windows dengan PHP pada `C:\xampp\php\php.exe`, klik dua kali `MULAI.cmd`.
3. Buka **http://127.0.0.1:8788**.

Atau jalankan dari folder proyek:

```sh
php -d extension=gd -S 127.0.0.1:8788 -t . index.php
```

Tidak membutuhkan Composer, npm, atau pembuatan database manual. Saat pertama dibuka, tabel dibuat dari `database/skema.sql`, lalu akun dan momen contoh ditambahkan. Database lokal serta foto pengguna tidak disertakan di repository.

## Akun demonstrasi lokal

| Peran | Email | Kata sandi |
| --- | --- | --- |
| User | fijar@circle.test | Circle123! |
| Teman | azizah@circle.test | Circle123! |
| Admin | admin@circle.test | Circle123! |

Kredensial tersebut khusus data contoh lokal. Gunakan konfigurasi akun yang sesuai sebelum publikasi.

## Struktur proyek

```text
circle - simple/
├── frontend/          # Halaman Peta/Momen/Circle/Akun, login, dan admin
├── backend/           # Koneksi, pemrosesan formulir, dan API lokasi
├── assets/            # CSS dan JavaScript
├── database/
│   └── skema.sql      # Struktur tabel SQLite
├── uploads/           # Foto privat, dilindungi dan tidak dilacak Git
├── index.php          # Pintu masuk dan pengarah URL
├── MULAI.cmd          # Menjalankan layanan lokal
└── README.txt         # Petunjuk penggunaan dan batasan lebih lengkap
```

URL `login.php`, `admin.php`, `backend.php`, dan `lokasi.php` diteruskan oleh `index.php` ke berkas dalam folder yang sesuai. Buka aplikasi melalui pintu masuk tersebut, bukan langsung membuka berkas internal.

## Fitur

- Guest dapat menjelajahi momen publik tanpa login.
- User dapat membuat circle, mengundang akun terdaftar, menerima/menolak undangan, dan mengatur audiens momen.
- Peta memisahkan lokasi anggota dari tempat posting momen.
- Berbagi lokasi meminta persetujuan, izin browser, dan durasi 15 menit, 1 jam, atau 4 jam. Jeda/Stop mencabut berbagi dan menghapus posisi terbaru.
- Lokasi hanya dibaca anggota circle yang diterima. Role admin tidak memberi akses otomatis ke lokasi atau foto privat.
- Admin meninjau laporan dan mengelola status akun dengan alasan serta catatan audit.

## Batasan

- Implementasi ini memakai PHP native dan SQLite. Integrasi Supabase belum dipasang. Dokumen topik tugas mensyaratkan Laravel; repository ini berisi implementasi PHP sederhana untuk pengembangan dan demonstrasi lokal.
- GPS berjalan saat halaman Peta aktif dan dijeda ketika halaman/aplikasi ditinggalkan. GPS memerlukan HTTPS atau localhost. Akses HTTP alamat laptop dari ponsel tidak cukup untuk menguji GPS.
- Pembaruan sekitar setiap 15 detik; posisi lebih lama dari 2 menit tidak ditampilkan. Tidak ada riwayat perjalanan atau pengiriman SOS. Posisi di peta tidak membuktikan seseorang aman.
- Leaflet 1.9.4 dan tile OpenStreetMap membutuhkan internet. Foto baru dikodekan ulang ke WebP untuk membuang metadata dan membatasi ukuran hasil.
- Apache memerlukan `mod_rewrite`, GD, dan penerapan aturan `.htaccess`. Pengujian aplikasi dilakukan menggunakan server PHP lokal.

## Verifikasi

Sebelum unggahan, pemeriksaan sintaks PHP/JavaScript serta pengujian login, hak akses, formulir, lokasi, foto privat, dan tampilan mobile/desktop telah dilakukan pada salinan data uji terpisah. GPS diuji menggunakan posisi simulasi, belum diuji pada ponsel fisik. Belum ada alur CI otomatis dalam repository.

Penjelasan penggunaan dan perawatan lebih lengkap tersedia di [README.txt](README.txt).
