<?php
require dirname(__DIR__).'/backend/koneksi.php';
$user = wajib_login();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    exit('Halaman ini khusus admin. <a href="index.php">Kembali ke CIRCLE</a>');
}
$laporan = query('SELECT r.*, m.caption, u.name AS reporter FROM reports r JOIN moments m ON m.id = r.moment_id JOIN users u ON u.id = r.reporter_id ORDER BY r.id DESC LIMIT 30')->fetchAll();
$pengguna = query('SELECT id, name, role, status FROM users ORDER BY id')->fetchAll();
$audit = query('SELECT a.*, u.name FROM audit a JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 10')->fetchAll();
$statistik = [
    ['Akun terdaftar', count($pengguna), 'user'],
    ['Circle dibuat', query('SELECT COUNT(*) FROM circles')->fetchColumn(), 'circle'],
    ['Momen terbit', query("SELECT COUNT(*) FROM moments m JOIN users u ON u.id = m.user_id WHERE m.status = 'published' AND u.status = 'active'")->fetchColumn(), 'photo'],
    ['Laporan terbuka', query("SELECT COUNT(*) FROM reports WHERE state = 'open'")->fetchColumn(), 'flag'],
];
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · CIRCLE</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(dirname(__DIR__).'/assets/style.css') ?>">
</head>

<body class="admin-page"><a class="skip" href="#main">Langsung ke isi</a>
  <aside class="sidebar admin-sidebar"><a class="brand" href="admin.php"><?= logo() ?></a><span class="eyebrow">ADMIN
      WORKSPACE</span>
    <nav aria-label="Navigasi admin"><a class="nav-link active" href="#overview"><?= ikon('map') ?>Ringkasan</a><a
        class="nav-link" href="#reports"><?= ikon('flag') ?>Laporan konten</a><a class="nav-link"
        href="#users"><?= ikon('user') ?>Pengguna</a><a class="nav-link" href="#audit"><?= ikon('check') ?>Log
        keputusan</a></nav>
    <div class="sidebar-bottom">
      <div class="privacy-card"><?= ikon('shield') ?><strong>Moderasi yang terukur.</strong>
        <p>Admin tidak otomatis membuka semua momen privat.</p>
      </div><a class="nav-link" href="index.php">← Kembali ke aplikasi</a>
      <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action" value="logout"><button
          class="nav-link"><?= ikon('logout') ?>Keluar</button></form>
    </div>
  </aside>
  <div class="workspace">
    <header class="topbar"><a class="brand mobile-brand" href="admin.php"><?= logo() ?></a><span
        class="breadcrumb">Workspace <span>/</span> Admin</span><span class="admin-identity">Admin CIRCLE <span
          class="avatar small">A</span></span></header>
    <details class="admin-mobile-menu">
      <summary>Menu admin <?= ikon('chevron') ?></summary>
      <nav aria-label="Navigasi admin layar kecil"><a href="#overview">Ringkasan</a><a href="#reports">Laporan</a><a href="#users">Pengguna</a><a href="#audit">Log keputusan</a><a href="index.php">Buka aplikasi</a></nav>
      <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action" value="logout"><button class="button secondary full">Keluar akun</button></form>
    </details>
    <main id="main" class="content admin-content">
      <?php flash(); ?>
      <section class="page-heading" id="overview">
        <div><span class="eyebrow">KELOLA DENGAN KEPEDULIAN</span>
          <h1>Jaga circle tetap nyaman<span class="purple">.</span></h1>
          <p>Tinjau laporan, kelola akun, dan lihat hasil keputusan.</p>
        </div><a class="button secondary" href="index.php">Buka aplikasi <?= ikon('arrow') ?></a>
      </section>
      <div class="stats-grid"><?php foreach ($statistik as [$label, $jumlah, $icon]) { ?><article class="stat-card">
          <?= ikon($icon) ?><span><?= e($label) ?></span><strong><?= $jumlah ?></strong><small>Diperbarui dari aktivitas
            aplikasi</small></article><?php } ?></div>
      <div class="admin-note"><?= ikon('shield') ?><div><strong>Privasi tetap jadi batas.</strong>
          <p>Panel hanya menampilkan konteks momen yang dilaporkan. Foto privat dan lokasi perangkat tidak ditampilkan.
          </p>
        </div>
      </div>
      <section class="panel admin-panel" id="reports">
        <div class="section-heading">
          <div>
            <h2>Antrean laporan</h2>
            <p class="muted small-text">Tinjau konteks dan simpan alasan keputusan.</p>
          </div><span class="badge">Moderasi</span>
        </div>
        <?php if (! $laporan) { ?><div class="empty-state compact-empty"><?= ikon('check') ?><h3>Belum ada laporan.</h3>
          <p>Laporan baru akan muncul di sini.</p>
        </div>
        <?php } else { ?><div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Laporan</th>
                <th>Konten dan alasan</th>
                <th>Status</th>
                <th>Keputusan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($laporan as $item) { ?><tr>
                <td><strong>#<?= $item['id'] ?></strong><small><?= e($item['reporter']) ?></small></td>
                <td>
                  <strong><?= e(mb_strimwidth($item['caption'], 0, 130, '…')) ?></strong><small><?= e($item['reason']) ?></small>
                </td>
                <td><span
                    class="badge <?= $item['state'] === 'open' ? 'warning' : 'success' ?>"><?= $item['state'] === 'open' ? 'Terbuka' : ($item['state'] === 'dismissed' ? 'Ditolak' : 'Ditangani') ?></span>
                </td>
                <td>
                  <?php if ($item['state'] === 'open') { ?><button class="button secondary compact"
                    data-admin-report="<?= $item['id'] ?>">Tinjau
                    laporan</button><?php } else { ?><small><?= e($item['resolution']) ?></small><?php } ?>
                </td>
              </tr><?php } ?>
            </tbody>
          </table>
        </div><?php } ?>
      </section>
      <section class="panel admin-panel" id="users">
        <div class="section-heading">
          <div>
            <h2>Pengguna</h2>
            <p class="muted small-text">Perubahan status selalu membutuhkan alasan.</p>
          </div><label class="search-label"><span class="sr-only">Cari nama pengguna</span><input id="user-search"
              placeholder="Cari nama pengguna…"></label>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Pengguna</th>
                <th>Level</th>
                <th>Status</th>
                <th>Tindakan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pengguna as $akun) { ?><tr data-user-row="<?= e(mb_strtolower($akun['name'])) ?>">
                <td><span class="user-cell"><span
                      class="avatar small"><?= e(mb_substr($akun['name'], 0, 1)) ?></span><strong><?= e($akun['name']) ?></strong></span>
                </td>
                <td><?= $akun['role'] === 'admin' ? 'Admin' : 'User' ?></td>
                <td><span
                    class="badge <?= $akun['status'] === 'active' ? 'success' : 'warning' ?>"><?= $akun['status'] === 'active' ? 'Aktif' : 'Dibatasi' ?></span>
                </td>
                <td>
                  <?php if ($akun['role'] !== 'admin') { ?><button class="button secondary compact"
                    data-admin-user="<?= $akun['id'] ?>" data-name="<?= e($akun['name']) ?>"
                    data-status="<?= $akun['status'] === 'active' ? 'suspended' : 'active' ?>"><?= $akun['status'] === 'active' ? 'Batasi akun' : 'Aktifkan kembali' ?></button><?php } else { ?><small
                    class="muted">Akun pengelola</small><?php } ?>
                </td>
              </tr><?php } ?>
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel admin-panel" id="audit">
        <h2>Log keputusan</h2>
        <div class="audit-list"><?php foreach ($audit as $log) { ?><article><?= ikon('check') ?><div>
              <strong><?= e($log['action']) ?></strong>
              <p><?= e($log['reason']) ?></p><small><?= e($log['name']) ?> · <?= e(lama($log['created_at'])) ?></small>
            </div>
          </article><?php } ?><?php if (! $audit) { ?><p class="muted">Belum ada keputusan yang dicatat.</p>
          <?php } ?></div>
      </section>
      <footer class="footer"><strong>CIRCLE ADMIN</strong><span>Tindakan kecil, dampak yang berarti.</span></footer>
    </main>
  </div>
  <dialog id="admin-decision" aria-labelledby="decision-title">
    <div class="dialog-heading">
      <h2 id="decision-title">Tinjau laporan</h2><button class="icon-button" data-close
        aria-label="Tutup"><?= ikon('close') ?></button>
    </div>
    <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action"
        id="decision-action"><input type="hidden" name="id" id="decision-id"><input type="hidden" name="status"
        id="decision-status"><label id="decision-options">Keputusan<select name="decision">
          <option value="dismiss">Tutup laporan tanpa menyembunyikan</option>
          <option value="hide">Sembunyikan momen yang dilaporkan</option>
        </select></label><label>Alasan keputusan<textarea name="reason" minlength="5" maxlength="500" required
          placeholder="Jelaskan pertimbangan tindakan ini"></textarea></label>
      <div class="inline-note">Keputusan dan alasan akan masuk ke log.</div><button class="button primary full">Simpan
        keputusan</button>
    </form>
  </dialog>
  <script src="assets/script.js?v=<?= filemtime(dirname(__DIR__).'/assets/script.js') ?>" defer></script>
</body>

</html>
