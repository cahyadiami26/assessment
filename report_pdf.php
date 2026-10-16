<?php
/**
 * Unified Candidate Report — PDF (domPDF manual install, tanpa composer)
 * Akses: HRD/Admin & Manajemen.
 * Parameter: ?participant=<id>  (satu peserta dlm satu paket)
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();
if (!isHrd() && !isManagement()) { http_response_code(403); exit('Akses ditolak.'); }

require_once __DIR__ . '/vendor/autoload_dompdf.php'; // domPDF (install manual, tanpa composer)

use Dompdf\Dompdf;
use Dompdf\Options;

$db = getAppDb();
$pid = (int)($_GET['participant'] ?? 0);

$st = $db->prepare('SELECT pp.*, p.nama AS paket_nama, p.tujuan, p.posisi_target, p.jadwal_mulai, p.jadwal_selesai
                    FROM package_participants pp JOIN test_packages p ON p.id=pp.package_id WHERE pp.id=?');
$st->execute([$pid]);
$part = $st->fetch();
if (!$part) exit('Peserta tidak ditemukan.');

// Data karyawan dari SSO bila perlu
$emp = null;
if ($part['participant_type'] === 'employee' && $part['user_id']) {
    $su = getSsoDb()->prepare('SELECT name,nik,email,position,division,department FROM users WHERE id=? LIMIT 1');
    $su->execute([(int)$part['user_id']]);
    $emp = $su->fetch();
}
$nama   = $part['candidate_name'] ?: ($emp['name'] ?? 'Karyawan');
$tglLhrInfo = [
  'NIK'    => $emp['nik'] ?? '-',
  'Email'  => $part['candidate_email'] ?: ($emp['email'] ?? '-'),
  'Jabatan'=> $emp['position'] ?? 'Calon Karyawan',
  'Bidang' => $emp['division'] ?? '-',
  'Divisi' => $emp['department'] ?? '-',
];

// Attempt + modul
$st = $db->prepare('SELECT a.*, m.nama AS modul_nama, m.durasi_menit
                    FROM exam_attempts a JOIN test_modules m ON m.id=a.module_id
                    WHERE a.participant_id=? ORDER BY m.id');
$st->execute([$pid]);
$attempts = $st->fetchAll();

// Rata2 final
$finals = array_filter(array_column($attempts, 'final_score'));
$rataFinal = $finals ? round(array_sum($finals) / count($finals), 2) : null;

// Predikat
function predikat(?float $s): string {
    if ($s === null) return 'Belum Lengkap';
    if ($s >= 85) return 'Sangat Baik (A)';
    if ($s >= 70) return 'Baik (B)';
    if ($s >= 55) return 'Cukup (C)';
    return 'Kurang (D)';
}

// Evaluasi psikotes per attempt
$psyMap = [];
if ($attempts) {
    $ids = implode(',', array_map(fn($a)=>(int)$a['id'], $attempts));
    foreach ($db->query("SELECT pe.*, u.name AS evaluator FROM psycho_evaluations pe LEFT JOIN (" . "SELECT 1 id, '' name) u ON 1=0 WHERE pe.attempt_id IN ($ids)")->fetchAll() as $pe) {
        $psyMap[(int)$pe['attempt_id']] = $pe;
    }
    // nama evaluator dari db SSO
    $evIds = array_filter(array_column($psyMap, 'evaluated_by'));
    if ($evIds) {
        $in = implode(',', array_map('intval', $evIds));
        $map = [];
        foreach (getSsoDb()->query("SELECT id,name FROM users WHERE id IN ($in)")->fetchAll() as $u) $map[(int)$u['id']] = $u['name'];
        foreach ($psyMap as &$p) $p['evaluator_name'] = $map[(int)$p['evaluated_by']] ?? 'HRD';
        unset($p);
    }
}

/* =================== Bangun HTML laporan =================== */
$logo = logoUrl('logo.png');
$now  = fmtDate(date('Y-m-d H:i:s'));

$html = '
<style>
 body{font-family:"DejaVu Sans",sans-serif;font-size:10px;color:#22303e}
 .hd{border-bottom:3px solid #1d5fa8;padding-bottom:10px;margin-bottom:14px}
 .hd h1{font-size:16px;color:#0b1f3a;margin:0 0 2px}
 .hd .sub{color:#6b7a8c;font-size:9px}
 table{width:100%;border-collapse:collapse;margin:8px 0}
 th{background:#12294a;color:#fff;font-size:9px;padding:6px;text-align:left;border:1px solid #12294a}
 td{padding:6px;border:1px solid #d7dde6;font-size:9.5px}
 tr:nth-child(even) td{background:#f4f7fb}
 .box{border:1px solid #d7dde6;border-radius:6px;padding:10px;margin:8px 0;background:#fbfcfe}
 .k{color:#6b7a8c}
 .big{font-size:22px;color:#1d5fa8;font-weight:bold}
 .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:bold}
 .bgreen{background:#e3f6ee;color:#1e9e6a}.borange{background:#fdf1dd;color:#c77c1d}.bred{background:#fbe9e9;color:#cf3f3f}
 .foot{margin-top:26px;font-size:9px;color:#6b7a8c;border-top:1px solid #d7dde6;padding-top:8px}
 .sig{margin-top:34px;font-size:10px}
 .sig .line{margin-top:52px;border-top:1px solid #22303e;width:180px;padding-top:4px}
 h2.sec{font-size:12px;color:#0b1f3a;border-left:4px solid #c9a227;padding-left:8px;margin:16px 0 6px}
</style>
<div class="hd">
  <table style="border:none"><tr>
    <td style="border:none;width:70px"><img src="'.$logo.'" style="width:52px"></td>
    <td style="border:none">
      <h1>LAPORAN HASIL ASSESSMENT TERPADU</h1>
      <div class="sub">PT Lampung Jasa Utama — Sistem E-Assessment · assessment.lju.co.id<br>Dicetak: '.$now.'</div>
    </td>
    <td style="border:none;text-align:right;width:120px"><span class="badge bgreen">RAHASIA / CONFIDENTIAL</span></td>
  </tr></table>
</div>

<h2 class="sec">Identitas Peserta</h2>
<table><tr><th>Nama</th><td>'.htmlspecialchars($nama).'</td><th>Tipe</th><td>'.($part['participant_type']==='employee'?'Karyawan (SSO)':'Calon Karyawan (Token)').'</td></tr>';
foreach (array_chunk($tglLhrInfo, 2, true) as $row) {
    $html .= '<tr>';
    foreach ($row as $k=>$v) $html .= '<th>'.$k.'</th><td>'.htmlspecialchars((string)$v).'</td>';
    if (count($row)<2) $html .= '<th></th><td></td>';
    $html .= '</tr>';
}
$html .= '<tr><th>Paket Assessment</th><td colspan="3">'.htmlspecialchars($part['paket_nama']).' ('.($part['tujuan']==='recruitment'?'Rekrutmen':'Evaluasi Kinerja').')</td></tr>
<tr><th>Periode Jadwal</th><td colspan="3">'.fmtDate($part['jadwal_mulai']).' s/d '.fmtDate($part['jadwal_selesai']).'</td></tr></table>

<h2 class="sec">Ringkasan Skor</h2>
<table>
<tr><th>Modul Tes</th><th>Skor Auto</th><th>Skor Manual HR</th><th>Skor Final</th><th>Status</th></tr>';
foreach ($attempts as $a) {
    $html .= '<tr><td>'.htmlspecialchars($a['modul_nama']).'</td>
      <td>'.($a['score_auto']!==null?number_format((float)$a['score_auto'],1):'-').'</td>
      <td>'.($a['score_manual']!==null?number_format((float)$a['score_manual'],1):'-').'</td>
      <td><b>'.($a['final_score']!==null?number_format((float)$a['final_score'],1):'-').'</b></td>
      <td>'.ucfirst($a['status']).'</td></tr>';
}
$html .= '<tr><td colspan="3" style="text-align:right;background:#eef3fa"><b>RATA-RATA SKOR AKHIR</b></td>
  <td style="background:#eef3fa"><span class="big">'.($rataFinal!==null?number_format($rataFinal,1):'-').'</span>/100</td>
  <td style="background:#eef3fa"><b>'.predikat($rataFinal).'</b></td></tr>
</table>';

/* Psikotes */
$hasPsy = false;
foreach ($attempts as $a) if (isset($psyMap[(int)$a['id']])) { $hasPsy = true; break; }
if ($hasPsy) {
    $html .= '<h2 class="sec">Evaluasi Psikotes (Penilaian Kualitatif HR)</h2>';
    foreach ($attempts as $a) {
        $pe = $psyMap[(int)$a['id']] ?? null;
        if (!$pe) continue;
        $cls = $pe['rekomendasi']==='recommended'?'bgreen':($pe['rekomendasi']==='consider'?'borange':'bred');
        $html .= '<div class="box"><b>'.htmlspecialchars($a['modul_nama']).'</b> — Rekomendasi: <span class="badge '.$cls.'">'.strtoupper(str_replace('_',' ',(string)$pe['rekomendasi']) ?: 'BELUM DINILAI').'</span>
        <table style="margin-top:6px">
          <tr><th style="width:130px">Kelebihan</th><td>'.nl2br(htmlspecialchars((string)$pe['kelebihan'])).'</td></tr>
          <tr><th>Area Pengembangan</th><td>'.nl2br(htmlspecialchars((string)$pe['area_pengembangan'])).'</td></tr>
          <tr><th>Catatan HR</th><td>'.nl2br(htmlspecialchars((string)$pe['catatan_hr'])).'</td></tr>
          <tr><th>Penilai</th><td>'.htmlspecialchars($pe['evaluator_name'] ?? 'HRD').' · '.fmtDate($pe['evaluated_at']).'</td></tr>
        </table></div>';
    }
}

/* Catatan proctoring */
$totalSwitch = array_sum(array_column($attempts, 'tab_switches'));
$html .= '<h2 class="sec">Catatan Proctoring</h2>
<div class="box">Total deteksi pindah tab selama sesi: <b>'.(int)$totalSwitch.'×</b>.
Autosubmit terjadi pada modul yang melebihi batas waktu pengerjaan.</div>

<div class="sig">
  <table style="border:none"><tr>
    <td style="border:none;width:50%">Bandar Lampung, '.fmtDate(date('Y-m-d'),false).'<br>Diketahui, <b>HRD PT Lampung Jasa Utama</b>
      <div class="line">'.htmlspecialchars($_SESSION['user_name'] ?? '').'</div></td>
    <td style="border:none">&nbsp;</td>
  </tr></table>
</div>
<div class="foot">Dokumen ini dihasilkan otomatis oleh Sistem E-Assessment PT Lampung Jasa Utama dan bersifat rahasia untuk keperluan internal rekrutmen/evaluasi kinerja.</div>';

/* =================== Render domPDF =================== */
$options = new Options();
$options->set('isRemoteEnabled', true);   // utk memuat logo eksternal
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$fname = 'Assessment_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nama) . '_' . date('Ymd_His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
echo $dompdf->output();
