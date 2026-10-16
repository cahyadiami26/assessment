<?php
/**
 * Paket Tes & Penjadwalan — HR/Admin
 * Gabungkan modul, tentukan jadwal, tunjuk peserta (SSO) / generate token calon + email.
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd()) { http_response_code(403); exit('Akses ditolak: halaman ini khusus HRD/Admin.'); }

require_once __DIR__ . '/includes/functions.php';
$db = getAppDb();
$aksi = $_GET['aksi'] ?? 'list';
$idPaket = (int)($_GET['id'] ?? 0);

/* ---------- Simpan paket dasar ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'paket') {
    verifyCsrf();
    $id      = (int)($_POST['id'] ?? 0);
    $nama    = trim($_POST['nama']);
    $tujuan  = $_POST['tujuan'] === 'performance' ? 'performance' : 'recruitment';
    $posisi  = trim($_POST['posisi_target'] ?? '');
    $mulai   = $_POST['jadwal_mulai'];
    $selesai = $_POST['jadwal_selesai'];
    $instr   = trim($_POST['instructions'] ?? '');
    $proctor = isset($_POST['camera_proctor']) ? 1 : 0;
    $status  = $_POST['status'] ?? 'draft';
    $modids  = array_map('intval', $_POST['module_ids'] ?? []);

    if (!$nama || !$mulai || !$selesai) {
        flash('danger', 'Nama & jadwal wajib lengkap.');
        header('Location: paket_test.php?aksi=' . ($id?"detail&id=$id":'tambah')); exit;
    }
    $db->beginTransaction();
    if ($id) {
        $db->prepare('UPDATE test_packages SET nama=?,tujuan=?,posisi_target=?,jadwal_mulai=?,jadwal_selesai=?,instructions=?,camera_proctor=?,status=? WHERE id=?')
           ->execute([$nama,$tujuan,$posisi,$mulai,$selesai,$instr,$proctor,$status,$id]);
    } else {
        $db->prepare('INSERT INTO test_packages (nama,tujuan,posisi_target,jadwal_mulai,jadwal_selesai,instructions,camera_proctor,status,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$nama,$tujuan,$posisi,$mulai,$selesai,$instr,$proctor,$status,$_SESSION['user_id']]);
        $id = (int)$db->lastInsertId();
    }
    $db->prepare('DELETE FROM package_modules WHERE package_id=?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO package_modules (package_id, module_id, urutan) VALUES (?,?,?)');
    foreach ($modids as $i => $m) { $ins->execute([$id, $m, $i+1]); }
    $db->commit();
    flash('success', 'Paket "' . $nama . '" disimpan.');
    header('Location: paket_test.php?aksi=detail&id=' . $id); exit;
}

/* ---------- Tambah karyawan via SSO ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'employee') {
    verifyCsrf();
    $pid  = (int)$_POST['package_id'];
    $uids = array_filter(array_map('intval', explode(',', $_POST['user_ids'] ?? '')));
    $n = 0;
    $st = $db->prepare('INSERT IGNORE INTO package_participants (package_id, participant_type, user_id, status) VALUES (?, "employee", ?, "invited")');
    foreach ($uids as $u) { $st->execute([$pid, $u]); $n += $st->rowCount(); }
    flash($n ? 'success' : 'warning', $n ? "$n karyawan ditambahkan sebagai peserta." : 'Tidak ada peserta baru (mungkin sudah terdaftar).');
    header("Location: paket_test.php?aksi=detail&id=$pid"); exit;
}

/* ---------- Tambah calon + token + email ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'candidate') {
    verifyCsrf();
    $pid   = (int)$_POST['package_id'];
    $namaC = trim($_POST['candidate_name']);
    $email = filter_var(trim($_POST['candidate_email']), FILTER_VALIDATE_EMAIL);
    if (!$namaC || !$email) {
        flash('danger', 'Nama & email calon wajib valid.');
        header("Location: paket_test.php?aksi=detail&id=$pid"); exit;
    }
    $token = strtoupper(bin2hex(random_bytes(8))); // 16 karakter
    $db->prepare('INSERT INTO package_participants (package_id, participant_type, candidate_name, candidate_email, access_token, invitation_sent) VALUES (?, "candidate", ?, ?, ?, 1)')
       ->execute([$pid, $namaC, $email, $token]);

    // Kirim undangan email
    $pkg = $db->prepare('SELECT * FROM test_packages WHERE id=?'); $pkg->execute([$pid]); $p = $pkg->fetch();
    $link = APP_URL . '/exam_lobby.php';
    $subject = 'Undangan Assessment — ' . ($p['nama'] ?? 'PT Lampung Jasa Utama');
    $body = "Yth. Bapak/Ibu {$namaC},\n\n"
          . "Anda diundang untuk mengikuti assessment \"{$p['nama']}\".\n\n"
          . "Jadwal : " . fmtDate($p['jadwal_mulai']) . " s/d " . fmtDate($p['jadwal_selesai']) . "\n"
          . "Akses  : {$link}\n"
          . "Token  : {$token}\nEmail: {$email}\n\n"
          . "Masuk melalui tab 'Calon Karyawan (Token)' pada Ruang Ujian.\n\n"
          . "Hormat kami,\nHRD PT Lampung Jasa Utama";
    $sent = @mail($email, $subject, $body, 'From: no-reply@lju.co.id');
    flash($sent ? 'success' : 'warning', 'Token dibuat: ' . $token . ($sent ? ' — undangan email terkirim.' : ' — gagal kirim email, bagikan token secara manual.'));
    header("Location: paket_test.php?aksi=detail&id=$pid"); exit;
}

/* ---------- Hapus peserta / paket ---------- */
if (isPost() && ($_POST['form'] ?? '') === 'hapus_peserta') {
    verifyCsrf();
    $db->prepare('DELETE FROM package_participants WHERE id=? AND package_id=?')->execute([(int)$_POST['peserta_id'], (int)$_POST['package_id']]);
    header('Location: paket_test.php?aksi=detail&id=' . (int)$_POST['package_id']); exit;
}
if (isPost() && ($_POST['form'] ?? '') === 'hapus') {
    verifyCsrf();
    try { $db->prepare('DELETE FROM test_packages WHERE id=?')->execute([(int)$_POST['id']]); flash('success','Paket dihapus.'); }
    catch (Throwable $e) { $db->prepare('UPDATE test_packages SET status="archived" WHERE id=?')->execute([(int)$_POST['id']]); flash('warning','Paket punya attempt — diarsipkan.'); }
    header('Location: paket_test.php'); exit;
}

$pageTitle = 'Paket & Jadwal'; $activeMenu = 'paket';
require_once __DIR__ . '/includes/header.php';

/* ================= DETAIL PAKET ================= */
if ($aksi === 'detail' && $idPaket) {
    $st = $db->prepare('SELECT * FROM test_packages WHERE id=?'); $st->execute([$idPaket]); $p = $st->fetch();
    if (!$p) { echo '<div class="alert alert-danger">Paket tidak ditemukan.</div>'; require __DIR__.'/includes/footer.php'; exit; }

    $mods = $db->query('SELECT m.* FROM package_modules pm JOIN test_modules m ON m.id=pm.module_id WHERE pm.package_id='.$idPaket.' ORDER BY pm.urutan')->fetchAll();
    $parts = $db->prepare('SELECT pp.*, (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.participant_id=pp.id AND ea.status IN ("submitted","reviewed")) AS modul_selesai FROM package_participants pp WHERE pp.package_id=? ORDER BY pp.id DESC');
    $parts->execute([$idPaket]); $parts = $parts->fetchAll();

    $allModules = $db->query('SELECT * FROM test_modules WHERE status="aktif" ORDER BY nama')->fetchAll();
    $modIdsSel  = array_column($mods, 'id');
    ?>
    <div class="flex justify-between items-center flex-wrap gap-2 mb-2">
      <div><h2 class="mb-0"><?= e($p['nama']) ?></h2>
        <span class="badge badge-<?= $p['status']==='active'?'success':'info' ?>"><?= e($p['status']) ?></span>
        <span class="badge badge-gold"><?= $p['tujuan']==='recruitment'?'Rekrutmen':'Evaluasi Kinerja' ?></span>
        <span class="text-muted">· <?= e($p['posisi_target'] ?: 'posisi umum') ?></span>
      </div>
      <a class="btn btn-outline btn-sm" href="paket_test.php?aksi=edit&id=<?= $p['id'] ?>">✏️ Edit Paket</a>
    </div>

    <div class="grid grid-2">
      <div class="card"><div class="card-header"><h3>📅 Jadwal & Modul</h3></div><div class="card-body">
        <p><strong>Mulai :</strong> <?= fmtDate($p['jadwal_mulai']) ?><br>
           <strong>Selesai:</strong> <?= fmtDate($p['jadwal_selesai']) ?><br>
           <strong>Proctoring kamera:</strong> <?= $p['camera_proctor']?'Aktif':'Nonaktif' ?></p>
        <?php foreach ($mods as $i=>$m): ?>
          <div class="module-list-item"><div class="ml-num"><?= $i+1 ?></div>
            <div><strong><?= e($m['nama']) ?></strong><div class="module-meta"><?= (int)$m['durasi_menit'] ?> menit</div></div>
          </div>
        <?php endforeach; if(!$mods) echo '<div class="empty-state">Belum ada modul terpasang.</div>'; ?>
        <?php if(!empty($p['instructions'])): ?><div class="divider"></div><h4>Aturan Ujian</h4><div style="white-space:pre-line"><?= e($p['instructions']) ?></div><?php endif; ?>
      </div></div>

      <div class="card"><div class="card-header"><h3>👥 Peserta (<?= count($parts) ?>)</h3></div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>Peserta</th><th>Akses</th><th>Progress</th><th></th></tr></thead><tbody>
        <?php foreach ($parts as $ps):
          $nm = $ps['candidate_name'] ?: ('Karyawan #' . $ps['user_id']); ?>
          <tr>
            <td><div class="cell-user"><?= renderAvatar(null, $nm, 'avatar-sm') ?><div><strong><?= e($nm) ?></strong>
              <div class="text-muted" style="font-size:12px"><?= e($ps['candidate_email'] ?: '') ?></div></div></div></td>
            <td><?= $ps['participant_type']==='employee' ? '<span class="badge badge-info">SSO</span>' : '<span class="badge badge-gold" title="'.e($ps['access_token']).'">Token: '.e(substr((string)$ps['access_token'],0,8)).'…</span>' ?></td>
            <td><?= (int)$ps['modul_selesai'] ?>/<?= count($mods) ?> modul<br><span class="badge badge-<?= $ps['status']==='finished'?'success':'muted' ?>"><?= e($ps['status']) ?></span></td>
            <td><form method="post" onsubmit="return confirm('Hapus peserta?')"><?= csrfField() ?>
              <input type="hidden" name="form" value="hapus_peserta"><input type="hidden" name="package_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="peserta_id" value="<?= $ps['id'] ?>"><button class="btn btn-ghost btn-sm">✕</button></form></td>
          </tr>
        <?php endforeach; if(!$parts) echo '<tr><td colspan="4" class="empty-state">Belum ada peserta.</td></tr>'; ?>
        </tbody></table></div>
      </div>

      <div class="card"><div class="card-header"><h3>➕ Tambah Karyawan (SSO)</h3></div><div class="card-body">
        <form method="post" id="fEmp"><?= csrfField() ?>
          <input type="hidden" name="form" value="employee"><input type="hidden" name="package_id" value="<?= $p['id'] ?>">
          <input type="hidden" name="user_ids" id="user_ids" value="">
          <input type="text" id="empSearch" class="form-control mb-1" placeholder="Cari nama / NIK / email karyawan...">
          <div id="empList" style="max-height:230px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:10px"></div>
          <button class="btn btn-primary btn-sm mt-2">Tambahkan Terpilih</button>
        </form>
      </div></div>

      <div class="card"><div class="card-header"><h3>➕ Tambah Calon Karyawan (Token + Email)</h3></div><div class="card-body">
        <form method="post"><?= csrfField() ?>
          <input type="hidden" name="form" value="candidate"><input type="hidden" name="package_id" value="<?= $p['id'] ?>">
          <div class="form-group"><label class="form-label">Nama Calon</label><input type="text" name="candidate_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Email</label><input type="email" name="candidate_email" class="form-control" required></div>
          <div class="form-hint mb-1">Sistem akan men-generate token akses unik & mengirim undangan email otomatis.</div>
          <button class="btn btn-success btn-sm">Buat Token & Kirim Undangan</button>
        </form>
      </div></div>
    </div>

    <script>
    // Pencarian karyawan dari db SSO (endpoint users_lookup.php)
    const empList = document.getElementById('empList');
    let empTimer;
    document.getElementById('empSearch').addEventListener('input', function(){
      clearTimeout(empTimer);
      const q = this.value;
      empTimer = setTimeout(()=>{
        fetch('users_lookup.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(rows=>{
          empList.innerHTML = rows.map(u=>`<label class="check-line"><input type="checkbox" value="${u.id}" onchange="syncIds()"> <span><b>${u.name}</b> <span style="color:var(--muted);font-size:12px">${u.position||''} · ${u.division||''}</span></span></label>`).join('') || '<div class="text-muted">Tidak ditemukan.</div>';
          syncIds();
        });
      }, 350);
    });
    function syncIds(){
      const ids=[...empList.querySelectorAll('input:checked')].map(i=>i.value);
      document.getElementById('user_ids').value = ids.join(',');
    }
    </script>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* ================= FORM TAMBAH / EDIT ================= */
if (in_array($aksi, ['tambah','edit'])) {
    $p = ['nama'=>'','tujuan'=>'recruitment','posisi_target'=>'','jadwal_mulai'=>'','jadwal_selesai'=>'','instructions'=>'','camera_proctor'=>1,'status'=>'draft','id'=>0];
    $selMods = [];
    if ($aksi === 'edit' && $idPaket) {
        $st = $db->prepare('SELECT * FROM test_packages WHERE id=?'); $st->execute([$idPaket]);
        if ($st->fetch()) { $p = $st->fetch();
            $selMods = array_column($db->query('SELECT module_id FROM package_modules WHERE package_id='.$idPaket)->fetchAll(), 'module_id'); }
    }
    $allModules = $db->query('SELECT * FROM test_modules WHERE status="aktif" ORDER BY nama')->fetchAll();
    ?>
    <div class="card">
      <div class="card-header"><h3><?= $aksi==='edit' ? 'Edit Paket #'.$p['id'] : 'Buat Paket Tes Baru' ?></h3>
        <a class="btn btn-ghost btn-sm" href="paket_test.php">← Kembali</a></div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="form" value="paket"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Nama Paket</label>
              <input type="text" name="nama" class="form-control" required placeholder="cth: Paket Rekrutmen Staff Finance" value="<?= e($p['nama']) ?>"></div>
            <div class="form-group"><label class="form-label">Posisi Target</label>
              <input type="text" name="posisi_target" class="form-control" placeholder="cth: Staff Keuangan" value="<?= e($p['posisi_target']) ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Tujuan</label>
              <select name="tujuan" class="form-control">
                <option value="recruitment" <?= $p['tujuan']==='recruitment'?'selected':'' ?>>Rekrutmen</option>
                <option value="performance" <?= $p['tujuan']==='performance'?'selected':'' ?>>Evaluasi Kinerja Internal</option>
              </select></div>
            <div class="form-group"><label class="form-label">Status</label>
              <select name="status" class="form-control">
                <?php foreach(['draft','scheduled','active','closed','archived'] as $s): ?>
                  <option value="<?= $s ?>" <?= $p['status']===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Jadwal Mulai</label>
              <input type="datetime-local" name="jadwal_mulai" class="form-control" required value="<?= e(str_replace(' ','T',substr((string)$p['jadwal_mulai'],0,16))) ?>"></div>
            <div class="form-group"><label class="form-label">Jadwal Selesai</label>
              <input type="datetime-local" name="jadwal_selesai" class="form-control" required value="<?= e(str_replace(' ','T',substr((string)$p['jadwal_selesai'],0,16))) ?>"></div>
          </div>
          <div class="form-group"><label class="form-label">Instruksi / Aturan Ujian (tampil di Lobby)</label>
            <textarea name="instructions" class="form-control" rows="3" placeholder="cth: Dilarang membuka tab lain, pastikan koneksi stabil..."><?= e($p['instructions']) ?></textarea></div>
          <div class="check-line"><input type="checkbox" name="camera_proctor" value="1" <?= $p['camera_proctor']?'checked':'' ?>> <label>Aktifkan proctoring webcam</label></div>

          <div class="form-group mt-2"><label class="form-label">Modul dalam paket (urutan = prioritas tampil)</label>
            <div style="max-height:300px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:10px">
              <?php foreach ($allModules as $m): ?>
                <label class="check-line"><input type="checkbox" name="module_ids[]" value="<?= $m['id'] ?>" <?= in_array($m['id'],$selMods)?'checked':'' ?>>
                  <span><strong><?= e($m['nama']) ?></strong> <span class="text-muted" style="font-size:12px"><?= (int)$m['durasi_menit'] ?> menit</span></span></label>
              <?php endforeach; if(!$allModules) echo '<div class="text-muted">Belum ada modul aktif — buat di menu <a href="modul_test.php">Modul Tes</a>.</div>'; ?>
            </div>
          </div>
          <div class="flex gap-1"><button class="btn btn-primary">Simpan Paket</button>
            <a class="btn btn-outline" href="paket_test.php">Batal</a></div>
        </form>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* ================= LIST PAKET ================= */
$pkgs = $db->query('SELECT p.*, 
    (SELECT COUNT(*) FROM package_participants pp WHERE pp.package_id=p.id) AS n_peserta,
    (SELECT GROUP_CONCAT(m.nama ORDER BY pm.urutan SEPARATOR ", ") FROM package_modules pm JOIN test_modules m ON m.id=pm.module_id WHERE pm.package_id=p.id) AS daftar_modul
  FROM test_packages p ORDER BY p.jadwal_mulai DESC')->fetchAll();
?>
<div class="card">
  <div class="card-header">
    <div><h3>Daftar Paket Assessment</h3><span class="sub">Rangkaian modul + jadwal + peserta/token</span></div>
    <a class="btn btn-primary btn-sm" href="paket_test.php?aksi=tambah">+ Buat Paket</a>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Paket</th><th>Tujuan</th><th>Jadwal</th><th>Modul</th><th>Peserta</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($pkgs as $p): ?>
      <tr>
        <td><strong><?= e($p['nama']) ?></strong><div class="text-muted" style="font-size:12px"><?= e($p['posisi_target'] ?: '-') ?></div></td>
        <td><span class="badge badge-gold"><?= $p['tujuan']==='recruitment'?'Rekrutmen':'Kinerja' ?></span></td>
        <td style="font-size:12.5px"><?= fmtDate($p['jadwal_mulai']) ?><br>s/d <?= fmtDate($p['jadwal_selesai']) ?></td>
        <td style="max-width:220px;font-size:12.5px"><?= e($p['daftar_modul'] ?: '-') ?></td>
        <td><?= (int)$p['n_peserta'] ?></td>
        <td><span class="badge badge-<?= $p['status']==='active'?'success':($p['status']==='closed'||$p['status']==='archived'?'muted':'info') ?>"><?= e($p['status']) ?></span></td>
        <td style="white-space:nowrap">
          <a class="btn btn-outline btn-sm" href="paket_test.php?aksi=detail&id=<?= $p['id'] ?>">Kelola</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Hapus paket?')"><?= csrfField() ?>
            <input type="hidden" name="form" value="hapus"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-danger btn-sm">Hapus</button></form>
        </td>
      </tr>
    <?php endforeach; if(!$pkgs) echo '<tr><td colspan="7"><div class="empty-state"><div class="big-ico">📦</div>Belum ada paket. Klik <strong>+ Buat Paket</strong>.</div></td></tr>'; ?>
    </tbody></table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
