<?php

// Semua proses yang mengubah data ditangani di sini, bukan di JavaScript.
require __DIR__.'/koneksi.php';
// $folderUpload ditetapkan di koneksi.php; foto tidak dilayani sebagai aset publik.
$user = pengguna();

// Foto hanya bisa dibaca jika pengunjung berhak melihat momennya.
if (isset($_GET['photo'])) {
    $momen = boleh_momen((int) $_GET['photo'], $user);
    $file = $momen && $momen['photo'] ? $folderUpload.'/'.basename($momen['photo']) : '';
    if (! $file || ! is_file($file)) {
        http_response_code(404);
        exit('Foto tidak tersedia.');
    }
    header('Content-Type: '.mime_content_type($file));
    session_write_close();
    readfile($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Gunakan formulir aplikasi.');
}
if (! hash_equals(token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(419);
    exit('Formulir kedaluwarsa. Kembali dan muat ulang halaman.');
}
$aksi = (string) ($_POST['action'] ?? '');
$tujuan = in_array($aksi, ['login', 'register'], true) ? 'login.php'.($aksi === 'register' ? '?mode=register' : '') : 'index.php?view=moments';
if (in_array($aksi, ['create_circle', 'invite', 'respond', 'leave'], true)) {
    $tujuan = 'index.php?view=circles';
}
if ($aksi === 'profile') {
    $tujuan = 'index.php?view=profile';
}
if (in_array($aksi, ['moderate', 'status'], true)) {
    $tujuan = 'admin.php';
}
// Sesi PHP mengunci permintaan serentak dari akun yang sama. Simpan ID formulir
// sebelum memproses supaya klik/retry ganda hanya melakukan satu perubahan.
$requestId = (string)($_POST['request_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
    http_response_code(422);
    exit('Formulir tidak lengkap. Kembali dan muat ulang halaman.');
}
$processed = $_SESSION['processed_forms'] ?? [];
if (isset($processed[$requestId])) pindah($tujuan, 'Formulir ini sudah diproses. Periksa hasil sebelum mengirim lagi.');
$processed[$requestId] = time();
$_SESSION['processed_forms'] = array_slice($processed, -100, null, true);
$_SESSION['old'] = array_intersect_key($_POST, array_flip(['name', 'email', 'caption', 'place', 'audience', 'circle_id', 'latitude', 'longitude', 'theme']));
$fotoBaru = null;

try {
    if ($aksi === 'register') {
        $nama = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($nama === '' || mb_strlen($nama) > 60) {
            throw new DomainException('Isi nama antara 1 dan 60 karakter.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new DomainException('Isi alamat email yang valid.');
        }
        if (strlen($password) < 8 || $password !== ($_POST['confirmation'] ?? '')) {
            throw new DomainException('Kata sandi minimal 8 karakter dan harus sama dengan konfirmasinya.');
        }
        if (query('SELECT id FROM users WHERE email = ?', [$email])->fetch()) {
            throw new DomainException('Email sudah digunakan. Silakan masuk atau gunakan email lain.');
        }
        // Role sengaja ditulis tetap: pendaftar tidak boleh memilih menjadi admin.
        query("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')", [$nama, $email, password_hash($password, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $conn->lastInsertId();
        unset($_SESSION['old']);
        pindah('index.php', 'Akun siap. Selamat datang di CIRCLE!');
    }

    if ($aksi === 'login') {
        $percobaan = $_SESSION['login_attempts'] ?? ['count' => 0, 'time' => time()];
        if (time() - $percobaan['time'] > 60) {
            $percobaan = ['count' => 0, 'time' => time()];
        }
        if ($percobaan['count'] >= 6) {
            throw new DomainException('Terlalu banyak percobaan. Coba lagi satu menit kemudian.');
        }
        $_SESSION['login_attempts'] = ['count' => $percobaan['count'] + 1, 'time' => $percobaan['time']];
        $akun = query("SELECT * FROM users WHERE email = ? AND status = 'active'", [strtolower(trim($_POST['email'] ?? ''))])->fetch();
        if (! $akun || ! password_verify($_POST['password'] ?? '', $akun['password'])) {
            throw new DomainException('Email atau kata sandi tidak sesuai, atau akun sedang dibatasi.');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $akun['id'];
        unset($_SESSION['old'], $_SESSION['login_attempts']);
        $lanjut = $_SESSION['after_login'] ?? 'index.php';
        unset($_SESSION['after_login']);
        pindah($akun['role'] === 'admin' ? 'admin.php' : $lanjut, 'Berhasil masuk. Senang bertemu lagi!');
    }

    $user = wajib_login();
    if ($aksi === 'logout') {
        $conn->beginTransaction();
        hentikan_lokasi((int)$user['id']);
        $conn->commit();
        unset($_SESSION['user_id'], $_SESSION['old']);
        session_regenerate_id(true);
        pindah('index.php', 'Kamu sudah keluar dari akun.');
    }

    if ($aksi === 'post') {
        $caption = trim($_POST['caption'] ?? '');
        $place = trim($_POST['place'] ?? '');
        $audience = $_POST['audience'] ?? 'self';
        $circleId = (int) ($_POST['circle_id'] ?? 0);
        if ($caption === '' || mb_strlen($caption) > 500) {
            throw new DomainException('Caption wajib diisi, maksimal 500 karakter.');
        }
        if (mb_strlen($place) > 80) {
            throw new DomainException('Nama tempat maksimal 80 karakter.');
        }
        if (! in_array($audience, ['public', 'circle', 'self'], true)) {
            throw new DomainException('Audiens tidak valid.');
        }
        if ($audience === 'circle' && ! anggota($user['id'], $circleId)) {
            throw new DomainException('Pilih circle yang sudah kamu ikuti.');
        }
        $lat = trim($_POST['latitude'] ?? '');
        $lon = trim($_POST['longitude'] ?? '');
        if (($lat === '') !== ($lon === '') || ($lat !== '' && (! is_numeric($lat) || ! is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180))) {
            throw new DomainException('Isi pasangan koordinat yang valid atau kosongkan keduanya.');
        }
        $theme = in_array($_POST['theme'] ?? '', ['coffee', 'garden', 'sunset'], true) ? $_POST['theme'] : 'coffee';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['photo'];
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024) {
                throw new DomainException('Unggah foto JPG, PNG, atau WebP maksimal 5 MB.');
            }
            $info = @getimagesize($upload['tmp_name']);
            $format = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (! $info || ! isset($format[$info['mime']]) || $info[0] > 6000 || $info[1] > 6000) {
                throw new DomainException('Foto tidak valid atau dimensinya melebihi 6000 piksel.');
            }
            if (! is_dir($folderUpload)) {
                mkdir($folderUpload, 0755, true);
            }
            $fotoBaru = bin2hex(random_bytes(16)).'.'.$format[$info['mime']];
            // Encode ulang membuang EXIF/GPS dan isi tambahan pada file gambar.
            if (!function_exists('imagecreatefromstring')) throw new DomainException('Pemroses foto belum aktif. Jalankan aplikasi dengan MULAI.cmd atau gunakan ilustrasi.');
            $gambar = @imagecreatefromstring(file_get_contents($upload['tmp_name']));
            if (!$gambar) throw new DomainException('Foto tidak dapat diproses.');
            // Batasi ukuran hasil agar daftar dan foto di ponsel tetap ringan.
            $skala = min(1, 1800 / max(imagesx($gambar), imagesy($gambar)));
            $hasil = imagescale($gambar, max(1, (int)(imagesx($gambar)*$skala)), max(1, (int)(imagesy($gambar)*$skala)));
            $fotoBaru = pathinfo($fotoBaru, PATHINFO_FILENAME).'.webp';
            if (!$hasil || !imagewebp($hasil, $folderUpload.'/'.$fotoBaru, 84)) throw new RuntimeException('Foto belum tersimpan.');
        }
        query('INSERT INTO moments (user_id, circle_id, caption, place, audience, theme, photo, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$user['id'], $audience === 'circle' ? $circleId : null, $caption, $place, $audience, $theme, $fotoBaru, $lat === '' ? null : (float) $lat, $lon === '' ? null : (float) $lon, gmdate('Y-m-d H:i:s')]);
        $fotoBaru = null; // File sudah menjadi bagian dari momen yang tersimpan.
        unset($_SESSION['old']);
        pindah($tujuan, 'Momen berhasil dibagikan.');
    }

    if ($aksi === 'delete') {
        $hasil = query("UPDATE moments SET status = 'deleted' WHERE id = ? AND user_id = ?", [(int) ($_POST['id'] ?? 0), $user['id']]);
        if (! $hasil->rowCount()) {
            throw new DomainException('Momen tidak ditemukan atau bukan milikmu.');
        }
        pindah($tujuan, 'Momen dihapus dari tampilan.');
    }

    if ($aksi === 'create_circle') {
        $nama = trim($_POST['name'] ?? '');
        if ($nama === '' || mb_strlen($nama) > 60) {
            throw new DomainException('Isi nama circle, maksimal 60 karakter.');
        }
        $conn->beginTransaction();
        query('INSERT INTO circles (name, owner_id) VALUES (?, ?)', [$nama, $user['id']]);
        query("INSERT INTO circle_members VALUES (?, ?, 'accepted')", [$conn->lastInsertId(), $user['id']]);
        $conn->commit();
        pindah($tujuan, 'Circle baru berhasil dibuat.');
    }

    if ($aksi === 'invite') {
        $id = (int) ($_POST['id'] ?? 0);
        if (! query('SELECT id FROM circles WHERE id = ? AND owner_id = ?', [$id, $user['id']])->fetch()) {
            throw new DomainException('Hanya pemilik yang dapat mengundang anggota.');
        }
        $teman = query("SELECT id FROM users WHERE email = ? AND status = 'active' AND role = 'user'", [strtolower(trim($_POST['email'] ?? ''))])->fetch();
        if (! $teman || $teman['id'] === $user['id']) {
            throw new DomainException('Gunakan email akun teman yang sudah terdaftar.');
        }
        if (anggota($teman['id'], $id)) {
            throw new DomainException('Teman ini sudah bergabung.');
        }
        query("INSERT INTO circle_members VALUES (?, ?, 'invited') ON CONFLICT(circle_id, user_id) DO UPDATE SET state = 'invited'", [$id, $teman['id']]);
        pindah($tujuan, 'Undangan terkirim. Temanmu bisa menerima dari halaman Circle.');
    }

    if ($aksi === 'respond') {
        $state = ($_POST['accept'] ?? '') === '1' ? 'accepted' : 'declined';
        $hasil = query("UPDATE circle_members SET state = ? WHERE circle_id = ? AND user_id = ? AND state = 'invited'", [$state, (int) ($_POST['id'] ?? 0), $user['id']]);
        if (! $hasil->rowCount()) {
            throw new DomainException('Undangan sudah tidak tersedia.');
        }
        pindah($tujuan, $state === 'accepted' ? 'Kamu bergabung ke circle. Lokasi perangkat tetap tidak dibagikan.' : 'Undangan ditolak.');
    }

    if ($aksi === 'leave') {
        $id = (int) ($_POST['id'] ?? 0);
        if (query('SELECT id FROM circles WHERE id = ? AND owner_id = ?', [$id, $user['id']])->fetch()) {
            throw new DomainException('Pemilik tetap berada dalam circle yang dikelolanya.');
        }
        $conn->beginTransaction();
        query("UPDATE circle_members SET state = 'left' WHERE circle_id = ? AND user_id = ?", [$id, $user['id']]);
        hentikan_lokasi((int)$user['id'], $id);
        $conn->commit();
        pindah($tujuan, 'Kamu sudah keluar. Momen privat circle tidak dapat diakses lagi.');
    }

    if ($aksi === 'profile') {
        $nama = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        if ($nama === '' || mb_strlen($nama) > 60 || mb_strlen($bio) > 160) {
            throw new DomainException('Nama wajib diisi maksimal 60 karakter; bio maksimal 160 karakter.');
        }
        query('UPDATE users SET name = ?, bio = ? WHERE id = ?', [$nama, $bio, $user['id']]);
        pindah('index.php?view=profile', 'Profil berhasil disimpan.');
    }

    if ($aksi === 'report') {
        $id = (int) ($_POST['id'] ?? 0);
        $alasan = trim($_POST['reason'] ?? '');
        if (! boleh_momen($id, $user)) {
            throw new DomainException('Momen tidak tersedia.');
        }
        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 200) {
            throw new DomainException('Alasan laporan antara 5 dan 200 karakter.');
        }
        if (query("SELECT 1 FROM reports WHERE reporter_id = ? AND moment_id = ? AND state = 'open'", [$user['id'], $id])->fetchColumn()) throw new DomainException('Laporanmu untuk momen ini masih ditinjau.');
        query('INSERT INTO reports (reporter_id, moment_id, reason, created_at) VALUES (?, ?, ?, ?)', [$user['id'], $id, $alasan, gmdate('Y-m-d H:i:s')]);
        pindah($tujuan, 'Laporan diterima untuk ditinjau admin.');
    }

    // Panel admin mempunyai proses sendiri; user tidak cukup hanya mengetahui URL-nya.
    if (in_array($aksi, ['moderate', 'status'], true)) {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Akses admin diperlukan.');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $alasan = trim($_POST['reason'] ?? '');
        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 500) {
            throw new DomainException('Isi alasan keputusan antara 5 dan 500 karakter.');
        }
        $conn->beginTransaction();
        if ($aksi === 'moderate') {
            $laporan = query("SELECT * FROM reports WHERE id = ? AND state = 'open'", [$id])->fetch();
            if (! $laporan) {
                throw new DomainException('Laporan sudah ditangani atau tidak tersedia.');
            }
            if (!in_array($_POST['decision'] ?? '', ['hide', 'dismiss'], true)) throw new DomainException('Pilih keputusan yang valid.');
            $hide = $_POST['decision'] === 'hide';
            if ($hide) {
                query("UPDATE moments SET status = 'hidden' WHERE id = ? AND status = 'published'", [$laporan['moment_id']]);
            }
            query('UPDATE reports SET state = ?, resolution = ? WHERE id = ?', [$hide ? 'resolved' : 'dismissed', $alasan, $id]);
            $catatan = ($hide ? 'Sembunyikan momen' : 'Tutup laporan').' · laporan #'.$id;
        } else {
            $akun = query("SELECT * FROM users WHERE id = ? AND role = 'user'", [$id])->fetch();
            if (! $akun) {
                throw new DomainException('Akun ini tidak dapat diubah melalui tindakan umum.');
            }
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'suspended';
            query('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
            if ($status === 'suspended') hentikan_lokasi($id);
            $catatan = ($status === 'active' ? 'Aktifkan akun ' : 'Batasi akun ').$akun['name'];
        }
        query('INSERT INTO audit (actor_id, action, reason, created_at) VALUES (?, ?, ?, ?)', [$user['id'], $catatan, $alasan, gmdate('Y-m-d H:i:s')]);
        $conn->commit();
        pindah('admin.php', 'Keputusan berhasil disimpan.');
    }
    throw new DomainException('Tindakan tidak dikenal.');
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($fotoBaru) {
        @unlink($folderUpload.'/'.$fotoBaru);
    }
    if (! ($error instanceof DomainException)) {
        error_log($error->getMessage());
    }
    $pesan = $error instanceof DomainException ? $error->getMessage() : 'Perubahan belum tersimpan. Silakan coba lagi.';
    if ($aksi === 'post') {
        $tujuan .= '&compose=1';
    }
    pindah($tujuan, $pesan, true);
}
