<?php
/**
 * Modul Hasil & Jawaban Peserta — HR/Admin (+ Manajemen read-only)
 * Melihat hasil dan jawaban peserta untuk SEMUA jenis tes:
 *   - single/multiple  : tampilkan pilihan vs kunci + benar/salah (auto-grading)
 *   - forced_pair/most_least : tampilkan jawaban mentah psikotes (interpretasi manual HR)
 *   - file_upload      : instruksi, file jawaban peserta (preview/unduh), rubrik & nilai manual
 *   - essay            : jawaban rich text, rubrik, catatan reviewer & nilai manual
 * Ditambah ringkasan attempt: skor auto/manual/final, status review, waktu, proctoring.
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd() && !isManagement()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin & Manajemen.'); }

require_once __DIR__ . '/includes/functions.php';
$db = getAppDb();

$TIPE_LABEL = [
    'single' => 'Pilihan Ganda', 'multiple' => 'Multiple Select', 'forced_pair' => 'Forced Pair (PAPI)',
    'most_least' => 'Most/Least', 'file_upload' => 'Praktik (Upload File)', 'essay' => 'Esai/Uraian',
];
$STATUS_BADGE = [
    'not_started' => ['muted', 'Belum Mulai'], 'in_progress' => ['warning', 'Sedang Mengerjakan'],
    'submitted'   => ['info', 'Menunggu Review'], 'reviewed' => ['success', 'Selesai Dinilai'],
];

/* Karyawan: nama asli dari tabel users (db SSO) via cross-db JOIN */
$NAMA_PESERTA = "COALESCE(u.name, pp.candidate_name, 'Karyawan')";

$attemptId = (int)($_GET['attempt'] ?? 0);

/* ================= DETAIL JAWABAN PESERTA ================= */
if ($attemptId):
    $st = $db->prepare("SELECT a.*, m.nama AS modul_nama, m.durasi_menit, p.nama AS paket_nama, p.tujuan,
                        pp.participant_type, pp.user_id, pp.candidate_name, pp.candidate_email, pp.access_token,
                        u.name AS user_name, u.nik AS user_nik, u.email AS user_email, u.position AS user_position,
                        u.division AS user_division, u.department AS user_department, u.avatar AS user_avatar
                        FROM exam_attempts a
                        JOIN test_modules m          ON m.id = a.module_id
                        JOIN test_packages p          ON p.id = a.package_id
                        JOIN package_participants pp  ON pp.id = a.participant_id
                        LEFT JOIN " . usersTable() . " u ON u.id = pp.user_id
                        WHERE a.id = ?");
    $st->execute([$attemptId]);
    $att = $st->fetch();
    if (!$att) { flash('danger', 'Data ujian (attempt) tidak ditemukan.'); header('Location: hasil_peserta.php'); exit; }

    $namaPeserta  = $att['user_name'] ?: ($att['candidate_name'] ?: 'Peserta');
    $avatarPeserta = $att['participant_type'] === 'employee' ? $att['user_avatar'] : null;

    /* Evaluasi psikotes (jika sudah diisi HR) */
    $psycho = null;
    try {
        $sp = $db->prepare('SELECT pe.*, evu.name AS evaluator_name FROM psycho_evaluations pe
                            LEFT JOIN ' . usersTable() . ' evu ON evu.id = pe.evaluated_by
                            WHERE pe.attempt_id = ?');
        $sp->execute([$attemptId]);
        $psycho = $sp->fetch();
    } catch (Throwable $e) {}

    /* Daftar jawaban + soal + kategori */
    $sq = $db->prepare("SELECT ea.*, q.pertanyaan, q.tipe_jawaban, q.opsi, q.kunci_jawaban, q.bobot,
                        q.rubrik, q.contoh_file, c.name AS kategori_nama, c.test_type
                        FROM exam_answers ea
                        JOIN questions q ON q.id = ea.question_id
                        JOIN question_categories c ON c.id = q.category_id
                        WHERE ea.attempt_id = ? ORDER BY q.id ASC");
    $sq->execute([$attemptId]);
    $answers = $sq->fetchAll();

    $nBenar = 0; $nSalah = 0; $nManualPending = 0;
    foreach ($answers as $aR) {
        if ($aR['is_correct'] === '1' || $aR['is_correct'] === 1) $nBenar++;
        elseif ($aR['is_correct'] !== null) $nSalah++;
        if ($aR['review_status'] === 'pending') $nManualPending++;
    }

    $pageTitle  = 'Hasil & Jawaban: ' . $namaPeserta;
    $activeMenu = 'hasil_peserta';
    require_once __DIR__ . '/includes/header.php';

    /** Render blok jawaban per tipe soal */
    function renderJawaban($a, array $TIPE_LABEL): void
    {
        $opsi  = $a['opsi'] ? json_decode($a['opsi'], true) : [];
        $kunci = $a['kunci_jawaban'] ? json_decode($a['kunci_jawaban'], true) : null;
        $isNote = is_array($kunci) && isset($kunci['note']);
        $ansOpts = $a['answer_options'] ? json_decode($a['answer_options'], true) : null;
        $t = $a['tipe_jawaban'];

        if ($t === 'single' || $t === 'multiple') {
            // Normalisasi kunci & jawaban (kompatibel data lama berformat label "A"/"B")
            $kunciIdx = (!$isNote && $a['kunci_jawaban']) ? normalizeAnswerKeys($a['kunci_jawaban'], count((array)$opsi)) : [];
            $ansIdx   = $a['answer_options'] ? normalizeAnswerKeys($a['answer_options'], count((array)$opsi)) : [];
            echo '<div style="display:flex;flex-direction:column;gap:6px">';
            foreach ((array)$opsi as $i => $o) {
                $letter = chr(65 + $i);
                $dijawab = in_array((string)$i, $ansIdx, true);
                $benar   = in_array((string)$i, $kunciIdx, true);
                $style = '';
                if ($dijawab && $benar)  $style = 'background:var(--success-bg);border-color:#bde8d3';
                elseif ($dijawab && !$benar) $style = 'background:var(--danger-bg);border-color:#f2c7c7';
                elseif ($benar)         $style = 'border-style:dashed;border-color:var(--success)';
                echo '<div class="check-line" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;' . e($style) . '">'
                    . '<strong>' . $letter . '.</strong> ' . e((string)$o)
                    . ($dijawab ? ' <span class="badge badge-info">dipilih peserta</span>' : '')
                    . ($benar ? ' <span class="badge badge-success">kunci</span>' : '')
                    . '</div>';
            }
            echo '</div>';
            if ($a['is_correct'] !== null) {
                echo '<div class="mt-1">' . ((int)$a['is_correct'] === 1
                    ? '<span class="badge badge-success">✓ Benar · poin ' . e($a['points_awarded']) . '</span>'
                    : '<span class="badge badge-danger">✗ Salah · poin ' . e($a['points_awarded']) . '</span>') . '</div>';
            }
        } elseif ($t === 'forced_pair') {
            $pilihan = is_array($ansOpts) ? ($ansOpts['choice'] ?? ($ansOpts[0] ?? null)) : $ansOpts;
            if (is_string($pilihan)) $pilihan = (int)$pilihan;
            echo '<div style="display:flex;flex-direction:column;gap:6px">';
            foreach ((array)$opsi as $i => $o) {
                $dijawab = ($pilihan !== null && (int)$pilihan === (int)$i);
                echo '<div class="check-line" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;'
                    . ($dijawab ? 'background:var(--primary-100);border-color:var(--primary-600)' : '') . '">'
                    . e((string)$o) . ($dijawab ? ' <span class="badge badge-info">dipilih peserta</span>' : '') . '</div>';
            }
            echo '</div><div class="form-hint mt-1">Skor mentah tersimpan — interpretasi dilakukan HR pada form evaluasi psikotes.</div>';
        } elseif ($t === 'most_least') {
            $most  = is_array($ansOpts) ? ($ansOpts['most'] ?? null) : null;
            $least = is_array($ansOpts) ? ($ansOpts['least'] ?? null) : null;
            echo '<div style="display:flex;flex-direction:column;gap:6px">';
            foreach ((array)$opsi as $i => $o) {
                $tags = '';
                if ($most !== null && (int)$most === (int)$i)  $tags .= ' <span class="badge badge-gold">MOST</span>';
                if ($least !== null && (int)$least === (int)$i) $tags .= ' <span class="badge badge-muted">LEAST</span>';
                echo '<div style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;' . ($tags ? 'background:var(--primary-100)' : '') . '">'
                    . e((string)$o) . $tags . '</div>';
            }
            echo '</div>';
            if ($most === null && $least === null) echo '<div class="form-hint">(tidak dijawab)</div>';
        } elseif ($t === 'essay') {
            $txt = trim((string)$a['answer_text']);
            if ($txt === '') {
                echo '<div class="form-hint">(peserta tidak menulis jawaban)</div>';
            } else {
                // Jawaban esai disimpan sebagai rich text (HTML) — tampil aman dengan strip tag berat
                echo '<div style="padding:12px 14px;background:#f8fafc;border:1px solid var(--border);border-radius:8px;line-height:1.65">'
                    . $txt /* HTML dari editor peserta, disanitasi saat input */ . '</div>';
            }
        } elseif ($t === 'file_upload') {
            $uf = $a['uploaded_file'];
            if ($uf) {
                echo '<div class="flex items-center gap-1">' . fileIcon($uf)
                    . ' <a href="uploads/answer_files/' . e($uf) . '" target="_blank" download><strong>' . e($uf) . '</strong></a>'
                    . ' <a class="btn btn-outline btn-sm" href="uploads/answer_files/' . e($uf) . '" download>⬇ Unduh</a></div>';
                if (isPreviewableImage($uf)) echo '<img src="uploads/answer_files/' . e($uf) . '" style="max-width:100%;max-height:340px;border-radius:10px;margin-top:10px;border:1px solid var(--border)">';
                if (isPdf($uf)) echo '<iframe src="uploads/answer_files/' . e($uf) . '" style="width:100%;height:380px;border:1px solid var(--border);border-radius:10px;margin-top:10px"></iframe>';
            } else {
                echo '<span class="badge badge-danger">Tidak mengunggah file</span>';
            }
            if (!empty($a['answer_text'])) echo '<div class="mt-1" style="font-size:13px;color:var(--muted)">Catatan peserta: ' . e($a['answer_text']) . '</div>';
        }
    }
?>

  <div class="card">
    <div class="card-header">
      <div style="display:flex;align-items:center;gap:14px">
        <?= renderAvatar($avatarPeserta, $namaPeserta) ?>
        <div>
          <h3 style="margin:0"><?= e($namaPeserta) ?></h3>
          <span class="sub">
            <?= e($att['modul_nama']) ?> · Paket: <?= e($att['paket_nama']) ?>
            (<?= $att['tujuan'] === 'recruitment' ? 'Rekrutmen' : 'Evaluasi Kinerja' ?>)
            <?php if ($att['participant_type'] === 'candidate'): ?>
              · Calon Karyawan <?= e($att['candidate_email'] ?: '') ?> · Token: <code><?= e(substr((string)$att['access_token'], 0, 8)) ?>…</code>
            <?php else: ?>
              · Karyawan (SSO) <?= $att['user_nik'] ? '· NIK ' . e($att['user_nik']) : '' ?><?= $att['user_position'] ? ' · ' . e($att['user_position']) : '' ?>
            <?php endif; ?>
          </span>
        </div>
      </div>
      <a class="btn btn-ghost btn-sm" href="hasil_peserta.php">← Kembali ke Daftar</a>
    </div>
    <div class="card-body">
      <div class="grid grid-4" style="gap:12px">
        <div class="stat-card"><div class="stat-ico bg-blue">🧮</div><div>
          <div class="stat-value"><?= $att['score_auto'] !== null ? e($att['score_auto']) : '—' ?></div>
          <div class="stat-label">Skor Auto-Grading</div></div></div>
        <div class="stat-card"><div class="stat-ico bg-gold">🧑‍⚖️</div><div>
          <div class="stat-value"><?= $att['score_manual'] !== null ? e($att['score_manual']) : '—' ?></div>
          <div class="stat-label">Skor Manual HR</div></div></div>
        <div class="stat-card"><div class="stat-ico bg-green">🏁</div><div>
          <div class="stat-value"><?= $att['final_score'] !== null ? e($att['final_score']) : '—' ?></div>
          <div class="stat-label">Nilai Akhir</div></div></div>
        <div class="stat-card"><div class="stat-ico bg-red">⏱️</div><div>
          <div class="stat-value" style="font-size:15px"><?= fmtDate($att['started_at']) ?></div>
          <div class="stat-label">Mulai · <?= $att['submitted_at'] ? 'selesai ' . fmtDate($att['submitted_at']) . ($att['auto_submitted'] ? ' (auto-submit)' : '') : 'belum submit' ?></div></div></div>
      </div>
      <div class="mt-1 flex items-center gap-1" style="flex-wrap:wrap">
        <span class="badge badge-<?= $STATUS_BADGE[$att['status']][0] ?? 'muted' ?>"><?= e($STATUS_BADGE[$att['status']][1] ?? $att['status']) ?></span>
        <span class="badge badge-muted"><?= count($answers) ?> soal dijawab</span>
        <span class="badge badge-success"><?= $nBenar ?> benar</span>
        <span class="badge badge-danger"><?= $nSalah ?> salah</span>
        <?php if ($nManualPending): ?><span class="badge badge-warning"><?= $nManualPending ?> menunggu review manual</span><?php endif; ?>
        <?php if ((int)$att['tab_switches'] > 0): ?><span class="badge badge-warning">⚠ Pindah tab: <?= (int)$att['tab_switches'] ?>×</span><?php endif; ?>
        <?php if ($psycho): ?>
          <span class="badge badge-gold">Psikotes: <?= strtoupper(e($psycho['rekomendasi'] ?? '-')) ?></span>
        <?php endif; ?>
        <?php if (isHrd()): ?>
          <a class="btn btn-outline btn-sm" style="margin-left:auto" href="hr_review_detail.php?attempt=<?= (int)$att['id'] ?>">🧑‍⚖️ Buka Panel Review / Beri Nilai</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($psycho): ?>
  <div class="card">
    <div class="card-header"><h3>Evaluasi Psikotes oleh HR</h3></div>
    <div class="card-body">
      <div class="grid grid-2" style="gap:12px">
        <div>
          <label class="form-label">Rekomendasi</label>
          <span class="badge badge-<?= $psycho['rekomendasi'] === 'recommended' ? 'success' : ($psycho['rekomendasi'] === 'consider' ? 'warning' : 'danger') ?>">
            <?= strtoupper(e($psycho['rekomendasi'] ?? '-')) ?></span>
          <span class="form-hint">oleh <?= e($psycho['evaluator_name'] ?? '-') ?> · <?= fmtDate($psycho['evaluated_at']) ?></span>
        </div>
        <div>
          <?php if (!empty($psycho['lampiran_pdf'])): ?>
            <label class="form-label">Lampiran Analisis (PDF)</label><br>
            📕 <a href="uploads/psychotes_reports/<?= e($psycho['lampiran_pdf']) ?>" target="_blank"><?= e($psycho['lampiran_pdf']) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($psycho['kelebihan'])): ?><label class="form-label mt-1">Kelebihan</label><div style="font-size:14px"><?= nl2br(e($psycho['kelebihan'])) ?></div><?php endif; ?>
      <?php if (!empty($psycho['area_pengembangan'])): ?><label class="form-label mt-1">Area Pengembangan</label><div style="font-size:14px"><?= nl2br(e($psycho['area_pengembangan'])) ?></div><?php endif; ?>
      <?php if (!empty($psycho['catatan_hr'])): ?><label class="form-label mt-1">Catatan HR</label><div style="font-size:14px"><?= nl2br(e($psycho['catatan_hr'])) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h3>Rincian Jawaban — Semua Tipe Tes (<?= count($answers) ?>)</h3>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
      <?php if (!$answers): ?>
        <div class="empty-state"><div class="big-ico">📝</div>Belum ada jawaban tersimpan untuk sesi ini.</div>
      <?php endif; ?>
      <?php foreach ($answers as $i => $a): ?>
        <div style="border:1px solid var(--border);border-radius:12px;padding:16px">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
            <strong style="color:var(--navy-900)">Soal #<?= $i + 1 ?></strong>
            <span class="badge badge-info"><?= e($a['kategori_nama']) ?></span>
            <span class="badge badge-muted"><?= e($TIPE_LABEL[$a['tipe_jawaban']] ?? $a['tipe_jawaban']) ?></span>
            <span class="badge badge-muted">bobot <?= e($a['bobot']) ?></span>
            <?php if ($a['review_status'] === 'reviewed'): ?>
              <span class="badge badge-success">✓ dinilai manual · poin <?= e($a['points_awarded']) ?></span>
            <?php elseif ($a['review_status'] === 'pending'): ?>
              <span class="badge badge-warning">menunggu review</span>
            <?php elseif ($a['is_correct'] !== null): ?>
              <span class="badge badge-<?= (int)$a['is_correct'] === 1 ? 'success' : 'danger' ?>">auto · poin <?= e($a['points_awarded']) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-weight:600;margin-bottom:10px"><?= nl2br(e(preg_replace('/\s+/', ' ', $a['pertanyaan']))) ?></div>

          <?php if (!empty($a['contoh_file'])): ?>
            <div class="form-hint" style="margin-bottom:8px">📎 File kasus/referensi:
              <a href="uploads/reference_files/<?= e($a['contoh_file']) ?>" target="_blank"><?= e($a['contoh_file']) ?></a></div>
          <?php endif; ?>

          <label class="form-label">Jawaban Peserta</label>
          <?php renderJawaban($a, $TIPE_LABEL); ?>

          <?php if (!empty($a['rubrik'])): ?>
            <details style="margin-top:10px"><summary style="cursor:pointer;font-size:13px;color:var(--muted)">Rubrik penilaian</summary>
              <div style="font-size:13px;padding:10px;background:#f8fafc;border-radius:8px;margin-top:6px"><?= nl2br(e($a['rubrik'])) ?></div></details>
          <?php endif; ?>
          <?php if (!empty($a['reviewer_notes'])): ?>
            <div class="alert alert-info mt-1" style="margin-bottom:0"><strong>Catatan reviewer:</strong> <?= nl2br(e($a['reviewer_notes'])) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/footer.php'; exit; endif; ?>
<?php
/* ================= LISTING HASIL SEMUA PESERTA ================= */
$fPaket   = (int)($_GET['paket'] ?? 0);
$fModul   = (int)($_GET['modul'] ?? 0);
$fStatus  = $_GET['status'] ?? '';
$fQ       = trim($_GET['q'] ?? '');

$packages = $db->query('SELECT id, nama FROM test_packages ORDER BY jadwal_mulai DESC')->fetchAll();
$modules  = $db->query('SELECT id, nama FROM test_modules WHERE status != "arsip" ORDER BY nama')->fetchAll();

$sql = "SELECT a.*, m.nama AS modul_nama, p.nama AS paket_nama, pp.participant_type,
        pp.candidate_name, $NAMA_PESERTA AS peserta_nama, u.avatar AS peserta_avatar,
        u.position AS peserta_position,
        (SELECT COUNT(*) FROM exam_answers ea WHERE ea.attempt_id=a.id AND ea.review_status='pending') AS n_pending,
        (SELECT COUNT(*) FROM exam_answers ea WHERE ea.attempt_id=a.id) AS n_jawab
        FROM exam_attempts a
        JOIN test_modules m          ON m.id = a.module_id
        JOIN test_packages p          ON p.id = a.package_id
        JOIN package_participants pp  ON pp.id = a.participant_id
        LEFT JOIN " . usersTable() . " u ON u.id = pp.user_id
        WHERE 1=1";
$params = [];
if ($fPaket)  { $sql .= ' AND a.package_id = ?';  $params[] = $fPaket; }
if ($fModul)  { $sql .= ' AND a.module_id = ?';   $params[] = $fModul; }
if ($fStatus) { $sql .= ' AND a.status = ?';      $params[] = $fStatus; }
if ($fQ)      { $sql .= " AND ($NAMA_PESERTA LIKE ? OR pp.candidate_email LIKE ?)"; $params[] = "%$fQ%"; $params[] = "%$fQ%"; }
$sql .= ' ORDER BY a.id DESC LIMIT 400';
$st = $db->prepare($sql); $st->execute($params);
$rows = $st->fetchAll();

$counts = ['total' => count($rows)];
foreach ($rows as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }

$pageTitle  = 'Hasil & Jawaban Peserta';
$activeMenu = 'hasil_peserta';
require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4">
  <div class="stat-card"><div class="stat-ico bg-blue">📋</div><div><div class="stat-value"><?= $counts['total'] ?></div><div class="stat-label">Sesi Ujian Tampil</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-red">⏳</div><div><div class="stat-value"><?= $counts['in_progress'] ?? 0 ?></div><div class="stat-label">Sedang Mengerjakan</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-gold">🧑‍⚖️</div><div><div class="stat-value"><?= $counts['submitted'] ?? 0 ?></div><div class="stat-label">Menunggu Review</div></div></div>
  <div class="stat-card"><div class="stat-ico bg-green">✅</div><div><div class="stat-value"><?= $counts['reviewed'] ?? 0 ?></div><div class="stat-label">Selesai Dinilai</div></div></div>
</div>

<div class="card">
  <div class="card-header">
    <div>
      <h3>Hasil &amp; Jawaban Peserta — Semua Jenis Tes</h3>
      <span class="sub">Klik <strong>Lihat Jawaban</strong> untuk memeriksa detail jawaban TPA, psikotes, praktik file, dan esai</span>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:6px">
    <form method="get" class="grid grid-4" style="gap:12px">
      <select name="paket" class="form-control" data-auto-submit>
        <option value="">— Semua Paket —</option>
        <?php foreach ($packages as $p): ?><option value="<?= $p['id'] ?>" <?= $fPaket == $p['id'] ? 'selected' : '' ?>><?= e($p['nama']) ?></option><?php endforeach; ?>
      </select>
      <select name="modul" class="form-control" data-auto-submit>
        <option value="">— Semua Modul —</option>
        <?php foreach ($modules as $m): ?><option value="<?= $m['id'] ?>" <?= $fModul == $m['id'] ? 'selected' : '' ?>><?= e($m['nama']) ?></option><?php endforeach; ?>
      </select>
      <select name="status" class="form-control" data-auto-submit>
        <option value="">— Semua Status —</option>
        <?php foreach (['in_progress' => 'Sedang Mengerjakan', 'submitted' => 'Menunggu Review', 'reviewed' => 'Selesai Dinilai', 'not_started' => 'Belum Mulai'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="q" class="form-control" placeholder="Cari nama peserta / email..." value="<?= e($fQ) ?>">
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Peserta</th><th>Paket / Modul</th><th>Status</th><th>Jawaban</th>
        <th>Auto</th><th>Manual</th><th>Nilai Akhir</th><th>Waktu</th><th>Aksi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="min-width:200px">
            <div style="display:flex;align-items:center;gap:10px">
              <?= renderAvatar($r['participant_type'] === 'employee' ? $r['peserta_avatar'] : null, $r['peserta_nama']) ?>
              <div>
                <div style="font-weight:600"><?= e($r['peserta_nama']) ?></div>
                <div style="font-size:12px;color:var(--muted)">
                  <?= $r['participant_type'] === 'candidate' ? '🎫 Calon (token)' : '🆔 Karyawan (SSO)' ?>
                  <?= $r['peserta_position'] ? ' · ' . e($r['peserta_position']) : '' ?>
                </div>
              </div>
            </div>
          </td>
          <td>
            <div style="font-size:13px"><?= e($r['paket_nama']) ?></div>
            <div style="font-size:12px;color:var(--muted)"><?= e($r['modul_nama']) ?></div>
          </td>
          <td>
            <span class="badge badge-<?= $STATUS_BADGE[$r['status']][0] ?? 'muted' ?>"><?= e($STATUS_BADGE[$r['status']][1] ?? $r['status']) ?></span>
            <?php if ((int)$r['n_pending'] > 0): ?><div style="font-size:11px;color:var(--warning)">⏳ <?= (int)$r['n_pending'] ?> item pending</div><?php endif; ?>
          </td>
          <td><?= (int)$r['n_jawab'] ?></td>
          <td><?= $r['score_auto'] !== null ? '<strong>' . e($r['score_auto']) . '</strong>' : '—' ?></td>
          <td><?= $r['score_manual'] !== null ? '<strong>' . e($r['score_manual']) . '</strong>' : '—' ?></td>
          <td>
            <?php if ($r['final_score'] !== null): ?>
              <span class="badge badge-<?= (float)$r['final_score'] >= 70 ? 'success' : ((float)$r['final_score'] >= 50 ? 'warning' : 'danger') ?>" style="font-size:13px"><?= e($r['final_score']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--muted)">
            <?= fmtDate($r['started_at']) ?><?= $r['auto_submitted'] ? '<br><span style="color:var(--warning)">auto-submit</span>' : '' ?>
          </td>
          <td style="white-space:nowrap">
            <a class="btn btn-outline btn-sm" href="hasil_peserta.php?attempt=<?= $r['id'] ?>">🔍 Lihat Jawaban</a>
            <?php if (isHrd()): ?>
              <a class="btn btn-ghost btn-sm" href="hr_review_detail.php?attempt=<?= $r['id'] ?>">Review</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td colspan="9"><div class="empty-state"><div class="big-ico">📄</div>Belum ada sesi ujian tercatat. Data muncul setelah peserta mengerjakan tes.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
