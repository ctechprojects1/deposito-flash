-- =============================================================================
--  Base local de preços do Microvix (cod_produto -> preço de venda e de custo)
--  Usada por Relatórios -> "Exportar mapa".
--  Importe no phpMyAdmin, no banco ecoman77_deposito (clique nele na coluna
--  da esquerda ANTES de Importar). RODAR UMA VEZ.
--  Substitui a migration 2026_10_29_000001.
--  A primeira exportação do mapa carrega os preços (≈1 min); depois é rápido.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `microvix_precos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod_produto` VARCHAR(30) NOT NULL,
  `preco_venda` DECIMAL(12,2) NULL DEFAULT NULL,
  `preco_custo` DECIMAL(12,2) NULL DEFAULT NULL,
  `ts` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `microvix_precos_cod_produto_unique` (`cod_produto`),
  KEY `microvix_precos_ts_index` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_29_000001_create_microvix_precos_table', 17) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000001_create_microvix_precos_table'
);
