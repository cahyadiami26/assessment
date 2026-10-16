-- Demo tabel users SSO (database: dms_db) — hanya untuk pengembangan lokal.
CREATE DATABASE IF NOT EXISTS dms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE dms_db;

CREATE TABLE IF NOT EXISTS users (
  id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nik varchar(50) DEFAULT NULL,
  name varchar(100) NOT NULL,
  email varchar(100) DEFAULT NULL,
  position enum('Direktur Utama','Direktur Operasional','Kepala Bidang','Kepala Divisi','Manager','Sekretaris','Staff','System Administrator','Corporate Secretary','Sekretaris Direksi','Sekretaris Komisaris') DEFAULT NULL,
  division enum('Keuangan','Pengadaan','Keamanan','Infokom','SDM','Direksi','IT','Legal','Kesekretariatan','Jasa Konstruksi','Pengangkutan & Pergudangan','Perdagangan','Energi & Migas') DEFAULT NULL,
  department enum('Direksi','Keuangan & Umum','Bisnis','Sekretariat','Pengawas Internal') DEFAULT NULL,
  username varchar(50) NOT NULL UNIQUE,
  password varchar(255) NOT NULL,
  role enum('admin','user','op_arsip') DEFAULT 'user',
  avatar varchar(255) DEFAULT NULL,
  photo varchar(255) DEFAULT NULL,
  signature varchar(255) DEFAULT NULL,
  rfid varchar(50) DEFAULT NULL,
  is_active tinyint(1) DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  webmail_user varchar(200) NOT NULL DEFAULT '',
  webmail_pass varchar(16) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

INSERT IGNORE INTO users (nik,name,email,position,division,department,username,password,role,avatar) VALUES
('0001','Admin HRD LJU','hrd@lju.co.id','Manager','SDM','Keuangan & Umum','hrd_admin','$2y$10$demoDemoDemoDemoDemoDu','admin',NULL),
('0002','Budi Santoso','budi@lju.co.id','Staff','Infokom','Bisnis','budi','$2y$10$demoDemoDemoDemoDemoDu','user','budi.png'),
('0003','Siti Aminah','siti@lju.co.id','Kepala Divisi','Keuangan','Keuangan & Umum','siti','$2y$10$demoDemoDemoDemoDemoDu','user',NULL),
('0004','Rizal Direksi','rizal@lju.co.id','Direktur Operasional','Direksi','Direksi','direktur','$2y$10$demoDemoDemoDemoDemoDu','user',NULL);
