<?php
/* ============================================================
 * NORMALISASI JAWABAN PILIHAN GANDA (AUTO-GRADING)
 * Kunci jawaban lama bisa tersimpan sebagai label ("A", "B")
 * atau indeks numerik ("0","1"). Jawaban peserta berupa indeks.
 * Fungsi ini menormalkan keduanya menjadi indeks berbasis 0.
 * ============================================================ */
function normalizeAnswerKeys($raw, int $numOptions = 0): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    if ($raw === null) return [];
    if (!is_array($raw)) $raw = [$raw];
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        // Label huruf A-Z (atau a-z) -> indeks 0-25
        if (preg_match('/^([a-zA-Z])$/', $v, $m)) {
            $idx = ord(strtoupper($m[1])) - 65;
            // Jika tidak masuk akal untuk jumlah opsi, lewati
            if ($numOptions > 0 && $idx >= $numOptions) continue;
            $out[] = (string)$idx;
            continue;
        }
        // Indeks numerik langsung
        if (ctype_digit($v)) { $out[] = (string)(int)$v; continue; }
        // Format tak dikenal — kembalikan apa adanya
        $out[] = $v;
    }
    return array_values(array_unique($out));
}

/** Bandingkan jawaban peserta vs kunci (case/urutan-insensitif, ternormalisasi). */
function answersMatch($pesertaRaw, $kunciRaw, int $numOptions = 0): bool {
    $a = normalizeAnswerKeys($pesertaRaw, $numOptions);
    $b = normalizeAnswerKeys($kunciRaw, $numOptions);
    if (!$b) return false;              // tanpa kunci -> dianggap belum dinilai (salah)
    sort($a); sort($b);
    return $a === $b;
}

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
