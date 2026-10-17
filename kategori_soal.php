<?php
/**
 * Modul Pengaturan Kategori Soal — HR/Admin
 * Kelola question_categories: TPA/Kognitif, Psikotes (PAPI/DISC), Software Office, Esai.
 * Setiap kategori punya test_type yang menentukan cara penilaian (auto vs manual).
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

require_once __DIR__ . '/includes/functions.php';
$db = getAppDb();
$aksi = $_GET['aksi'] ?? 'list';

$TEST_TYPES = [
    'cognitive' => ['label' => 'TPA / Kognitif',   'desc' => 'Verbal, Numerik, Logika/Spasial — auto-grading dari kunci jawaban.',            'ico' => '🧠'],
    'personality' => ['label' => 'Kepribadian / Psikotes', 'desc' => 'PAPI Kostick, DISC, Motivasi/Gaya Kerja — skor mentah disimpan, interpretasi manual HR.', 'ico' => '🎭'],
    'software'  => ['label' => 'Software Office & Teknis', 'desc' => 'Teori (pilihan ganda) dan praktik upload file (.xlsx/.docx) — teori auto, praktik manual.', 'ico' => '💻'],
    'essay'     => ['label' => 'Uraian / Esai',      'desc' => 'Jawaban rich text, dinilai manual oleh HR berdasarkan rubrik.',                 'ico' => '✍️'],
];

/* ---------- Simpan kategori (tambah/edit) ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'kategori') {
    verifyCsrf();
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $slug        = trim($_POST['slug'] ?? '');
    $test_type   = $_POST['test_type'] ?? 'cognitive';
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || !isset($TEST_TYPES[$test_type])) {
        flash('danger', 'Nama dan tipe tes wajib diisi dengan benar.');
        header('Location: kategori_soal.php?aksi=' . ($id ? "edit&id=$id" : 'tambah')); exit;
    }
    // Slug otomatis dari nama bila kosong
    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    } else {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug), '-'));
    }

    try {
        if ($id) {
            $st = $db->prepare('UPDATE question_categories SET name=?, slug=?, test_type=?, description=? WHERE id=?');
            $st->execute([$name, $slug, $test_type, $description, $id]);
            flash('success', 'Kategori berhasil diperbarui.');
        } else {
            $st = $db->prepare('INSERT INTO question_categories (name, slug, test_type, description) VALUES (?,?,?,?)');
            $st->execute([$name, $slug, $test_type, $description]);
            flash('success', 'Kategori baru berhasil ditambahkan.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('danger', 'Slug "' . $slug . '" sudah dipakai kategori lain. Ganti slug atau biarkan kosong (otomatis).');
        } else {
            flash('danger', 'Gagal menyimpan kategori: ' . $e->getMessage());
        }
        header('Location: kategori_soal.php?aksi=' . ($id ? "edit&id=$id" : 'tambah')); exit;
    }
    header('Location: kategori_soal.php'); exit;
}

/* ---------- Hapus kategori ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'hapus') {
    verifyCsrf();
    $id = (int)$_POST['id'];
    try {
        $db->prepare('DELETE FROM question_categories WHERE id=?')->execute([$id]);
        flash('success', 'Kategori dihapus.');
    } catch (Throwable $e) {
        flash('warning', 'Kategori tidak dapat dihapus karena masih dipakai oleh soal/modul tes.');
    }
    header('Location: kategori_soal.php'); exit;
}

/* ---------- Data utk tampilan ---------- */
$stats = [];
try {
    foreach ($db->query('SELECT category_id, COUNT(*) n, SUM(status="aktif") n_aktif FROM questions GROUP BY category_id') as $r) {
        $stats[(int)$r['category_id']] = ['n' => (int)$r['n'], 'aktif' => (int)$r['n_aktif']];
    }
} catch (Throwable $e) { /* tabel questions mungkin kosong */ }

$modulStats = [];
try {
    foreach ($db->query('SELECT category_id, COUNT(*) n FROM module_questions mq JOIN questions q ON q.id=mq.question_id GROUP BY q.category_id') as $r) {
        $modulStats[(int)$r['category_id']] = (int)$r['n'];
    }
} catch (Throwable $e) {}

$rows = $db->query('SELECT * FROM question_categories ORDER BY test_type, name')->fetchAll();

$pageTitle  = 'Pengaturan Kategori Soal';
$activeMenu = 'kategori_soal';
require_once __DIR__ . '/includes/header.php';

$formMode = in_array($aksi, ['tambah', 'edit']) ? $aksi : null;
$rowEdit = null;
if ($formMode === 'edit') {
    $st = $db->prepare('SELECT * FROM question_categories WHERE id=?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $rowEdit = $st->fetch();
    if (!$rowEdit) { $formMode = null; flash('danger', 'Kategori tidak ditemukan.'); }
}
?>

<?php if ($formMode): ?>
  <div class="card">
    <div class="card-header">
      <h3><?= $formMode === 'edit' ? 'Edit Kategori #' . (int)$rowEdit['id'] . ': ' . e($rowEdit['name']) : 'Tambah Kategori Baru' ?></h3>
      <a class="btn btn-ghost btn-sm" href="kategori_soal.php">← Kembali ke Daftar</a>
    </div>
    <div class="card-body">
      <form method="post" id="katForm">
        <?= csrfField() ?><input type="hidden" name="form" value="kategori">
        <input type="hidden" name="id" value="<?= (int)($rowEdit['id'] ?? 0) ?>">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nama Kategori <span style="color:var(--danger)">*</span></label>
            <input type="text" name="name" id="inpName" class="form-control" required
                   placeholder="cth: Tes Potensi Akademik - Numerik" value="<?= e($rowEdit['name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Slug (kode unik)</label>
            <input type="text" name="slug" id="inpSlug" class="form-control"
                   placeholder="kosongkan = otomatis dari nama" value="<?= e($rowEdit['slug'] ?? '') ?>">
            <div class="form-hint">Huruf kecil, angka & tanda hubung. Digunakan sistem untuk mengelompokkan soal.</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Tipe Tes <span style="color:var(--danger)">*</span></label>
          <div class="grid grid-2" style="gap:12px" id="typePicker">
            <?php foreach ($TEST_TYPES as $k => $tt): ?>
              <label class="card" style="padding:14px;cursor:pointer;display:flex;gap:10px;align-items:flex-start;border:2px solid <?= ($rowEdit['test_type'] ?? 'cognitive') === $k ? 'var(--primary-600)' : 'var(--border)' ?>" data-type-opt="<?= $k ?>">
                <input type="radio" name="test_type" value="<?= $k ?>" style="margin-top:3px"
                       <?= ($rowEdit['test_type'] ?? 'cognitive') === $k ? 'checked' : '' ?>>
                <span>
                  <strong><?= $tt['ico'] ?> <?= e($tt['label']) ?></strong><br>
                  <span style="font-size:12px;color:var(--muted)"><?= e($tt['desc']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Deskripsi / Petunjuk Pengerjaan</label>
          <textarea name="description" class="form-control" rows="3"
                    placeholder="Keterangan singkat tentang kategori ini, tampilkan saat HR menyusun soal..."><?= e($rowEdit['description'] ?? '') ?></textarea>
        </div>

        <div class="flex gap-1">
          <button class="btn btn-primary"><?= $formMode === 'edit' ? 'Simpan Perubahan' : 'Simpan Kategori' ?></button>
          <a class="btn btn-outline" href="kategori_soal.php">Batal</a>
        </div>
      </form>
    </div>
  </div>
  <script>
    // Generate slug otomatis dari nama bila slug belum pernah diketik manual
    (function () {
      var inpName = document.getElementById('inpName'),
          inpSlug = document.getElementById('inpSlug'),
          slugEdited = inpSlug.value.trim() !== '';
      inpSlug.addEventListener('input', function () { slugEdited = this.value.trim() !== ''; });
      inpName.addEventListener('input', function () {
        if (slugEdited) return;
        inpSlug.value = this.value.toLowerCase()
          .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').substring(0, 60);
      });
      // Highlight border radio terpilih
      document.querySelectorAll('#typePicker input[type=radio]').forEach(function (r) {
        r.addEventListener('change', function () {
          document.querySelectorAll('#typePicker [data-type-opt]').forEach(function (l) {
            l.style.borderColor = l.querySelector('input').checked ? 'var(--primary-600)' : 'var(--border)';
          });
        });
      });
    })();
  </script>

<?php else: ?>

  <div class="card">
    <div class="card-header">
      <div>
        <h3>Pengaturan Kategori Soal</h3>
        <span class="sub"><?= count($rows) ?> kategori · kelompokkan bank soal berdasarkan jenis tes &amp; metode penilaiannya</span>
      </div>
      <a class="btn btn-primary btn-sm" href="kategori_soal.php?aksi=tambah">+ Tambah Kategori</a>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>#</th><th>Kategori</th><th>Tipe Tes</th><th>Metode Penilaian</th>
            <th>Jumlah Soal</th><th>Dipakai Modul</th><th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): $tt = $TEST_TYPES[$r['test_type']] ?? null; ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td style="max-width:340px">
              <div style="font-weight:700"><?= ($tt['ico'] ?? '📁') ?> <?= e($r['name']) ?></div>
              <div style="font-size:12px;color:var(--muted)">
                <code><?= e($r['slug']) ?></code>
                <?php if (!empty($r['description'])): ?> · <?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $r['description']), 0, 80, '…')) ?><?php endif; ?>
              </div>
            </td>
            <td><span class="badge badge-info"><?= e($tt['label'] ?? $r['test_type']) ?></span></td>
            <td style="font-size:12px;color:var(--muted);max-width:280px">
              <?= e($tt['desc'] ?? '-') ?>
            </td>
            <td>
              <strong><?= $stats[(int)$r['id']]['n'] ?? 0 ?></strong>
              <?php if (($stats[(int)$r['id']]['n'] ?? 0) > 0): ?>
                <div style="font-size:11px;color:var(--muted)"><?= (int)($stats[(int)$r['id']]['aktif'] ?? 0) ?> aktif</div>
              <?php endif; ?>
            </td>
            <td><?= $modulStats[(int)$r['id']] ?? 0 ?> soal</td>
            <td style="white-space:nowrap">
              <a class="btn btn-outline btn-sm" href="bank_soal.php?kategori=<?= (int)$r['id'] ?>">Lihat Soal</a>
              <a class="btn btn-outline btn-sm" href="kategori_soal.php?aksi=edit&id=<?= $r['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus kategori \'<?= e(addslashes($r['name'])) ?>\'? Hanya bisa jika tidak ada soal yang memakai.')">
                <?= csrfField() ?><input type="hidden" name="form" value="hapus"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-danger btn-sm">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="7"><div class="empty-state"><div class="big-ico">🗂️</div>Belum ada kategori. Klik <strong>+ Tambah Kategori</strong> untuk memulai (mis. TPA Verbal, PAPI Kostick, Praktik Excel).</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Catatan Tipe Tes</h3></div>
    <div class="card-body">
      <div class="grid grid-2" style="gap:12px">
        <?php foreach ($TEST_TYPES as $k => $tt): ?>
          <div class="alert alert-info" style="margin:0">
            <strong><?= $tt['ico'] ?> <?= e($tt['label']) ?></strong> (<?= $k ?>)<br>
            <span style="font-size:13px"><?= e($tt['desc']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php';
