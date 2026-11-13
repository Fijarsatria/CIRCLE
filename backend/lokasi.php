<?php
// API pemantauan: semua posisi diperiksa ulang di server, bukan disembunyikan lewat CSS.
declare(strict_types=1);
require __DIR__.'/koneksi.php';
header('Content-Type: application/json; charset=utf-8');

function jawab(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$user = pengguna();
if (!$user) jawab(['error' => 'Sesi berakhir. Masuk kembali untuk membuka circle.'], 401);
$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? json_decode(file_get_contents('php://input'), true) : $_GET;
if (!is_array($input)) jawab(['error' => 'Permintaan tidak valid.'], 422);
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) jawab(['error' => 'Metode tidak tersedia.'], 405);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(token(), (string)($input['csrf'] ?? ''))) {
    jawab(['error' => 'Formulir kedaluwarsa. Muat ulang halaman.'], 419);
}
$circle = filter_var($input['circle_id'] ?? null, FILTER_VALIDATE_INT);
if (!$circle || !anggota((int)$user['id'], $circle)) jawab(['error' => 'Circle tidak tersedia untuk akun ini.'], 403);
$now = time();
$action = $_SERVER['REQUEST_METHOD'] === 'GET' ? 'read' : ($input['action'] ?? '');

try {
    // Posisi yang lama dibersihkan; program hanya menyimpan posisi terbaru, bukan perjalanan.
    query('DELETE FROM location_positions WHERE received_at < ? OR EXISTS (
        SELECT 1 FROM location_sharing s WHERE s.circle_id = location_positions.circle_id
        AND s.user_id = location_positions.user_id AND (s.status <> \'active\' OR s.expires_at <= ?))', [$now - 120, $now]);

    if ($action === 'read') {
        $rows = query("SELECT u.id, u.name, s.status, s.expires_at, s.updated_at,
            p.latitude, p.longitude, p.accuracy_m, p.received_at
            FROM circle_members cm JOIN users u ON u.id = cm.user_id
            LEFT JOIN location_sharing s ON s.circle_id = cm.circle_id AND s.user_id = cm.user_id
            LEFT JOIN location_positions p ON p.circle_id = cm.circle_id AND p.user_id = cm.user_id
            WHERE cm.circle_id = ? AND cm.state = 'accepted' AND u.status = 'active'
            ORDER BY u.id", [$circle])->fetchAll();
        $members = [];
        foreach ($rows as $r) {
            $state = $r['status'] ?? 'stopped';
            if ($state === 'active' && (int)$r['expires_at'] <= $now) $state = 'expired';
            $fresh = $state === 'active' && $r['received_at'] !== null && (int)$r['received_at'] >= $now - 120;
            $member = ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $state,
                'fresh' => $fresh, 'expires_at' => (int)($r['expires_at'] ?? 0)];
            // Tidak mengirim koordinat null/palsu atau koordinat lama ke browser.
            if ($fresh) $member += ['latitude' => (float)$r['latitude'], 'longitude' => (float)$r['longitude'],
                'accuracy_m' => (float)$r['accuracy_m'], 'received_at' => (int)$r['received_at']];
            $members[] = $member;
        }
        $mine = query('SELECT status, expires_at, session_key FROM location_sharing WHERE circle_id = ? AND user_id = ?', [$circle, $user['id']])->fetch();
        jawab(['members' => $members, 'mine' => $mine ?: null, 'server_time' => $now]);
    }

    // Satu transaksi menjaga pemeriksaan keanggotaan dan perubahan izin tetap utuh.
    $conn->beginTransaction();
    if (!anggota((int)$user['id'], $circle)) throw new DomainException('Keanggotaan sudah berubah.');
    if ($action === 'start') {
        $duration = (int)($input['duration'] ?? 0);
        if (($input['consent'] ?? false) !== true || !in_array($duration, [900, 3600, 14400], true)) {
            throw new DomainException('Pilih durasi dan setujui berbagi lokasi.');
        }
        // Session key membedakan sesi lama dengan sesi baru. Update terlambat tidak dapat menghidupkan sesi lama.
        $key = bin2hex(random_bytes(24));
        query("INSERT INTO location_sharing (circle_id, user_id, status, expires_at, session_key, updated_at)
            VALUES (?, ?, 'active', ?, ?, ?) ON CONFLICT(circle_id, user_id) DO UPDATE
            SET status = 'active', expires_at = excluded.expires_at, session_key = excluded.session_key, updated_at = excluded.updated_at",
            [$circle, $user['id'], $now + $duration, $key, $now]);
        query('DELETE FROM location_positions WHERE circle_id = ? AND user_id = ?', [$circle, $user['id']]);
        $conn->commit();
        jawab(['key' => $key, 'expires_at' => $now + $duration]);
    }
    $share = query('SELECT * FROM location_sharing WHERE circle_id = ? AND user_id = ?', [$circle, $user['id']])->fetch();
    if (!$share || !hash_equals((string)$share['session_key'], (string)($input['key'] ?? ''))) {
        $conn->rollBack();
        jawab(['error' => 'Sesi berbagi berubah. Muat ulang status sebelum mencoba lagi.'], 409);
    }
    if (in_array($action, ['pause', 'stop'], true)) {
        $state = $action === 'pause' ? 'paused' : 'stopped';
        query('UPDATE location_sharing SET status = ?, session_key = ?, updated_at = ? WHERE circle_id = ? AND user_id = ?', [$state, '', $now, $circle, $user['id']]);
        query('DELETE FROM location_positions WHERE circle_id = ? AND user_id = ?', [$circle, $user['id']]);
        $conn->commit();
        jawab(['status' => $state]);
    }
    if ($action !== 'update') throw new DomainException('Tindakan tidak dikenal.');
    if ($share['status'] !== 'active' || (int)$share['expires_at'] <= $now) {
        $conn->rollBack();
        jawab(['error' => 'Berbagi lokasi tidak aktif. Pilih Bagikan untuk memulai kembali.'], 409);
    }
    $values = [];
    foreach (['latitude', 'longitude', 'accuracy_m', 'observed_at'] as $field) {
        if (!isset($input[$field]) || !is_numeric($input[$field]) || !is_finite((float)$input[$field])) throw new DomainException('Data lokasi tidak lengkap.');
        $values[$field] = (float)$input[$field];
    }
    if (abs($values['latitude']) > 90 || abs($values['longitude']) > 180 || $values['accuracy_m'] < 0
        || abs($values['observed_at'] - $now) > 120) throw new DomainException('Posisi tidak valid atau terlalu lama. Ambil posisi terbaru.');
    $last = query('SELECT received_at FROM location_positions WHERE circle_id = ? AND user_id = ?', [$circle, $user['id']])->fetchColumn();
    if ($last && $now - (int)$last < 10) {
        $conn->rollBack();
        jawab(['error' => 'Tunggu sebentar sebelum mengirim posisi berikutnya.'], 429);
    }
    query('INSERT INTO location_positions VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(circle_id, user_id) DO UPDATE
        SET latitude = excluded.latitude, longitude = excluded.longitude, accuracy_m = excluded.accuracy_m,
        observed_at = excluded.observed_at, received_at = excluded.received_at',
        [$circle, $user['id'], $values['latitude'], $values['longitude'], $values['accuracy_m'], (int)$values['observed_at'], $now]);
    $conn->commit();
    jawab(['status' => 'active', 'received_at' => $now]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($error instanceof DomainException) jawab(['error' => $error->getMessage()], 422);
    error_log('CIRCLE lokasi: '.$error->getMessage());
    jawab(['error' => 'Status belum tersimpan. Periksa koneksi dan coba lagi.'], 503);
}
