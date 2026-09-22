-- =============================================================================
--  Tabela da feature Movimentação (mover estoque entre endereços)
--  Importe no phpMyAdmin, no banco ecoman77_deposito.
--  Substitui a migration 2026_09_22_000001 (que precisaria de terminal).
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `origin_location_id` BIGINT UNSIGNED NOT NULL,
  `destination_location_id` BIGINT UNSIGNED NOT NULL,
  `quantidade` DECIMAL(12,2) NOT NULL,
  `motivo` VARCHAR(191) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `movements_created_at_index` (`created_at`),
  KEY `movements_product_id_foreign` (`product_id`),
  KEY `movements_origin_location_id_foreign` (`origin_location_id`),
  KEY `movements_destination_location_id_foreign` (`destination_location_id`),
  KEY `movements_user_id_foreign` (`user_id`),
  CONSTRAINT `movements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `movements_origin_location_id_foreign` FOREIGN KEY (`origin_location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `movements_destination_location_id_foreign` FOREIGN KEY (`destination_location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_22_000001_create_movements_table', 3) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_22_000001_create_movements_table');

SET FOREIGN_KEY_CHECKS = 1;
