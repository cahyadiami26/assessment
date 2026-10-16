<?php
/**
 * RUANG UJIAN / EXAM LOBBY (halaman masuk tunggal peserta)
 * - Tab 1: Login via SSO (karyawan) — deteksi otomatis session LJU_APPS
 * - Tab 2: Input Token + Email (calon karyawan)
 * - Dynamic HR Dashboard Link di header (hanya jika role HR/Admin)
 * - System & Device Check (webcam + koneksi) sebelum ujian
 * - Ringkasan modul, total durasi, aturan, tombol Start aktif sesuai jadwal
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

/* Tidak memaksa requireSsoLogin() di sini — calon karyawan masuk pakai token.
   Namun jika sudah login SSO, kita sinkronkan profilnya. */
if (!empty($_SESSION['user_id'])) {
    refreshSessionFromUsers();
}

$db = getAppDb();

/* ---------- Aksi: verifikasi token calon ---------- */
$lobbyError = '';
if (isPost() && ($_POST['form'] ?? '') === 'token') {
    verifyCsrf();
    $token = strtoupper(trim($_POST['access_token'] ?? ''));
    $email = strtolower(trim($_POST['candidate_email'] ?? ''));
    if ($token === '' || $email === '') {
        $lobbyError = 'Token dan email wajib diisi.';
    } else {
        $st = $db->prepare('SELECT pp.*, p.nama AS paket_nama FROM package_participants pp JOIN test_packages p ON p.id=pp.package_id WHERE pp.access_token=? AND LOWER(pp.candidate_email)=? LIMIT 1');
        $st->execute([$token, $email]);
        $cand = $st->fetch();
        if (!$cand) {
            $lobbyError = 'Token/email tidak cocok untuk paket manapun. Periksa kembali undangan Anda.';
        } else {
            // Simpan identitas kandidat dalam session lokal (bukan SSO)
            $_SESSION['exam_candidate'] = [
                'participant_id' => (int)$cand['id'],
                'package_id'     => (int)$cand['package_id'],
                'name'           => $cand['candidate_name'],
                'email'          => $cand['candidate_email'],
            ];
            header('Location: exam_lobby.php'); exit;
        }
    }
}

/* ---------- Identitas peserta aktif di lobby ---------- */
$candidate = $_SESSION['exam_candidate'] ?? null;
$isEmployee = !empty($_SESSION['user_id']);

// Peserta: karyawan → semua paket yang menunjuk user_id saya; kandidat → paket dari token
$myPackages = [];
if ($candidate) {
    $st = $db->prepare('SELECT p.*, pp.id AS participant_id, pp.status AS part_status
                        FROM package_participants pp JOIN test_packages p ON p.id = pp.package_id
                        WHERE pp.id = ?');
    $st->execute([$candidate['participant_id']]);
    if ($row = $st->fetch()) $myPackages[] = $row;
} elseif ($isEmployee) {
    $st = $db->prepare('SELECT p.*, pp.id AS participant_id, pp.status AS part_status
                        FROM package_participants pp JOIN test_packages p ON p.id = pp.package_id
                        WHERE pp.user_id = ? ORDER BY p.jadwal_mulai DESC LIMIT 10');
    $st->execute([$_SESSION['user_id']]);
    $myPackages = $st->fetchAll();
}

/** Info lengkap satu paket utk kartu lobby */
function paketInfo(PDO $db, array $p): array
{
    $mods = $db->query('SELECT m.* FROM package_modules pm JOIN test_modules m ON m.id=pm.module_id WHERE pm.package_id=' . (int)$p['id'] . ' ORDER BY pm.urutan')->fetchAll();
    $totalDurasi = array_sum(array_column($mods, 'durasi_menit'));
    $done = $db->query('SELECT COUNT(*) FROM exam_attempts WHERE participant_id=' . (int)$p['participant_id'] . ' AND status IN ("submitted","reviewed")')->fetchColumn();
    $now = date('Y-m-d H:i:s');
    $windowOpen = ($p['jadwal_mulai'] <= $now && $p['jadwal_selesai'] >= $now);
    return ['mods' => $mods, 'total_durasi' => $totalDurasi, 'done' => (int)$done, 'open' => $windowOpen];
}

$displayName = $candidate['name'] ?? ($_SESSION['user_name'] ?? 'Peserta');
$displayEmail = $candidate['email'] ?? ($_SESSION['user_email'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ruang Ujian — E-Assessment LJU</title>
<link rel="icon" href="<?= e(logoUrl('logo.png')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="background:var(--bg)">

<!-- ===== Header dengan Dynamic HR Dashboard Link ===== -->
<header class="topbar">
  <div class="flex items-center gap-2">
    <img src="<?= e(logoUrl('logo.png')) ?>" alt="Logo LJU" style="height:36px;background:#fff;border-radius:8px;padding:2px">
    <div><strong style="color:var(--navy-900)">Ruang Ujian · E-Assessment</strong>
      <div class="text-muted" style="font-size:12px">PT Lampung Jasa Utama</div></div>
  </div>
  <div class="topbar-right">
    <?php if ($isEmployee && isHrd()): ?>
      <!-- Tautan Dashboard HR hanya muncul jika akun SSO terdeteksi role HR/Admin -->
      <a class="btn btn-sm btn-primary" href="index.php">⚙️ Dashboard HR</a>
    <?php endif; ?>
    <?php if ($isEmployee): ?>
      <div class="user-chip"><?= renderAvatar($_SESSION['avatar'] ?? null, $displayName) ?>
        <div><div class="u-name"><?= e($displayName) ?></div><div class="u-role">Login via SSO</div></div></div>
    <?php elseif ($candidate): ?>
      <span class="badge badge-gold">Akses Token: <?= e($candidate['name']) ?></span>
      <form method="post" action="logout_exam.php" style="display:inline"><?= csrfField() ?><button class="btn btn-ghost btn-sm">Keluar</button></form>
    <?php endif; ?>
  </div>
</header>

<main class="content" style="max-width:980px;margin:0 auto">

<?php if ($lobbyError): ?><div class="alert alert-danger"><?= e($lobbyError) ?></div><?php endif; ?>

<!-- ======================= FORM MASUK (jika belum teridentifikasi) ======================= -->
<?php if (!$isEmployee && !$candidate): ?>
<div class="auth-page" style="background:none;padding:30px 0;align-items:flex-start">
  <div class="auth-card" style="max-width:560px">
    <div class="auth-head">
      <img src="<?= e(logoUrl('logo.png')) ?>" alt="LJU">
      <div class="auth-title">Selamat Datang di Ruang Ujian</div>
      <div class="auth-sub">Silakan masuk sesuai identitas Anda untuk memulai assessment.</div>
    </div>
    <div class="auth-body">
      <div class="tabs">
        <button type="button" class="tab-btn active" data-tab="paneSSO">👤 Karyawan (SSO)</button>
        <button type="button" class="tab-btn" data-tab="paneToken">🎫 Calon Karyawan (Token)</button>
      </div>

      <div id="paneSSO" class="tab-pane active">
        <p class="text-muted">Karyawan PT Lampung Jasa Utama cukup login melalui <strong>Portal SSO LJU</strong>. Sistem akan mendeteksi akun Anda secara otomatis.</p>
        <a class="btn btn-primary btn-lg w-100"
           href="https://lju.co.id/dashboard/?target_system=<?= urlencode(APP_URL) ?>&redirect_after_login=<?= urlencode('/exam_lobby.php') ?>">
           🔐 Login via SSO Portal</a>
        <div class="divider"></div>
        <div class="check-line"><input type="checkbox" checked disabled><span class="text-muted" style="font-size:12.5px">Terkoneksi ke domain <code>.lju.co.id</code> (cookie LJU_APPS)</span></div>
      </div>

      <div id="paneToken" class="tab-pane">
        <p class="text-muted">Calon karyawan memasukkan <strong>Token Akses</strong> dan <strong>Email</strong> yang dikirim pada undangan assessment.</p>
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="form" value="token">
          <div class="form-group"><label class="form-label">Token Akses</label>
            <input type="text" name="access_token" class="form-control" placeholder="cth: 4F2A9C1E..." required style="text-transform:uppercase;font-family:monospace"></div>
          <div class="form-group"><label class="form-label">Email</label>
            <input type="email" name="candidate_email" class="form-control" placeholder="nama@email.com" required></div>
          <button class="btn btn-success btn-lg w-100">Verifikasi Token & Masuk</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body></html>
<?php exit; ?>
<?php endif; ?>

<!-- ======================= LOBBY PESERTA ======================= -->
<h2 style="margin-top:6px">Halo, <?= e($displayName) ?> 👋</h2>
<p class="text-muted">Selesaikan pemeriksaan perangkat di bawah ini, lalu mulai ujian sesuai jadwal paket Anda.</p>

<div class="grid grid-2">

  <!-- ---- Device & System Check ---- -->
  <div class="card">
    <div class="card-header"><h3>🖥️ System &amp; Device Check</h3><span class="sub" id="dcSummary">Memeriksa...</span></div>
    <div class="card-body">
      <div class="device-check-item">📶 <span>Koneksi Internet</span>
        <span class="badge badge-muted dc-status" id="dcNet">Mengecek…</span></div>
      <div class="device-check-item">🧭 <span>Keamanan Halaman (HTTPS)</span>
        <span class="badge badge-muted dc-status" id="dcHttps">…</span></div>
      <div class="device-check-item">📷 <span>Kamera Webcam</span>
        <span class="badge badge-muted dc-status" id="dcCam">Belum diuji</span>
        <button class="btn btn-outline btn-sm" onclick="testCamera()">Uji Kamera</button></div>
      <video id="camPreview" class="webcam-preview" autoplay muted playsinline style="display:none"></video>
      <div class="device-check-item">⏰ <span>Waktu Perangkat</span>
        <span class="badge badge-info dc-status" id="dcClock"><?= date('H:i:s') ?></span></div>
      <div class="alert alert-warning mt-2 mb-0" style="font-size:12.5px">
        ℹ️ Pastikan baterai laptop ≥ 50%, tutup aplikasi lain, dan gunakan Chrome/Edge terbaru selama ujian berlangsung.
      </div>
    </div>
  </div>

  <!-- ---- Daftar Paket Ujian ---- -->
  <div class="card">
    <div class="card-header"><h3>🎯 Paket Assessment Anda</h3></div>
    <div class="card-body">
      <?php if (!$myPackages): ?>
        <div class="empty-state"><div class="big-ico">📭</div>Anda belum ditunjuk sebagai peserta paket manapun.<br>Hubungi tim HRD.</div>
      <?php endif; ?>
      <?php foreach ($myPackages as $p): $info = paketInfo($db, $p); ?>
        <div class="card" style="box-shadow:none;background:#fbfcfe">
          <div class="card-body" style="padding:16px">
            <div class="flex justify-between items-center flex-wrap gap-1">
              <div>
                <strong style="font-size:15px"><?= e($p['nama']) ?></strong>
                <div class="module-meta"><?= fmtDate($p['jadwal_mulai']) ?> → <?= fmtDate($p['jadwal_selesai']) ?></div>
              </div>
              <?php if ($info['open']): ?>
                <a class="btn btn-primary" href="exam_run.php?participant_id=<?= (int)$p['participant_id'] ?>">▶️ Mulai Ujian</a>
              <?php elseif (strtotime($p['jadwal_mulai']) > time()): ?>
                <span class="badge badge-warning">⏳ Belum dibuka</span>
              <?php else: ?>
                <span class="badge badge-danger">Jadwal berakhir</span>
              <?php endif; ?>
            </div>
            <div class="divider" style="margin:12px 0"></div>
            <?php foreach ($info['mods'] as $i => $m): ?>
              <div class="module-list-item" style="padding:9px 12px;margin-bottom:6px">
                <div class="ml-num" style="width:28px;height:28px;font-size:12px"><?= $i+1 ?></div>
                <div><strong style="font-size:13px"><?= e($m['nama']) ?></strong>
                  <div class="module-meta"><?= (int)$m['durasi_menit'] ?> menit</div></div>
              </div>
            <?php endforeach; ?>
            <div class="flex justify-between mt-1">
              <span class="badge badge-info">Total durasi: <?= $info['total_durasi'] ?> menit</span>
              <span class="badge badge-<?= $info['done'] >= count($info['mods']) && count($info['mods'])>0 ? 'success':'muted' ?>">Progres: <?= $info['done'] ?>/<?= count($info['mods']) ?> modul</span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ---- Aturan Ujian ---- -->
<?php foreach ($myPackages as $p): if (empty($p['instructions'])) continue; ?>
  <div class="card">
    <div class="card-header"><h3>📜 Aturan — <?= e($p['nama']) ?></h3></div>
    <div class="card-body" style="white-space:pre-line"><?= e($p['instructions']) ?></div>
  </div>
<?php endforeach; ?>

</main>

<footer class="footer text-center">&copy; <?= date('Y') ?> PT Lampung Jasa Utama · assessment.lju.co.id</footer>

<script src="assets/js/app.js"></script>
<script>
/* Device check */
let camOk = false;
function setBadge(id, ok, txt){
  const el = document.getElementById(id);
  el.className = 'badge dc-status ' + (ok===null?'badge-muted':(ok?'badge-success':'badge-danger'));
  el.textContent = txt;
}
(function checkNet(){
  const t0 = performance.now();
  fetch(location.href, {method:'HEAD', cache:'no-store'}).then(()=>{
    const lat = Math.round(performance.now()-t0);
    setBadge('dcNet', lat < 3000, 'Stabil ('+lat+' ms)');
  }).catch(()=>setBadge('dcNet', false, 'Tidak stabil'));
})();
setBadge('dcHttps', window.isSecureContext, window.isSecureContext?'Aman':'Tidak HTTPS');
setBadge('dcClock', null, new Date().toLocaleTimeString('id-ID'));

function testCamera(){
  setBadge('dcCam', null, 'Meminta izin…');
  if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){ setBadge('dcCam', false,'Perangkat tidak mendukung'); return; }
  navigator.mediaDevices.getUserMedia({video:true}).then(stream=>{
    const v=document.getElementById('camPreview'); v.srcObject=stream; v.style.display='block';
    camOk=true; setBadge('dcCam', true, 'Aktif ✓');
  }).catch(()=>setBadge('dcCam', false, 'Ditolak / tidak ada kamera'));
}
</script>
</body>
</html>
