<?php
/**
 * E-Assessment PT Lampung Jasa Utama
 * util_regrade.php — Rekalkulasi auto-grading untuk seluruh attempt tersimpan.
 *
 * Gunakan sekali setelah deploy perbaikan auto-grading (normalisasi kunci A/B/indeks),
 * agar skor attempt lama yang sebelumnya salah dinilai otomatis terkoreksi.
 * Akses: hanya role admin/HRD, dari browser atau CLI.
 *   php util_regrade.php          (CLI)
 *   https://assessment.lju.co.id/util_regrade.php?key=regrade2026  (browser)
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

// Proteksi ekstra via CLI/browser
if (PHP_SAPI !== 'cli') {
    requireSsoLogin();
    if (($_GET['key'] ?? '') !== 'regrade2026' || !isHrd()) { http_response_code(403); exit('Akses ditolak.'); }
}

$db = getAppDb();

// 1) Normalisasi kunci jawaban lama berformat label huruf ("A","B") -> indeks berbasis 0
$qs = $db->query('SELECT id, tipe_jawaban, opsi, kunci_jawaban FROM questions WHERE tipe_jawaban IN ("single","multiple")')->fetchAll();
$nKunci = 0;
foreach ($qs as $q) {
    $numOpts = is_countable(json_decode((string)$q['opsi'], true)) ? count(json_decode((string)$q['opsi'], true)) : 0;
    $norm = json_encode(normalizeAnswerKeys($q['kunci_jawaban'], $numOpts));
    if ($norm !== $q['kunci_jawaban']) {
        $db->prepare('UPDATE questions SET kunci_jawaban=? WHERE id=?')->execute([$norm, (int)$q['id']]);
        $nKunci++;
    }
}

// 2) Re-grade semua jawaban single/multiple pada seluruh attempt
$ans = $db->query('SELECT ans.id, ans.attempt_id, ans.answer_options, q.kunci_jawaban, q.bobot, q.opsi
                   FROM exam_answers ans JOIN questions q ON q.id = ans.question_id
                   WHERE q.tipe_jawaban IN ("single","multiple")')->fetchAll();
$nFix = 0; $perAttempt = [];
$upd = $db->prepare('UPDATE exam_answers SET is_correct=?, points_awarded=?, review_status="auto" WHERE id=?');
foreach ($ans as $r) {
    $numOpts = is_countable(json_decode((string)$r['opsi'], true)) ? count(json_decode((string)$r['opsi'], true)) : 0;
    $correct = answersMatch($r['answer_options'], $r['kunci_jawaban'], $numOpts);
    $pts = $correct ? (float)$r['bobot'] : 0.0;
    $before = $db->prepare('SELECT is_correct FROM exam_answers WHERE id=?'); $before->execute([(int)$r['id']]);
    $old = $before->fetchColumn();
    $upd->execute([$correct ? 1 : 0, $pts, (int)$r['id']]);
    if (((int)$old !== ($correct ? 1 : 0))) $nFix++;
    $aid = (int)$r['attempt_id'];
    $perAttempt[$aid]['got'] = ($perAttempt[$aid]['got'] ?? 0) + $pts;
    $perAttempt[$aid]['max'] = ($perAttempt[$aid]['max'] ?? 0) + (float)$r['bobot'];
}

// 3) Recompute score_auto per attempt
$nAtt = 0;
$upA = $db->prepare('UPDATE exam_attempts SET score_auto=? WHERE id=?');
foreach ($perAttempt as $aid => $v) {
    $score = $v['max'] > 0 ? round($v['got'] / $v['max'] * 100, 2) : null;
    $upA->execute([$score, $aid]);
    $nAtt++;
}

$msg = "Selesai. Kunci ternormalisasi: $nKunci soal · Jawaban dikoreksi: $nFix · Attempt di-recompute: $nAtt";
if (PHP_SAPI === 'cli') { echo $msg . PHP_EOL; }
else { echo '<pre style="font-family:monospace;padding:20px">' . htmlspecialchars($msg) . '</pre>'; }
