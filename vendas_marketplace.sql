-- =============================================================================
--  Vendas Shopee/TikTok: pedidos da planilha "A Enviar" -> separação e embalagem
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_10_28_000001.
--  Depois: em Usuários, marque "Vendas Shopee/TikTok" para quem vai usar a aba
--  (admin já enxerga).
--  Guarda só o necessário para separar e embalar: sem CPF, telefone, endereço.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `vendas_pedidos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `deposito_id` BIGINT UNSIGNED NOT NULL,
  `canal` VARCHAR(20) NOT NULL,
  `pedido` VARCHAR(60) NOT NULL,
  `status` VARCHAR(60) NULL DEFAULT NULL,
  `rastreio` VARCHAR(60) NULL DEFAULT NULL,
  `envio` VARCHAR(100) NULL DEFAULT NULL,
  `cliente` VARCHAR(191) NULL DEFAULT NULL,
  `criado_em` DATETIME NULL DEFAULT NULL,
  `pago_em` DATETIME NULL DEFAULT NULL,
  `withdrawal_request_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `separado_em` DATETIME NULL DEFAULT NULL,
  `separado_por_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `embalado_em` DATETIME NULL DEFAULT NULL,
  `embalado_por_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendas_pedidos_deposito_id_canal_pedido_unique` (`deposito_id`, `canal`, `pedido`),
  KEY `vendas_pedidos_deposito_id_separado_em_index` (`deposito_id`, `separado_em`),
  KEY `vendas_pedidos_deposito_id_embalado_em_index` (`deposito_id`, `embalado_em`),
  CONSTRAINT `vendas_pedidos_deposito_id_foreign` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`),
  CONSTRAINT `vendas_pedidos_withdrawal_request_id_foreign` FOREIGN KEY (`withdrawal_request_id`) REFERENCES `withdrawal_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendas_pedidos_separado_por_id_foreign` FOREIGN KEY (`separado_por_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendas_pedidos_embalado_por_id_foreign` FOREIGN KEY (`embalado_por_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendas_pedido_itens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendas_pedido_id` BIGINT UNSIGNED NOT NULL,
  `sku_principal` VARCHAR(100) NULL DEFAULT NULL,
  `sku` VARCHAR(100) NULL DEFAULT NULL,
  `nome` VARCHAR(255) NOT NULL,
  `variacao` VARCHAR(191) NULL DEFAULT NULL,
  `quantidade` DECIMAL(12,2) NOT NULL,
  `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vendas_pedido_itens_vendas_pedido_id_foreign` (`vendas_pedido_id`),
  KEY `vendas_pedido_itens_product_id_foreign` (`product_id`),
  CONSTRAINT `vendas_pedido_itens_vendas_pedido_id_foreign` FOREIGN KEY (`vendas_pedido_id`) REFERENCES `vendas_pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendas_pedido_itens_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_28_000001_create_vendas_marketplace_tables', 16) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_28_000001_create_vendas_marketplace_tables'
);
