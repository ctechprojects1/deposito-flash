-- =============================================================================
--  SKUs de anúncio (Shopee/TikTok) de cada produto.
--  Ao ligar os produtos da base Shopee ao Microvix (Gerenciar endereços ->
--  "Microvix como base única"), o SKU antigo fica aqui e as vendas continuam
--  achando o produto sem a planilha da Shopee.
--  Importe no phpMyAdmin, no banco ecoman77_deposito (clique nele na coluna
--  da esquerda ANTES de Importar). RODAR UMA VEZ.
--  Substitui a migration 2026_10_29_000002.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `produto_skus` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(100) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `produto_skus_sku_unique` (`sku`),
  KEY `produto_skus_product_id_foreign` (`product_id`),
  CONSTRAINT `produto_skus_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_29_000002_create_produto_skus_table', 17) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000002_create_produto_skus_table'
);
