-- =============================================================================
--  Base local de códigos de barras do Microvix (cod_barra -> cod_produto)
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_09_24_000001.
--  Depois do deploy: Gerenciar endereços -> "Sincronizar agora" (≈1 min).
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `microvix_barras` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod_barra` VARCHAR(60) NOT NULL,
  `cod_produto` VARCHAR(30) NOT NULL,
  `ts` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `microvix_barras_cod_barra_unique` (`cod_barra`),
  KEY `microvix_barras_cod_produto_index` (`cod_produto`),
  KEY `microvix_barras_ts_index` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_24_000001_create_microvix_barras_table', 6) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_24_000001_create_microvix_barras_table'
);
