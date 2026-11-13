<?php
require dirname(__DIR__).'/backend/koneksi.php';
$user = pengguna();
if ($user) {
    header('Location: '.($user['role'] === 'admin' ? 'admin.php' : 'index.php'));
    exit;
}
$daftar = ($_GET['mode'] ?? '') === 'register';
$old = $_SESSION['old'] ?? [];
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $daftar ? 'Daftar' : 'Masuk' ?> · CIRCLE</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(dirname(__DIR__).'/assets/style.css') ?>">
</head>

<body class="auth-page">
  <main class="auth-layout" id="main">
    <section class="auth-art"><a class="brand" href="index.php"><?= logo() ?></a>
      <div><span class="eyebrow">CIRCLE UNTUK ORANG TERDEKAT</span>
        <h1>Saling jaga.<br>Tetap<br>terhubung.</h1>
        <p>Lihat kabar dan lokasi orang terdekat,<br>dengan persetujuan dan kendali masing-masing.</p>
      </div>
      <div class="orbits"><span>F</span><span>A</span><span>C</span></div><small>Ceritamu, pilihanmu. Bagikan kepada
        orang yang tepat.</small>
    </section>
    <section class="auth-form"><a class="back-link" href="index.php">← Jelajahi sebagai guest</a>
      <div class="auth-card"><a class="brand auth-brand" href="index.php"><?= logo() ?></a><span class="eyebrow">SELAMAT
          DATANG DI CIRCLE</span>
        <h1><?= $daftar ? 'Mulai cerita barumu.' : 'Senang bertemu lagi.' ?></h1>
        <p class="muted">
          <?= $daftar ? 'Buat akun untuk terhubung dengan orang terdekat.' : 'Masuk untuk melihat circle dan berbagi kabar.' ?>
        </p>
        <?php flash(); ?>
        <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action"
            value="<?= $daftar ? 'register' : 'login' ?>">
          <?php if ($daftar) { ?><label>Nama<input name="name" maxlength="60" required autocomplete="name"
              value="<?= e($old['name'] ?? '') ?>"></label><?php } ?>
          <label>Email<input type="email" name="email" maxlength="255" autocomplete="email" required
              placeholder="nama@email.com" value="<?= e($old['email'] ?? '') ?>"></label>
          <label>Kata sandi<input type="password" name="password" required <?= $daftar ? 'minlength="8"' : '' ?>
              autocomplete="<?= $daftar ? 'new-password' : 'current-password' ?>"></label>
          <?php if ($daftar) { ?><label>Ulangi kata sandi<input type="password" name="confirmation" minlength="8"
              required autocomplete="new-password"></label>
          <p class="small-text muted">Minimal 8 karakter. Akun baru selalu menjadi user.</p><?php } ?>
          <button
            class="button primary full"><?= $daftar ? 'Buat akun' : 'Masuk ke CIRCLE' ?><?= ikon('arrow') ?></button>
        </form>
        <p class="auth-switch"><?= $daftar ? 'Sudah punya akun?' : 'Baru di sini?' ?> <a
            href="login.php<?= $daftar ? '' : '?mode=register' ?>"><?= $daftar ? 'Masuk' : 'Buat akun' ?></a></p>
        <details class="demo">
          <summary>Akun untuk mencoba</summary>
          <p>User: <strong>fijar@circle.test</strong><br>Admin: <strong>admin@circle.test</strong><br>Kata sandi:
            <strong>Circle123!</strong></p><small>Data contoh untuk demonstrasi lokal.</small>
        </details>
      </div><small class="auth-footer">CIRCLE · Kelompok 8 Web Programming</small>
    </section>
  </main>
  <script src="assets/script.js?v=<?= filemtime(dirname(__DIR__).'/assets/script.js') ?>" defer></script>
</body>

</html>
<?php unset($_SESSION['old']); ?>
