<?php
/**
 * EXAM API — autosave, upload file praktik, log tab-switch, submit + auto-grading.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$db = getAppDb();
$action = $_GET['action'] ?? 'save';

/* ---------- Autentikasi peserta (SSO atau token) ---------- */
$candidate = $_SESSION['exam_candidate'] ?? null;
$isEmployee = !empty($_SESSION['user_id']);
if (!$candidate && !$isEmployee) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$attemptId = (int)($_POST['attempt_id'] ?? 0);
$st = $db->prepare('SELECT a.*, m.nama AS modul_nama, pp.package_id, pp.participant_type, pp.user_id
                   FROM exam_attempts a
                   JOIN test_modules m ON m.id=a.module_id
                   JOIN package_participants pp ON pp.id=a.participant_id
                   WHERE a.id=?');
$st->execute([$attemptId]);
$att = $st->fetch();
if (!$att || $att['status'] !== 'in_progress') { echo json_encode(['ok'=>false,'error'=>'invalid_attempt']); exit; }

// Kecocokan identitas
if ($att['participant_type'] === 'employee') {
    if (!$isEmployee || (int)$att['user_id'] !== (int)$_SESSION['user_id']) { echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
} else {
    if (!$candidate || (int)$candidate['participant_id'] !== (int)$att['participant_id']) { echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
}

// CSRF utk API
$tok = $_POST['csrf_token'] ?? '';
if (!hash_equals(csrfToken(), $tok)) { echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

function out(array $j): never { echo json_encode($j); exit; }

/* ================= SAVE JAWABAN (autosave) ================= */
if ($action === 'save') {
    $qid = (int)($_POST['question_id'] ?? 0);
    $answerText = $_POST['answer_text'] ?? null;
    $answerOpts = $_POST['answer_options'] ?? null;

    $st = $db->prepare('SELECT q.tipe_jawaban FROM questions q JOIN module_questions mq ON mq.question_id=q.id WHERE mq.module_id=? AND q.id=?');
    $st->execute([(int)$att['module_id'], $qid]);
    $q = $st->fetch();
    if (!$q) out(['ok'=>false,'error'=>'question_not_in_module']);

    // Insert / update jawaban
    $db->prepare('INSERT INTO exam_answers (attempt_id, question_id, answer_text, answer_options, review_status)
                  VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text), answer_options=VALUES(answer_options)')
       ->execute([
           $attemptId, $qid,
           $answerText !== null ? $answerText : null,
           $answerOpts ?: null,
           in_array($q['tipe_jawaban'], ['essay','file_upload','forced_pair','most_least'], true) ? 'pending' : 'auto',
       ]);
    out(['ok'=>true]);
}

/* ================= UPLOAD FILE PRAKTIK ================= */
if ($action === 'upload') {
    $qid = (int)($_POST['question_id'] ?? 0);
    $st = $db->prepare('SELECT q.tipe_jawaban FROM questions q JOIN module_questions mq ON mq.question_id=q.id WHERE mq.module_id=? AND q.id=?');
    $st->execute([(int)$att['module_id'], $qid]);
    $q = $st->fetch();
    if (!$q || $q['tipe_jawaban'] !== 'file_upload') out(['ok'=>false,'error'=>'bukan_soal_upload']);

    $ALLOWED = ['xlsx','xls','docx','doc','pdf','jpg','jpeg','png','pptx','ppt','csv','txt'];
    $name = handleUpload($_FILES['file'] ?? [], 'answer_files', $ALLOWED, 15);
    if (!$name) out(['ok'=>false,'error'=>'upload_gagal']);

    $db->prepare('INSERT INTO exam_answers (attempt_id, question_id, uploaded_file, review_status)
                  VALUES (?,?,?, "pending")
                  ON DUPLICATE KEY UPDATE uploaded_file=VALUES(uploaded_file), review_status="pending"')
       ->execute([$attemptId, $qid, $name]);
    out(['ok'=>true,'name'=>$name]);
}

/* ================= LOG TAB SWITCH ================= */
if ($action === 'log') {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true) ?: [];
    $db->prepare('UPDATE exam_attempts SET tab_switches = tab_switches + 1 WHERE id=?')->execute([$attemptId]);
    $db->prepare('INSERT INTO activity_logs (attempt_id, user_ref, action, detail, ip_address) VALUES (?,?,?,?,?)')
       ->execute([$attemptId, $candidate['email'] ?? ($_SESSION['username'] ?? ''), 'tab_switch', 'Pindah tab terdeteksi', $_SERVER['REMOTE_ADDR'] ?? '']);
    out(['ok'=>true]);
}

/* ================= SUBMIT + AUTO GRADING ================= */
if ($action === 'submit') {
    $isAuto = !empty($_POST['auto']);
    $now = date('Y-m-d H:i:s');

    // Simpan semua jawaban dari form (final flush) — selain itu data autosave sudah ada di DB
    foreach (($_POST['ans'] ?? []) as $qid => $val) {
        $qid = (int)$qid;
        $optsJson = is_array($val) ? json_encode(array_values($val)) : json_encode(is_string($val) && str_starts_with($val, '{') ? json_decode($val, true) : [$val]);
        $db->prepare('INSERT INTO exam_answers (attempt_id, question_id, answer_options) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE answer_options=VALUES(answer_options)')
           ->execute([$attemptId, $qid, $optsJson]);
    }
    foreach (($_POST['essay'] ?? []) as $qid => $html) {
        if (trim((string)$html) === '') continue;
        $db->prepare('INSERT INTO exam_answers (attempt_id, question_id, answer_text, review_status) VALUES (?,?,?, "pending")
                      ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text)')
           ->execute([$attemptId, (int)$qid, $html]);
    }

    /* ---- Auto-grade soal single & multiple ---- */
    $rows = $db->prepare('SELECT ans.id, ans.answer_options, q.kunci_jawaban, q.bobot, q.tipe_jawaban
                          FROM exam_answers ans JOIN questions q ON q.id=ans.question_id
                          WHERE ans.attempt_id=? AND q.tipe_jawaban IN ("single","multiple")');
    $rows->execute([$attemptId]);
    $totalGot = 0.0; $totalMax = 0.0;
    foreach ($rows->fetchAll() as $r) {
        $kunci = array_map('strval', (array)json_decode((string)$r['kunci_jawaban'], true));
        $resp  = array_map('strval', (array)json_decode((string)$r['answer_options'], true));
        sort($kunci); sort($resp);
        $correct = ($resp == $kunci);
        $pts = $correct ? (float)$r['bobot'] : 0.0;
        $totalGot += $pts; $totalMax += (float)$r['bobot'];
        $db->prepare('UPDATE exam_answers SET is_correct=?, points_awarded=?, review_status="auto" WHERE id=?')
           ->execute([$correct ? 1 : 0, $pts, (int)$r['id']]);
    }
    $scoreAuto = $totalMax > 0 ? round($totalGot / $totalMax * 100, 2) : null;

    $db->prepare('UPDATE exam_attempts SET submitted_at=?, auto_submitted=?, score_auto=?, status="submitted" WHERE id=?')
       ->execute([$now, $isAuto ? 1 : 0, $scoreAuto, $attemptId]);

    // Update status peserta
    $db->prepare('UPDATE package_participants SET status="finished" WHERE id=?')->execute([(int)$att['participant_id']]);

    $db->prepare('INSERT INTO activity_logs (attempt_id, user_ref, action, detail, ip_address) VALUES (?,?,?,?,?)')
       ->execute([$attemptId, $candidate['email'] ?? ($_SESSION['username'] ?? ''), $isAuto ? 'auto_submit' : 'submit', 'Skor auto: ' . ($scoreAuto ?? '-'), $_SERVER['REMOTE_ADDR'] ?? '']);

    /* ---- Arahkan ke modul berikutnya ---- */
    $mods = $db->query('SELECT pm.module_id, pm.urutan FROM package_modules pm WHERE pm.package_id=' . (int)$att['package_id'] . ' ORDER BY pm.urutan')->fetchAll();
    $nextUrl = 'exam_lobby.php';
    foreach ($mods as $i => $m) {
        if ((int)$m['module_id'] === (int)$att['module_id'] && isset($mods[$i+1])) {
            $pid = (int)$att['participant_id'];
            $nextUrl = 'exam_run.php?participant_id=' . $pid . '&module=' . (int)$mods[$i+1]['module_id'];
            break;
        }
    }
    out(['ok'=>true,'score_auto'=>$scoreAuto,'next_url'=>$nextUrl]);
}

out(['ok'=>false,'error'=>'unknown_action']);
