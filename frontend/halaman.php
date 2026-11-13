<?php
// Tampilan Peta, Momen, Circle, dan Akun. Diakses melalui index.php di folder utama.
require dirname(__DIR__).'/backend/koneksi.php';
$user = pengguna();
$view = in_array($_GET['view'] ?? '', ['moments', 'circles', 'profile'], true) ? $_GET['view'] : 'map';
if (in_array($view, ['circles', 'profile'], true)) {
    $user = wajib_login();
}
$momen = momen_terlihat($user);
$circles = $user ? query("SELECT c.*, cm.state, u.name AS owner_name,
    (SELECT COUNT(*) FROM circle_members x WHERE x.circle_id = c.id AND x.state = 'accepted') AS total
    FROM circles c JOIN circle_members cm ON cm.circle_id = c.id JOIN users u ON u.id = c.owner_id
    WHERE cm.user_id = ? AND cm.state IN ('accepted', 'invited') ORDER BY c.id DESC", [$user['id']])->fetchAll() : [];
$menu = ['map' => ['Peta', 'map'], 'moments' => ['Momen', 'photo'], 'circles' => ['Circle', 'circle'], 'profile' => ['Akun', 'user']];
// Data pin memakai hasil pemeriksaan izin yang sama dengan daftar momen.
$pin = array_values(array_map(fn ($m) => [
    'id' => $m['id'], 'name' => $m['name'], 'place' => $m['place'],
    'caption' => $m['caption'], 'audience' => $m['audience'], 'theme' => $m['theme'],
    'photo' => $m['photo'] ? 'backend.php?photo='.$m['id'] : null,
    'time' => lama($m['created_at']), 'latitude' => $m['latitude'], 'longitude' => $m['longitude'],
], array_filter($momen, fn ($m) => $m['latitude'] !== null && $m['longitude'] !== null)));
$old = $_SESSION['old'] ?? [];
$circleAktif = array_values(array_filter($circles, fn($c) => $c['state'] === 'accepted'));
$circlePilih = (int)($_GET['circle'] ?? ($circleAktif[0]['id'] ?? 0));
if (!in_array($circlePilih, array_column($circleAktif, 'id'))) $circlePilih = (int)($circleAktif[0]['id'] ?? 0);
$circleTerpilih = array_values(array_filter($circleAktif, fn($c) => (int)$c['id'] === $circlePilih))[0] ?? null;

?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#6552d9">
  <title>CIRCLE · Saling jaga, tetap terhubung</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(dirname(__DIR__).'/assets/style.css') ?>">
  <?php if ($view === 'map') { ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><?php } ?>
</head>

<body class="<?= $view === 'map' ? 'map-page' : 'app-page' ?>">
  <a class="skip" href="#main">Langsung ke isi</a>
  <aside class="sidebar">
    <a class="brand" href="index.php"><?= logo() ?></a>
    <p class="eyebrow">SALING JAGA, TETAP DEKAT</p>
    <nav aria-label="Navigasi desktop">
      <?php foreach ($menu as $key => [$label, $icon]) { ?>
      <a href="index.php?view=<?= $key ?>" class="nav-link <?= $view === $key ? 'active' : '' ?>"
        <?= $view === $key ? 'aria-current="page"' : '' ?>><?= ikon($icon) ?><?= $label ?></a>
      <?php } ?>
      <?php if (($user['role'] ?? '') === 'admin') { ?><a class="nav-link" href="admin.php"><?= ikon('shield') ?>Panel
        admin</a><?php } ?>
    </nav>
    <div class="sidebar-bottom">
      <div class="privacy-card"><?= ikon('shield') ?><strong>Kendali ada di kamu.</strong>
        <p>Lokasimu dibagikan hanya saat kamu memilihnya.</p>
      </div>
      <?php if ($user) { ?><a class="account" href="index.php?view=profile"><span
          class="avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span><span><strong><?= e($user['name']) ?></strong><small>Akun
            <?= e($user['role']) ?></small></span></a>
      <?php } else { ?><a class="button primary full" href="login.php">Masuk ke CIRCLE</a><?php } ?>
    </div>
  </aside>
  <div class="workspace">
    <?php if ($view !== 'map') { ?>
    <header class="topbar">
      <a class="brand mobile-brand" href="index.php"><?= logo() ?></a>
      <span class="breadcrumb">Duniamu <span>/</span> <?= e($menu[$view][0]) ?></span>
      <div class="header-actions">
        <span class="version-label">RUANG ORANG TERDEKAT</span>
        <?php if ($user) { ?><button class="icon-button" data-open="compose"
          aria-label="Buat momen"><?= ikon('plus') ?></button><a class="avatar small" href="index.php?view=profile"
          aria-label="Buka akun"><?= e(mb_substr($user['name'], 0, 1)) ?></a>
        <?php } else { ?><a class="button secondary compact" href="login.php">Masuk</a><a class="button primary compact"
          href="login.php?mode=register">Daftar</a><?php } ?>
      </div>
    </header>
    <?php } ?>
    <main id="main" class="content <?= $view === 'map' ? 'map-content' : '' ?>">
      <?php flash(); ?>
      <?php if ($view === 'map') { ?>
      <h1 class="sr-only">Peta circle dan kabar anggota</h1>
      <!-- Peta menjadi seluruh layar. Pencarian, kontrol, dan panel adalah lapisan di atasnya. -->
      <section class="map-stage" aria-label="Peta momen CIRCLE">
        <div id="map" aria-label="Peta interaktif lokasi posting">
          <div class="map-fallback">
            <div class="map-lines"></div><span class="map-fallback-note" role="status">Memuat peta…</span>
          </div>
        </div>
        <div class="map-top">
          <div class="map-top-row">
            <div class="map-search" role="search" aria-label="Cari momen di peta">
              <a class="map-brand" href="index.php" aria-label="Beranda CIRCLE"><?= logo() ?></a>
              <label class="sr-only" for="map-search">Cari tempat, nama, atau cerita</label>
              <input id="map-search" type="search" placeholder="Cari tempat atau cerita…" autocomplete="off" maxlength="100">
              <button class="search-clear icon-button" id="map-search-clear" aria-label="Hapus pencarian" hidden><?= ikon('close') ?></button>
            </div>
            <a class="map-account <?= $user ? 'avatar' : 'icon-button' ?>" href="<?= $user ? 'index.php?view=profile' : 'login.php' ?>"
              aria-label="<?= $user ? 'Buka akun' : 'Masuk ke CIRCLE' ?>"><?= $user ? e(mb_substr($user['name'], 0, 1)) : ikon('user') ?></a>
          </div>
          <?php if ($user) { ?>
          <div class="circle-toolbar">
            <button class="circle-picker" id="circle-select" type="button" data-open="circle-switcher"
              data-circle-id="<?= $circlePilih ?>" data-circle-name="<?= e($circleTerpilih['name'] ?? 'Belum ada circle') ?>"
              aria-haspopup="dialog" aria-controls="circle-switcher" aria-expanded="false" aria-label="Pilih circle: <?= e($circleTerpilih['name'] ?? 'belum ada circle') ?>">
              <span class="circle-picker-icon"><?= ikon('circle') ?></span>
              <span class="circle-picker-copy"><strong id="circle-current-name"><?= e($circleTerpilih['name'] ?? 'Pilih circle') ?></strong><small id="circle-current-members"><?= $circleTerpilih ? (int)$circleTerpilih['total'].' anggota' : 'Buat ruang terdekatmu' ?></small></span>
              <?= ikon('chevron') ?>
            </button>
            <div class="mode-switch" role="group" aria-label="Mode peta"><button id="mode-members" aria-pressed="true">Anggota</button><button id="mode-moments" aria-pressed="false">Momen</button></div>
          </div>
          <?php } ?>
          <div class="map-chips" role="group" aria-label="Audiens momen di peta">
            <button class="map-chip selected" data-map-audience="all" aria-pressed="true"><?= ikon('map') ?>Semua momen</button>
            <button class="map-chip" data-map-audience="public" aria-pressed="false"><?= ikon('pin') ?>Publik</button>
            <button class="map-chip" data-map-audience="circle" aria-pressed="false" <?= $user ? '' : 'data-login' ?>><?= ikon('circle') ?>Circle-ku</button>
            <?php if ($user) { ?><button class="map-chip" data-map-audience="self" aria-pressed="false"><?= ikon('shield') ?>Hanya saya</button><?php } ?>
          </div>
        </div>
        <?php if ($user) { ?>
        <aside class="safety-panel" id="safety-panel" aria-label="Pemantauan circle">
          <details id="member-sheet">
            <summary><span class="safety-symbol"><?= ikon('shield') ?></span><span><small>CIRCLE PILIHANMU</small><strong id="member-count">Memuat anggota…</strong></span><?= ikon('chevron') ?></summary>
            <div id="members-list" class="members-list"></div>
          </details>
          <div class="sharing-state"><span class="status-dot"></span><span id="sharing-status">Lokasimu belum dibagikan</span></div>
          <div class="sharing-actions">
            <button class="button primary" id="share-open" data-open="share-location"><?= ikon('locate') ?><span>Bagikan lokasi saya</span></button>
            <button class="button secondary" id="share-pause" hidden>Jeda</button><button class="button secondary danger" id="share-stop" hidden>Stop</button>
          </div>
          <p id="sharing-feedback" class="sharing-feedback" role="status">Hanya anggota circle pilihan yang dapat melihat.</p>
          <?php if (!$circleAktif) { ?><a class="text-link" href="index.php?view=circles">Buat atau terima undangan circle <?= ikon('arrow') ?></a><?php } ?>
        </aside>
        <?php } ?>
        <div class="map-tools" aria-label="Kontrol peta">
          <div class="map-zoom">
            <button class="icon-button" id="map-zoom-in" aria-label="Perbesar peta"><?= ikon('plus') ?></button>
            <button class="icon-button" id="map-zoom-out" aria-label="Perkecil peta"><?= ikon('minus') ?></button>
          </div>
          <button class="icon-button" id="map-reset" aria-label="Tampilkan semua pin"><?= ikon('locate') ?></button>
          <button class="icon-button map-compose" <?= $user ? 'data-open="compose"' : 'data-login' ?> aria-label="Buat momen"><?= ikon('plus') ?></button>
        </div>
        <!-- Panel ringkas dapat dibuka dengan tombol; tidak memerlukan gerakan swipe. -->
        <aside class="map-preview" id="map-preview" aria-labelledby="preview-title" data-expanded="false">
          <div class="preview-handle" aria-hidden="true"></div>
          <div class="preview-heading">
            <button class="preview-toggle" id="preview-toggle" aria-expanded="false" aria-controls="preview-detail">
              <span><span class="preview-kicker" id="preview-kicker">JELAJAHI CIRCLE</span><strong id="preview-title"><?= count($pin) ?> momen di peta</strong></span><?= ikon('chevron') ?>
            </button>
            <button class="icon-button" id="preview-close" aria-label="Tutup pilihan momen" hidden><?= ikon('close') ?></button>
          </div>
          <div class="preview-story">
            <div class="preview-art" id="preview-art"><?= ilustrasi('garden') ?></div>
            <div class="preview-copy"><strong id="preview-author">Cerita kecil, titik temu baru.</strong>
              <p id="preview-caption">Ketuk pin untuk melihat cerita di tempat itu.</p>
              <span id="preview-meta">Lokasi posting · bukan lokasi orang</span>
            </div>
          </div>
          <div id="preview-detail" class="preview-detail" hidden>
            <p><?= ikon('shield') ?>Pin momen menunjukkan tempat posting, bukan posisi anggota.</p>
            <a class="button primary full" id="preview-link" href="index.php?view=moments">Lihat semua momen <?= ikon('arrow') ?></a>
          </div>
        </aside>
        <p id="map-feedback" class="sr-only" role="status" aria-live="polite"></p>
        <?php foreach (['coffee', 'garden', 'sunset'] as $theme) { ?><template id="map-art-<?= $theme ?>"><?= ilustrasi($theme) ?></template><?php } ?>
      </section>
      <?php } elseif ($view === 'moments') { ?>
      <section class="page-heading">
        <div><span class="eyebrow">CERITA YANG INGIN DISIMPAN</span>
          <h1>Kabar dari circle<span class="purple">.</span></h1>
          <p>Kabar sederhana, dibagikan ke orang yang tepat.</p>
        </div>
        <button class="button primary" <?= $user ? 'data-open="compose"' : 'data-login' ?>><?= ikon('plus') ?>Buat momen</button>
      </section>
      <section class="feed-section" aria-label="Daftar momen">
        <div class="section-heading">
          <div><span class="eyebrow">DARI DUNIAMU</span>
            <h2>Momen terbaru</h2>
          </div>
          <div class="filters" role="group" aria-label="Filter momen"><button
              data-filter="all" class="selected" aria-pressed="true">Semua</button><button data-filter="public"
              aria-pressed="false">Publik</button><?php if ($user) { ?><button data-filter="circle"
              aria-pressed="false">Circle</button><?php } ?></div>
        </div>
        <div class="moment-grid">
          <?php foreach ($momen as $item) { ?>
          <article class="moment-card" id="moment-<?= $item['id'] ?>" data-audience="<?= e($item['audience']) ?>">
            <div class="moment-cover">
              <?php if ($item['photo']) { ?><img src="backend.php?photo=<?= $item['id'] ?>"
                alt="Foto momen <?= e($item['name']) ?>" loading="lazy" width="800"
                height="500"><?php } else { ?><?= ilustrasi($item['theme']) ?><?php } ?>
              <span
                class="audience-label"><?= ikon($item['audience'] === 'public' ? 'map' : 'shield') ?><?= ['public' => 'Publik', 'circle' => 'Circle', 'self' => 'Hanya saya'][$item['audience']] ?></span>
            </div>
            <div class="moment-body">
              <div class="moment-author"><span
                  class="avatar small <?= $item['user_id'] % 2 ? 'peach' : 'green' ?>"><?= e(mb_substr($item['name'], 0, 1)) ?></span>
                <div><strong><?= e($item['name']) ?></strong><small><?= e(lama($item['created_at'])) ?></small></div>
                <?php if ($user && $user['id'] !== $item['user_id']) { ?><button class="icon-button report-button"
                  data-report="<?= $item['id'] ?>"
                  aria-label="Laporkan momen <?= e($item['name']) ?>"><?= ikon('flag') ?></button><?php } ?>
              </div>
              <p class="caption"><?= nl2br(e($item['caption'])) ?></p>
              <?php if ($item['place']) { ?><span
                class="place"><?= ikon('pin') ?><?= e($item['place']) ?></span><?php } ?>
              <?php if ($user && $user['id'] === $item['user_id']) { ?><form method="post" action="backend.php"
                class="delete-form"
                data-confirm="Hapus momen ini dari tampilan? Setelah dihapus, foto juga tidak dapat dibuka melalui tautannya.">
                <?php csrf(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id"
                  value="<?= $item['id'] ?>"><button class="text-button danger">Hapus momen</button></form>
              <?php } ?>
            </div>
          </article>
          <?php } ?>
        </div>
        <div class="empty-state" id="filter-empty" <?= $momen ? 'hidden' : '' ?>><?= ikon('photo') ?><h2>Belum ada momen
            di sini.</h2>
          <p>Mulai dengan satu cerita kecil hari ini.</p>
        </div>
      </section>
      <?php } elseif ($view === 'circles') { ?>
      <section class="page-heading">
        <div><span class="eyebrow">ORANG PILIHANMU</span>
          <h1>Circle kamu<span class="purple">.</span></h1>
          <p>Terhubung dengan orang yang kamu percaya. Berbagi lokasi tetap atas pilihanmu.</p>
        </div><button class="button primary" data-open="new-circle"><?= ikon('plus') ?>Buat circle</button>
      </section>
      <div class="circle-grid">
        <?php foreach ($circles as $circle) { ?>
        <article class="panel circle-card">
          <div class="circle-art">
            <?= ikon('circle') ?><span><?= $circle['state'] === 'invited' ? 'UNDANGAN BARU' : 'CIRCLE-MU' ?></span>
          </div>
          <h2><?= e($circle['name']) ?></h2>
          <p class="muted">Oleh <?= e($circle['owner_name']) ?> · <?= $circle['total'] ?> anggota</p>
          <?php if ($circle['state'] === 'invited') { ?><p>Kamu diundang bergabung. Lokasi perangkat tidak ikut
            dibagikan.</p>
          <form method="post" action="backend.php" class="button-row"><?php csrf(); ?><input type="hidden" name="action"
              value="respond"><input type="hidden" name="id" value="<?= $circle['id'] ?>"><button class="button primary"
              name="accept" value="1">Terima undangan</button><button class="button secondary" name="accept"
              value="0">Tolak</button></form>
          <?php } else { ?>
          <a class="button secondary full" href="index.php?circle=<?= $circle['id'] ?>"><?= ikon('map') ?>Buka peta anggota</a>
          <div class="member-list">
            <?php foreach (query("SELECT u.name FROM users u JOIN circle_members cm ON cm.user_id = u.id WHERE cm.circle_id = ? AND cm.state = 'accepted' AND u.status = 'active'", [$circle['id']])->fetchAll() as $member) { ?><span
              class="member-chip"><span
                class="avatar small"><?= e(mb_substr($member['name'], 0, 1)) ?></span><?= e($member['name']) ?></span><?php } ?>
          </div>
          <?php if ($circle['owner_id'] === $user['id']) { ?><details>
            <summary>Undang teman</summary>
            <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action"
                value="invite"><input type="hidden" name="id" value="<?= $circle['id'] ?>"><label>Email akun teman<input
                  type="email" name="email" placeholder="teman@email.com" required></label><button
                class="button primary full">Kirim undangan</button></form>
          </details>
          <?php } else { ?><form method="post" action="backend.php"
            data-confirm="Keluar dari circle ini? Akses momen privat circle akan dihentikan."><?php csrf(); ?><input
              type="hidden" name="action" value="leave"><input type="hidden" name="id"
              value="<?= $circle['id'] ?>"><button class="text-button danger">Keluar dari circle</button></form>
          <?php } ?>
          <?php } ?>
        </article>
        <?php } ?>
        <?php if (! $circles) { ?><div class="empty-state"><?= ikon('circle') ?><h2>Mulai dari lingkaran kecil.</h2>
          <p>Buat circle pertama dan undang teman yang kamu percaya.</p><button class="button primary"
            data-open="new-circle">Buat circle</button>
        </div><?php } ?>
      </div>
      <?php } else { ?>
      <section class="page-heading">
        <div><span class="eyebrow">DIRIMU, PILIHANMU</span>
          <h1>Profil kamu<span class="purple">.</span></h1>
          <p>Kelola identitas dan cerita yang ingin kamu bagikan.</p>
        </div>
      </section>
      <div class="profile-layout">
        <section class="panel">
          <div class="profile-heading"><span class="avatar large"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
            <div>
              <h2><?= e($user['name']) ?></h2>
              <p class="muted"><?= e($user['email']) ?></p>
            </div>
          </div>
          <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action"
              value="profile"><label>Nama<input name="name" value="<?= e($user['name']) ?>" required maxlength="60"
                autocomplete="name"></label><label>Bio singkat<textarea name="bio" maxlength="160"
                rows="3"><?= e($user['bio']) ?></textarea></label><button class="button primary">Simpan
              perubahan</button></form>
        </section>
        <aside class="panel"><?= ikon('shield') ?><h2>Cerita sesuai pilihanmu.</h2>
          <p class="muted">Setiap momen bisa kamu bagikan ke publik, circle tertentu, atau hanya untuk dirimu.</p>
          <div class="inline-note">Atur berbagi lokasi dari Peta. Kamu dapat menjeda atau menghentikannya kapan saja.</div>
          <?php if ($user['role'] === 'admin') { ?><a class="button secondary full" href="admin.php">Buka panel
            admin</a><?php } ?>
          <form method="post" action="backend.php" class="logout-form"><?php csrf(); ?><input type="hidden"
              name="action" value="logout"><button class="text-button danger"><?= ikon('logout') ?>Keluar dari
              akun</button></form>
        </aside>
      </div>
      <?php } ?>
      <?php if ($view !== 'map') { ?><footer class="footer"><strong>CIRCLE</strong><span>Tempat, momen, dan orang terdekat.</span></footer><?php } ?>
    </main>
  </div>
  <nav class="bottom-nav" aria-label="Navigasi mobile"><?php foreach ($menu as $key => [$label, $icon]) { ?><a
      href="index.php?view=<?= $key ?>" class="<?= $view === $key ? 'active' : '' ?>"
      <?= $view === $key ? 'aria-current="page"' : '' ?>><?= ikon($icon) ?><span><?= $label ?></span></a><?php } ?>
  </nav>

  <?php if ($user) { ?>
  <!-- Pilihan circle memakai dialog yang sama di mobile/desktop, tanpa menu native browser. -->
  <dialog id="circle-switcher" class="circle-switcher" aria-labelledby="circle-switch-title" aria-describedby="circle-switch-description">
    <div class="sheet-grip" aria-hidden="true"></div>
    <div class="dialog-heading">
      <div><span class="eyebrow">ORANG TERDEKATMU</span><h2 id="circle-switch-title" tabindex="-1" autofocus>Pilih circle</h2></div>
      <button class="icon-button" data-close aria-label="Tutup pilihan circle"><?= ikon('close') ?></button>
    </div>
    <p id="circle-switch-description">Pilih circle yang ingin kamu lihat di peta.</p>
    <div class="circle-choice-list" role="group" aria-label="Daftar circle">
      <?php foreach ($circleAktif as $c) { ?>
      <button class="circle-choice" type="button" data-select-circle="<?= $c['id'] ?>" data-member-count="<?= (int)$c['total'] ?>" aria-pressed="<?= (int)$c['id'] === $circlePilih ? 'true' : 'false' ?>">
        <span class="circle-choice-icon"><?= ikon('circle') ?></span>
        <span class="circle-choice-copy"><strong data-choice-name><?= e($c['name']) ?></strong><small><?= (int)$c['total'] ?> anggota · <?= (int)$c['owner_id'] === (int)$user['id'] ? 'Dikelola kamu' : 'Circle yang kamu ikuti' ?></small></span>
        <span class="circle-choice-check" aria-hidden="true"><?= ikon('check') ?></span>
      </button>
      <?php } ?>
      <?php if (!$circleAktif) { ?><div class="circle-picker-empty"><?= ikon('circle') ?><strong>Belum ada circle</strong><p>Buat circle atau terima undangan untuk saling berbagi kabar.</p></div><?php } ?>
    </div>
    <p class="circle-switch-feedback" id="circle-switch-feedback" role="status"></p>
    <div class="circle-switch-note"><?= ikon('shield') ?><p>Berpindah circle akan menjeda lokasi yang sedang kamu bagikan.</p></div>
    <a class="button secondary full" href="index.php?view=circles"><?= ikon('circle') ?><?= $circleAktif ? 'Kelola circle' : 'Buka halaman Circle' ?><?= ikon('arrow') ?></a>
  </dialog>
  <dialog id="share-location" aria-labelledby="share-title">
    <div class="dialog-heading"><div><span class="eyebrow">KAMU TETAP MEMEGANG KENDALI</span><h2 id="share-title">Bagikan lokasimu</h2></div><button class="icon-button" data-close aria-label="Tutup"><?= ikon('close') ?></button></div>
    <p>Lokasi perangkatmu hanya terlihat oleh anggota aktif di <strong id="share-circle-name">circle pilihan</strong>.</p>
    <form id="share-form">
      <label>Berapa lama?<select id="share-duration"><option value="900">15 menit</option><option value="3600" selected>1 jam</option><option value="14400">4 jam</option></select></label>
      <div class="inline-note"><?= ikon('shield') ?>Berjalan saat halaman ini aktif. Pindah halaman atau membuka aplikasi lain akan menjeda berbagi. Titik yang tidak diperbarui disembunyikan setelah 2 menit.</div>
      <label class="consent-row"><input type="checkbox" id="share-consent" required><span>Saya setuju membagikan lokasi ke circle ini dan dapat menghentikannya kapan saja.</span></label>
      <p id="permission-feedback" role="status" class="sharing-feedback"></p>
      <button class="button primary full" id="share-submit">Izinkan dan bagikan</button>
    </form>
  </dialog>
  <dialog id="compose" aria-labelledby="compose-title" <?= isset($_GET['compose']) ? 'data-auto-open' : '' ?>>
    <div class="dialog-heading">
      <div><span class="eyebrow">BAGIKAN HAL KECIL</span>
        <h2 id="compose-title">Momen baru</h2>
      </div><button class="icon-button" data-close aria-label="Tutup"><?= ikon('close') ?></button>
    </div>
    <form method="post" action="backend.php" enctype="multipart/form-data"><?php csrf(); ?><input type="hidden"
        name="action" value="post">
      <label class="upload-label"><?= ikon('photo') ?><span><strong>Pilih foto momenmu</strong><small>Opsional · JPG,
            PNG, WebP · maksimal 5 MB</small></span><input type="file" name="photo" id="photo-input"
          accept="image/jpeg,image/png,image/webp"></label><img id="photo-preview" alt="Pratinjau foto pilihan" hidden>
      <label>Ilustrasi jika tanpa foto<select name="theme">
          <option value="coffee">Secangkir cerita</option>
          <option value="garden">Jeda di taman</option>
          <option value="sunset">Langit senja</option>
        </select></label>
      <label>Caption<textarea name="caption" maxlength="500" rows="3" required
          placeholder="Apa cerita kecilmu hari ini?"><?= e($old['caption'] ?? '') ?></textarea></label>
      <div class="form-row"><label>Audiens<select name="audience">
            <option value="self">Hanya saya</option>
            <option value="circle">Circle pilihan</option>
            <option value="public">Publik</option>
          </select></label><label>Circle<select name="circle_id">
            <option value="">Pilih untuk audiens circle</option>
            <?php foreach ($circles as $circle) { ?><?php if ($circle['state'] === 'accepted') { ?><option
              value="<?= $circle['id'] ?>"><?= e($circle['name']) ?></option><?php } ?><?php } ?>
          </select></label></div>
      <details>
        <summary>Tambahkan tempat posting</summary>
        <p class="muted small-text">Opsional. Titik ini tetap pada momen, bukan lokasi perangkat yang bergerak.</p>
        <label>Nama tempat<input name="place" maxlength="80" value="<?= e($old['place'] ?? '') ?>"></label>
        <div class="form-row"><label>Latitude<input type="number" name="latitude" step="any" min="-90" max="90"
              value="<?= e($old['latitude'] ?? '') ?>"></label><label>Longitude<input type="number" name="longitude"
              step="any" min="-180" max="180" value="<?= e($old['longitude'] ?? '') ?>"></label></div>
      </details>
      <div class="inline-note"><?= ikon('shield') ?>Periksa audiensmu sebelum membagikan.</div><button
        class="button primary full">Bagikan momen <?= ikon('arrow') ?></button>
    </form>
  </dialog>
  <dialog id="new-circle" aria-labelledby="circle-title">
    <div class="dialog-heading">
      <h2 id="circle-title">Circle baru</h2><button class="icon-button" data-close
        aria-label="Tutup"><?= ikon('close') ?></button>
    </div>
    <p class="muted">Ruang untuk orang yang kamu percaya.</p>
    <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action"
        value="create_circle"><label>Nama circle<input name="name" maxlength="60" required
          placeholder="Misalnya: Teman sepulang kelas"></label><button class="button primary full">Buat circle</button>
    </form>
  </dialog>
  <dialog id="report" aria-labelledby="report-title">
    <div class="dialog-heading">
      <h2 id="report-title">Laporkan momen</h2><button class="icon-button" data-close
        aria-label="Tutup"><?= ikon('close') ?></button>
    </div>
    <form method="post" action="backend.php"><?php csrf(); ?><input type="hidden" name="action" value="report"><input
        type="hidden" name="id" id="report-id"><label>Alasan laporan<textarea name="reason" minlength="5"
          maxlength="200" required placeholder="Jelaskan masalah pada momen ini"></textarea></label>
      <p class="muted small-text">Admin hanya meninjau konteks momen yang dilaporkan.</p><button
        class="button primary full">Kirim laporan</button>
    </form>
  </dialog>
  <?php } ?>
  <script id="safety-data" type="application/json"><?= json_encode(['loggedIn' => (bool)$user, 'userId' => $user['id'] ?? null, 'csrf' => token(), 'circleId' => $circlePilih], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script id="map-data"
    type="application/json"><?= json_encode($pin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php if ($view === 'map') { ?>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script><?php } ?>
  <script src="assets/script.js?v=<?= filemtime(dirname(__DIR__).'/assets/script.js') ?>" defer></script>
</body>

</html>
<?php unset($_SESSION['old']); ?>
