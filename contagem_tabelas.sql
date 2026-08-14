-- =============================================================================
--  Tabelas da feature Contagem / Inventário
--  Importe no phpMyAdmin, no banco ecoman77_deposito.
--  (substitui as migrations 000006 e 000007, que precisariam de terminal)
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_counts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `descricao` VARCHAR(191) NULL DEFAULT NULL,
  `escopo_time` VARCHAR(191) NULL DEFAULT NULL,
  `status` ENUM('aberta','finalizada','cancelada') NOT NULL DEFAULT 'aberta',
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `total_itens` INT UNSIGNED NOT NULL DEFAULT 0,
  `itens_contados` INT UNSIGNED NOT NULL DEFAULT 0,
  `finalizada_em` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_counts_status_index` (`status`),
  KEY `inventory_counts_user_id_foreign` (`user_id`),
  CONSTRAINT `inventory_counts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_count_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_count_id` BIGINT UNSIGNED NOT NULL,
  `location_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `qtd_sistema` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `qtd_contada` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `contada` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `count_item_unico` (`inventory_count_id`,`location_id`,`product_id`),
  KEY `inventory_count_items_location_id_foreign` (`location_id`),
  KEY `inventory_count_items_product_id_foreign` (`product_id`),
  CONSTRAINT `inventory_count_items_inventory_count_id_foreign` FOREIGN KEY (`inventory_count_id`) REFERENCES `inventory_counts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_count_items_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `inventory_count_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra as migrations (evita reexecução se um dia rodar `php artisan migrate`)
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_08_14_000006_create_inventory_counts_table', 2) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_14_000006_create_inventory_counts_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_08_14_000007_create_inventory_count_items_table', 2) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_14_000007_create_inventory_count_items_table');

SET FOREIGN_KEY_CHECKS = 1;
