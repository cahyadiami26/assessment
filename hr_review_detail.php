<?php
/**
 * HR ASSESSOR PORTAL — Side-by-Side Review & Form Evaluasi Psikotes
 * - Baca jawaban esai / unduh file praktik berdampingan dengan rubrik/kunci acuan
 * - Form rekomendasi (Recommended/Consider/Not Recommended), catatan, upload lampiran PDF
 * - Konsolidasi skor manual → final_score attempt
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

require_once __DIR__ . '/includes/functions.php';
$db = getAppDb();
$attemptId = (int)($_GET['attempt'] ?? 0);

/* Auto-migration ringan: pastikan kolom skor_manual ada di psycho_evaluations */
try {
    $col = $db->query("SHOW COLUMNS FROM psycho_evaluations LIKE 'skor_manual'")->fetch();
    if (!$col) $db->exec('ALTER TABLE psycho_evaluations ADD COLUMN skor_manual DECIMAL(6,2) NULL AFTER catatan_hr');
} catch (Throwable $e) {}

/* ---------- Ambil data attempt + peserta ---------- */
$st = $db->prepare('SELECT a.*, m.nama AS modul_nama, m.id AS mid, p.nama AS paket_nama, pp.*, 
                    cat.test_type AS mod_test_type
                    FROM exam_attempts a
                    JOIN test_modules m ON m.id=a.module_id
                    JOIN test_packages p ON p.id=a.package_id
                    JOIN package_participants pp ON pp.id=a.participant_id
                    LEFT JOIN question_categories cat ON 1=0
                    WHERE a.id=?');
$st->execute([$attemptId]);
$att = $st->fetch();
if (!$att) exit('Attempt tidak ditemukan.');

$emp = null;
if ($att['participant_type'] === 'employee' && $att['user_id']) {
    $su = getSsoDb()->prepare('SELECT id,name,nik,email,position,division,department,avatar FROM users WHERE id=? LIMIT 1');
    $su->execute([(int)$att['user_id']]);
    $emp = $su->fetch();
}
$pesertaNama  = $att['candidate_name'] ?: ($emp['name'] ?? 'Karyawan');
$pesertaEmail = $att['candidate_email'] ?: ($emp['email'] ?? '');

/* ---------- Simpan review per jawaban (esai/file) ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'grade') {
    verifyCsrf();
    $ansId = (int)$_POST['answer_id'];
    $pts   = (float)$_POST['points_awarded'];
    $notes = trim($_POST['reviewer_notes'] ?? '');
    $db->prepare('UPDATE exam_answers SET points_awarded=?, is_correct=NULL, reviewer_notes=?, reviewed_by=?, review_status="reviewed" WHERE id=?')
       ->execute([$pts, $notes, $_SESSION['user_id'], $ansId]);
    // Konsolidasi skor otomatis setiap kali HR menyimpan nilai manual
    recalcAttemptScore($db, $attemptId);
    flash('success', 'Penilaian tersimpan & skor direkalkulasi.');
    header("Location: hr_review_detail.php?attempt=$attemptId"); exit;
}

/* ---------- Simpan evaluasi psikotes + konsolidasi skor ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'psycho') {
    verifyCsrf();
    $rek  = $_POST['rekomendasi'] ?? '';
    $lebih= trim($_POST['kelebihan'] ?? '');
    $area = trim($_POST['area_pengembangan'] ?? '');
    $cat  = trim($_POST['catatan_hr'] ?? '');
    $skorManualPsy = ($_POST['score_manual'] ?? '') !== '' ? (float)$_POST['score_manual'] : null;

    // Skor manual utk attempt ini (dari jawaban esai/praktik yang direview) dihitung otomatis;
    // field skor_manual opsional di form dipakai utk psikotes murni tanpa soal bernilai.
    $pdfLama = $_POST['lampiran_lama'] ?? null;
    $baru = handleUpload($_FILES['lampiran_pdf'] ?? [], 'psychotes_reports', ['pdf'], 20);
    if ($baru) $pdfLama = $baru;

    $db->prepare('INSERT INTO psycho_evaluations (attempt_id, rekomendasi, kelebihan, area_pengembangan, catatan_hr, skor_manual, lampiran_pdf, evaluated_by)
                  VALUES (?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE rekomendasi=VALUES(rekomendasi), kelebihan=VALUES(kelebihan),
                    area_pengembangan=VALUES(area_pengembangan), catatan_hr=VALUES(catatan_hr),
                    skor_manual=VALUES(skor_manual),
                    lampiran_pdf=VALUES(lampiran_pdf), evaluated_by=VALUES(evaluated_by), evaluated_at=NOW()')
       ->execute([$attemptId, $rek, $lebih, $area, $cat, $skorManualPsy, $pdfLama, $_SESSION['user_id']]);

    /* Konsolidasi Skor Akhir attempt ini + seluruh attempt paket peserta */
    recalcAttemptScore($db, $attemptId, $skorManualPsy);
    // Rekap attempt lain milik peserta pada paket yang sama (jika belum dihitung)
    foreach ($db->query('SELECT id FROM exam_attempts WHERE participant_id=' . (int)$att['participant_id'])->fetchAll() as $other) {
        if ((int)$other['id'] !== $attemptId) recalcAttemptScore($db, (int)$other['id']);
    }
    flash('success', 'Evaluasi psikotes disimpan & skor akhir dikonsolidasi.');
    header("Location: hr_review_detail.php?attempt=$attemptId"); exit;
}

/* ---------- Data jawaban untuk tampilan ---------- */
$st = $db->prepare('SELECT ans.*, q.pertanyaan, q.tipe_jawaban, q.opsi, q.kunci_jawaban, q.rubrik, q.bobot, q.contoh_file, c.name AS kategori, c.test_type
                    FROM exam_answers ans JOIN questions q ON q.id=ans.question_id
                    JOIN question_categories c ON c.id=q.category_id
                    WHERE ans.attempt_id=? ORDER BY q.id');
$st->execute([$attemptId]);
$answers = $st->fetchAll();

$st = $db->prepare('SELECT * FROM psycho_evaluations WHERE attempt_id=?'); $st->execute([$attemptId]);
$psy = $st->fetch();

$hasPsycho = in_array('personality', array_column($answers, 'test_type'), true);

$pageTitle = 'Review: ' . $pesertaNama; $activeMenu = 'review';
require_once __DIR__ . '/includes/header.php';
?>

<div class="flex justify-between items-center flex-wrap gap-2 mb-2">
  <div>
    <h2 class="mb-0"><?= e($pesertaNama) ?></h2>
    <span class="text-muted"><?= e($att['paket_nama']) ?> → <strong><?= e($att['modul_nama']) ?></strong>
      · <?= $att['participant_type']==='employee' ? 'Karyawan ('.e($emp['nik'] ?? '').'/'.e($emp['position'] ?? '').')' : 'Calon Karyawan ('.e($pesertaEmail).')' ?></span>
  </div>
  <div class="flex gap-1">
    <a class="btn btn-outline btn-sm" href="hr_review.php">← Antrean</a>
    <a class="btn btn-primary btn-sm" target="_blank" href="report_pdf.php?participant=<?= (int)$att['participant_id'] ?>">📄 Unified Report PDF</a>
  </div>
</div>

<div class="grid grid-4">
  <div class="stat-card"><div class="stat-ico bg-blue">🤖</div><div><div class="stat-value"><?= $att['score_auto']!==null?number_format((float)$att['score_auto'],1):'-' ?></div><div class="stat-label">Skor Auto-Grading</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-gold">🧑‍⚖️</div><div><div class="stat-value"><?= $att['score_manual']!==null?number_format((float)$att['score_manual'],1):'-' ?></div><div class="stat-label">Skor Manual HR</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-green">🏁</div><div><div class="stat-value"><?= $att['final_score']!==null?number_format((float)$att['final_score'],1):'-' ?></div><div class="stat-label">Skor Final Modul</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-red">👁</div><div><div class="stat-value"><?= (int)$att['tab_switches'] ?></div><div class="stat-label">Pindah Tab (Proctoring)</div></div></div>
</div>

<!-- ================= JAWABAN PER SOAL (Side-by-Side) ================= -->
<?php foreach ($answers as $a):
    if (!in_array($a['tipe_jawaban'], ['essay','file_upload'], true)) continue;
    $opsi = json_decode((string)$a['opsi'], true) ?: [];
    $resp = json_decode((string)$a['answer_options'], true);
?>
<div class="card">
  <div class="card-header">
    <h3 style="font-size:14px">
      <?= $a['tipe_jawaban']==='essay' ? '✍️ Esai' : '📎 Praktik File' ?> — [<?= e($a['kategori']) ?>]
      <span class="badge badge-<?= $a['review_status']==='reviewed'?'success':'warning' ?>"><?= e($a['review_status']) ?></span>
    </h3>
    <span class="sub">Bobot maksimal: <?= e($a['bobot']) ?></span>
  </div>
  <div class="card-body">
    <div class="question-stem"><?= nl2br(e($a['pertanyaan'])) ?></div>
    <div class="review-split">
      <!-- Jawaban peserta -->
      <div class="answer-view">
        <h4 style="margin-top:0">Jawaban Peserta</h4>
        <?php if ($a['tipe_jawaban'] === 'essay'): ?>
          <div><?= $a['answer_text'] ?: '<em class="text-muted">(tidak dijawab)</em>' ?></div>
        <?php else: ?>
          <?php if (!empty($a['uploaded_file'])): $uf = $a['uploaded_file']; ?>
            <div class="flex items-center gap-1">
              <?= fileIcon($uf) ?>
              <a href="uploads/answer_files/<?= e($uf) ?>" target="_blank" download><strong><?= e($uf) ?></strong></a>
              <a class="btn btn-outline btn-sm" href="uploads/answer_files/<?= e($uf) ?>" download>⬇ Unduh</a>
            </div>
            <?php if (isPreviewableImage($uf)): ?><img src="uploads/answer_files/<?= e($uf) ?>" style="max-width:100%;max-height:340px;border-radius:10px;margin-top:12px;border:1px solid var(--border)"><?php endif; ?>
            <?php if (isPdf($uf)): ?><iframe src="uploads/answer_files/<?= e($uf) ?>" style="width:100%;height:420px;border:1px solid var(--border);border-radius:10px;margin-top:12px"></iframe><?php endif; ?>
          <?php else: ?><em class="text-muted">Peserta tidak mengunggah berkas.</em><?php endif; ?>
        <?php endif; ?>
      </div>
      <!-- Rubrik / kunci acuan -->
      <div class="rubric-view">
        <h4 style="margin-top:0">📌 Rubrik / Acuan Penilaian</h4>
        <div style="white-space:pre-line"><?= $a['rubrik'] ?: '<em class="text-muted">Rubrik belum diisi oleh HR saat membuat soal.</em>' ?></div>
        <?php if (!empty($a['contoh_file'])): $cf=$a['contoh_file']; ?>
          <div class="divider"></div>
          <strong>Contoh hasil akhir:</strong> <?= fileIcon($cf) ?>
          <a href="uploads/reference_files/<?= e($cf) ?>" target="_blank">buka / unduh</a>
          <?php if (isPreviewableImage($cf)): ?><img src="uploads/reference_files/<?= e($cf) ?>" style="max-width:100%;max-height:260px;border-radius:8px;margin-top:8px"><?php endif; ?>
          <?php if (isPdf($cf)): ?><iframe src="uploads/reference_files/<?= e($cf) ?>" style="width:100%;height:300px;border:1px solid var(--border);border-radius:8px;margin-top:8px"></iframe><?php endif; ?>
        <?php endif; ?>

        <form method="post" class="mt-2">
          <?= csrfField() ?><input type="hidden" name="form" value="grade"><input type="hidden" name="answer_id" value="<?= $a['id'] ?>">
          <div class="form-group"><label class="form-label">Nilai (0 – <?= e($a['bobot']) ?>)</label>
            <input type="number" step="0.5" min="0" max="<?= e($a['bobot']) ?>" name="points_awarded" class="form-control" value="<?= e($a['points_awarded'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Catatan Penilai</label>
            <textarea name="reviewer_notes" class="form-control" rows="2"><?= e($a['reviewer_notes'] ?? '') ?></textarea></div>
          <button class="btn btn-success btn-sm">💾 Simpan Nilai</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ================= RAW ANSWERS PSIKOTES ================= -->
<?php $psyAnswers = array_filter($answers, fn($x)=>$x['test_type']==='personality'); ?>
<?php if ($psyAnswers): ?>
<div class="card">
  <div class="card-header"><h3>🧠 Jawaban Mentah Psikotes (Forced Choice)</h3>
    <span class="sub">Interpretasi & rekomendasi dinilai manual di bawah</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Pernyataan</th><th>Pilihan Peserta</th></tr></thead>
    <tbody>
    <?php foreach ($psyAnswers as $a):
        $opsi = json_decode((string)$a['opsi'], true) ?: [];
        $r = json_decode((string)$a['answer_options'], true) ?: [];
        $pickTxt = '-';
        if (isset($r['pick'])) $pickTxt = e($opsi[$r['pick']] ?? '?');
        elseif (isset($r['most']) || isset($r['least'])) {
            $pickTxt = 'MOST: ' . e($opsi[$r['most']] ?? '-') . '<br>LEAST: ' . e($opsi[$r['least']] ?? '-');
        } elseif (is_array($r)) {
            $pickTxt = e(implode(', ', array_map(fn($k)=>$opsi[$k] ?? $k, $r)));
        } ?>
      <tr><td style="max-width:420px"><?= nl2br(e(mb_strimwidth(preg_replace('/\s+/',' ',$a['pertanyaan']),0,160,'…'))) ?></td><td><?= $pickTxt ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<!-- ================= FORM EVALUASI KUALITATIF PSIKOTES ================= -->
<?php if ($hasPsycho || $psy): ?>
<div class="card">
  <div class="card-header"><h3>📝 Form Evaluasi Kualitatif Psikotes</h3>
    <?php if ($psy && $psy['lampiran_pdf']): ?>
      <a class="btn btn-outline btn-sm" target="_blank" href="uploads/psychotes_reports/<?= e($psy['lampiran_pdf']) ?>">📕 Lihat Lampiran Analisis</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?><input type="hidden" name="form" value="psycho">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Status Rekomendasi</label>
          <select name="rekomendasi" class="form-control">
            <option value="">— Pilih —</option>
            <option value="recommended"      <?= ($psy['rekomendasi']??'')==='recommended'?'selected':'' ?>>✅ Recommended</option>
            <option value="consider"         <?= ($psy['rekomendasi']??'')==='consider'?'selected':'' ?>>🤔 Consider</option>
            <option value="not_recommended"  <?= ($psy['rekomendasi']??'')==='not_recommended'?'selected':'' ?>>❌ Not Recommended</option>
          </select></div>
        <div class="form-group"><label class="form-label">Skor Manual Psikotes (0–100, opsional)</label>
          <input type="number" min="0" max="100" step="1" name="score_manual" class="form-control" placeholder="Isi bila memberi bobot numerik" value="<?= e($psy['skor_manual'] ?? '') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Kelebihan</label>
          <textarea name="kelebihan" class="form-control" rows="3"><?= e($psy['kelebihan'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Area Pengembangan</label>
          <textarea name="area_pengembangan" class="form-control" rows="3"><?= e($psy['area_pengembangan'] ?? '') ?></textarea></div>
      </div>
      <div class="form-group"><label class="form-label">Catatan HR</label>
        <textarea name="catatan_hr" class="form-control" rows="2"><?= e($psy['catatan_hr'] ?? '') ?></textarea></div>
      <div class="form-group"><label class="form-label">Lampiran PDF Hasil Analisis Psikotes</label>
        <input type="file" name="lampiran_pdf" class="form-control" accept=".pdf">
        <?php if ($psy && !empty($psy['lampiran_pdf'])): ?>
          <input type="hidden" name="lampiran_lama" value="<?= e($psy['lampiran_pdf']) ?>">
          <div class="form-hint">File aktif: <?= e($psy['lampiran_pdf']) ?> (upload baru akan menggantikannya)</div>
        <?php endif; ?>
      </div>
      <button class="btn btn-primary">💾 Simpan Evaluasi & Konsolidasi Skor</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php';
