-- Master Data Supplier & Curah (portal Kaizen)
-- Jalankan SEKALI di phpMyAdmin (login root) pada database kaizen_hse_portal.
-- User aplikasi kaizen_app tidak punya hak CREATE, jadi migrasi ini butuh akses root.

CREATE TABLE IF NOT EXISTS `master_suppliers` (
  `id` varchar(64) NOT NULL,
  `sup_id` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `contact` varchar(128) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sup_id` (`sup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `master_curahs` (
  `id` varchar(64) NOT NULL,
  `curah_id` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(32) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_curah_id` (`curah_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed awal Master Curah (daftar produk TMO). Aman dijalankan ulang: INSERT IGNORE.
INSERT IGNORE INTO `master_curahs` (`id`, `curah_id`, `name`, `unit`, `description`) VALUES
('MCR-SEED-001', 'CRH-001', 'TMO 0W-16 SN/GF-6 L', 'LITER', NULL),
('MCR-SEED-002', 'CRH-002', 'TMO 10W-40 API SN NEW FORMULA (CURAH)', 'LITER', NULL),
('MCR-SEED-003', 'CRH-003', 'TMO DIFFERENTIAL GEAR OIL 90 GL5', 'LITER', NULL),
('MCR-SEED-004', 'CRH-004', 'TMO LUBRICANT 15W-40 API CI-4 NEW FORMULA CURAH', 'LITER', NULL),
('MCR-SEED-005', 'CRH-005', 'TMO LUBRICANT 5W-30 API SN GF-5 NEW FORMULA', 'LITER', NULL),
('MCR-SEED-006', 'CRH-006', 'TMO LUBRICANT 5W-30 ACEA A5/B5', 'LITER', NULL),
('MCR-SEED-007', 'CRH-007', 'TMO LUBRICANT SAE 10W-30 API SN', 'LITER', NULL),
('MCR-SEED-008', 'CRH-008', 'TMO MANUAL TRANSMISSION FLUID 75W-90', 'LITER', NULL),
('MCR-SEED-009', 'CRH-009', 'TMO MANUAL TRANSMISSION FLUID 80W-90 GL4', 'LITER', NULL),
('MCR-SEED-010', 'CRH-010', 'TMO MTF 80W GL-4', 'LITER', NULL);
