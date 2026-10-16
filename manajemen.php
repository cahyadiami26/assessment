<?php
/**
 * MANAJEMEN PORTAL — Summary Dashboard & Laporan hasil (read-only).
 * Akses: jabatan manajerial (Direksi/Kepala/Manager/dll).
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isManagement()) { http_response_code(403); exit('Akses ditolak: halaman khusus manajemen.'); }

$db = getAppDb();

$totalPaket   = (int)$db->query('SELECT COUNT(*) FROM test_packages WHERE status!="draft"')->fetchColumn();
$totalPeserta = (int)$db->query('SELECT COUNT(*) FROM package_participants')->fetchColumn();
$selesai      = (int)$db->query('SELECT COUNT(*) FROM package_participants WHERE status="finished"')->fetchColumn();
$rataNilai    = (float)$db->query('SELECT COALESCE(AVG(final_score),0) FROM exam_attempts WHERE final_score IS NOT NULL')->fetchColumn();

// Distribusi rekomendasi psikotes
$rekRows = $db->query('SELECT rekomendasi, COUNT(*) n FROM psycho_evaluations GROUP BY rekomendasi')->fetchAll();
$rekMap  = array_column($rekRows, 'n', 'rekomendasi');

// Top performers lintas paket
$top = $db->query('SELECT pp.candidate_name, pp.user_id, p.nama paket, AVG(a.final_score) rata
                   FROM exam_attempts a JOIN package_participants pp ON pp.id=a.participant_id
                   JOIN test_packages p ON p.id=a.package_id
                   WHERE a.final_score IS NOT NULL
                   GROUP BY pp.id HAVING COUNT(a.id)>=1 ORDER BY rata DESC LIMIT 10')->fetchAll();
$userIds = array_filter(array_column($top,'user_id'));
$ssoNames = [];
if ($userIds) {
    $in = implode(',', array_map('intval',$userIds));
    foreach (getSsoDb()->query("SELECT id,name FROM users WHERE id IN ($in)")->fetchAll() as $u) $ssoNames[(int)$u['id']]=$u['name'];
}

// Ringkasan per paket
$perPaket = $db->query('SELECT p.id, p.nama, p.tujuan,
        (SELECT COUNT(*) FROM package_participants x WHERE x.package_id=p.id) n_peserta,
        (SELECT AVG(a.final_score) FROM exam_attempts a JOIN package_participants pp ON pp.id=a.participant_id WHERE pp.package_id=p.id AND a.final_score IS NOT NULL) rata
      FROM test_packages p WHERE p.status!="draft" ORDER BY p.jadwal_mulai DESC LIMIT 12')->fetchAll();

$pageTitle = 'Dashboard Manajemen'; $activeMenu = 'manajemen';
require_once __DIR__ . '/includes/header.php';
?>
<div class="grid grid-4">
  <div class="stat-card"><div class="stat-ico bg-blue">📦</div><div><div class="stat-value"><?= $totalPaket ?></div><div class="stat-label">Paket Assessment</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-gold">👥</div><div><div class="stat-value"><?= $totalPeserta ?></div><div class="stat-label">Total Peserta</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-green">✅</div><div><div class="stat-value"><?= $selesai ?></div><div class="stat-label">Menyelesaikan Tes</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-blue">🎯</div><div><div class="stat-value"><?= number_format($rataNilai,1) ?></div><div class="stat-label">Rata-Rata Nilai Nasional</div></div></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3>🧭 Distribusi Rekomendasi Psikotes</h3></div>
    <div class="card-body">
      <?php
      $tot = array_sum($rekMap) ?: 1;
      $defs = [
        ['recommended','✅ Recommended','var(--success)'],
        ['consider','🤔 Consider','var(--warning)'],
        ['not_recommended','❌ Not Recommended','var(--danger)'],
      ];
      foreach ($defs as [$k,$lbl,$color]):
        $n = (int)($rekMap[$k] ?? 0); $pct = round($n/$tot*100); ?>
        <div class="mb-2">
          <div class="flex justify-between" style="font-size:13px"><span><?= $lbl ?></span><strong><?= $n ?> peserta (<?= $pct ?>%)</strong></div>
          <div class="score-bar mt-1"><span style="width:<?= $pct ?>%;background:<?= $color ?>"></span></div>
        </div>
      <?php endforeach; if (!$rekRows) echo '<div class="empty-state">Belum ada evaluasi psikotes oleh HR.</div>'; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>🏆 Top Performers</h3></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>#</th><th>Nama</th><th>Paket</th><th>Rata Nilai</th></tr></thead>
      <tbody>
      <?php foreach ($top as $i=>$t): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= e($t['candidate_name'] ?: ($ssoNames[(int)$t['user_id']] ?? 'Karyawan')) ?></strong></td>
          <td style="font-size:12.5px"><?= e(mb_strimwidth($t['paket'],0,40,'…')) ?></td>
          <td><span class="badge badge-<?= $t['rata']>=85?'success':($t['rata']>=70?'info':'warning') ?>"><?= number_format((float)$t['rata'],1) ?></span></td>
        </tr>
      <?php endforeach; if(!$top) echo '<tr><td colspan="4" class="empty-state">Belum ada nilai final.</td></tr>'; ?>
      </tbody></table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>📊 Ringkasan Per Paket</h3>
    <span class="sub">Laporan detail peserta dapat diunduh melalui menu Laporan HRD</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Paket</th><th>Tujuan</th><th>Peserta</th><th>Rata-Rata Nilai</th><th>Grafik</th></tr></thead>
    <tbody>
    <?php foreach ($perPaket as $p): $r=(float)($p['rata'] ?? 0); ?>
      <tr>
        <td><strong><?= e($p['nama']) ?></strong></td>
        <td><span class="badge badge-gold"><?= $p['tujuan']==='recruitment'?'Rekrutmen':'Kinerja' ?></span></td>
        <td><?= (int)$p['n_peserta'] ?></td>
        <td><strong><?= $p['rata']!==null?number_format($r,1):'-' ?></strong></td>
        <td style="min-width:180px"><div class="score-bar"><span style="width:<?= $r ?>%"></span></div></td>
      </tr>
    <?php endforeach; if(!$perPaket) echo '<tr><td colspan="5" class="empty-state">Belum ada paket.</td></tr>'; ?>
    </tbody></table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
