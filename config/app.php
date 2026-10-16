<?php
/**
 * E-Assessment PT Lampung Jasa Utama
 * Bootstrap Aplikasi: Session SSO, konstanta, helper umum
 */

if (defined('EASSESS_BOOTSTRAPPED')) {
    return;
}
define('EASSESS_BOOTSTRAPPED', true);

require_once __DIR__ . '/database.php';

/* =========================================================
 * 1. SESSION SSO (cookie domain .lju.co.id)
 * ========================================================= */
ini_set('session.cookie_domain', '.lju.co.id');
session_name('LJU_APPS');

session_set_cookie_params([
    'lifetime'  => 86400,
    'path'      => '/',
    'domain'    => '.lju.co.id',
    'secure'    => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly'  => true,
    'samesite'  => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
 * 2. Proteksi Halaman: redirect ke Portal SSO jika belum login
 * ========================================================= */
function requireSsoLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: https://lju.co.id/dashboard/?target_system=' . urlencode('https://assessment.lju.co.id') . '&redirect_after_login=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/**
 * Sinkronkan ulang data session dari tabel users (db SSO).
 * Dipanggil pada awal request agar profil selalu segar.
 */
function refreshSessionFromUsers(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    try {
        $stmt = getSsoDb()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $userRow = $stmt->fetch();
        if (!$userRow) {
            // User tidak aktif / tidak ada -> paksa logout SSO lokal
            unset($_SESSION['user_id']);
            return;
        }
        $_SESSION['user_id']      = $userRow['id'];
        $_SESSION['user_nik']     = $userRow['nik'];
        $_SESSION['username']     = $userRow['username'];
        $_SESSION['user_name']    = $userRow['name'];
        $_SESSION['user_email']   = $userRow['email'];
        $_SESSION['role']         = $userRow['role'];
        $_SESSION['position']     = $userRow['position'];
        // Nomenklatur aplikasi: division => nama_bidang, department => nama_divisi
        $_SESSION['nama_bidang']  = $userRow['division'];
        $_SESSION['nama_divisi']  = $userRow['department'];
        $_SESSION['avatar']       = $userRow['avatar'];
        $_SESSION['photo']        = $userRow['photo'];
        $_SESSION['signature']    = $userRow['signature'];
        $_SESSION['rfid']         = $userRow['rfid'];
        $_SESSION['webmail_user'] = $userRow['webmail_user'];
        $_SESSION['webmail_pass'] = $userRow['webmail_pass'];
    } catch (Throwable $e) {
        // Jangan bocorkan detail DB ke pengguna
        error_log('[E-Assess] refreshSessionFromUsers: ' . $e->getMessage());
    }
}

/* =========================================================
 * 3. Helper Umum
 * ========================================================= */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(419);
        exit('Sesi token tidak valid. Silakan muat ulang halaman.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function getFlashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function currentUserRole(): string
{
    return $_SESSION['role'] ?? '';
}

function isAdmin(): bool
{
    return in_array(currentUserRole(), ['admin'], true);
}

/** HRD/Admin boleh mengakses modul HR Assessor. */
function isHrd(): bool
{
    return isAdmin() || (($_SESSION['nama_bidang'] ?? '') === 'SDM');
}

/** Manajemen: jabatan struktural (melihat dashboard summary & laporan). */
function isManagement(): bool
{
    $pos = $_SESSION['position'] ?? '';
    $managementPositions = [
        'Direktur Utama', 'Direktur Operasional', 'Kepala Bidang', 'Kepala Divisi',
        'Manager', 'Corporate Secretary', 'Sekretaris Direksi', 'Sekretaris Komisaris',
    ];
    return in_array($pos, $managementPositions, true);
}

/**
 * Avatar user: gunakan foto DMS, fallback inisial.
 */
function userAvatarUrl(?string $avatar): string
{
    if (!empty($avatar)) {
        return 'https://dms.lju.co.id/uploads/avatars/' . rawurlencode($avatar);
    }
    return '';
}

function initialsOf(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : '?';
}

function renderAvatar(?string $avatar, string $name, string $extraClass = ''): string
{
    $url = userAvatarUrl($avatar);
    $ini = e(initialsOf($name));
    if ($url !== '') {
        return '<img src="' . e($url) . '" alt="' . e($name) . '" title="' . e($name) . '" class="avatar-img ' . e($extraClass) . '" onerror="this.style.display=\'none\';this.insertAdjacentHTML(\'afterend\',\'<span class=\\\\\'avatar-initial ' . e($extraClass) . '\\\\\'>' . $ini . '</span>\')">';
    }
    return '<span class="avatar-initial ' . e($extraClass) . '" title="' . e($name) . '">' . $ini . '</span>';
}

/** Format tanggal Indonesia */
function fmtDate(?string $dt, bool $withTime = true): string
{
    if (!$dt) return '-';
    $ts = strtotime($dt);
    $bulan = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $out = date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($withTime) $out .= ', ' . date('H:i', $ts);
    return $out;
}

/** Logo LJU */
function logoUrl(string $variant = 'logo.png'): string
{
    return 'https://lju.co.id/assets/images/logo/' . $variant;
}
