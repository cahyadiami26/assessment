<?php
/**
 * UJIAN SAYA — daftar attempt milik karyawan yang login via SSO.
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();

$db = getAppDb();
$st = $db->prepare('SELECT a.*, m.nama AS modul_nama, p.nama AS paket_nama
                    FROM exam_attempts a
                    JOIN test_modules m ON m.id=a.module_id
                    JOIN test_packages p ON p.id=a.package_id
                    JOIN package_participants pp ON pp.id=a.participant_id
                    WHERE pp.user_id=? ORDER BY a.submitted_at DESC');
$st->execute([$_SESSION['user_id']]);
$rows = $st->fetchAll();

$pageTitle = 'Ujian Saya'; $activeMenu = 'ujian_saya';
require_once __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <div><h3>🎯 Riwayat & Status Assessment Saya</h3>
      <span class="sub"><?= e($_SESSION['user_name']) ?> · NIK <?= e($_SESSION['user_nik'] ?: '-') ?></span></div>
    <a class="btn btn-primary btn-sm" href="exam_lobby.php">🚪 Buka Ruang Ujian (Lobby)</a>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Paket / Modul</th><th>Mulai</th><th>Selesai</th><th>Skor Akhir</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['paket_nama']) ?></strong><div class="text-muted" style="font-size:12px"><?= e($r['modul_nama']) ?></div></td>
        <td style="font-size:12.5px"><?= fmtDate($r['started_at']) ?></td>
        <td style="font-size:12.5px"><?= fmtDate($r['submitted_at']) ?><?= $r['auto_submitted']?' <span class="badge badge-warning">auto</span>':'' ?></td>
        <td><strong><?= $r['final_score']!==null?number_format((float)$r['final_score'],1):'-' ?></strong></td>
        <td><span class="badge badge-<?= $r['status']==='reviewed'?'success':($r['status']==='submitted'?'info':'warning') ?>"><?= e($r['status']) ?></span></td>
      </tr>
    <?php endforeach; if(!$rows) echo '<tr><td colspan="5"><div class="empty-state"><div class="big-ico">📭</div>Belum ada riwayat ujian. Buka Ruang Ujian untuk melihat paket yang ditunjuk untuk Anda.</div></td></tr>'; ?>
    </tbody></table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
