-- ============================================================
-- SEED DEMO: Contoh soal untuk alur end-to-end E-Assessment
-- Idempotent terhadap slug kategori (menggunakan subquery id).
-- ============================================================
USE assessment_db;

-- ---------- TPA Verbal (single) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot, created_by)
SELECT id, 'single', 'GURU : SEKOLAH = ... : ...', NULL, NULL, 1.00, NULL FROM question_categories WHERE slug='tpa_verbal';
-- catatan: opsi/kunci disimpan JSON di bawah (update setelah insert agar sederhana)
SET @q := LAST_INSERT_ID();
UPDATE questions SET opsi = '["Dokter : Rumah Sakit","Siswa : Kelas","Kepala : Wakil","Belajar : Perpustakaan"]', kunci_jawaban = '["A"]' WHERE id=@q;

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Sinonim dari "EFISIEN" adalah...', '["Boros","Hemat","Lamban","Mahal"]', '["B"]', 1.00 FROM question_categories WHERE slug='tpa_verbal';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Antonim dari "PROGRESIF" adalah...', '["Maju","Stagnan","Modern","Cepat"]', '["B"]', 1.00 FROM question_categories WHERE slug='tpa_verbal';

-- ---------- TPA Numerik (single & multiple) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Deret: 2, 6, 12, 20, 30, ... Angka berikutnya adalah...', '["40","42","44","46"]', '["B"]', 1.00 FROM question_categories WHERE slug='tpa_numerik';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Jika 3x + 7 = 22, maka x = ...', '["3","4","5","6"]', '["C"]', 1.00 FROM question_categories WHERE slug='tpa_numerik';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'multiple', 'Manakah yang merupakan bilangan prima? (pilih semua yang benar)', '["2","9","13","15","23"]', '["A","C","E"]', 2.00 FROM question_categories WHERE slug='tpa_numerik';

-- ---------- TPA Logika (single) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Semua mammals bernapas dengan paru-paru. Paus adalah mammal. Kesimpulan:', '["Paus bernapas dengan insang","Paus bernapas dengan paru-paru","Paus bukan hewan","Tidak dapat disimpulkan"]', '["B"]', 1.00 FROM question_categories WHERE slug='tpa_logika';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Cermin dari huruf "b" adalah...', '["d","p","q","b"]', '["D"]', 1.00 FROM question_categories WHERE slug='tpa_logika';

-- ---------- PAPI Kostick (forced_pair) — pola A/B per pasangan faktor kerja ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'forced_pair', 'Mana yang PALING menggambarkan diri Anda?', '["Saya bekerja terstruktur sesuai SOP dan prosedur baku","Saya berani mengambil keputusan cepat meski informasi belum lengkap"]', NULL, 1.00 FROM question_categories WHERE slug='papi';
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'forced_pair', 'Mana yang PALING menggambarkan diri Anda?', '["Saya senang membimbing dan mengembangkan rekan baru","Saya fokus mengejar target angka dan hasil akhir"]', NULL, 1.00 FROM question_categories WHERE slug='papi';
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'forced_pair', 'Mana yang PALING menggambarkan diri Anda?', '["Saya teliti memeriksa detail sebelum mengirim pekerjaan","Saya nyaman memimpin rapat dan presentasi di depan banyak orang"]', NULL, 1.00 FROM question_categories WHERE slug='papi';
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'forced_pair', 'Mana yang PALING menggambarkan diri Anda?', '["Saya menjaga hubungan harmonis dengan seluruh rekan kerja","Saya siap menerima tugas berat dan deadline ketat"]', NULL, 1.00 FROM question_categories WHERE slug='papi';

-- ---------- DISC (most_least) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'most_least', 'Dari pernyataan berikut, pilih yang PALING SESUAI (Most) dan PALING TIDAK SESUAI (Least) dengan diri Anda.', '["Saya suka memulai hal baru dan menantang status quo","Saya mampu meyakinkan dan menginspirasi orang lain","Saya pendengar yang sabar dan konsisten","Saya berpegang pada data, aturan, dan akurasi"]', NULL, 1.00 FROM question_categories WHERE slug='disc';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'most_least', 'Dari pernyataan berikut, pilih Most dan Least.', '["Saya cepat memutuskan dan tegas","Saya antusias membangun relasi","Saya menghindari konflik dan menjaga kestabilan","Saya teliti dan sistematis dalam dokumentasi"]', NULL, 1.00 FROM question_categories WHERE slug='disc';

-- ---------- Motivasi & Gaya Kerja (most_least) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'most_least', 'Pilih Most & Least mengenai motivasi kerja Anda.', '["Pengakuan atas prestasi saya","Jaminan stabilitas karier","Kebebasan cara bekerja","Kolaborasi tim yang solid"]', NULL, 1.00 FROM question_categories WHERE slug='motivasi';

-- ---------- Excel teori (single) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Fungsi Excel untuk menjumlahkan nilai dengan satu kriteria tertentu adalah...', '["SUMIF","COUNTBLANK","PRODUCT","TEXTJOIN"]', '["A"]', 1.00 FROM question_categories WHERE slug='excel';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Hasil dari formula =VLOOKUP(101;A2:D10;3;FALSE) jika tidak ditemukan nilai 101 adalah...', '["#N/A","#VALUE!","0","#REF!"]', '["A"]', 1.00 FROM question_categories WHERE slug='excel';

-- ---------- Excel praktik (file_upload) ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, contoh_file, rubrik, bobot)
SELECT id, 'file_upload',
'Praktik Excel: Unduh file data penjualan mentah yang diberikan penguji, lalu buat: (1) tabel pivot rekap per wilayah, (2) formula SUMIFS total penjualan bulan berjalan, (3) grafik batang tren 6 bulan. Unggah hasil akhir dalam format .xlsx dengan nama NAMA-Anda_Penjualan.xlsx.',
NULL,
'Rubrik (skala 100):\n- Kerapian & struktur tabel pivot (25)\n- Kebenaran formula SUMIFS/VLOOKUP (35)\n- Grafik sesuai instruksi (20)\n- Formatting & profesionalisme (20)', 5.00 FROM question_categories WHERE slug='excel';

-- ---------- Word teori ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, opsi, kunci_jawaban, bobot)
SELECT id, 'single', 'Fitur Microsoft Word untuk membuat daftar isi otomatis berdasarkan heading adalah...', '["Mail Merge","Table of Contents","Track Changes","Cross-reference"]', '["B"]', 1.00 FROM question_categories WHERE slug='word';

-- ---------- Esai ----------
INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, rubrik, bobot)
SELECT id, 'essay',
'Jelaskan pengalaman Anda menangani masalah mendesak di tempat kerja: situasi, tindakan yang Anda ambil, dan hasilnya. Uraikan maksimal 300 kata.',
'Penilaian: STAR method jelas (40), kualitas solusi & dampak (40), bahasa & sistematika (20)', 4.00 FROM question_categories WHERE slug='esai';

INSERT INTO questions (category_id, tipe_jawaban, pertanyaan, rubrik, bobot)
SELECT id, 'essay',
'Bagaimana pemahaman Anda tentang tata kelola perusahaan (Good Corporate Governance) dan penerapannya di BUMN/BUMD daerah?',
'Penilaian: kedalaman konsep (40), relevansi konteks BUMD (30), argumentasi (30)', 4.00 FROM question_categories WHERE slug='esai';

-- ---------- Modul demo ----------
INSERT INTO test_modules (nama, kategori_slug, durasi_menit, acak_soal, jumlah_soal, deskripsi, status) VALUES
('Tes Potensi Akademik (Demo)', 'tpa_verbal', 30, 1, 8, 'Kombinasi verbal, numerik, logika dari bank soal aktif.', 'aktif'),
('PAPI Kostick & DISC (Demo)', 'papi', 25, 0, NULL, 'Forced pair & most-least. Skor mentah disimpan, interpretasi oleh HR.', 'aktif'),
('Praktik Microsoft Excel (Demo)', 'excel', 45, 0, NULL, 'Teori fungsi + studi kasus upload berkas .xlsx.', 'aktif'),
('Esai Wawasan (Demo)', 'esai', 40, 0, NULL, 'Dua pertanyaan uraian, dinilai manual HR.', 'aktif');

-- Relasi soal -> modul (TPA: semua soal kognitif)
INSERT IGNORE INTO module_questions (module_id, question_id, urutan)
SELECT tm.id, q.id, ROW_NUMBER() OVER (ORDER BY q.id)
FROM test_modules tm JOIN questions q ON q.category_id = (SELECT id FROM question_categories WHERE slug IN ('tpa_verbal')) OR q.category_id IN (SELECT id FROM question_categories WHERE slug IN ('tpa_numerik','tpa_logika'))
WHERE tm.kategori_slug='tpa_verbal' AND q.tipe_jawaban IN ('single','multiple');

-- PAPI/DISC/Motivasi
INSERT IGNORE INTO module_questions (module_id, question_id, urutan)
SELECT tm.id, q.id, ROW_NUMBER() OVER (ORDER BY q.id)
FROM test_modules tm JOIN questions q ON q.category_id IN (SELECT id FROM question_categories WHERE slug IN ('papi','disc','motivasi'))
WHERE tm.kategori_slug='papi' AND q.tipe_jawaban IN ('forced_pair','most_least');

-- Excel
INSERT IGNORE INTO module_questions (module_id, question_id, urutan)
SELECT tm.id, q.id, ROW_NUMBER() OVER (ORDER BY q.id)
FROM test_modules tm JOIN questions q ON q.category_id = (SELECT id FROM question_categories WHERE slug='excel')
WHERE tm.kategori_slug='excel';

-- Esai
INSERT IGNORE INTO module_questions (module_id, question_id, urutan)
SELECT tm.id, q.id, ROW_NUMBER() OVER (ORDER BY q.id)
FROM test_modules tm JOIN questions q ON q.category_id = (SELECT id FROM question_categories WHERE slug='esai')
WHERE tm.kategori_slug='esai';

-- Paket demo (jadwal longgar agar mudah dites)
INSERT INTO test_packages (nama, tujuan, posisi_target, jadwal_mulai, jadwal_selesai, instructions, camera_proctor, status) VALUES
('Paket Rekrutmen Staff Finance (Demo)', 'recruitment', 'Staff Keuangan', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY),
 '1. Pastikan koneksi internet stabil.\n2. Kamera wajib aktif selama ujian.\n3. Dilarang berpindah tab/jendela.\n4. Jawaban tersimpan otomatis.', 1, 'active');

INSERT INTO package_modules (package_id, module_id, urutan)
SELECT tp.id, tm.id, ROW_NUMBER() OVER (ORDER BY tm.id)
FROM test_packages tp JOIN test_modules tm
WHERE tp.nama LIKE '%Demo%' AND tm.nama LIKE '%Demo%';
