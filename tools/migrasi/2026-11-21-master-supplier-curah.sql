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
