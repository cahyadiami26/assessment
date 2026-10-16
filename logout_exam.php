<?php
/**
 * Keluar dari mode akses token (kandidat) — bukan logout SSO.
 */
require_once __DIR__ . '/config/app.php';
unset($_SESSION['exam_candidate']);
header('Location: exam_lobby.php');
exit;
