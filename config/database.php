<?php
/**
 * E-Assessment PT Lampung Jasa Utama
 * Konfigurasi Database (PDO)
 *
 * Dua koneksi:
 *  - getAppDb() : database aplikasi assessment (assessment_db)
 *  - getSsoDb() : database SSO / users (dms_db) -> berada di mesin yang sama, database berbeda
 */

// ---- Database Aplikasi Assessment ----
define('APP_DB_HOST', 'localhost');
define('APP_DB_NAME', 'assessment_db');
define('APP_DB_USER', 'root');
define('APP_DB_PASS', '');
define('APP_DB_CHARSET', 'utf8mb4');

// ---- Database SSO (users) ----
// Tabel `users` berada di database SSO (berbeda dari db aplikasi, satu mesin MySQL yang sama).
// Karena satu mesin, query dapat memakai prefix nama database: SSO_DB_NAME . '.users'
define('SSO_DB_HOST', 'localhost');
define('SSO_DB_NAME', 'dms_db');   // sesuaikan jika nama db SSO berbeda (mis. ljucoid_dms)
define('SSO_DB_USER', 'root');
define('SSO_DB_PASS', '');
define('SSO_DB_CHARSET', 'utf8mb4');

/** Nama tabel users lengkap dengan prefix database SSO (untuk cross-database JOIN). */
function usersTable(): string
{
    return '`' . SSO_DB_NAME . '`.`users`';
}

// Base URL aplikasi
define('APP_URL', 'https://assessment.lju.co.id');
define('APP_NAME', 'E-Assessment LJU');

/**
 * Koneksi PDO database aplikasi
 */
function getAppDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . APP_DB_HOST . ';dbname=' . APP_DB_NAME . ';charset=' . APP_DB_CHARSET;
        $pdo = new PDO($dsn, APP_DB_USER, APP_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Koneksi PDO database SSO (tabel users)
 */
function getSsoDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . SSO_DB_HOST . ';dbname=' . SSO_DB_NAME . ';charset=' . SSO_DB_CHARSET;
        $pdo = new PDO($dsn, SSO_DB_USER, SSO_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
