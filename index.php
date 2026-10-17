<?php
/**
 * HRD/Admin Dashboard — Ringkasan & statistik assessment.
 */
$pageTitle  = 'Dashboard HR';
$activeMenu = 'dashboard';
require_once __DIR__ . '/includes/header.php';

if (!isHrd()) {
    echo '<div class="alert alert-danger">Anda tidak memiliki akses ke halaman ini.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$db = getAppDb();

// Statistik utama
$stat = [
  'soal'      => (int)$db->query('SELECT COUNT(*) FROM questions WHERE status="aktif"')->fetchColumn(),
  'modul'     => (int)$db->query('SELECT COUNT(*) FROM test_modules WHERE status="aktif"')->fetchColumn(),
  'paket'     => (int)$db->query('SELECT COUNT(*) FROM test_packages WHERE status IN ("scheduled","active")')->fetchColumn(),
  'pending'   => (int)$db->query('SELECT COUNT(*) FROM exam_attempts a WHERE EXISTS (SELECT 1 FROM exam_answers ans WHERE ans.attempt_id=a.id AND ans.review_status="pending")')->fetchColumn(),
];

// Paket aktif terdekat
$paketAktif = $db->query('SELECT p.*, 
        (SELECT COUNT(*) FROM package_participants pp WHERE pp.package_id=p.id) AS total_peserta,
        (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.package_id=p.id AND ea.status IN ("submitted","reviewed")) AS selesai
      FROM test_packages p WHERE p.status IN ("scheduled","active") ORDER BY jadwal_mulai ASC LIMIT 6')->fetchAll();

// Leaderboard nilai final tertinggi (nama karyawan diambil dari session/db SSO via lookup terpisah)
$leader = $db->query('SELECT pp.candidate_name, pp.user_id, pp.participant_type,
        AVG(a.final_score) AS rata_nilai, COUNT(DISTINCT a.id) AS modul_selesai
      FROM exam_attempts a
      JOIN package_participants pp ON pp.id = a.participant_id
      WHERE a.final_score IS NOT NULL
      GROUP BY pp.id ORDER BY rata_nilai DESC LIMIT 8')->fetchAll();

// Kaya nama karyawan dari db SSO (users) bila participant_type = employee
if ($leader) {
    $userIds = array_filter(array_column($leader, 'user_id'));
    $ssoNames = [];
    if ($userIds) {
        $in = implode(',', array_map('intval', $userIds));
        foreach (getSsoDb()->query("SELECT id, name FROM users WHERE id IN ($in)")->fetchAll() as $u) {
            $ssoNames[$u['id']] = $u['name'];
        }
    }
    foreach ($leader as &$l) {
        $l['nama'] = $l['candidate_name'] ?: ($ssoNames[$l['user_id']] ?? 'Karyawan');
    }
    unset($l);
}
?>

<div class="grid grid-4">
  <div class="stat-card"><div class="stat-ico bg-blue">🗂️</div><div><div class="stat-value"><?= $stat['soal'] ?></div><div class="stat-label">Soal Aktif</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-gold">🧩</div><div><div class="stat-value"><?= $stat['modul'] ?></div><div class="stat-label">Modul Tes</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-green">📦</div><div><div class="stat-value"><?= $stat['paket'] ?></div><div class="stat-label">Paket Berjalan</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-red">🧑‍⚖️</div><div><div class="stat-value"><?= $stat['pending'] ?></div><div class="stat-label">Antrean Review</div></div></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3>Paket Assessment Aktif</h3><a class="btn btn-sm btn-outline" href="paket_test.php">Kelola Paket</a></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Paket</th><th>Jadwal</th><th>Peserta</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($paketAktif as $p): ?>
          <tr>
            <td><strong><?= e($p['nama']) ?></strong><div class="text-muted" style="font-size:12px"><?= e($p['posisi_target'] ?: '-') ?></div></td>
            <td style="font-size:12.5px"><?= fmtDate($p['jadwal_mulai']) ?><br>s/d <?= fmtDate($p['jadwal_selesai']) ?></td>
            <td><?= (int)$p['selesai'] ?>/<?= (int)$p['total_peserta'] ?></td>
            <td><span class="badge badge-<?= $p['status']==='active'?'success':'info' ?>"><?= e($p['status']) ?></span></td>
          </tr>
        <?php endforeach; if (!$paketAktif): ?>
          <tr><td colspan="4" class="empty-state">Belum ada paket aktif.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Peringkat Nilai Peserta</h3><a class="btn btn-sm btn-outline" href="laporan.php">Laporan PDF</a></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>#</th><th>Peserta</th><th>Rata Nilai</th><th>Modul</th></tr></thead>
        <tbody>
        <?php foreach ($leader as $i => $l): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e($l['nama']) ?> <span class="badge badge-muted"><?= $l['participant_type']==='employee'?'Karyawan':'Calon' ?></span></td>
            <td><div class="score-bar" style="max-width:120px"><span style="width:<?= (float)$l['rata_nilai'] ?>%"></span></div><strong><?= number_format((float)$l['rata_nilai'], 1) ?></strong></td>
            <td><?= (int)$l['modul_selesai'] ?></td>
          </tr>
        <?php endforeach; if (!$leader): ?>
          <tr><td colspan="4" class="empty-state">Belum ada nilai final.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-3">
  <div class="card"><div class="card-body text-center">
    <div style="font-size:30px">🗂️</div><h4>Buat Soal Baru</h4>
    <p class="text-muted">TPA, PAPI, DISC, Praktik Office, Esai.</p>
    <a class="btn btn-primary w-100" href="bank_soal.php?aksi=tambah">+ Tambah Soal</a>
    <a class="btn btn-outline w-100" href="kategori_soal.php?aksi=tambah" style="margin-top:8px">🏷️ Kelola Kategori Soal</a>
    <a class="btn btn-outline w-100" href="hasil_peserta.php" style="margin-top:8px">🔍 Lihat Hasil &amp; Jawaban Peserta</a>
  </div></div>
  <div class="card"><div class="card-body text-center">
    <div style="font-size:30px">📦</div><h4>Susun Paket Tes</h4>
    <p class="text-muted">Gabungkan modul, jadwalkan, tunjuk peserta/token.</p>
    <a class="btn btn-primary w-100" href="paket_test.php?aksi=tambah">+ Buat Paket</a>
  </div></div>
  <div class="card"><div class="card-body text-center">
    <div style="font-size:30px">🧑‍⚖️</div><h4>Review Jawaban</h4>
    <p class="text-muted">Esai, praktik upload & psikotes menunggu penilaian.</p>
    <a class="btn btn-primary w-100" href="hr_review.php">Buka Antrean</a>
  </div></div>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
