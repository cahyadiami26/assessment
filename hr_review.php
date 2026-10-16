<?php
/**
 * HR ASSESSOR PORTAL — Antrean Periksa (Review Queue)
 * Daftar attempt peserta dengan status Pending Review untuk esai, praktik upload & psikotes.
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

require_once __DIR__ . '/includes/functions.php';
$db = getAppDb();

$mode = $_GET['mode'] ?? 'all'; // all | essay | file | psycho

$where = "a.status IN ('submitted','reviewed')";
$params = [];
if ($mode === 'essay')  $where .= " AND EXISTS(SELECT 1 FROM exam_answers x JOIN questions q ON q.id=x.question_id WHERE x.attempt_id=a.id AND q.tipe_jawaban='essay' AND x.review_status='pending')";
if ($mode === 'file')   $where .= " AND EXISTS(SELECT 1 FROM exam_answers x JOIN questions q ON q.id=x.question_id WHERE x.attempt_id=a.id AND q.tipe_jawaban='file_upload' AND x.uploaded_file IS NOT NULL AND x.review_status='pending')";
if ($mode === 'psycho') $where .= " AND EXISTS(SELECT 1 FROM exam_answers x JOIN questions q ON q.id=x.question_id JOIN question_categories c ON c.id=q.category_id WHERE x.attempt_id=a.id AND c.test_type='personality')";
// "all" = ada jawaban pending ATAU psikotes yang belum dievaluasi
if ($mode === 'all')     $where .= " AND (EXISTS(SELECT 1 FROM exam_answers x WHERE x.attempt_id=a.id AND x.review_status='pending') OR EXISTS(SELECT 1 FROM exam_answers x JOIN questions q ON q.id=x.question_id JOIN question_categories c ON c.id=q.category_id WHERE x.attempt_id=a.id AND c.test_type='personality'))";

$sql = "SELECT a.*, m.nama AS modul_nama, p.nama AS paket_nama, pp.participant_type, pp.candidate_name, pp.user_id, pp.candidate_email,
        (SELECT COUNT(*) FROM exam_answers x WHERE x.attempt_id=a.id AND x.review_status='pending') AS n_pending,
        (SELECT pe.rekomendasi FROM psycho_evaluations pe WHERE pe.attempt_id=a.id LIMIT 1) AS rekomendasi
        FROM exam_attempts a
        JOIN test_modules m ON m.id=a.module_id
        JOIN test_packages p ON p.id=a.package_id
        JOIN package_participants pp ON pp.id=a.participant_id
        WHERE $where ORDER BY a.submitted_at DESC LIMIT 200";
$st = $db->prepare($sql); $st->execute($params);
$rows = $st->fetchAll();

// Kaya nama karyawan dari db SSO
$userIds = array_filter(array_column($rows, 'user_id'));
$ssoUsers = [];
if ($userIds) {
    $in = implode(',', array_map('intval', $userIds));
    foreach (getSsoDb()->query("SELECT id,name,nik,avatar,division,department FROM users WHERE id IN ($in)")->fetchAll() as $u) {
        $ssoUsers[(int)$u['id']] = $u;
    }
}

$pageTitle = 'Antrean Periksa'; $activeMenu = 'review';
require_once __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <div><h3>🧑‍⚖️ Review Queue — Penilaian Manual HR</h3>
      <span class="sub">Esai, praktik file upload, dan interpretasi psikotes (PAPI/DISC/Motivasi)</span></div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <div class="tabs">
      <a class="tab-btn <?= $mode==='all'?'active':'' ?>" href="hr_review.php?mode=all">Semua (<?= count($rows) ?>)</a>
      <a class="tab-btn <?= $mode==='essay'?'active':'' ?>" href="hr_review.php?mode=essay">Esai</a>
      <a class="tab-btn <?= $mode==='file'?'active':'' ?>" href="hr_review.php?mode=file">Praktik File</a>
      <a class="tab-btn <?= $mode==='psycho'?'active':'' ?>" href="hr_review.php?mode=psycho">Psikotes</a>
    </div>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Peserta</th><th>Paket / Modul</th><th>Dikirim</th><th>Skor Auto</th><th>Menunggu</th><th>Status</th><th></th></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $emp = $ssoUsers[(int)$r['user_id']] ?? null;
        $nama = $r['candidate_name'] ?: ($emp['name'] ?? 'Karyawan');
        $avatar = $emp['avatar'] ?? null; ?>
      <tr>
        <td><div class="cell-user"><?= renderAvatar($avatar, $nama, 'avatar-sm') ?>
          <div><strong><?= e($nama) ?></strong>
            <div class="text-muted" style="font-size:12px"><?= $r['participant_type']==='employee' ? 'Karyawan · '.e($emp['nik'] ?? '') : 'Calon · '.e($r['candidate_email'] ?? '') ?></div>
          </div></div></td>
        <td><strong style="font-size:13px"><?= e($r['modul_nama']) ?></strong>
          <div class="text-muted" style="font-size:12px"><?= e($r['paket_nama']) ?></div></td>
        <td style="font-size:12.5px"><?= fmtDate($r['submitted_at']) ?>
          <?= $r['auto_submitted'] ? '<br><span class="badge badge-warning">⏱ auto-submit</span>' : '' ?>
          <?= $r['tab_switches'] > 0 ? '<br><span class="badge badge-danger">👁 pindah tab ' . (int)$r['tab_switches'] . '×</span>' : '' ?></td>
        <td><strong><?= $r['score_auto'] !== null ? number_format((float)$r['score_auto'],1) : '-' ?></strong>/100</td>
        <td><?= (int)$r['n_pending'] ?> item</td>
        <td>
          <?php if ($r['rekomendasi']): ?>
            <span class="badge badge-<?= $r['rekomendasi']==='recommended'?'success':($r['rekomendasi']==='consider'?'warning':'danger') ?>"><?= e($r['rekomendasi']) ?></span>
          <?php else: ?>
            <span class="badge badge-<?= $r['status']==='reviewed'?'success':'info' ?>"><?= e($r['status']) ?></span>
          <?php endif; ?>
        </td>
        <td><a class="btn btn-primary btn-sm" href="hr_review_detail.php?attempt=<?= (int)$r['id'] ?>">Periksa →</a></td>
      </tr>
    <?php endforeach; if (!$rows): ?>
      <tr><td colspan="7"><div class="empty-state"><div class="big-ico">✅</div>Tidak ada antrean periksa pada kategori ini.</div></td></tr>
    <?php endif; ?>
    </tbody></table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
