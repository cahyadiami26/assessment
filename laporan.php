<?php
/**
 * Laporan & Unified Candidate Report — HR/Admin
 * Daftar seluruh peserta per paket + tombol unduh PDF (domPDF).
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

$db = getAppDb();
$fPaket = (int)($_GET['paket'] ?? 0);

$pkgs = $db->query('SELECT id, nama FROM test_packages ORDER BY jadwal_mulai DESC')->fetchAll();

$sql = 'SELECT pp.*, p.nama AS paket_nama,
          (SELECT AVG(a.final_score) FROM exam_attempts a WHERE a.participant_id=pp.id) AS rata_final,
          (SELECT COUNT(*) FROM exam_attempts a WHERE a.participant_id=pp.id AND a.status="reviewed") AS modul_reviewed,
          (SELECT COUNT(*) FROM package_modules pm WHERE pm.package_id=pp.package_id) AS total_modul,
          (SELECT pe.rekomendasi FROM psycho_evaluations pe JOIN exam_attempts a ON a.id=pe.attempt_id WHERE a.participant_id=pp.id ORDER BY pe.evaluated_at DESC LIMIT 1) AS rekomendasi
        FROM package_participants pp JOIN test_packages p ON p.id=pp.package_id';
$params = [];
if ($fPaket) { $sql .= ' WHERE pp.package_id=?'; $params[] = $fPaket; }
$sql .= ' ORDER BY p.jadwal_mulai DESC, rata_final DESC LIMIT 500';
$st = $db->prepare($sql); $st->execute($params);
$rows = $st->fetchAll();

$userIds = array_filter(array_column($rows, 'user_id'));
$ssoUsers = [];
if ($userIds) {
    $in = implode(',', array_map('intval', $userIds));
    foreach (getSsoDb()->query("SELECT id,name,nik,avatar FROM users WHERE id IN ($in)")->fetchAll() as $u) {
        $ssoUsers[(int)$u['id']] = $u;
    }
}

$pageTitle = 'Laporan Hasil'; $activeMenu = 'laporan';
require_once __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <div><h3>📄 Unified Candidate Report</h3><span class="sub">Gabungan nilai auto-grading + manual review HR → PDF domPDF</span></div>
    <form method="get" class="flex gap-1 items-center">
      <select name="paket" class="form-control" style="width:260px" data-auto-submit>
        <option value="">— Semua Paket —</option>
        <?php foreach ($pkgs as $p): ?><option value="<?= $p['id'] ?>" <?= $fPaket===$p['id']?'selected':'' ?>><?= e($p['nama']) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Peserta</th><th>Paket</th><th>Progres Review</th><th>Rata Skor Akhir</th><th>Rekomendasi Psikotes</th><th>Laporan</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $emp = $ssoUsers[(int)$r['user_id']] ?? null;
        $nama = $r['candidate_name'] ?: ($emp['name'] ?? 'Karyawan');
        $rf = $r['rata_final'] !== null ? (float)$r['rata_final'] : null; ?>
      <tr>
        <td><div class="cell-user"><?= renderAvatar($emp['avatar'] ?? null, $nama, 'avatar-sm') ?>
          <div><strong><?= e($nama) ?></strong>
            <div class="text-muted" style="font-size:12px"><?= $r['participant_type']==='employee'?e($emp['nik'] ?? ''):'Calon · '.e($r['candidate_email']) ?></div></div></div></td>
        <td style="font-size:13px"><?= e($r['paket_nama']) ?></td>
        <td><span class="badge badge-<?= (int)$r['modul_reviewed']>=(int)$r['total_modul'] && (int)$r['total_modul']>0?'success':'warning' ?>"><?= (int)$r['modul_reviewed'] ?>/<?= (int)$r['total_modul'] ?> modul</span></td>
        <td><?php if ($rf!==null): ?>
              <div class="score-bar" style="max-width:110px;display:inline-block;vertical-align:middle"><span style="width:<?= $rf ?>%"></span></div>
              <strong><?= number_format($rf,1) ?></strong>
            <?php else: ?>-<?php endif; ?></td>
        <td><?php if ($r['rekomendasi']): ?>
              <span class="badge badge-<?= $r['rekomendasi']==='recommended'?'success':($r['rekomendasi']==='consider'?'warning':'danger') ?>"><?= e(str_replace('_',' ',$r['rekomendasi'])) ?></span>
            <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
        <td>
          <a class="btn btn-primary btn-sm" target="_blank" href="report_pdf.php?participant=<?= (int)$r['id'] ?>">📥 Unduh PDF</a>
        </td>
      </tr>
    <?php endforeach; if(!$rows) echo '<tr><td colspan="6"><div class="empty-state"><div class="big-ico">📄</div>Belum ada data peserta.</div></td></tr>'; ?>
    </tbody></table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
