<?php

// File bersama: koneksi database, sesi login, dan fungsi kecil untuk semua halaman.
// SQLite menyimpan data dalam satu file, jadi belum perlu menyiapkan MySQL.
declare(strict_types=1);

session_name('circle_simple_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

// Lokasi penyimpanan. Variabel lingkungan opsional dipakai hanya untuk pengujian terisolasi.
$folderDatabase = getenv('CIRCLE_DATA_DIR') ?: dirname(__DIR__).'/database';
$folderUpload = getenv('CIRCLE_UPLOAD_DIR') ?: dirname(__DIR__).'/uploads';
if (! is_dir($folderDatabase)) {
    mkdir($folderDatabase, 0755, true);
}
$conn = new PDO('sqlite:'.$folderDatabase.'/circle.sqlite');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$conn->exec('PRAGMA foreign_keys = ON');
$conn->exec('PRAGMA busy_timeout = 5000');

// Tabel dibuat otomatis ketika program dibuka pertama kali.
$conn->exec(file_get_contents(dirname(__DIR__).'/database/skema.sql'));

// Data contoh untuk mencoba program. Tidak berisi lokasi perangkat asli.
if ((int) $conn->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    $conn->beginTransaction();
    $insert = $conn->prepare('INSERT INTO users (name, email, password, role, bio) VALUES (?, ?, ?, ?, ?)');
    $password = password_hash('Circle123!', PASSWORD_DEFAULT);
    $insert->execute(['Fijar', 'fijar@circle.test', $password, 'user', 'Cerita kecil dari Bandung.']);
    $insert->execute(['Azizah', 'azizah@circle.test', $password, 'user', 'Kopi, taman, dan kabar baik.']);
    $insert->execute(['Admin CIRCLE', 'admin@circle.test', $password, 'admin', 'Pengelola CIRCLE.']);
    $conn->exec("INSERT INTO circles (name, owner_id) VALUES ('Circle dekat', 1)");
    $conn->exec("INSERT INTO circle_members VALUES (1, 1, 'accepted'), (1, 2, 'accepted')");
    $insert = $conn->prepare('INSERT INTO moments (user_id, caption, place, audience, theme, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([2, 'Ngopi sebentar, cerita lebih lama.', 'Kedai Senja', 'public', 'coffee', -6.9734, 107.6327, gmdate('Y-m-d H:i:s', time() - 480)]);
    $insert->execute([1, 'Jeda kecil di antara kelas.', 'Taman kampus', 'public', 'garden', -6.9748, 107.6308, gmdate('Y-m-d H:i:s', time() - 1200)]);
    $insert->execute([2, 'Langit sore selalu punya cerita.', 'Bandung', 'public', 'sunset', -6.9704, 107.6351, gmdate('Y-m-d H:i:s', time() - 2400)]);
    $conn->commit();
}

// Escape teks sebelum ditampilkan agar input tidak dianggap sebagai kode HTML.
function e(mixed $teks): string
{
    return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
}
function token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}
function csrf(): void
{
    echo '<input type="hidden" name="csrf" value="'.e(token()).'">';
    // Identitas formulir menjaga POST yang terkirim ulang tidak menggandakan perubahan.
    echo '<input type="hidden" name="request_id" value="'.bin2hex(random_bytes(16)).'">';
}
function query(string $sql, array $data = []): PDOStatement
{
    global $conn;
    $hasil = $conn->prepare($sql);
    $hasil->execute($data);

    return $hasil;
}
function pengguna(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = query("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']])->fetch();
    if (! $user) {
        unset($_SESSION['user_id']);
    }

    return $user ?: null;
}
function wajib_login(): array
{
    $user = pengguna();
    if (! $user) {
        // Setelah login, kembalikan ke tab privat yang tadi dipilih.
        if (in_array($_GET['view'] ?? '', ['circles', 'profile'], true)) {
            $_SESSION['after_login'] = 'index.php?view='.$_GET['view'];
        }
        header('Location: login.php');
        exit;
    }

    return $user;
}
function pindah(string $tujuan, string $pesan, bool $error = false): never
{
    $_SESSION['flash'] = ['text' => $pesan, 'error' => $error];
    header('Location: '.$tujuan, true, 303);
    exit;
}
function flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="notice '.($f['error'] ? 'error' : '').'" role="'.($f['error'] ? 'alert' : 'status').'" tabindex="-1">'.e($f['text']).'</div>';
}
function lama(string $waktu): string
{
    $menit = max(0, (int) ((time() - strtotime($waktu.' UTC')) / 60));

    return $menit < 1 ? 'Baru saja' : ($menit < 60 ? $menit.' menit lalu' : ($menit < 1440 ? intdiv($menit, 60).' jam lalu' : intdiv($menit, 1440).' hari lalu'));
}
function anggota(int $userId, int $circleId): bool
{
    return (bool) query("SELECT 1 FROM circle_members WHERE user_id = ? AND circle_id = ? AND state = 'accepted'", [$userId, $circleId])->fetchColumn();
}

// Pemeriksaan yang sama digunakan untuk feed, pin peta, foto, dan laporan.
// Admin juga tidak otomatis mendapat akses momen circle atau momen pribadi.
function momen_terlihat(?array $user): array
{
    $id = $user['id'] ?? 0;

    return query("SELECT m.*, u.name FROM moments m JOIN users u ON u.id = m.user_id
        WHERE m.status = 'published' AND u.status = 'active'
        AND (m.audience = 'public' OR m.user_id = ? OR (m.audience = 'circle' AND EXISTS (
            SELECT 1 FROM circle_members cm WHERE cm.circle_id = m.circle_id AND cm.user_id = ? AND cm.state = 'accepted'
        ))) ORDER BY m.id DESC LIMIT 30", [$id, $id])->fetchAll();
}
function boleh_momen(int $id, ?array $user): ?array
{
    // Otorisasi satu objek tidak bergantung pada batas 30 item pada feed.
    $userId = $user['id'] ?? 0;
    $m = query("SELECT m.* FROM moments m JOIN users u ON u.id = m.user_id
        WHERE m.id = ? AND m.status = 'published' AND u.status = 'active'
        AND (m.audience = 'public' OR m.user_id = ? OR (m.audience = 'circle' AND EXISTS (
            SELECT 1 FROM circle_members cm WHERE cm.circle_id = m.circle_id AND cm.user_id = ? AND cm.state = 'accepted'
        )))", [$id, $userId, $userId])->fetch();

    return $m ?: null;
}
function ikon(string $nama): string
{
    $path = match ($nama) {
        'map' => '<path d="m9 18-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Zm0 0V3m6 3v15"/>',
        'photo' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 6-6 4 4 3-3 5 5"/>',
        'circle' => '<circle cx="12" cy="8" r="3"/><path d="M6 21v-2a6 6 0 0 1 12 0v2M5 4a3 3 0 0 0-1 6m16-6a3 3 0 0 1 0 6M2 19v-2a4 4 0 0 1 3-4m17 6v-2a4 4 0 0 0-3-4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 22v-2a8 8 0 0 1 16 0v2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'locate' => '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/>',
        'chevron' => '<path d="m6 15 6-6 6 6"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 10-8 10S4 17 4 12V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'flag' => '<path d="M5 22V3c5-4 9 4 14 0v12c-5 4-9-4-14 0"/>',
        'logout' => '<path d="M9 3H4v18h5m5-14 5 5-5 5m-7-5h12"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/>',
        default => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/>',
    };

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
}
function logo(): string
{
    return '<span class="logo-mark"><svg viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="18" fill="currentColor"/><path d="m10 26 10-15 10 15" fill="none" stroke="white" stroke-width="2.5"/><g fill="white"><circle cx="10" cy="26" r="4"/><circle cx="20" cy="11" r="4"/><circle cx="30" cy="26" r="4"/></g></svg></span><span>circle<span class="purple">.</span></span>';
}
function ilustrasi(string $tema): string
{
    $isi = match ($tema) {
        'garden' => '<path d="M0 260 190 110 390 280 610 100 800 230v270H0Z" fill="#9bb79e"/><path d="M0 380 230 220 420 390 660 230 800 340v160H0Z" fill="#587e69"/>',
        'sunset' => '<path d="M0 270 190 180 360 310 600 160 800 230v270H0Z" fill="#bd92aa"/><path d="M0 380 230 260 420 390 660 280 800 340v160H0Z" fill="#7b7199"/>',
        default => '<rect y="320" width="800" height="180" fill="#b17e58"/><rect x="60" y="60" width="240" height="235" rx="110" fill="#c1cfb3"/><path d="M180 60v235M60 180h240" stroke="#fff0d2" stroke-width="10"/><ellipse cx="480" cy="380" rx="135" ry="24" fill="#895b43" opacity=".25"/><path d="M390 230h180v110q-90 85-180 0Z" fill="#fff4de"/><ellipse cx="480" cy="232" rx="90" ry="24" fill="#fff4de"/><ellipse cx="480" cy="235" rx="72" ry="15" fill="#8f6248"/><path d="M570 258c80-15 80 85 0 70" fill="none" stroke="#fff4de" stroke-width="24"/>',
    };
    $bg = ['garden' => '#e8ecd5', 'sunset' => '#f3d1c2', 'coffee' => '#efc9a8'][$tema] ?? '#efc9a8';

    return '<svg class="illustration" viewBox="0 0 800 500" role="img" aria-label="Ilustrasi momen"><rect width="800" height="500" fill="'.$bg.'"/><circle cx="640" cy="100" r="52" fill="#fff1c4"/>'.$isi.'</svg>';
}

// Dipanggil pada logout, keluar circle, dan pembatasan akun: izin dan posisi dicabut bersama.
function hentikan_lokasi(int $userId, ?int $circleId = null): void
{
    $where = 'user_id = ?'.($circleId === null ? '' : ' AND circle_id = ?');
    $params = $circleId === null ? [$userId] : [$userId, $circleId];
    query("UPDATE location_sharing SET status = 'stopped', session_key = '', updated_at = ".time()." WHERE ".$where, $params);
    query('DELETE FROM location_positions WHERE '.$where, $params);
}
