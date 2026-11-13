CIRCLE — Saling jaga, tetap terhubung
====================================

CARA MENJALANKAN
1. Klik dua kali MULAI.cmd. PHP XAMPP harus berada di C:\xampp\php\php.exe.
2. Buka http://127.0.0.1:8788. Biarkan jendela layanan tetap terbuka.
3. Tekan Ctrl+C di jendela layanan untuk menghentikan aplikasi.
Data tersimpan otomatis. Tidak perlu Composer, npm, build, atau membuat database manual.

AKSES APLIKASI
Tamu dapat membuka peta momen publik. Circle dan Akun memerlukan login.

SUSUNAN FOLDER
circle - simple/
  index.php               Pintu masuk; mengarahkan halaman dan permintaan.
  MULAI.cmd               Menjalankan web lokal seperti biasa.
  README.txt              Petunjuk penggunaan dan penjelasan program ini.
  frontend/
    halaman.php           Tampilan Peta, Momen, Circle, dan Akun.
    login.php             Tampilan masuk dan daftar.
    admin.php             Tampilan dashboard, laporan, pengguna, dan audit.
  backend/
    koneksi.php           Koneksi SQLite, sesi, data contoh, dan fungsi bersama.
    proses.php            Proses formulir, izin, moderasi, dan pembacaan foto.
    lokasi.php            API berbagi, membaca, menjeda, dan menghentikan lokasi.
  assets/
    style.css             Seluruh tampilan: aturan mobile dahulu, lalu desktop.
    script.js             Interaksi peta, pemilih circle, dialog, filter, dan GPS.
  database/
    skema.sql             Struktur tabel SQLite; dibaca otomatis oleh koneksi.php.
    circle.sqlite         Isi database: akun, circle, momen, izin lokasi, laporan.
  uploads/                Foto momen yang diunggah; aksesnya diperiksa backend.

File .htaccess melindungi database, foto, dan kode internal ketika memakai Apache.
Nama folder menunjukkan fungsinya; tidak ada folder framework atau dependensi.

CARA MEMBACA ALUR PROGRAM
Browser membuka index.php -> tampilan dalam frontend/ ditampilkan.
Form dikirim ke backend.php -> index.php meneruskan ke backend/proses.php.
Peta meminta lokasi.php -> index.php meneruskan ke backend/lokasi.php.
Proses memakai backend/koneksi.php -> database/circle.sqlite.
Tampilan memakai assets/style.css dan assets/script.js.
Foto dibaca melalui backend setelah izin diperiksa, bukan lewat URL uploads/.

URL login.php, admin.php, backend.php, dan lokasi.php tetap dapat dipakai supaya
tautan serta formulir lama tidak rusak. File aslinya sudah dipisahkan ke folder;
tidak ada file duplikat di depan. Jalankan dari MULAI.cmd/index.php, jangan membuka
file dalam frontend/ atau backend/ langsung dari alamat browser.

.env belum diperlukan karena database SQLite tidak memakai kredensial layanan luar.
Jika nanti memakai layanan cloud, simpan rahasia di server, jangan di JavaScript.

ALUR UTAMA
Peta Anggota: pilih circle -> buka daftar anggota -> ketuk anggota dengan lokasi tersedia.
Berbagi: Bagikan lokasi saya -> pilih durasi -> centang persetujuan -> izinkan GPS browser.
Jeda menghentikan pembacaan dan menghapus posisi terakhir. Lanjutkan meminta persetujuan lagi.
Stop mengakhiri sesi. Durasi: 15 menit, 1 jam, atau 4 jam (awal 1 jam).
Peta Momen: pin menunjukkan tempat posting, bukan posisi perangkat yang bergerak.
Circle: buat circle -> pemilik undang email akun terdaftar -> teman Terima/Tolak.
Momen: ilustrasi/foto + caption -> pilih Hanya saya, Circle, atau Publik -> bagikan.
Admin: tinjau laporan -> simpan keputusan dan alasan; pembatasan akun juga dicatat.

BATASAN YANG PERLU DIPAHAMI
- GPS berjalan pada halaman peta yang aktif; berpindah halaman/aplikasi menjeda berbagi.
- GPS memerlukan HTTPS, kecuali localhost/127.0.0.1 pada komputer sendiri.
  Membuka alamat HTTP laptop dari ponsel bukan pengujian GPS yang memadai.
- Pengiriman dan pembacaan status setiap sekitar 15 detik; posisi lebih lama dari
  2 menit disembunyikan. Akurasi di atas 100 meter ditandai Perkiraan.
- Titik hanya tersedia bagi anggota accepted pada circle yang dipilih. Admin tidak
  memperoleh akses lokasi karena role. Logout, keluar circle, dan pembatasan akun mencabut berbagi.
- Jika jaringan putus saat Jeda/Stop, GPS lokal berhenti segera. Aplikasi menampilkan
  bahwa penghapusan server belum terkonfirmasi; posisi tidak ditampilkan setelah kedaluwarsa.
- Hanya posisi terbaru disimpan; tidak ada riwayat perjalanan atau pengiriman SOS.
  Posisi di peta tidak membuktikan seseorang aman dan tidak menggantikan layanan darurat.
- Foto maksimal 5 MB, dimensi maksimal 6000 px. Foto baru dikodekan ulang ke WebP
  maksimal 1800 px agar ringan dan metadata EXIF/GPS tidak ikut tersimpan.
- Latar peta memakai Leaflet 1.9.4 dan OpenStreetMap sehingga membutuhkan internet.
  Daftar anggota dan Momen tetap tersedia bila layanan peta gagal dimuat.
- Daftar momen memuat 30 item terbaru yang diizinkan. Admin memuat 30 laporan
  terbaru, seluruh pengguna untuk pencarian lokal, dan 10 keputusan terakhir.

HUBUNGAN DENGAN TUGAS BESAR / SUPABASE
Folder ini adalah aplikasi PHP native dengan SQLite untuk pengembangan sederhana
dan demonstrasi lokal. Login memakai sesi PHP + password_hash, bukan Supabase Auth.
Supabase/PostgreSQL/Storage dan konfirmasi email belum terhubung.
Dokumen topik awal meminta Laravel; folder circle tetap disimpan sebagai acuan Laravel.
Jangan menghapus Composer/public/config dari Laravel karena diperlukan framework tersebut.
ERD Supabase di PRD tetap menjadi rancangan integrasi lanjutan, bukan database yang
sudah dipasang di aplikasi ini. Migrasi akan memerlukan pemetaan ID dan aturan RLS.

PERAWATAN
Salin folder database dan uploads sebagai cadangan saat layanan berhenti. Jangan hapus circle.sqlite
untuk merapikan folder karena di dalamnya ada akun dan konten.
Untuk memakai Apache, GD harus aktif dan mod_rewrite dan aturan .htaccess
harus benar-benar diberlakukan. MULAI.cmd sudah memakai router yang menolak akses internal.
Gunakan HTTPS dan nonaktifkan akun contoh sebelum mempublikasikan aplikasi.
