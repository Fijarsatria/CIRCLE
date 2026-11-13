<?php
// Pintu masuk aplikasi dan router kecil untuk MULAI.cmd maupun Apache.
// URL tetap singkat; file tampilan dan proses disimpan dalam folder masing-masing.
declare(strict_types=1);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (PHP_SAPI !== 'cli-server') {
    // Mendukung aplikasi yang ditempatkan dalam subfolder htdocs.
    $base = rtrim(str_replace('\\', '/', dirname(rawurldecode($_SERVER['SCRIPT_NAME']))), '/');
    if ($base !== '' && $base !== '.' && str_starts_with($path, $base.'/')) {
        $path = substr($path, strlen($base));
    }
}

// Hanya dua aset ini yang boleh dibaca langsung oleh server lokal.
if (PHP_SAPI === 'cli-server' && in_array($path, ['/assets/style.css', '/assets/script.js'], true)) {
    return false;
}

$halaman = [
    '/' => 'frontend/halaman.php',
    '/index.php' => 'frontend/halaman.php',
    '/login.php' => 'frontend/login.php',
    '/admin.php' => 'frontend/admin.php',
    '/backend.php' => 'backend/proses.php',
    '/lokasi.php' => 'backend/lokasi.php',
];

if (!isset($halaman[$path])) {
    http_response_code(404);
    exit('Halaman tidak tersedia.');
}

require __DIR__.'/'.$halaman[$path];
