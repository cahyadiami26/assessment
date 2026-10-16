<?php
/**
 * Helper upload berkas aman (jawaban praktik, file referensi, laporan psikotes).
 */

function uploadDir(string $sub = ''): string
{
    return rtrim(__DIR__ . '/../uploads/' . $sub, '/');
}

/**
 * Pindahkan file yang diupload ke folder tujuan dengan nama acak.
 * @return string|null nama file baru, atau null jika tidak ada upload / gagal.
 */
function handleUpload(array $fileInput, string $subDir, array $allowedExt, int $maxMb = 15): ?string
{
    if (!isset($fileInput['error']) || $fileInput['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fileInput['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'Gagal mengunggah file (kode error: ' . (int)$fileInput['error'] . ').');
        return null;
    }
    if ($fileInput['size'] > $maxMb * 1024 * 1024) {
        flash('danger', "Ukuran file maksimal {$maxMb} MB.");
        return null;
    }
    $ext = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        flash('danger', 'Ekstensi file tidak diizinkan: .' . $ext);
        return null;
    }
    $dir = uploadDir($subDir);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($fileInput['name'], PATHINFO_FILENAME));
    $safe = substr($safe, 0, 60);
    $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $safe . '.' . $ext;
    $dest = $dir . '/' . $newName;

    if (!move_uploaded_file($fileInput['tmp_name'], $dest)) {
        flash('danger', 'Gagal menyimpan file upload.');
        return null;
    }
    return $newName;
}

/** Ekstensi yang dapat ditampilkan inline (preview) */
function isPreviewableImage(?string $f): bool
{
    if (!$f) return false;
    return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}
function isPdf(?string $f): bool
{
    return $f && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf';
}

/** Ikon sederhana berdasarkan ekstensi */
function fileIcon(?string $f): string
{
    $ext = strtolower(pathinfo((string)$f, PATHINFO_EXTENSION));
    return match ($ext) {
        'xls','xlsx','csv' => '📊',
        'doc','docx'       => '📄',
        'ppt','pptx'       => '📽️',
        'pdf'              => '📕',
        'jpg','jpeg','png','gif','webp' => '🖼️',
        default            => '📎',
    };
}
