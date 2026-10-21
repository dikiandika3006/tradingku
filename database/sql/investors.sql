-- =====================================================================
-- Master investor & riwayat setoran (Pelagic Capital)
--
-- Untuk di-import ke database produksi (MySQL), mis. lewat phpMyAdmin.
-- Aman dijalankan lebih dari sekali: tabel dibuat hanya jika belum ada,
-- baris yang id-nya sudah ada dilewati (INSERT IGNORE), dan migrasi
-- 2026_10_21_000000_create_investors_tables dicatat sebagai sudah jalan
-- supaya tombol "Run Migrations" tidak mengulanginya.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `investors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `join_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `investors_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `investor_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `investor_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `investor_transactions_investor_id_date_index` (`investor_id`,`date`),
  CONSTRAINT `investor_transactions_investor_id_foreign` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data
INSERT  IGNORE INTO `investors` (`id`, `code`, `name`, `join_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES (1,'INV-001','Hasbiar','2026-06-19','aktif',NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investors` (`id`, `code`, `name`, `join_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES (2,'INV-002','Diki','2026-06-19','aktif',NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investors` (`id`, `code`, `name`, `join_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES (3,'INV-003','Fiqih','2026-06-19','aktif',NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investors` (`id`, `code`, `name`, `join_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES (4,'INV-004','Fins Mochi','2026-06-19','aktif',NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (1,3,'2026-06-18','initial_capital',3000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (2,4,'2026-06-19','initial_capital',3000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (3,2,'2026-06-19','initial_capital',3000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (4,1,'2026-06-19','initial_capital',2161000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (5,1,'2026-06-20','initial_capital',839000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (6,3,'2026-07-10','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (7,4,'2026-07-10','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (8,1,'2026-07-10','top_up',1000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (9,2,'2026-07-10','top_up',1000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (10,1,'2026-07-28','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (11,3,'2026-08-01','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (12,4,'2026-08-01','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (13,1,'2026-08-20','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (14,2,'2026-08-20','top_up',1000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (15,1,'2026-09-01','top_up',1000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (16,4,'2026-09-01','top_up',1500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (17,3,'2026-09-10','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (18,2,'2026-09-21','top_up',1000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (19,4,'2026-10-20','top_up',1000000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (20,1,'2026-10-20','top_up',1500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (21,2,'2026-10-20','top_up',1500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');
INSERT  IGNORE INTO `investor_transactions` (`id`, `investor_id`, `date`, `type`, `amount`, `notes`, `created_at`, `updated_at`) VALUES (22,3,'2026-10-20','top_up',500000.00,NULL,'2026-10-21 10:55:44','2026-10-21 10:55:44');

-- Tandai migrasi sudah jalan (hanya jika belum tercatat)
INSERT INTO `migrations` (`migration`, `batch`)
SELECT x.migration, x.batch
FROM (
    SELECT '2026_10_21_000000_create_investors_tables' AS migration,
           (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`) AS batch
) x
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = x.migration
);

SET FOREIGN_KEY_CHECKS = 1;
