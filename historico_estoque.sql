-- =============================================================================
--  Histórico de estoque: toda alteração de saldo fica registrada
--  (separação, estorno, movimentação, contagem, ajuste, zerar, importação...).
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Precisa do multi_cd.sql já importado. Substitui a migration 2026_09_29_000001.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `stock_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `deposito_id` BIGINT UNSIGNED NOT NULL,
  `location_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `tipo` VARCHAR(30) NOT NULL,
  `referencia` VARCHAR(120) NULL DEFAULT NULL,
  `observacao` VARCHAR(255) NULL DEFAULT NULL,
  `quantidade_anterior` DECIMAL(12,2) NOT NULL,
  `quantidade_nova` DECIMAL(12,2) NOT NULL,
  `diferenca` DECIMAL(12,2) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_logs_deposito_id_created_at_index` (`deposito_id`, `created_at`),
  KEY `stock_logs_location_id_created_at_index` (`location_id`, `created_at`),
  KEY `stock_logs_product_id_created_at_index` (`product_id`, `created_at`),
  KEY `stock_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `stock_logs_deposito_id_foreign` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`),
  CONSTRAINT `stock_logs_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `stock_logs_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `stock_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_29_000001_create_stock_logs_table', 11) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_29_000001_create_stock_logs_table'
);
