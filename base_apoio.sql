-- =============================================================================
--  Base de apoio de produtos (planilhas Shopee etc.) + SKU no produto.
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_10_06_000001.
--  Depois: Mapa > Gerenciar endereços > "Base de apoio" > importar a planilha.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `catalogo_produtos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fonte` VARCHAR(30) NOT NULL,
  `chave` VARCHAR(191) NOT NULL,
  `nome` VARCHAR(255) NOT NULL,
  `variacao` VARCHAR(191) NULL DEFAULT NULL,
  `sku` VARCHAR(100) NULL DEFAULT NULL,
  `sku_pai` VARCHAR(100) NULL DEFAULT NULL,
  `ean` VARCHAR(60) NULL DEFAULT NULL,
  `externo_id` VARCHAR(60) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catalogo_produtos_fonte_chave_unique` (`fonte`, `chave`),
  KEY `catalogo_produtos_sku_index` (`sku`),
  KEY `catalogo_produtos_sku_pai_index` (`sku_pai`),
  KEY `catalogo_produtos_ean_index` (`ean`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `products`
  ADD COLUMN `sku` VARCHAR(100) NULL DEFAULT NULL AFTER `codigo_barras`,
  ADD KEY `products_sku_index` (`sku`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_06_000001_catalogo_produtos', 13) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_06_000001_catalogo_produtos'
);
