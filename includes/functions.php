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

/**
 * Konsolidasi skor SATU attempt: gabungan auto-grading + nilai manual HR.
 * - Skor manual dihitung dari agregat jawaban esai/file_upload yang review_status='reviewed'
 *   (poin didapat / bobot maksimal × 100). Bila psikotes memberi skor manual numerik eksplisit
 *   ($skorManualOverride) dan tidak ada soal esai/praktik, nilai tsb dipakai langsung.
 * - Status attempt → 'reviewed' HANYA bila semua jawaban yang butuh review manual sudah dinilai
 *   (atau memang tidak ada), sehingga badge "Menunggu Review" hilang setelah HR selesai menilai.
 * - final_score = rata-rata score_auto & score_manual (bila keduanya ada).
 */
function recalcAttemptScore(PDO $db, int $attemptId, ?float $skorManualOverride = null): void
{
    $att = $db->prepare('SELECT id, status, score_auto FROM exam_attempts WHERE id=?');
    $att->execute([$attemptId]);
    $a = $att->fetch();
    if (!$a || $a['status'] === 'in_progress' || $a['status'] === 'not_started') return;

    /* ---- Skor manual dari jawaban esai/praktik yang sudah direview ---- */
    $mm = $db->prepare('SELECT SUM(CASE WHEN ans.review_status="reviewed" THEN ans.points_awarded END) got,
                               SUM(CASE WHEN ans.review_status="reviewed" THEN q.bobot END)   maxb,
                               COUNT(*) n_total,
                               SUM(CASE WHEN q.tipe_jawaban IN ("essay","file_upload") AND ans.review_status<>"reviewed" THEN 1 ELSE 0 END) n_pending_manual
                        FROM exam_answers ans JOIN questions q ON q.id=ans.question_id
                        WHERE ans.attempt_id=? AND q.tipe_jawaban IN ("essay","file_upload")');
    $mm->execute([$attemptId]);
    $m = $mm->fetch();

    $manual = null;
    if ((float)$m['maxb'] > 0) {
        $manual = round((float)$m['got'] / (float)$m['maxb'] * 100, 2);
    } elseif ($skorManualOverride !== null) {
        $manual = round($skorManualOverride, 2);
    }
    //gabungkan: jika ada override DAN agregat manual, pakai rata-ratanya
    if ($skorManualOverride !== null && $manual !== null && $manual !== round($skorManualOverride, 2)) {
        $manual = round(($manual + $skorManualOverride) / 2, 2);
    }

    /* ---- Cek sisa pekerjaan review lain (psikotes utk modul personality) ---- */
    $nPendingAny = (int)$db->query('SELECT COUNT(*) FROM exam_answers WHERE attempt_id=' . $attemptId . " AND review_status='pending'")->fetchColumn();
    $isPsycho = (bool)$db->query(
        "SELECT COUNT(*) FROM exam_answers ans JOIN questions q ON q.id=ans.question_id
         JOIN question_categories c ON c.id=q.category_id AND c.test_type='personality'
         WHERE ans.attempt_id=$attemptId")->fetchColumn();
    $psyDone = true;
    if ($isPsycho) {
        $psyDone = (bool)$db->query("SELECT COUNT(*) FROM psycho_evaluations WHERE attempt_id=$attemptId
                                     AND rekomendasi IS NOT NULL AND rekomendasi<>''")->fetchColumn();
    }

    $selesaiReview = ($nPendingAny === 0) && $psyDone;

    /* ---- Final score: rata-rata berbobot auto & manual ---- */
    $auto = $a['score_auto'] !== null ? (float)$a['score_auto'] : null;
    $final = null;
    if ($auto !== null && $manual !== null) $final = round(($auto + $manual) / 2, 2);
    elseif ($auto !== null)                 $final = $auto;
    elseif ($manual !== null)               $final = $manual;

    $newStatus = $selesaiReview ? 'reviewed' : $a['status'];
    $db->prepare('UPDATE exam_attempts SET score_manual=?, final_score=?, status=? WHERE id=?')
       ->execute([$manual, $final, $newStatus, $attemptId]);
}
