-- =============================================================================
--  Código de barras nos produtos + Microvix opcional
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_09_23_000001.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `products`
  ADD COLUMN `codigo_barras` VARCHAR(60) NULL AFTER `codigo_microvix`,
  MODIFY COLUMN `codigo_microvix` VARCHAR(60) NULL,
  ADD UNIQUE KEY `products_codigo_barras_unique` (`codigo_barras`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_23_000001_add_barcode_to_products', 5) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000001_add_barcode_to_products'
);
