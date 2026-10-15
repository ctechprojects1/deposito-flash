-- =============================================================================
--  Base local de produtos do Microvix (cod_produto -> nome + referência)
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_10_15_000001.
--  Depois do deploy: Gerenciar endereços -> "Sincronizar agora" (≈2 min).
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `microvix_produtos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod_produto` VARCHAR(30) NOT NULL,
  `nome` VARCHAR(255) NOT NULL,
  `referencia` VARCHAR(120) NULL DEFAULT NULL,
  `desativado` TINYINT(1) NOT NULL DEFAULT 0,
  `ts` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `microvix_produtos_cod_produto_unique` (`cod_produto`),
  KEY `microvix_produtos_referencia_index` (`referencia`),
  KEY `microvix_produtos_ts_index` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_15_000001_create_microvix_produtos_table', 15) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_15_000001_create_microvix_produtos_table'
);
