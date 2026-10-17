-- ============================================================
-- E-ASSESSMENT PT LAMPUNG JASA UTAMA
-- Database: assessment_db
-- ============================================================
CREATE DATABASE IF NOT EXISTS assessment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE assessment_db;

-- ------------------------------------------------------------
-- 1. KATEGORI BANK SOAL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS question_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,          -- tpa, papi, disc, office_practice, essay, ...
  test_type ENUM('cognitive','personality','software','essay') NOT NULL DEFAULT 'cognitive',
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. BANK SOAL
-- tipe_jawaban:
--   single      = pilihan ganda (1 kunci)
--   multiple    = multiple select (beberapa kunci)
--   forced_pair = 2 pilihan (PAPI style)
--   most_least  = multi-option forced choice (Most/Least)
--   file_upload = praktik upload file
--   essay       = uraian/esai rich text
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  tipe_jawaban ENUM('single','multiple','forced_pair','most_least','file_upload','essay') NOT NULL DEFAULT 'single',
  pertanyaan TEXT NOT NULL,                    -- stem / instruksi soal
  gambar MEDIUMTEXT NULL,                      -- data URI base64 / URL gambar (opsional)
  opsi JSON NULL,                              -- ["Opsi A","Opsi B",...] utk most_least/forced_pair
  kunci_jawaban JSON NULL,                     -- ["A"] / ["A","C"] / indeks opsi
  bobot DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  contoh_file VARCHAR(255) NULL,               -- file referensi hasil akhir (praktik)
  rubrik TEXT NULL,                            -- panduan penilaian manual (esai/praktik)
  status ENUM('aktif','arsip') NOT NULL DEFAULT 'aktif',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES question_categories(id) ON DELETE RESTRICT,
  INDEX idx_tipe (tipe_jawaban),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. MODUL TES (Test Builder)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS test_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,                  -- ex: Tes Potensi Akademik
  kategori_slug VARCHAR(100) NULL,             -- kelompok utama soal
  durasi_menit INT NOT NULL DEFAULT 60,
  acak_soal TINYINT(1) NOT NULL DEFAULT 0,
  jumlah_soal INT NULL,                        -- null = semua soal terpilih
  deskripsi TEXT NULL,
  status ENUM('draft','aktif','arsip') NOT NULL DEFAULT 'aktif',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_id INT NOT NULL,
  question_id INT NOT NULL,
  urutan INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_mod_q (module_id, question_id),
  FOREIGN KEY (module_id) REFERENCES test_modules(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. PAKET TES & JADWAL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS test_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,                  -- ex: Paket Rekrutmen Staff Finance
  tujuan ENUM('recruitment','performance') NOT NULL DEFAULT 'recruitment',
  posisi_target VARCHAR(150) NULL,
  jadwal_mulai DATETIME NOT NULL,
  jadwal_selesai DATETIME NOT NULL,
  instructions TEXT NULL,                      -- aturan ujian yg tampil di lobby
  camera_proctor TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('draft','scheduled','active','closed','archived') NOT NULL DEFAULT 'draft',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS package_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  module_id INT NOT NULL,
  urutan INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_pkg_mod (package_id, module_id),
  FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES test_modules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. PESERTA PAKET (karyawan via SSO / calon via token)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS package_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  participant_type ENUM('employee','candidate') NOT NULL DEFAULT 'employee',
  user_id INT NULL,                            -- link ke users.id (SSO) utk karyawan
  candidate_name VARCHAR(150) NULL,
  candidate_email VARCHAR(150) NULL,
  access_token VARCHAR(64) NULL UNIQUE,        -- token utk calon karyawan
  invitation_sent TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('invited','started','in_progress','finished','expired') NOT NULL DEFAULT 'invited',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
  INDEX idx_token (access_token),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. SESI UJIAN (attempt) per peserta per modul
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exam_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  participant_id INT NOT NULL,                 -- package_participants.id
  package_id INT NOT NULL,
  module_id INT NOT NULL,
  started_at DATETIME NULL,
  expires_at DATETIME NULL,                    -- batas waktu (timer)
  submitted_at DATETIME NULL,
  auto_submitted TINYINT(1) NOT NULL DEFAULT 0,
  score_auto DECIMAL(6,2) NULL,                -- nilai auto-grading (skala 100)
  score_manual DECIMAL(6,2) NULL,              -- nilai review HR (skala 100)
  final_score DECIMAL(6,2) NULL,
  status ENUM('not_started','in_progress','submitted','reviewed') NOT NULL DEFAULT 'not_started',
  tab_switches INT NOT NULL DEFAULT 0,         -- anti-cheat counter
  webcam_snapshot VARCHAR(255) NULL,
  FOREIGN KEY (participant_id) REFERENCES package_participants(id) ON DELETE CASCADE,
  FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES test_modules(id) ON DELETE CASCADE,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7. JAWABAN PESERTA
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exam_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  answer_text MEDIUMTEXT NULL,                 -- esai (rich text html) / pilihan teks
  answer_options JSON NULL,                    -- ["A","C"] / {"most":2,"least":0}
  uploaded_file VARCHAR(255) NULL,             -- path file praktik
  is_correct TINYINT(1) NULL,                  -- utk auto-graded
  points_awarded DECIMAL(6,2) NULL,
  reviewed_by INT NULL,
  reviewer_notes TEXT NULL,
  review_status ENUM('auto','pending','reviewed') NOT NULL DEFAULT 'auto',
  answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_attempt_q (attempt_id, question_id),
  INDEX idx_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 8. HASIL EVALUASI PSIKOTES (manual oleh HR)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS psycho_evaluations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  rekomendasi ENUM('recommended','consider','not_recommended') NULL,
  kelebihan TEXT NULL,
  area_pengembangan TEXT NULL,
  catatan_hr TEXT NULL,
  skor_manual DECIMAL(6,2) NULL,               -- skor numerik opsional dari form psikotes (0-100)
  lampiran_pdf VARCHAR(255) NULL,              -- hasil analisis psikotes dari HR
  evaluated_by INT NULL,
  evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_attempt_psycho (attempt_id),
  FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 9. LOG AKTIVITAS (audit / proctoring)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NULL,
  user_ref VARCHAR(100) NULL,                  -- username / email/token
  action VARCHAR(60) NOT NULL,                 -- login, start_exam, tab_switch, autosave, submit ...
  detail TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED: Kategori Bank Soal
-- ============================================================
INSERT INTO question_categories (name, slug, test_type, description) VALUES
('Tes Potensi Akademik - Verbal',        'tpa_verbal',    'cognitive',   'Analogi, silogisme, pemahaman bacaan.'),
('Tes Potensi Akademik - Numerik',       'tpa_numerik',   'cognitive',   'Deret angka, aritmatika, logika numerik.'),
('Tes Potensi Akademik - Logika/Spasial','tpa_logika',    'cognitive',   'Penalaran logis dan spasial.'),
('PAPI Kostick',                         'papi',          'personality', 'Forced pair 2 pilihan, evaluasi pola kerja.'),
('DISC',                                 'disc',          'personality', 'Dominance, Influence, Steadiness, Compliance.'),
('Motivasi & Gaya Kerja',                'motivasi',      'personality', 'Multi-option forced choice Most/Least.'),
('Microsoft Excel',                      'excel',         'software',    'Teori rumus/fungsi & praktik upload berkas.'),
('Microsoft Word',                       'word',          'software',    'Teori fitur & praktik dokumen.'),
('PowerPoint',                           'powerpoint',    'software',    'Teori presentasi & praktik slide.'),
('Esai / Uraian',                        'esai',         'essay',       'Jawaban naratif yang dinilai manual oleh HR.')
ON DUPLICATE KEY UPDATE name = VALUES(name);
