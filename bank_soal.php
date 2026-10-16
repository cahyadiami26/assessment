<?php
/**
 * Modul Bank Soal — HR/Admin
 * Mendukung: single, multiple, forced_pair, most_least, file_upload, essay
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

require_once __DIR__ . '/includes/functions.php';
$db = getAppDb();
$aksi = $_GET['aksi'] ?? 'list';

$ALLOWED_REF_EXT = ['xlsx','xls','docx','doc','pdf','jpg','jpeg','png','pptx','ppt','csv','txt'];

/* ---------- Simpan soal (tambah/edit) ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'soal') {
    verifyCsrf();
    $id          = (int)($_POST['id'] ?? 0);
    $category_id = (int)$_POST['category_id'];
    $tipe        = $_POST['tipe_jawaban'];
    $pertanyaan  = trim($_POST['pertanyaan']);
    $bobot       = (float)($_POST['bobot'] ?: 1);
    $rubrik      = trim($_POST['rubrik'] ?? '');
    $status      = $_POST['status'] ?? 'aktif';

    $opsi = array_values(array_filter(array_map('trim', $_POST['opsi'] ?? [])));
    $opsiJson = $opsi ? json_encode($opsi) : null;

    // Kunci jawaban
    $kunci = null;
    if ($tipe === 'single')   $kunci = json_encode([$_POST['kunci_single'] ?? '']);
    if ($tipe === 'multiple') $kunci = json_encode(array_values($_POST['kunci_multi'] ?? []));
    if ($tipe === 'forced_pair' || $tipe === 'most_least') $kunci = json_encode(['note' => 'skor mentah disimpan, interpretasi manual HR']);

    // Upload contoh file hasil akhir (referensi praktik)
    $contohFile = $_POST['contoh_file_lama'] ?? null;
    $baru = handleUpload($_FILES['contoh_file'] ?? [], 'reference_files', $ALLOWED_REF_EXT, 20);
    if ($baru) $contohFile = $baru;

    if (!$pertanyaan || !$category_id) {
        flash('danger', 'Kategori dan pertanyaan wajib diisi.');
        header('Location: bank_soal.php?aksi=' . ($id ? "edit&id=$id" : 'tambah')); exit;
    }

    if ($id) {
        $st = $db->prepare('UPDATE questions SET category_id=?, tipe_jawaban=?, pertanyaan=?, opsi=?, kunci_jawaban=?, bobot=?, rubrik=?, contoh_file=?, status=? WHERE id=?');
        $st->execute([$category_id, $tipe, $pertanyaan, $opsiJson, $kunci, $bobot, $rubrik, $contohFile, $status, $id]);
        flash('success', 'Soal berhasil diperbarui.');
    } else {
        $st = $db->prepare('INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot, rubrik, contoh_file, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$category_id, $tipe, $pertanyaan, $opsiJson, $kunci, $bobot, $rubrik, $contohFile, $_SESSION['user_id']]);
        flash('success', 'Soal baru berhasil ditambahkan.');
    }
    header('Location: bank_soal.php'); exit;
}

/* ---------- Hapus / arsipkan ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'hapus') {
    verifyCsrf();
    $id = (int)$_POST['id'];
    try {
        $db->prepare('DELETE FROM questions WHERE id=?')->execute([$id]);
        flash('success', 'Soal dihapus.');
    } catch (Throwable $e) {
        $db->prepare('UPDATE questions SET status="arsip" WHERE id=?')->execute([$id]);
        flash('warning', 'Soal sedang dipakai modul — dipindahkan ke arsip.');
    }
    header('Location: bank_soal.php'); exit;
}

/* ---------- Data utk tampilan ---------- */
$categories = $db->query('SELECT * FROM question_categories ORDER BY test_type, name')->fetchAll();

$fCategory = $_GET['kategori'] ?? '';
$fTipe     = $_GET['tipe'] ?? '';
$fQ        = trim($_GET['q'] ?? '');

$sql = 'SELECT q.*, c.name AS kategori_nama, c.test_type FROM questions q JOIN question_categories c ON c.id=q.category_id WHERE 1=1';
$params = [];
if ($fCategory) { $sql .= ' AND q.category_id=?'; $params[] = $fCategory; }
if ($fTipe)     { $sql .= ' AND q.tipe_jawaban=?'; $params[] = $fTipe; }
if ($fQ)        { $sql .= ' AND q.pertanyaan LIKE ?'; $params[] = "%$fQ%"; }
$sql .= ' ORDER BY q.id DESC LIMIT 300';
$st = $db->prepare($sql); $st->execute($params);
$rows = $st->fetchAll();

$tipeLabels = [
  'single'=>'Pilihan Ganda','multiple'=>'Multiple Select','forced_pair'=>'Forced Pair (PAPI)',
  'most_least'=>'Most/Least','file_upload'=>'Praktik (Upload File)','essay'=>'Esai/Uraian'
];

$pageTitle  = 'Bank Soal';
$activeMenu = 'bank_soal';
require_once __DIR__ . '/includes/header.php';

$formMode = in_array($aksi, ['tambah','edit']) ? $aksi : null;
$rowEdit = null;
if ($formMode === 'edit') {
    $st = $db->prepare('SELECT * FROM questions WHERE id=?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $rowEdit = $st->fetch();
    if (!$rowEdit) { $formMode = null; flash('danger', 'Soal tidak ditemukan.'); }
}
?>

<?php if ($formMode): ?>
  <?php
    $curOpsi = $rowEdit && $rowEdit['opsi'] ? json_decode($rowEdit['opsi'], true) : ['',''];
    $curKunci = $rowEdit && $rowEdit['kunci_jawaban'] ? json_decode($rowEdit['kunci_jawaban'], true) : [];
  ?>
  <div class="card">
    <div class="card-header">
      <h3><?= $formMode==='edit' ? 'Edit Soal #'.(int)$rowEdit['id'] : 'Tambah Soal Baru' ?></h3>
      <a class="btn btn-ghost btn-sm" href="bank_soal.php">← Kembali ke Daftar</a>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?><input type="hidden" name="form" value="soal">
        <input type="hidden" name="id" value="<?= (int)($rowEdit['id'] ?? 0) ?>">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Kategori Tes</label>
            <select name="category_id" class="form-control" required>
              <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($rowEdit['category_id'] ?? '')==$c['id']?'selected':'' ?>>
                  [<?= e($c['test_type']) ?>] <?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tipe Jawaban</label>
            <select name="tipe_jawaban" class="form-control" required onchange="this.form.submit()">
              <?php foreach ($tipeLabels as $k=>$v): ?>
                <option value="<?= $k ?>" <?= ($rowEdit['tipe_jawaban'] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Pertanyaan / Instruksi</label>
          <textarea name="pertanyaan" class="form-control" rows="3" required><?= e($rowEdit['pertanyaan'] ?? '') ?></textarea>
          <div class="form-hint">Untuk praktik: tuliskan kasus & instruksi pengerjaan selengkap mungkin.</div>
        </div>

        <?php $t = $_POST['tipe_jawaban'] ?? $rowEdit['tipe_jawaban'] ?? 'single'; ?>

        <?php if (in_array($t, ['single','multiple','forced_pair','most_least'])): ?>
          <div class="form-group">
            <label class="form-label">Opsi Jawaban</label>
            <div id="opsiWrap">
              <?php foreach ($curOpsi as $i => $o): ?>
                <div class="check-line"><input type="text" name="opsi[]" class="form-control" value="<?= e($o) ?>" placeholder="Opsi <?= chr(65+$i) ?>"></div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline btn-sm mt-1" onclick="addOpsi()">+ Tambah Opsi</button>
          </div>

          <?php if ($t === 'single'): ?>
            <div class="form-group"><label class="form-label">Kunci Jawaban</label>
              <select name="kunci_single" class="form-control" style="max-width:300px">
                <?php foreach ($curOpsi as $i => $o): ?>
                  <option value="<?= $i ?>" <?= in_array((string)$i, array_map('strval',(array)($curKunci[0] ?? '')), true)?'selected':'' ?>>Opsi <?= chr(65+$i) ?>: <?= e(mb_substr($o,0,50)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php elseif ($t === 'multiple'): ?>
            <div class="form-group"><label class="form-label">Kunci Jawaban (centang semua yang benar)</label>
              <?php foreach ($curOpsi as $i => $o): ?>
                <div class="check-line"><input type="checkbox" name="kunci_multi[]" value="<?= $i ?>" <?= in_array((string)$i, array_map('strval',(array)$curKunci), true)?'checked':'' ?>> Opsi <?= chr(65+$i) ?>: <?= e(mb_substr($o,0,60)) ?></div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="alert alert-info">Tipe <strong><?= e($tipeLabels[$t]) ?></strong>: sistem menyimpan <em>jawaban mentah</em> peserta. Interpretasi, skor & rekomendasi dilakukan manual oleh HR di Portal Assessor.</div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (in_array($t, ['file_upload','essay'])): ?>
          <?php if ($t === 'file_upload'): ?>
            <div class="form-group">
              <label class="form-label">Contoh File Hasil Akhir (referensi — opsional)</label>
              <input type="file" name="contoh_file" class="form-control" accept=".xlsx,.xls,.docx,.doc,.pdf,.jpg,.jpeg,.png,.pptx,.csv,.txt">
              <?php if (!empty($rowEdit['contoh_file'])): $cf=$rowEdit['contoh_file'];?>
                <input type="hidden" name="contoh_file_lama" value="<?= e($cf) ?>">
                <div class="mt-1 flex items-center gap-1">
                  <?= fileIcon($cf) ?> <a href="uploads/reference_files/<?= e($cf) ?>" target="_blank"><?= e($cf) ?></a>
                  <?php if (isPreviewableImage($cf)): ?><img src="uploads/reference_files/<?= e($cf) ?>" style="max-height:90px;border-radius:8px;margin-left:10px"><?php endif; ?>
                  <?php if (isPdf($cf)): ?><iframe src="uploads/reference_files/<?= e($cf) ?>" style="height:120px;width:100%;border:1px solid var(--border);border-radius:8px;margin-top:6px"></iframe><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="form-hint">Format: xlsx, docx, pdf, jpg, png, pptx, csv, txt (maks 20MB). Peserta dapat melihat & mengunduh file ini saat ujian.</div>
            </div>
          <?php endif; ?>
          <div class="form-group">
            <label class="form-label">Rubrik Penilaian (untuk HR)</label>
            <textarea name="rubrik" class="form-control" rows="3" placeholder="Aspek yang dinilai, rentang skor..."><?= e($rowEdit['rubrik'] ?? '') ?></textarea>
          </div>
        <?php endif; ?>

        <div class="form-row">
          <div class="form-group"><label class="form-label">Bobot Soal</label>
            <input type="number" step="0.5" min="0" name="bobot" class="form-control" value="<?= e($rowEdit['bobot'] ?? '1') ?>"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <option value="aktif" <?= ($rowEdit['status'] ?? 'aktif')==='aktif'?'selected':'' ?>>Aktif</option>
              <option value="arsip" <?= ($rowEdit['status'] ?? '')==='arsip'?'selected':'' ?>>Arsip</option>
            </select></div>
        </div>

        <div class="flex gap-1">
          <button class="btn btn-primary"><?= $formMode==='edit'?'Simpan Perubahan':'Simpan Soal' ?></button>
          <a class="btn btn-outline" href="bank_soal.php">Batal</a>
        </div>
      </form>
    </div>
  </div>
  <script>
    function addOpsi(){
      var w=document.getElementById('opsiWrap');
      var n=w.querySelectorAll('input').length;
      var d=document.createElement('div'); d.className='check-line';
      d.innerHTML='<input type="text" name="opsi[]" class="form-control" placeholder="Opsi '+String.fromCharCode(65+n)+'">';
      w.appendChild(d);
    }
  </script>

<?php else: ?>

  <div class="card">
    <div class="card-header">
      <div>
        <h3>Koleksi Bank Soal</h3>
        <span class="sub">Total <?= count($rows) ?> soal ditampilkan · mendukung TPA, Psikotes, Software Office & Esai</span>
      </div>
      <a class="btn btn-primary btn-sm" href="bank_soal.php?aksi=tambah">+ Tambah Soal</a>
    </div>
    <div class="card-body" style="padding-bottom:6px">
      <form method="get" class="grid grid-3" style="gap:12px">
        <select name="kategori" class="form-control" data-auto-submit>
          <option value="">— Semua Kategori —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $fCategory==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="tipe" class="form-control" data-auto-submit>
          <option value="">— Semua Tipe —</option>
          <?php foreach ($tipeLabels as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $fTipe===$k?'selected':'' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" class="form-control" placeholder="Cari teks soal..." value="<?= e($fQ) ?>">
      </form>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>#</th><th>Pertanyaan</th><th>Kategori</th><th>Tipe</th><th>Bobot</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td style="max-width:380px">
              <div style="font-weight:600"><?= e(mb_strimwidth(preg_replace('/\s+/',' ',$r['pertanyaan']),0,110,'…')) ?></div>
              <?php if (!empty($r['contoh_file'])): ?>
                <div style="font-size:12px"><?= fileIcon($r['contoh_file']) ?> <a href="uploads/reference_files/<?= e($r['contoh_file']) ?>" target="_blank">file referensi</a></div>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-info"><?= e($r['kategori_nama']) ?></span></td>
            <td><?= e($tipeLabels[$r['tipe_jawaban']] ?? $r['tipe_jawaban']) ?></td>
            <td><?= e($r['bobot']) ?></td>
            <td><span class="badge badge-<?= $r['status']==='aktif'?'success':'muted' ?>"><?= e($r['status']) ?></span></td>
            <td style="white-space:nowrap">
              <a class="btn btn-outline btn-sm" href="bank_soal.php?aksi=edit&id=<?= $r['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus soal ini?')">
                <?= csrfField() ?><input type="hidden" name="form" value="hapus"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-danger btn-sm">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="7"><div class="empty-state"><div class="big-ico">🗂️</div>Belum ada soal. Klik <strong>+ Tambah Soal</strong> untuk memulai.</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php';
