<?php
/**
 * Modul Test Builder — HR/Admin
 * Susun modul: ambil soal dari Bank Soal (manual/acak), durasi, pengacakan.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

$db = getAppDb();
$aksi = $_GET['aksi'] ?? 'list';

/* ---------- Simpan modul + daftar soal ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'modul') {
    verifyCsrf();
    $id       = (int)($_POST['id'] ?? 0);
    $nama     = trim($_POST['nama']);
    $durasi   = max(5, (int)$_POST['durasi_menit']);
    $acak     = isset($_POST['acak_soal']) ? 1 : 0;
    $jumlah   = $_POST['jumlah_soal'] !== '' ? (int)$_POST['jumlah_soal'] : null;
    $desk     = trim($_POST['deskripsi'] ?? '');
    $status   = $_POST['status'] ?? 'aktif';
    $qids     = array_map('intval', $_POST['question_ids'] ?? []);

    if (!$nama) { flash('danger', 'Nama modul wajib diisi.'); header('Location: modul_test.php?aksi=' . ($id?"edit&id=$id":'tambah')); exit; }

    $db->beginTransaction();
    if ($id) {
        $db->prepare('UPDATE test_modules SET nama=?, durasi_menit=?, acak_soal=?, jumlah_soal=?, deskripsi=?, status=? WHERE id=?')
           ->execute([$nama, $durasi, $acak, $jumlah, $desk, $status, $id]);
    } else {
        $db->prepare('INSERT INTO test_modules (nama, durasi_menit, acak_soal, jumlah_soal, deskripsi, status, created_by) VALUES (?,?,?,?,?,?,?)')
           ->execute([$nama, $durasi, $acak, $jumlah, $desk, $status, $_SESSION['user_id']]);
        $id = (int)$db->lastInsertId();
    }
    // Reset & simpan relasi soal
    $db->prepare('DELETE FROM module_questions WHERE module_id=?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO module_questions (module_id, question_id, urutan) VALUES (?,?,?)');
    foreach ($qids as $i => $q) { $ins->execute([$id, $q, $i + 1]); }
    $db->commit();
    flash('success', 'Modul "' . $nama . '" disimpan dengan ' . count($qids) . ' soal.');
    header('Location: modul_test.php'); exit;
}

if (isPost() && ($_POST['form'] ?? '') === 'hapus') {
    verifyCsrf();
    try {
        $db->prepare('DELETE FROM test_modules WHERE id=?')->execute([(int)$_POST['id']]);
        flash('success', 'Modul dihapus.');
    } catch (Throwable $e) {
        $db->prepare('UPDATE test_modules SET status="arsip" WHERE id=?')->execute([(int)$_POST['id']]);
        flash('warning', 'Modul dipakai paket — dipindahkan ke arsip.');
    }
    header('Location: modul_test.php'); exit;
}

/* ---------- Data tampilan ---------- */
$categories = $db->query('SELECT * FROM question_categories ORDER BY name')->fetchAll();
$allQuestions = $db->query('SELECT q.id, q.category_id, q.tipe_jawaban, q.pertanyaan, q.bobot, c.name AS kat FROM questions q JOIN question_categories c ON c.id=q.category_id WHERE q.status="aktif" ORDER BY q.id DESC')->fetchAll();

$pageTitle = 'Modul Tes'; $activeMenu = 'modul';
require_once __DIR__ . '/includes/header.php';

$formMode = in_array($aksi, ['tambah','edit']) ? $aksi : null;
$modEdit = null; $modQids = [];
if ($formMode === 'edit') {
    $st = $db->prepare('SELECT * FROM test_modules WHERE id=?'); $st->execute([(int)($_GET['id'] ?? 0)]);
    $modEdit = $st->fetch();
    if ($modEdit) {
        $st2 = $db->query('SELECT question_id FROM module_questions WHERE module_id=' . (int)$modEdit['id']);
        $modQids = array_column($st2->fetchAll(), 'question_id');
    } else { $formMode = null; }
}
?>

<?php if ($formMode): ?>
<div class="card">
  <div class="card-header">
    <h3><?= $formMode==='edit' ? 'Edit Modul #'.(int)$modEdit['id'] : 'Buat Modul Tes Baru' ?></h3>
    <a class="btn btn-ghost btn-sm" href="modul_test.php">← Kembali</a>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="form" value="modul">
      <input type="hidden" name="id" value="<?= (int)($modEdit['id'] ?? 0) ?>">

      <div class="grid grid-2">
        <div>
          <div class="form-group"><label class="form-label">Nama Modul</label>
            <input type="text" name="nama" class="form-control" required placeholder="cth: Tes Potensi Akademik" value="<?= e($modEdit['nama'] ?? '') ?>"></div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Durasi (menit)</label>
              <input type="number" name="durasi_menit" class="form-control" min="5" required value="<?= e($modEdit['durasi_menit'] ?? '60') ?>"></div>
            <div class="form-group"><label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="aktif" <?= ($modEdit['status'] ?? 'aktif')==='aktif'?'selected':'' ?>>Aktif</option>
                <option value="draft" <?= ($modEdit['status'] ?? '')==='draft'?'selected':'' ?>>Draft</option>
                <option value="arsip" <?= ($modEdit['status'] ?? '')==='arsip'?'selected':'' ?>>Arsip</option>
              </select></div>
          </div>
          <div class="form-group"><label class="form-label">Deskripsi / Petunjuk Modul</label>
            <textarea name="deskripsi" class="form-control" rows="3"><?= e($modEdit['deskripsi'] ?? '') ?></textarea></div>
          <div class="check-line"><input type="checkbox" name="acak_soal" value="1" <?= !empty($modEdit['acak_soal'])?'checked':'' ?>> <label>Acak urutan soal saat ujian</label></div>
          <div class="form-group" style="max-width:260px"><label class="form-label">Ambil acak sejumlah ... soal (opsional)</label>
            <input type="number" name="jumlah_soal" class="form-control" min="1" placeholder="kosong = semua soal terpilih" value="<?= e($modEdit['jumlah_soal'] ?? '') ?>"></div>
        </div>

        <div>
          <label class="form-label">Pilih Soal dari Bank Soal <span class="text-muted" style="font-weight:400">(<span id="selCount">0</span> dipilih)</span></label>

          <!-- Alat bantu seleksi cepat -->
          <div style="border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:8px;background:var(--bg,#f6f7fb)">
            <div class="form-row" style="gap:8px;align-items:flex-end">
              <div class="form-group" style="margin:0;flex:1;min-width:150px">
                <label class="form-label" style="font-size:11.5px">Kategori Soal</label>
                <select id="pickCat" class="form-control">
                  <option value="">— Pilih kategori —</option>
                  <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin:0;width:110px">
                <label class="form-label" style="font-size:11.5px">Jumlah N (acak)</label>
                <input type="number" id="pickN" class="form-control" min="1" placeholder="mis. 10">
              </div>
            </div>
            <div class="flex gap-1 mt-1" style="flex-wrap:wrap">
              <button type="button" class="btn btn-outline btn-sm" onclick="ambilKategori(false)" title="Tandai centang semua soal pada kategori terpilih">Ambil dari kategori</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="ambilKategori(true)" title="Hanya soal kategori ini yang tercentang (ganti pilihan lain)">Ambil SEMUA dari kategori</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="ambilAcakKategori()" title="Centang acak sejumlah N soal dari kategori terpilih">Ambil acak N dari kategori</button>
              <span style="flex:1"></span>
              <button type="button" class="btn btn-ghost btn-sm" onclick="setAllVisible(true)">Pilih tampil</button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="setAllVisible(false)">Kosongkan tampil</button>
            </div>
          </div>

          <input type="text" id="qSearch" class="form-control" placeholder="Cari soal..." style="margin-bottom:8px">
          <select id="filterKatList" class="form-control" onchange="terapkanFilter()" style="margin-bottom:8px">
            <option value="">— Tampilkan semua kategori dalam daftar —</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="max-height:430px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:10px">
            <?php foreach ($allQuestions as $q): ?>
              <label class="check-line q-item" data-cat="<?= (int)$q['category_id'] ?>" data-qid="<?= (int)$q['id'] ?>" data-text="<?= e(mb_strtolower(substr(preg_replace('/\s+/',' ',$q['pertanyaan']),0,180))) ?>">
                <input type="checkbox" name="question_ids[]" value="<?= $q['id'] ?>" <?= in_array($q['id'],$modQids)?'checked':'' ?> onchange="updateSel()">
                <span style="font-size:12.5px"><?= fileIcon('') ?> <b>#<?= $q['id'] ?></b> [<?= e($q['kat']) ?>] <?= e(mb_strimwidth(preg_replace('/\s+/',' ',$q['pertanyaan']),0,90,'…')) ?></span>
              </label>
            <?php endforeach; ?>
            <div id="qNone" style="display:none;padding:16px;text-align:center;color:var(--muted,#888)">Tidak ada soal yang cocok dengan filter.</div>
          </div>
        </div>
      </div>

      <div class="flex gap-1 mt-2">
        <button class="btn btn-primary">Simpan Modul</button>
        <a class="btn btn-outline" href="modul_test.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<script>
function updateSel(){ document.getElementById('selCount').textContent = document.querySelectorAll('input[name="question_ids[]"]:checked').length; }

function terapkanFilter(){
  var v = document.getElementById('qSearch').value.toLowerCase();
  var kat = document.getElementById('filterKatList').value;
  var terlihat = 0;
  document.querySelectorAll('.q-item').forEach(function(el){
    var okText = el.dataset.text.includes(v);
    var okKat  = !kat || el.dataset.cat === kat;
    var show   = okText && okKat;
    el.style.display = show ? '' : 'none';
    if (show) terlihat++;
  });
  document.getElementById('qNone').style.display = terlihat ? 'none' : '';
}
document.getElementById('qSearch').addEventListener('input', terapkanFilter);

function itemsKategori(idKat){
  return Array.prototype.slice.call(document.querySelectorAll('.q-item[data-cat="'+idKat+'"]'));
}
function cekBox(el){ return el.querySelector('input[type="checkbox"]'); }

// Ambil soal dari kategori terpilih (append) atau replace=true (hanya kategori tsb yang tercentang)
function ambilKategori(replace){
  var kat = document.getElementById('pickCat').value;
  if(!kat){ alert('Pilih kategori soal terlebih dahulu.'); return; }
  var items = itemsKategori(kat);
  if(!items.length){ alert('Tidak ada soal aktif pada kategori tersebut.'); return; }
  if(replace){
    document.querySelectorAll('input[name="question_ids[]"]').forEach(function(cb){ cb.checked=false; });
  }
  items.forEach(function(el){ cekBox(el).checked = true; });
  updateSel();
}

// Ambil acak sejumlah N soal dari kategori terpilih (append ke pilihan yang ada, tanpa duplikasi)
function ambilAcakKategori(){
  var kat = document.getElementById('pickCat').value;
  var n = parseInt(document.getElementById('pickN').value || '0', 10);
  if(!kat){ alert('Pilih kategori soal terlebih dahulu.'); return; }
  if(!n || n < 1){ alert('Isi jumlah N (acak) terlebih dahulu.'); return; }
  var items = itemsKategori(kat);
  if(!items.length){ alert('Tidak ada soal aktif pada kategori tersebut.'); return; }
  // Fisher-Yates shuffle
  for (var i = items.length - 1; i > 0; i--) {
    var j = Math.floor(Math.random() * (i + 1));
    var t = items[i]; items[i] = items[j]; items[j] = t;
  }
  var tercentang = 0;
  items.slice(0, n).forEach(function(el){ cekBox(el).checked = true; tercentang++; });
  if (n > items.length) alert('Soal tersedia hanya ' + items.length + ' pada kategori ini, semuanya diambil.');
  updateSel();
}

function setAllVisible(state){
  document.querySelectorAll('.q-item').forEach(function(el){
    if (el.style.display !== 'none') cekBox(el).checked = state;
  });
  updateSel();
}
updateSel();
</script>

<?php else: ?>
<div class="card">
  <div class="card-header">
    <div><h3>Daftar Modul Tes</h3><span class="sub">Satuan komponen yang digabung menjadi Paket Tes</span></div>
    <a class="btn btn-primary btn-sm" href="modul_test.php?aksi=tambah">+ Buat Modul</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Modul</th><th>Durasi</th><th>Soal</th><th>Pengacakan</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php
      $mods = $db->query('SELECT m.*, (SELECT COUNT(*) FROM module_questions mq WHERE mq.module_id=m.id) AS n_soal FROM test_modules m ORDER BY m.id DESC')->fetchAll();
      foreach ($mods as $m): ?>
        <tr>
          <td><strong><?= e($m['nama']) ?></strong><div class="text-muted" style="font-size:12px"><?= e(mb_strimwidth((string)$m['deskripsi'],0,80,'…')) ?></div></td>
          <td><?= (int)$m['durasi_menit'] ?> menit</td>
          <td><?= (int)$m['n_soal'] ?> soal</td>
          <td><?= $m['acak_soal'] ? 'Ya' : 'Tidak' ?><?= $m['jumlah_soal'] ? ' (ambil ' . (int)$m['jumlah_soal'] . ')' : '' ?></td>
          <td><span class="badge badge-<?= $m['status']==='aktif'?'success':($m['status']==='draft'?'warning':'muted') ?>"><?= e($m['status']) ?></span></td>
          <td style="white-space:nowrap">
            <a class="btn btn-outline btn-sm" href="modul_test.php?aksi=edit&id=<?= $m['id'] ?>">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus modul?')">
              <?= csrfField() ?><input type="hidden" name="form" value="hapus"><input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn btn-danger btn-sm">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; if (!$mods): ?>
        <tr><td colspan="6"><div class="empty-state"><div class="big-ico">🧩</div>Belum ada modul. Klik <strong>+ Buat Modul</strong>.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php';
