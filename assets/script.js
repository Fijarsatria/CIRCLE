// Interaksi ringan saja. Login, izin, dan penyimpanan tetap diperiksa backend/proses.php.
const pilih = (selector) => document.querySelector(selector);
let pemicuDialog = null;

function bukaDialog(id) {
  const dialog = document.getElementById(id);
  if (!dialog) return;
  pemicuDialog = document.activeElement;
  dialog.showModal();
}

function tutupDialog(dialog) {
  dialog.close();
  pemicuDialog?.focus();
}

document.addEventListener('click', (event) => {
  const tombol = event.target.closest('button');
  if (!tombol) return;
  if (tombol.dataset.open) bukaDialog(tombol.dataset.open);
  if (tombol.hasAttribute('data-close')) tutupDialog(tombol.closest('dialog'));
  if (tombol.hasAttribute('data-login')) location.href = 'login.php';
  if (tombol.dataset.report) {
    pilih('#report-id').value = tombol.dataset.report;
    bukaDialog('report');
  }

  // Form keputusan digunakan ulang agar admin.php tetap singkat.
  if (tombol.dataset.adminReport || tombol.dataset.adminUser) {
    const laporan = Boolean(tombol.dataset.adminReport);
    pilih('#decision-action').value = laporan ? 'moderate' : 'status';
    pilih('#decision-id').value = laporan ? tombol.dataset.adminReport : tombol.dataset.adminUser;
    pilih('#decision-status').value = tombol.dataset.status || '';
    pilih('#decision-options').hidden = !laporan;
    pilih('#decision-title').textContent = laporan ?
      'Tinjau laporan #' + tombol.dataset.adminReport :
      (tombol.dataset.status === 'active' ? 'Aktifkan ' : 'Batasi ') + tombol.dataset.name;
    bukaDialog('admin-decision');
  }
});

// Escape dan pengembalian fokus juga bekerja saat dialog ditutup dengan keyboard.
document.querySelectorAll('dialog').forEach((dialog) => {
  dialog.addEventListener('close', () => pemicuDialog?.focus());
});
if (pilih('[data-auto-open]')) bukaDialog(pilih('[data-auto-open]').id);
pilih('.notice.error')?.focus();

// Form tetap memakai POST biasa. Cegah klik ganda tanpa menghilangkan nilai tombol Terima/Tolak.
document.querySelectorAll('form:not(#share-form)').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (form.dataset.sending === 'true') {
      event.preventDefault();
      return;
    }
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) {
      event.preventDefault();
      return;
    }
    form.dataset.sending = 'true';
    form.setAttribute('aria-busy', 'true');
    if (event.submitter) {
      event.submitter.setAttribute('aria-disabled', 'true');
      event.submitter.textContent = 'Memproses…';
    }
  });
});

// Pratinjau foto tidak mengunggah apa pun sampai formulir dikirim.
let alamatPreview = null;
pilih('#photo-input')?.addEventListener('change', (event) => {
  const file = event.target.files[0];
  const preview = pilih('#photo-preview');
  if (alamatPreview) URL.revokeObjectURL(alamatPreview);
  preview.hidden = true;
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    event.target.value = '';
    alert('Pilih foto berukuran maksimal 5 MB.');
    return;
  }
  alamatPreview = URL.createObjectURL(file);
  preview.src = alamatPreview;
  preview.hidden = false;
});

document.querySelectorAll('[data-filter]').forEach((button) => {
  button.addEventListener('click', () => {
    let jumlah = 0;
    document.querySelectorAll('[data-filter]').forEach((item) => {
      item.classList.toggle('selected', item === button);
      item.setAttribute('aria-pressed', String(item === button));
    });
    document.querySelectorAll('[data-audience]').forEach((card) => {
      card.hidden = button.dataset.filter !== 'all' && card.dataset.audience !== button.dataset.filter;
      if (!card.hidden) jumlah++;
    });
    pilih('#filter-empty').hidden = jumlah > 0;
  });
});

pilih('#user-search')?.addEventListener('input', (event) => {
  const kata = event.target.value.toLocaleLowerCase('id');
  document.querySelectorAll('[data-user-row]').forEach((row) => {
    row.hidden = !row.dataset.userRow.includes(kata);
  });
});

// Peta momen dan anggota memakai satu instance Leaflet. Izin data diperiksa PHP.
if (pilih('#map')) {
  const points = JSON.parse(pilih('#map-data').textContent);
  const stage = pilih('.map-stage');
  const panel = pilih('#map-preview');
  const search = pilih('#map-search');
  const note = pilih('.map-fallback-note');
  const markers = new Map();
  const bolehAnimasi = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  const audienceNames = { public: 'Publik', circle: 'Circle', self: 'Hanya saya' };
  let map = null;
  let markerLayer = null;
  let audience = 'all';
  let selected = null;
  let matches = points;

  // Foto tetap dibaca melalui backend.php. Ilustrasi berasal dari template lokal.
  function isiGambar(target, point, dekoratif = false) {
    target.replaceChildren();
    if (point.photo) {
      const foto = document.createElement('img');
      foto.src = point.photo;
      foto.alt = dekoratif ? '' : 'Foto momen ' + point.name;
      foto.loading = 'lazy';
      target.append(foto);
    } else {
      const tema = pilih('#map-art-' + point.theme) || pilih('#map-art-garden');
      const gambar = tema.content.firstElementChild.cloneNode(true);
      gambar.setAttribute('preserveAspectRatio', 'xMidYMid slice');
      if (dekoratif) {
        gambar.setAttribute('aria-hidden', 'true');
        gambar.removeAttribute('aria-label');
        gambar.removeAttribute('role');
      }
      target.append(gambar);
    }
  }

  function bukaPanel(terbuka) {
    panel.dataset.expanded = String(terbuka);
    pilih('#preview-toggle').setAttribute('aria-expanded', String(terbuka));
    pilih('#preview-detail').hidden = !terbuka;
  }

  function tautanMomen(label, href) {
    const link = pilih('#preview-link');
    const panah = link.querySelector('svg').cloneNode(true);
    link.replaceChildren(document.createTextNode(label), panah);
    link.href = href;
  }

  function tampilkanRingkasan() {
    selected = null;
    bukaPanel(false);
    pilih('#preview-close').hidden = true;
    pilih('#preview-kicker').textContent = search.value.trim() ? 'HASIL PENCARIAN' : 'JELAJAHI CIRCLE';
    pilih('#preview-title').textContent = matches.length + ' momen di peta';
    pilih('#preview-author').textContent = matches.length ? 'Cerita kecil, titik temu baru.' : 'Belum ada titik yang cocok.';
    pilih('#preview-caption').textContent = matches.length ?
      'Ketuk pin untuk melihat cerita di tempat itu.' :
      (points.length ? 'Coba kata lain atau pilih Semua momen.' : 'Momen dengan tempat posting akan muncul di sini.');
    pilih('#preview-meta').textContent = 'Lokasi posting · bukan lokasi orang';
    isiGambar(pilih('#preview-art'), { theme: 'garden' });
    tautanMomen('Lihat semua momen', 'index.php?view=moments');
    perbaruiPilihanPin();
  }

  function perbaruiPilihanPin() {
    markers.forEach((marker, id) => {
      const terpilih = selected?.id === id;
      marker.setZIndexOffset(terpilih ? 1000 : 0);
      marker.getElement()?.classList.toggle('is-selected', terpilih);
      marker.getElement()?.setAttribute('aria-pressed', String(terpilih));
    });
  }

  function pilihMomen(point) {
    selected = point;
    pilih('#preview-kicker').textContent = 'MOMEN PILIHAN · ' + audienceNames[point.audience];
    pilih('#preview-title').textContent = point.place || 'Cerita ' + point.name;
    pilih('#preview-author').textContent = point.name;
    pilih('#preview-caption').textContent = point.caption;
    pilih('#preview-meta').textContent = point.time + ' · Lokasi posting';
    pilih('#preview-close').hidden = false;
    isiGambar(pilih('#preview-art'), point);
    tautanMomen('Buka momen', 'index.php?view=moments#moment-' + point.id);
    bukaPanel(true);
    perbaruiPilihanPin();
    pilih('#map-feedback').textContent = 'Momen ' + point.name + ' di ' + (point.place || 'lokasi posting') + ' dipilih.';
    if (map) {
      // Posisikan pin sedikit di atas tengah, sehingga panel tidak menutupi pilihannya.
      const posisi = map.project([point.latitude, point.longitude], map.getZoom());
      const ukuran = map.getSize();
      const landscape = ukuran.x >= 600 && ukuran.y <= 520;
      const tujuanY = landscape ? ukuran.y / 2 : Math.max(194, Math.min(ukuran.y / 2 - 60, panel.getBoundingClientRect().top - stage.getBoundingClientRect().top - 26));
      posisi.x += ukuran.x / 2 - (landscape ? ukuran.x * .72 : ukuran.x / 2);
      posisi.y += ukuran.y / 2 - tujuanY;
      map.panTo(map.unproject(posisi, map.getZoom()), { animate: bolehAnimasi });
    }
  }

  function muatHasil() {
    if (stage.dataset.mode === 'members') {
      pilih('#map-search-clear').hidden = !search.value;
      document.dispatchEvent(new Event('circle-search'));
      return;
    }
    const kata = search.value.trim().toLocaleLowerCase('id');
    matches = points.filter((point) =>
      (audience === 'all' || point.audience === audience) &&
      [point.name, point.place, point.caption].join(' ').toLocaleLowerCase('id').includes(kata)
    );
    pilih('#map-search-clear').hidden = !search.value;
    document.querySelectorAll('[data-map-audience]').forEach((button) => {
      const aktif = button.dataset.mapAudience === audience;
      button.classList.toggle('selected', aktif);
      button.setAttribute('aria-pressed', String(aktif));
    });
    markers.forEach((marker, id) => {
      const terlihat = matches.some((point) => point.id === id);
      if (terlihat && !markerLayer.hasLayer(marker)) markerLayer.addLayer(marker);
      if (!terlihat && markerLayer.hasLayer(marker)) markerLayer.removeLayer(marker);
    });
    tampilkanRingkasan();
    pilih('#map-feedback').textContent = matches.length + ' momen sesuai pencarian dan audiens yang dipilih.';
  }

  function tampilkanSemuaPin(animasi = bolehAnimasi) {
    if (!map || !matches.length || stage.dataset.mode === 'members') return;
    const tinggi = map.getSize().y;
    const landscape = stage.clientWidth >= 600 && tinggi <= 520;
    const landscapeLebar = landscape && stage.clientWidth >= 760;
    const tertutupPanel = tinggi - (panel.getBoundingClientRect().top - stage.getBoundingClientRect().top);
    map.fitBounds(matches.map((point) => [point.latitude, point.longitude]), {
      // Perhitungkan tinggi pin, pencarian, dan panel; bukan hanya kotak koordinat.
      paddingTopLeft: [landscape ? Math.min(500, stage.clientWidth * .58) : 55, landscapeLebar ? 110 : 200],
      paddingBottomRight: [75, landscape ? 110 : Math.min(tertutupPanel + 28, Math.max(80, tinggi - 280))],
      maxZoom: 16,
      animate: animasi,
    });
  }

  // Ukuran panel dapat berubah mengikuti panjang caption dan ukuran teks pengguna.
  new ResizeObserver(([entry]) => {
    stage.style.setProperty('--preview-height', Math.ceil(entry.target.getBoundingClientRect().height) + 'px');
  }).observe(panel);

  pilih('#preview-toggle').addEventListener('click', () => bukaPanel(panel.dataset.expanded !== 'true'));
  pilih('#preview-close').addEventListener('click', () => {
    const pinLama = markers.get(selected?.id)?.getElement();
    tampilkanRingkasan();
    pinLama?.focus();
  });
  search.addEventListener('input', muatHasil);
  search.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      search.value = '';
      muatHasil();
    }
    if (event.key === 'Enter' && stage.dataset.mode !== 'members') {
      event.preventDefault();
      if (matches.length === 1) pilihMomen(matches[0]);
      else tampilkanSemuaPin();
    }
  });
  pilih('#map-search-clear').addEventListener('click', () => {
    search.value = '';
    muatHasil();
    tampilkanSemuaPin();
    search.focus();
  });
  document.querySelectorAll('[data-map-audience]').forEach((button) => {
    button.addEventListener('click', () => {
      if (button.hasAttribute('data-login')) return;
      audience = button.dataset.mapAudience;
      muatHasil();
      tampilkanSemuaPin();
    });
  });

  if (!window.L) {
    note.textContent = 'Peta memerlukan internet. Buka tab Momen untuk melihat cerita yang tersedia.';
    document.querySelectorAll('.map-zoom button, #map-reset').forEach((button) => button.disabled = true);
  } else {
    note.hidden = true;
    map = L.map('map', {
      center: [-6.9732, 107.6325], zoom: 16, minZoom: 3, maxZoom: 19,
      zoomControl: false, scrollWheelZoom: true,
    });
    markerLayer = L.layerGroup().addTo(map);
    let tileErrors = 0;
    let loadedTiles = 0;
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).on('tileload', () => {
      loadedTiles++;
      note.hidden = true;
    }).on('tileerror', () => {
      if (++tileErrors >= 4 && !loadedTiles) {
        note.textContent = 'Latar peta belum dapat dimuat. Cerita tetap tersedia di tab Momen.';
        note.hidden = false;
      }
    }).addTo(map);

    points.forEach((point) => {
      const isi = document.createElement('div');
      const bingkai = document.createElement('span');
      bingkai.className = 'pin-picture';
      const gambar = document.createElement('span');
      gambar.className = 'pin-image';
      isiGambar(gambar, point, true);
      const inisial = document.createElement('span');
      inisial.className = 'pin-initial';
      inisial.textContent = [...point.name][0];
      bingkai.append(gambar, inisial);
      const label = document.createElement('span');
      label.className = 'pin-label';
      label.textContent = point.place || 'Momen ' + point.name;
      isi.append(bingkai, label);
      const namaPin = 'Buka momen ' + point.name + ' di ' + (point.place || 'lokasi posting');
      const marker = L.marker([point.latitude, point.longitude], {
        icon: L.divIcon({ className: 'moment-pin', html: isi.innerHTML, iconSize: [54, 62], iconAnchor: [27, 61] }),
        title: namaPin, keyboard: true, riseOnHover: true,
      });
      marker.on('add', () => {
        marker.getElement().setAttribute('aria-label', namaPin);
        marker.getElement().setAttribute('aria-pressed', 'false');
      });
      marker.on('click', () => pilihMomen(point));
      markers.set(point.id, marker);
    });
    map.on('click', () => bukaPanel(false));
    map.on('zoomend', () => {
      pilih('#map-zoom-in').disabled = map.getZoom() >= map.getMaxZoom();
      pilih('#map-zoom-out').disabled = map.getZoom() <= map.getMinZoom();
    });
    pilih('#map-zoom-in').addEventListener('click', () => map.zoomIn(undefined, { animate: bolehAnimasi }));
    pilih('#map-zoom-out').addEventListener('click', () => map.zoomOut(undefined, { animate: bolehAnimasi }));
    pilih('#map-reset').addEventListener('click', () => {
      if (stage.dataset.mode === 'members') { document.dispatchEvent(new Event('circle-fit')); return; }
      search.value = '';
      audience = 'all';
      muatHasil();
      tampilkanSemuaPin();
    });
    new ResizeObserver(() => {
      map.invalidateSize({ pan: false });
      if (stage.dataset.mode === 'members') return;
      if (selected) pilihMomen(selected);
      else tampilkanSemuaPin(false);
    }).observe(stage);
  }
  window.circleMap = {
    map,
    mode(anggota) {
      stage.dataset.mode = anggota ? 'members' : 'moments';
      search.value = '';
      search.placeholder = anggota ? 'Cari nama anggota…' : 'Cari tempat atau cerita…';
      search.setAttribute('aria-label', anggota ? 'Cari nama anggota' : 'Cari tempat, nama, atau cerita');
      pilih('#map').setAttribute('aria-label', anggota ? 'Peta lokasi anggota circle' : 'Peta tempat posting momen');
      pilih('#mode-members')?.setAttribute('aria-pressed', String(anggota));
      pilih('#mode-moments')?.setAttribute('aria-pressed', String(!anggota));
      if (markerLayer && map) {
        if (anggota) map.removeLayer(markerLayer);
        else markerLayer.addTo(map);
      }
      muatHasil();
      if (!anggota) tampilkanSemuaPin(false);
    },
  };
  window.circleMap.mode(Boolean(pilih('#mode-members')));
}

// Berbagi lokasi selalu dimulai dengan persetujuan. Tidak ada GPS saat halaman dibuka.
if (pilih('#safety-panel')) {
  const config = JSON.parse(pilih('#safety-data').textContent);
  const bridge = window.circleMap;
  const sheet = pilih('#safety-panel');
  const dialog = pilih('#share-location');
  const circlePicker = pilih('#circle-select');
  const circleDialog = pilih('#circle-switcher');
  const circleChoices = [...document.querySelectorAll('[data-select-circle]')];
  let switchingCircle = false;
  const feedback = pilih('#sharing-feedback');
  const izinFeedback = pilih('#permission-feedback');
  const pinLayer = bridge.map ? L.layerGroup().addTo(bridge.map) : null;
  let circleId = Number(circlePicker.dataset.circleId), members = [], mine = null, localKey = '';
  let watchId = null, latestPosition = null, lastSent = 0, expiry = 0;
  let sending = false, polling = false, starting = false, generation = 0, serverOffset = 0;
  let firstFit = true, pendingStop = null, selectedMember = null;
  const pinById = new Map();
  const now = () => Math.floor(Date.now() / 1000) + serverOffset;
  const fresh = member => member.fresh && member.received_at >= now() - 120 && member.expires_at > now();

  async function api(action, values = {}, target = circleId) {
    const response = await fetch(action === 'read' ? 'lokasi.php?circle_id=' + target : 'lokasi.php', {
      method: action === 'read' ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
      ...(action !== 'read' ? {headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf: config.csrf, circle_id: target, action, ...values})} : {}),
    });
    let data;
    try { data = await response.json(); } catch { throw new Error('Status belum dapat dibaca. Coba muat ulang.'); }
    if (!response.ok) { const error = new Error(data.error); error.status = response.status; throw error; }
    return data;
  }

  function hentikanGPS() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = null;
    latestPosition = null;
    localKey = '';
    generation++; // Hasil GPS/request sesi lama tidak boleh memulai lagi setelah dijeda.
  }

  function statusSaya() {
    const active = mine?.status === 'active' && Number(mine.expires_at) > now();
    sheet.dataset.sharing = active && localKey ? 'active' : 'stopped';
    pilih('#share-open').hidden = Boolean(active && localKey);
    pilih('#share-open').disabled = !circleId || starting || Boolean(pendingStop);
    pilih('#share-pause').disabled = Boolean(pendingStop);
    pilih('#share-stop').disabled = Boolean(pendingStop);
    pilih('#share-open span').textContent = mine?.status === 'paused' || active ? 'Lanjutkan berbagi' : 'Bagikan lokasi saya';
    pilih('#share-pause').hidden = !active || !localKey;
    pilih('#share-stop').hidden = !active && !pendingStop;
    const sampai = active ? new Date(Number(mine.expires_at)*1000).toLocaleTimeString('id', {hour:'2-digit',minute:'2-digit'}) : '';
    pilih('#sharing-status').textContent = active && localKey ? 'Berbagi aktif sampai ' + sampai
      : active ? 'Sesi server aktif · GPS halaman ini belum berjalan'
      : mine?.status === 'paused' ? 'Berbagi lokasi dijeda'
      : mine?.status === 'expired' || (mine && Number(mine.expires_at) <= now()) ? 'Durasi berbagi sudah berakhir'
      : 'Lokasimu belum dibagikan';
    if (pendingStop) pilih('#sharing-status').textContent = 'GPS dijeda · menunggu konfirmasi server';
  }

  function labelMember(member) {
    if (fresh(member)) {
      const age = Math.max(0, now() - member.received_at);
      return (member.accuracy_m > 100 ? 'Perkiraan' : 'Lokasi terbaru') + ' · ±' + Math.round(member.accuracy_m) + ' m · ' + (age < 15 ? 'baru saja' : age + ' dtk lalu');
    }
    return member.status === 'paused' ? 'Berbagi dijeda' : member.status === 'expired' ? 'Durasi berakhir'
      : member.status === 'active' ? 'Menunggu pembaruan lokasi' : 'Belum membagikan lokasi';
  }

  function fokusMember(member) {
    if (!fresh(member) || !bridge.map) {
      feedback.textContent = member.name + ': ' + labelMember(member) + '.';
      return;
    }
    selectedMember = member.id;
    const y = bridge.map.getSize().y;
    const panelTop = sheet.getBoundingClientRect().top;
    const available = Math.max(210, panelTop - pilih('.map-stage').getBoundingClientRect().top);
    const pos = bridge.map.project([member.latitude, member.longitude], 16);
    pos.y += y/2 - Math.min(available/2 + 50, y/2);
    bridge.map.setView(bridge.map.unproject(pos, 16), 16, {animate: !matchMedia('(prefers-reduced-motion: reduce)').matches});
    feedback.textContent = member.name + ' · ' + labelMember(member) + '. Titik ini bukan jaminan keadaan aman.';
    renderMembers();
  }

  function renderMembers() {
    const keyword = pilih('#map-search').value.trim().toLocaleLowerCase('id');
    const shown = members.filter(m => m.name.toLocaleLowerCase('id').includes(keyword));
    const list = pilih('#members-list');
    // Simpan fokus keyboard ketika polling memperbarui baris yang sama.
    const focusId = document.activeElement?.dataset.memberId;
    const pinFocus = document.activeElement?.dataset.memberPinId;
    const scrollTop = list.scrollTop;
    list.replaceChildren();
    pinLayer?.clearLayers(); pinById.clear();
    const count = members.filter(fresh).length;
    pilih('#member-count').textContent = circleId ? count + ' dari ' + members.length + ' lokasi tersedia' : 'Belum ada circle';
    for (const member of shown) {
      const button = document.createElement('button');
      button.className = 'member-row' + (fresh(member) ? ' is-fresh' : '');
      button.dataset.memberId = member.id;
      const avatar = document.createElement('span'); avatar.className = 'avatar small'; avatar.textContent = [...member.name][0];
      const copy = document.createElement('span'); copy.className = 'member-copy';
      const name = document.createElement('strong'); name.textContent = member.name + (member.id === Number(config.userId) ? ' (kamu)' : '');
      const state = document.createElement('small'); state.textContent = labelMember(member);
      copy.append(name,state); button.append(avatar,copy);
      button.addEventListener('click', () => fokusMember(member)); list.append(button);
      if (fresh(member) && bridge.map && pilih('.map-stage').dataset.mode === 'members') {
        const content = document.createElement('div');
        const initial = document.createElement('span'); initial.textContent = [...member.name][0]; content.append(initial);
        const marker = L.marker([member.latitude, member.longitude], {icon: L.divIcon({className:'member-map-pin',html:content.innerHTML,iconSize:[46,46],iconAnchor:[23,23]}),title:member.name,keyboard:true});
        marker.on('click',()=> {pilih('#member-sheet').open = true; fokusMember(member);});
        marker.addTo(pinLayer); marker.getElement().dataset.memberPinId=member.id; pinById.set(member.id,marker);
        if (selectedMember === member.id) L.circle([member.latitude,member.longitude], {radius:member.accuracy_m,color:'#6552d9',fillOpacity:.09,weight:1,interactive:false}).addTo(pinLayer);
      }
    }
    if (!shown.length) { const empty = document.createElement('p'); empty.className='sharing-feedback'; empty.textContent=keyword?'Nama anggota tidak ditemukan.':'Anggota circle akan muncul di sini.'; list.append(empty); }
    list.scrollTop=scrollTop;
    if (pinFocus) pinById.get(Number(pinFocus))?.getElement()?.focus({preventScroll:true});
    if (focusId) list.querySelector('[data-member-id="' + focusId + '"]')?.focus({preventScroll:true});
    statusSaya();
  }

  function fitMembers() {
    const positions = members.filter(fresh).map(m => [m.latitude,m.longitude]);
    if (pilih('.map-stage').dataset.mode !== 'members') return;
    if (!positions.length || !bridge.map) { feedback.textContent = 'Belum ada lokasi terbaru. Buka daftar anggota untuk melihat statusnya.'; return; }
    const size = bridge.map.getSize();
    const covered = Math.max(100,size.y-(sheet.getBoundingClientRect().top-pilih('.map-stage').getBoundingClientRect().top));
    bridge.map.fitBounds(positions, {maxZoom:16,paddingTopLeft:[60,170],paddingBottomRight:[70,Math.min(covered+30, size.y-230)],animate:false});
  }

  async function refresh() {
    if (!circleId || polling || document.hidden) return;
    polling = true;
    const target = circleId, requestGeneration = generation;
    try {
      const data = await api('read',{},target);
      if (target !== circleId || requestGeneration !== generation) return;
      serverOffset = data.server_time - Math.floor(Date.now()/1000);
      members = data.members; mine = data.mine;
      if (localKey && (mine?.session_key !== localKey || mine?.status !== 'active' || Number(mine.expires_at) <= now())) hentikanGPS();
      if (pendingStop?.circle === target) {
        if (mine?.status !== 'active' || mine?.session_key !== pendingStop.key) pendingStop = null;
        else { await api(pendingStop.action,{key:pendingStop.key},target); mine.status=pendingStop.action==='pause'?'paused':'stopped'; pendingStop=null; members=members.map(m=>m.id===Number(config.userId)?{...m,fresh:false,status:mine.status}:m); }
      }
      renderMembers();
      if (firstFit && members.some(fresh)) {fitMembers();firstFit=false;}
    } catch (error) {
      if (target !== circleId) return;
      if ([401,403].includes(error.status)) {hentikanGPS();members=[];mine=null;circlePicker.disabled=true;pilih('#share-open').disabled=true;}
      feedback.textContent = error.message || 'Koneksi terputus. Lokasi lama otomatis disembunyikan setelah 2 menit.';
      renderMembers();
      if ([401,403].includes(error.status)) { circleId=0;statusSaya(); }
    } finally {
      polling = false;
      // Bila circle berganti saat pembacaan lama selesai, segera baca circle yang baru.
      if (target !== circleId) refresh();
    }
  }

  // Jeda menghapus pin lokal segera. Bila jaringan putus, status server tidak diklaim berhasil.
  async function stopSharing(action='pause', beacon=false) {
    const key = localKey || mine?.session_key;
    const target = circleId;
    hentikanGPS();
    if (starting) {starting=false;pilih('#share-submit').disabled=false;}
    if (!key || !target || mine?.status !== 'active') {statusSaya();return;}
    pendingStop = {action,key,circle:target};
    members = members.map(m=>m.id===Number(config.userId)?{...m,fresh:false,status:'paused'}:m);
    renderMembers();
    if (beacon) {
      navigator.sendBeacon('lokasi.php', new Blob([JSON.stringify({action,csrf:config.csrf,circle_id:target,key})],{type:'application/json'}));
      return;
    }
    try {
      await api(action,{key},target);
      if (target===circleId) {mine={...mine,status:action==='pause'?'paused':'stopped',session_key:''};pendingStop=null;feedback.textContent=action==='pause'?'Berbagi dijeda. Pilih Lanjutkan untuk memberi izin lagi.':'Berbagi dihentikan. Posisi terakhirmu sudah dihapus.';renderMembers();}
    } catch (error) {feedback.textContent='GPS sudah dijeda. Penghapusan posisi di server belum terkonfirmasi; titik kedaluwarsa dalam 2 menit. '+error.message;}
  }

  async function sendPosition(force=false) {
    if (!localKey || !latestPosition || sending || document.hidden) return;
    if (expiry <= now()) {await stopSharing('stop');return;}
    if (!force && Date.now()-lastSent < 15000) return;
    if (Date.now()-latestPosition.timestamp > 120000) {feedback.textContent='Menunggu lokasi baru dari perangkat. Titik lama akan disembunyikan.';return;}
    sending=true;lastSent=Date.now();
    const key=localKey,target=circleId,pos=latestPosition;
    try {
      await api('update',{key,latitude:pos.coords.latitude,longitude:pos.coords.longitude,accuracy_m:pos.coords.accuracy,observed_at:Math.floor(pos.timestamp/1000)},target);
      if (localKey===key) {feedback.textContent='Hanya anggota '+circlePicker.dataset.circleName+' yang dapat melihat lokasimu.';await refresh();}
    } catch (error) {
      if (localKey!==key) return;
      feedback.textContent=error.message || 'Posisi belum terkirim. Periksa koneksi internet.';
      if ([401,403,409,419].includes(error.status)) {hentikanGPS();await refresh();}
    } finally {sending=false;}
  }

  pilih('#share-open').addEventListener('click',()=> {
    pilih('#share-circle-name').textContent=circlePicker.dataset.circleName;
    pilih('#share-consent').checked=false;izinFeedback.textContent='';
  });
  dialog.addEventListener('close',()=> {if (starting) {generation++;starting=false;pilih('#share-submit').disabled=false;}});
  pilih('#share-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (starting || !circleId || !pilih('#share-consent').checked) return;
    if (!window.isSecureContext || !navigator.geolocation) {izinFeedback.textContent='Lokasi memerlukan HTTPS atau localhost. Halaman lain tetap dapat digunakan.';return;}
    starting=true;const current=++generation,target=circleId;
    pilih('#share-submit').disabled=true;izinFeedback.textContent='Menunggu izin dan posisi perangkat…';
    try {
      const position=await new Promise((resolve,reject)=>navigator.geolocation.getCurrentPosition(resolve,reject,{enableHighAccuracy:true,timeout:20000,maximumAge:0}));
      if (generation!==current || !dialog.open || document.hidden) return;
      const data=await api('start',{consent:true,duration:Number(pilih('#share-duration').value)},target);
      if (generation!==current || !dialog.open || document.hidden) {await api('pause',{key:data.key},target);return;}
      localKey=data.key;expiry=data.expires_at;latestPosition=position;lastSent=0;
      mine={status:'active',session_key:localKey,expires_at:expiry};
      starting=false;tutupDialog(dialog);statusSaya();
      await sendPosition(true);
      if (!localKey) return;
      watchId=navigator.geolocation.watchPosition(pos=>{latestPosition=pos;sendPosition();},error=>{
        stopSharing('pause').then(()=> {feedback.textContent=error.code===1?'Izin lokasi dicabut. Berbagi dijeda.':'Lokasi perangkat belum tersedia. Pilih Lanjutkan untuk mencoba lagi.';});
      },{enableHighAccuracy:true,timeout:25000,maximumAge:0});
    } catch (error) {izinFeedback.textContent=error.code===1?'Izin lokasi ditolak. Kamu tetap dapat melihat circle tanpa membagikan lokasi.':error.code===3?'Lokasi belum ditemukan. Coba lagi di tempat dengan sinyal lebih baik.':error.message || 'Lokasi belum tersedia. Coba lagi.';}
    finally {if (generation===current) starting=false;pilih('#share-submit').disabled=false;statusSaya();}
  });
  pilih('#share-pause').addEventListener('click',()=>stopSharing('pause'));
  pilih('#share-stop').addEventListener('click',()=>stopSharing('stop'));
  // Tidak memakai select native: semua pilihan berupa tombol yang dapat diakses keyboard.
  circlePicker.addEventListener('click', () => {
    circlePicker.setAttribute('aria-expanded', 'true');
    pilih('#circle-switch-feedback').textContent = '';
  });
  circleDialog.addEventListener('close', () => circlePicker.setAttribute('aria-expanded', 'false'));
  circleDialog.addEventListener('click', event => {
    const box = circleDialog.getBoundingClientRect();
    if (event.target === circleDialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) tutupDialog(circleDialog);
  });
  circleChoices.forEach(choice => choice.addEventListener('click', async () => {
    if (switchingCircle) return;
    const nextId = Number(choice.dataset.selectCircle);
    if (nextId === circleId) { tutupDialog(circleDialog); return; }
    switchingCircle = true;
    circlePicker.disabled = true;
    circleChoices.forEach(button => button.disabled = true);
    circleDialog.setAttribute('aria-busy', 'true');
    try {
      await stopSharing('pause');
      // Kegagalan jeda harus diselesaikan sebelum kontrol beralih ke circle lain.
      if (pendingStop) {
        pilih('#circle-switch-feedback').textContent = 'Circle belum diganti. GPS sudah dijeda, tetapi server belum mengonfirmasi. Periksa koneksi dan coba lagi.';
        return;
      }
      circleId = nextId;
      circlePicker.dataset.circleId = String(circleId);
      circlePicker.dataset.circleName = choice.querySelector('[data-choice-name]').textContent;
      pilih('#circle-current-name').textContent = circlePicker.dataset.circleName;
      pilih('#circle-current-members').textContent = choice.dataset.memberCount + ' anggota';
      circlePicker.setAttribute('aria-label', 'Pilih circle: ' + circlePicker.dataset.circleName);
      circleChoices.forEach(button => button.setAttribute('aria-pressed', String(button === choice)));
      members = []; mine = null; firstFit = true; selectedMember = null;
      pilih('#map-search').value = '';
      pilih('#map-search-clear').hidden = true;
      feedback.textContent = 'Hanya anggota circle pilihan yang dapat melihat.';
      renderMembers();
      const url = new URL(location.href); url.searchParams.set('circle', String(circleId));
      history.replaceState(null, '', url);
      tutupDialog(circleDialog);
      await refresh();
    } finally {
      switchingCircle = false;
      circlePicker.disabled = false;
      circleChoices.forEach(button => button.disabled = false);
      circleDialog.removeAttribute('aria-busy');
    }
  }));
  pilih('#mode-members').addEventListener('click',()=>{bridge.mode(true);renderMembers();fitMembers();});
  pilih('#mode-moments').addEventListener('click',()=>{bridge.mode(false);pinLayer?.clearLayers();});
  document.addEventListener('circle-search',()=>{if(pilih('.map-stage').dataset.mode==='members') {renderMembers();if(pilih('#map-search').value)pilih('#member-sheet').open=true;}});
  document.addEventListener('circle-fit',fitMembers);
  document.addEventListener('visibilitychange',()=>{if(document.hidden && (localKey || starting))stopSharing('pause',true);else if(!document.hidden)refresh();});
  window.addEventListener('pagehide',()=>{if(localKey || starting)stopSharing('pause',true);});
  setInterval(()=>{if(!document.hidden){refresh();sendPosition();}},15000);
  setInterval(()=>{if(!document.hidden){if(localKey && expiry<=now())stopSharing('stop');renderMembers();}},5000);
  renderMembers();refresh();
}
