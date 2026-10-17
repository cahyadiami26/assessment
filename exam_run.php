<?php
/**
 * EXAM EXECUTION — Ruang pengerjaan tes per modul.
 * - Timer limit + auto-submit
 * - Autosave real-time (AJAX exam_api.php)
 * - Anti-kecurangan: deteksi pindah tab, blocking contextmenu/devtools shortcut dasar
 * - Tipe soal: single, multiple, forced_pair, most_least, file_upload, essay
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

$db = getAppDb();
$participantId = (int)($_GET['participant_id'] ?? 0);
$moduleId      = (int)($_GET['module'] ?? 0);

/* Identitas peserta: SSO karyawan ATAU kandidat token */
$candidate = $_SESSION['exam_candidate'] ?? null;
$isEmployee = !empty($_SESSION['user_id']);
if (!$candidate && !$isEmployee) {
    header('Location: exam_lobby.php'); exit;
}

/* Validasi kepesertaan */
$st = $db->prepare('SELECT pp.*, p.nama AS paket_nama, p.jadwal_mulai, p.jadwal_selesai, p.camera_proctor
                    FROM package_participants pp JOIN test_packages p ON p.id=pp.package_id WHERE pp.id=?');
$st->execute([$participantId]);
$part = $st->fetch();
if (!$part) { exit('Peserta tidak ditemukan.'); }
if ($part['participant_type'] === 'employee') {
    if (!$isEmployee || (int)$part['user_id'] !== (int)$_SESSION['user_id']) exit('Akses ditolak: sesi SSO tidak cocok.');
} else {
    if (!$candidate || (int)$candidate['participant_id'] !== $participantId) exit('Akses ditolak: masuk kembali dengan token Anda.');
}

/* Jadwal window */
$now = date('Y-m-d H:i:s');
if ($now < $part['jadwal_mulai'] || $now > $part['jadwal_selesai']) {
    flash('warning', 'Paket belum/tidak dapat diakses sesuai jadwal.');
    header('Location: exam_lobby.php'); exit;
}

/* Ambil daftar modul paket */
$mods = $db->query('SELECT m.* FROM package_modules pm JOIN test_modules m ON m.id=pm.module_id WHERE pm.package_id=' . (int)$part['package_id'] . ' ORDER BY pm.urutan')->fetchAll();
if (!$moduleId && $mods) $moduleId = (int)$mods[0]['id'];
$curMod = null; foreach ($mods as $m) if ((int)$m['id'] === $moduleId) $curMod = $m;
if (!$curMod) { header('Location: exam_lobby.php'); exit; }

/* Attempt: buat jika belum ada; jika sudah submit → tampilkan status */
$st = $db->prepare('SELECT * FROM exam_attempts WHERE participant_id=? AND module_id=? LIMIT 1');
$st->execute([$participantId, $moduleId]);
$attempt = $st->fetch();

if (!$attempt) {
    $expires = date('Y-m-d H:i:s', time() + (int)$curMod['durasi_menit'] * 60);
    $db->prepare('INSERT INTO exam_attempts (participant_id, package_id, module_id, started_at, expires_at, status) VALUES (?,?,?,?,?, "in_progress")')
       ->execute([$participantId, $part['package_id'], $moduleId, $now, $expires]);
    $attemptId = (int)$db->lastInsertId();
    $attempt = ['id'=>$attemptId,'started_at'=>$now,'expires_at'=>$expires,'status'=>'in_progress','submitted_at'=>null];
    logActivity($db, $attemptId, $part['candidate_email'] ?? ($_SESSION['username'] ?? ''), 'start_exam', 'Mulai modul ' . $curMod['nama']);
} else {
    $attemptId = (int)$attempt['id'];
}

function logActivity(PDO $db, ?int $attemptId, string $ref, string $action, string $detail = ''): void
{
    $db->prepare('INSERT INTO activity_logs (attempt_id, user_ref, action, detail, ip_address) VALUES (?,?,?,?,?)')
       ->execute([$attemptId, $ref, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? '']);
}

/* Jika attempt selesai → alihkan ke modul berikutnya / lobby */
if (in_array($attempt['status'], ['submitted','reviewed'], true)) {
    $idx = array_search($moduleId, array_map('intval', array_column($mods, 'id')));
    $next = $mods[$idx + 1] ?? null;
    if ($next) { header('Location: exam_run.php?participant_id='.$participantId.'&module='.$next['id']); exit; }
    flash('success', 'Seluruh modul dalam paket telah diselesaikan. Terima kasih!');
    header('Location: exam_lobby.php'); exit;
}

/* Daftar soal modul (dengan pengacakan bila diminta) */
$qSql = 'SELECT q.* FROM module_questions mq JOIN questions q ON q.id=mq.question_id WHERE mq.module_id=? ORDER BY mq.urutan';
$st = $db->prepare($qSql); $st->execute([$moduleId]);
$questions = $st->fetchAll();
if ($curMod['acak_soal'] && !$curMod['jumlah_soal']) shuffle($questions);
if ($curMod['jumlah_soal']) $questions = array_slice($questions, 0, (int)$curMod['jumlah_soal']);

/* Jawaban tersimpan (untuk restore saat refresh) */
$st = $db->prepare('SELECT * FROM exam_answers WHERE attempt_id=?'); $st->execute([$attemptId]);
$saved = []; foreach ($st->fetchAll() as $a) $saved[(int)$a['question_id']] = $a;

$remainSec = max(0, strtotime($attempt['expires_at']) - time());
$userRef = $part['candidate_name'] ?? ($_SESSION['user_name'] ?? 'Peserta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($curMod['nama']) ?> — Ruang Ujian</title>
<link rel="icon" href="<?= e(logoUrl('logo.png')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="exam-header">
  <div>
    <strong>📝 <?= e($curMod['nama']) ?></strong>
    <span style="opacity:.75;font-size:12px"> · <?= e($part['paket_nama']) ?> · <?= e($userRef) ?></span>
  </div>
  <div class="flex items-center gap-2">
    <span class="autosave-note" id="saveNote">Autosave aktif</span>
    <div class="timer-pill" id="timer"><?= gmdate('H:i:s', $remainSec) ?></div>
  </div>
</div>

<main class="content" style="max-width:900px;margin:0 auto;padding-top:20px">
  <?php if (!empty($curMod['deskripsi'])): ?>
    <div class="alert alert-info"><?= e($curMod['deskripsi']) ?></div>
  <?php endif; ?>

  <form id="examForm" onsubmit="return submitExam(event)">
  <?php foreach ($questions as $i => $q):
      $qid = (int)$q['id']; $sv = $saved[$qid] ?? null; $opsi = json_decode((string)$q['opsi'], true) ?: [];
      // Normalisasi jawaban tersimpan (indeks berbasis 0, kompatibel format lama "A"/"B")
      $selIdx = $sv ? normalizeAnswerKeys($sv['answer_options'] ?? '', count($opsi)) : []; ?>
    <div class="question-card mb-2" data-qid="<?= $qid ?>" id="q<?= $qid ?>">
      <div class="flex justify-between items-center mb-1">
        <span class="badge badge-info">Soal <?= $i+1 ?> / <?= count($questions) ?></span>
        <span class="text-muted" style="font-size:12px">Bobot: <?= e($q['bobot']) ?></span>
      </div>
      <div class="question-stem"><?= nl2br(e($q['pertanyaan'])) ?></div>

      <?php if ($q['tipe_jawaban'] === 'single'): ?>
        <?php foreach ($opsi as $k => $o): ?>
          <label class="option-row <?= in_array((string)$k, $selIdx, true) ? 'selected':'' ?>">
            <input type="radio" name="ans[<?= $qid ?>]" value="<?= $k ?>" onchange="onAnswer(this)" <?= in_array((string)$k, $selIdx, true)?'checked':'' ?>>
            <span><b><?= chr(65+$k) ?>.</b> <?= e($o) ?></span>
          </label>
        <?php endforeach; ?>

      <?php elseif ($q['tipe_jawaban'] === 'multiple'): ?>
        <p class="form-hint mb-1">Pilih lebih dari satu jawaban yang benar.</p>
        <?php foreach ($opsi as $k => $o): ?>
          <label class="option-row <?= in_array((string)$k,$selIdx,true)?'selected':'' ?>">
            <input type="checkbox" name="ans[<?= $qid ?>][]" value="<?= $k ?>" onchange="onAnswer(this)" <?= in_array((string)$k,$selIdx,true)?'checked':'' ?>>
            <span><b><?= chr(65+$k) ?>.</b> <?= e($o) ?></span>
          </label>
        <?php endforeach; ?>

      <?php elseif ($q['tipe_jawaban'] === 'forced_pair'): ?>
        <p class="form-hint mb-1">Pilih pernyataan yang paling menggambarkan diri Anda.</p>
        <?php $fp = json_decode((string)($sv['answer_options'] ?? '{}'), true) ?: []; ?>
        <?php foreach ($opsi as $k => $o): ?>
          <label class="option-row <?= ($fp['pick'] ?? null)===$k?'selected':'' ?>">
            <input type="radio" name="ans_fp_<?= $qid ?>" value="<?= $k ?>" onchange="onForcedPair(<?= $qid ?>, this.value)" <?= ($fp['pick'] ?? null)===$k?'checked':'' ?>>
            <span><?= e($o) ?></span>
          </label>
        <?php endforeach; ?>
        <input type="hidden" name="ans[<?= $qid ?>]" id="fpval_<?= $qid ?>" value="<?= e(json_encode($fp)) ?>">

      <?php elseif ($q['tipe_jawaban'] === 'most_least'): ?>
        <table class="most-least-table">
          <thead><tr><th>Pernyataan</th><th style="width:90px">Most</th><th style="width:90px">Least</th></tr></thead>
          <tbody>
          <?php $ml = json_decode((string)($sv['answer_options'] ?? '{}'), true) ?: []; ?>
          <?php foreach ($opsi as $k => $o): ?>
            <tr><td><?= e($o) ?></td>
              <td><input type="radio" name="most_<?= $qid ?>" value="<?= $k ?>" <?= ($ml['most'] ?? null)==$k?'checked':'' ?> onchange="onMostLeast(<?= $qid ?>)"></td>
              <td><input type="radio" name="least_<?= $qid ?>" value="<?= $k ?>" <?= ($ml['least'] ?? null)==$k?'checked':'' ?> onchange="onMostLeast(<?= $qid ?>)"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <input type="hidden" name="ans[<?= $qid ?>]" id="mlval_<?= $qid ?>" value="<?= e(json_encode($ml)) ?>">

      <?php elseif ($q['tipe_jawaban'] === 'file_upload'): ?>
        <div class="alert alert-warning" style="font-size:13px">📎 Kerjakan kasus pada aplikasi yang sesuai, lalu unggah berkas hasilnya di bawah ini.</div>
        <?php if (!empty($q['contoh_file'])): $cf = $q['contoh_file']; ?>
          <div class="rubric-view mb-2">
            <strong>📌 Contoh File Hasil Akhir:</strong>
            <div class="mt-1 flex items-center gap-1">
              <?= fileIcon($cf) ?> <a href="uploads/reference_files/<?= e($cf) ?>" target="_blank" download>Unduh &amp; lihat referensi</a>
              <?php if (isPreviewableImage($cf)): ?><img src="uploads/reference_files/<?= e($cf) ?>" style="max-height:120px;border-radius:8px;margin-left:12px"><?php endif; ?>
              <?php if (isPdf($cf)): ?><iframe src="uploads/reference_files/<?= e($cf) ?>" style="height:180px;width:100%;border:1px solid var(--border);border-radius:8px;margin-top:8px"></iframe><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        <input type="file" name="file_<?= $qid ?>" class="form-control" accept=".xlsx,.xls,.docx,.doc,.pdf,.jpg,.jpeg,.png,.pptx,.ppt,.csv,.txt" onchange="uploadFile(<?= $qid ?>, this)">
        <div class="form-hint mt-1" id="fn_<?= $qid ?>">
          <?php if (!empty($sv['uploaded_file'])): ?>✅ Terunggah: <strong><?= e($sv['uploaded_file']) ?></strong><?php else: ?>Format: xlsx, docx, pdf, jpg, png, pptx, csv (maks 15MB).<?php endif; ?>
        </div>

      <?php elseif ($q['tipe_jawaban'] === 'essay'): ?>
        <div class="rte-toolbar" id="rte-toolbar-e<?= $qid ?>">
          <button type="button" data-cmd="bold"><b>B</b></button>
          <button type="button" data-cmd="italic"><i>I</i></button>
          <button type="button" data-cmd="underline"><u>U</u></button>
          <button type="button" data-cmd="insertUnorderedList">• List</button>
        </div>
        <div class="rte-area" id="rte-e<?= $qid ?>" contenteditable="true"><?= $sv['answer_text'] ?? '' ?></div>
        <input type="hidden" name="essay[<?= $qid ?>]" id="rte-hidden-e<?= $qid ?>" value="">
        <script>initRTE('e<?= $qid ?>');</script>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card"><div class="card-body flex justify-between items-center flex-wrap gap-2">
    <div>
      <strong>Sisa waktu: </strong><span class="timer-pill" id="timer2"><?= gmdate('H:i:s', $remainSec) ?></span>
      <div class="text-muted" style="font-size:12px">Jawaban tersimpan otomatis. Saat waktu habis, ujian dikirim otomatis.</div>
    </div>
    <button type="submit" class="btn btn-success btn-lg">✅ Selesaikan & Kirim Modul Ini</button>
  </div></div>
  </form>
</main>

<script>
const API = 'exam_api.php';
const ATTEMPT_ID = <?= $attemptId ?>;
const CSRF = '<?= e(csrfToken()) ?>';
let remain = <?= $remainSec ?>;
let submitting = false;

/* ---------- Timer + Auto Submit ---------- */
function tick(){
  if (submitting) return;
  remain--;
  const s = remain > 0 ? remain : 0;
  const txt = String(Math.floor(s/3600)).padStart(2,'0')+':'+String(Math.floor(s%3600/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');
  document.getElementById('timer').textContent = txt;
  document.getElementById('timer2').textContent = txt;
  if (s <= 300) document.getElementById('timer').classList.add('danger');
  if (s <= 0){ doSubmit(true); }
}
setInterval(tick, 1000);

/* ---------- Autosave ---------- */
function note(cls, msg){ const n=document.getElementById('saveNote'); n.className='autosave-note '+cls; n.textContent=msg; }
function saveAnswer(qid, payload){
  note('saving','Menyimpan…');
  const fd = new FormData();
  fd.append('attempt_id', ATTEMPT_ID); fd.append('question_id', qid); fd.append('csrf_token', CSRF);
  for (const k in payload) fd.append(k, payload[k]);
  fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
    note(j.ok ? 'saved':'', j.ok ? 'Tersimpan ✓ ('+new Date().toLocaleTimeString('id-ID')+')' : 'Gagal menyimpan!');
  }).catch(()=>note('','⚠ Koneksi penyimpanan terputus'));
}
window.onAnswer = function(el){
  const card = el.closest('.question-card'); const qid = card.dataset.qid;
  card.querySelectorAll('.option-row').forEach(r=>r.classList.remove('selected'));
  el.closest('.option-row').classList.add('selected');
  const inputs = card.querySelectorAll('input:checked');
  const vals = [...inputs].map(i=>i.value);
  saveAnswer(qid, {answer_options: JSON.stringify(vals)});
};
window.onForcedPair = function(qid, val){
  document.getElementById('fpval_'+qid).value = JSON.stringify({pick: parseInt(val)});
  saveAnswer(qid, {answer_options: document.getElementById('fpval_'+qid).value});
};
window.onMostLeast = function(qid){
  const m = document.querySelector(`input[name="most_${qid}"]:checked`);
  const l = document.querySelector(`input[name="least_${qid}"]:checked`);
  const obj = {most: m?parseInt(m.value):null, least: l?parseInt(l.value):null};
  document.getElementById('mlval_'+qid).value = JSON.stringify(obj);
  saveAnswer(qid, {answer_options: JSON.stringify(obj)});
};
/* Essay autosave (debounce) */
let essayTimer;
document.querySelectorAll('[contenteditable]').forEach(a=>{
  a.addEventListener('input', ()=>{ clearTimeout(essayTimer);
    essayTimer = setTimeout(()=>{
      const qid = a.id.replace('rte-e','');
      saveAnswer(qid, {answer_text: a.innerHTML});
    }, 1200);
  });
});
/* Upload file praktik */
function uploadFile(qid, input){
  if(!input.files.length) return;
  const fd = new FormData();
  fd.append('attempt_id', ATTEMPT_ID); fd.append('question_id', qid); fd.append('csrf_token', CSRF);
  fd.append('file', input.files[0]);
  document.getElementById('fn_'+qid).textContent = 'Mengunggah '+input.files[0].name+'…';
  fetch(API+'?action=upload', {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
    document.getElementById('fn_'+qid).innerHTML = j.ok ? '✅ Terunggah: <b>'+j.name+'</b>' : '❌ Gagal: '+j.error;
  });
}

/* ---------- Anti-kecurangan: deteksi pindah tab ---------- */
let switches = 0;
document.addEventListener('visibilitychange', ()=>{
  if (document.hidden){
    switches++;
    fetch(API+'?action=log', {method:'POST', body: JSON.stringify({attempt_id:ATTEMPT_ID, csrf_token:CSRF})})
      .catch(()=>{});
    if (switches>=1) alert('PERINGATAN: Pindah tab terdeteksi dan tercatat oleh sistem proctoring ('+switches+'x).');
  }
});
document.addEventListener('contextmenu', e=>e.preventDefault());
document.addEventListener('keydown', e=>{
  if (e.key==='F12' || (e.ctrlKey && ['c','u','s','p'].includes(e.key.toLowerCase()))) e.preventDefault();
});

/* ---------- Submit ---------- */
function submitExam(ev){ ev.preventDefault(); doSubmit(false); return false; }
function doSubmit(auto){
  if (submitting) return; submitting = true;
  const fd = new FormData(document.getElementById('examForm'));
  fd.append('attempt_id', ATTEMPT_ID); fd.append('csrf_token', CSRF); fd.append('auto', auto?1:0);
  note('saving','Mengirim jawaban…');
  fetch(API+'?action=submit', {method:'POST', body:fd}).then(r=>r.json()).then(j=>{
    if (j.ok){ location.href = j.next_url || 'exam_lobby.php'; }
    else { submitting=false; alert('Gagal mengirim: '+(j.error||'unknown')); }
  }).catch(()=>{ submitting=false; alert('Koneksi gagal, coba lagi.'); });
}
</script>
</body>
</html>
