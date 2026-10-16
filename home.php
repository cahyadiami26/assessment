<?php
/**
 * Landing page publik E-Assessment PT Lampung Jasa Utama.
 * Pengunjung diarahkan ke Ruang Ujian (exam_lobby) yang menangani SSO/Token.
 */
require_once __DIR__ . '/config/app.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Assessment — PT Lampung Jasa Utama</title>
<link rel="icon" href="<?= e(logoUrl('logo.png')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  .hero{min-height:100vh;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;
        background:linear-gradient(135deg,#0b1f3a 0%,#16345c 55%,#1d5fa8 100%);color:#fff;padding:40px 20px;position:relative;overflow:hidden}
  .hero::after{content:"";position:absolute;width:520px;height:520px;border-radius:50%;background:rgba(201,162,39,.14);top:-160px;right:-140px}
  .hero img.logo{height:74px;background:#fff;border-radius:14px;padding:8px;margin-bottom:22px}
  .hero h1{color:#fff;font-size:34px;margin:0 0 8px;font-weight:800}
  .hero p{color:#bcd0e8;max-width:640px;font-size:15px}
  .hero .cta-row{margin-top:26px;display:flex;gap:14px;flex-wrap:wrap;justify-content:center}
  .feat{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:44px;max-width:900px}
  @media(max-width:820px){.feat{grid-template-columns:repeat(2,1fr)}}
  .feat .f{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:16px;color:#dbe7f5;font-size:13px}
  .feat .f b{display:block;color:#fff;margin-bottom:4px;font-size:14px}
</style>
</head>
<body>
<div class="hero">
  <img src="<?= e(logoUrl('logo.png')) ?>" alt="Logo PT Lampung Jasa Utama" class="logo">
  <h1>Sistem Assessment Karyawan<br>E-Assessment PT Lampung Jasa Utama</h1>
  <p>Meningkatkan efisiensi proses <strong>recruitment</strong> dan <strong>evaluasi kinerja internal</strong> — Bank Soal TPA & Psikotes, Praktik Software Office, Ruang Ujian dengan proctoring, hingga Laporan PDF terpadu.</p>

  <div class="cta-row">
    <a class="btn btn-lg btn-primary" href="exam_lobby.php">🚪 Masuk Ruang Ujian / Lobby</a>
    <?php if (!empty($_SESSION['user_id']) && isHrd()): ?>
      <a class="btn btn-lg btn-outline" style="background:#fff" href="index.php">⚙️ Dashboard HR</a>
    <?php endif; ?>
  </div>

  <div class="feat">
    <div class="f"><b>🗂️ Bank Soal Serbaguna</b>TPA, PAPI Kostick, DISC, Most/Least, Excel/Word/PPT, Esai.</div>
    <div class="f"><b>🖥️ Device Check</b>Verifikasi webcam & koneksi sebelum ujian dimulai.</div>
    <div class="f"><b>⏱️ Timer & Auto-Submit</b>Jawaban tersimpan real-time, anti pindah tab.</div>
    <div class="f"><b>📄 Unified Report PDF</b>Konsolidasi auto-grading + review manual HR.</div>
  </div>
</div>
</body>
</html>
