<?php
/**
 * Header layout aplikasi (sidebar + topbar dibuka).
 * Variabel yang dapat di-set sebelum include:
 *   $pageTitle   : judul halaman di topbar
 *   $activeMenu  : key menu aktif di sidebar
 */
require_once __DIR__ . '/../config/app.php';
requireSsoLogin();
refreshSessionFromUsers();

$pageTitle  = $pageTitle ?? 'Dashboard';
$activeMenu = $activeMenu ?? '';
$userName   = $_SESSION['user_name'] ?? 'Pengguna';
$userRole   = isAdmin() ? 'HRD / Admin' : ($_SESSION['position'] ?: 'Peserta');
if (isManagement() && !isAdmin()) $userRole = 'Manajemen';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(logoUrl('logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">
      <img src="<?= e(logoUrl('logo.png')) ?>" alt="Logo LJU">
      <div>
        <div class="brand-text">E-Assessment</div>
        <div class="brand-sub">PT Lampung Jasa Utama</div>
      </div>
    </div>
    <nav>
      <?php if (isHrd() || isManagement()): ?>
        <div class="nav-section">Utama</div>
        <a href="index.php" class="<?= $activeMenu==='dashboard'?'active':'' ?>"><span class="ico">📊</span> Dashboard</a>
      <?php endif; ?>

      <?php if (isHrd()): ?>
        <div class="nav-section">Kelola Assessment</div>
        <a href="bank_soal.php" class="<?= $activeMenu==='bank_soal'?'active':'' ?>"><span class="ico">🗂️</span> Bank Soal</a>
        <a href="modul_test.php" class="<?= $activeMenu==='modul'?'active':'' ?>"><span class="ico">🧩</span> Modul Tes</a>
        <a href="paket_test.php" class="<?= $activeMenu==='paket'?'active':'' ?>"><span class="ico">📦</span> Paket & Jadwal</a>
        <a href="hr_review.php" class="<?= $activeMenu==='review'?'active':'' ?>"><span class="ico">🧑‍⚖️</span> Antrean Periksa</a>
        <a href="laporan.php" class="<?= $activeMenu==='laporan'?'active':'' ?>"><span class="ico">📄</span> Laporan PDF</a>
      <?php endif; ?>

      <?php if (isManagement() && !isHrd()): ?>
        <div class="nav-section">Monitoring</div>
        <a href="manajemen.php" class="<?= $activeMenu==='manajemen'?'active':'' ?>"><span class="ico">📈</span> Summary & Laporan</a>
      <?php endif; ?>

      <div class="nav-section">Saya</div>
      <a href="ujian_saya.php" class="<?= $activeMenu==='ujian_saya'?'active':'' ?>"><span class="ico">🎯</span> Ujian Saya</a>
      <a href="exam_lobby.php" class="<?= $activeMenu==='lobby'?'active':'' ?>"><span class="ico">🚪</span> Ruang Ujian (Lobby)</a>
    </nav>
  </aside>

  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title"><?= e($pageTitle) ?></div>
      <div class="topbar-right">
        <?php if (isHrd()): ?>
          <a class="btn btn-outline btn-sm" href="index.php">Dashboard HR</a>
        <?php endif; ?>
        <div class="user-chip">
          <?= renderAvatar($_SESSION['avatar'] ?? null, $userName) ?>
          <div>
            <div class="u-name"><?= e($userName) ?></div>
            <div class="u-role"><?= e($userRole) ?><?= !empty($_SESSION['nama_bidang']) ? ' · ' . e($_SESSION['nama_bidang']) : '' ?></div>
          </div>
        </div>
      </div>
    </header>
    <main class="content">
      <?php foreach (getFlashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
