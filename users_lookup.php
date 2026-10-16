<?php
/**
 * Endpoint AJAX: pencarian karyawan dari tabel users (db SSO).
 * Hanya untuk HR/Admin. Mengembalikan JSON [{id,name,nik,position,division,email}]
 */
require_once __DIR__ . '/config/app.php';
requireSsoLogin();
refreshSessionFromUsers();

header('Content-Type: application/json; charset=utf-8');

if (!isHrd()) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    // daftar awal: 20 user aktif terbaru
    $rows = getSsoDb()->query('SELECT id, name, nik, position, division, email FROM users WHERE is_active=1 ORDER BY name ASC LIMIT 20')->fetchAll();
} else {
    $stmt = getSsoDb()->prepare(
        'SELECT id, name, nik, position, division, email FROM users
         WHERE is_active=1 AND (name LIKE ? OR nik LIKE ? OR email LIKE ? OR username LIKE ?)
         ORDER BY name ASC LIMIT 30'
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll();
}

echo json_encode(array_map(static fn($r) => [
    'id'       => (int)$r['id'],
    'name'     => $r['name'],
    'nik'      => $r['nik'],
    'position' => $r['position'],
    'division' => $r['division'],   // ditampilkan sbg "Bidang" di UI
    'email'    => $r['email'],
], $rows), JSON_UNESCAPED_UNICODE);
