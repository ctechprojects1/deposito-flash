-- =============================================================================
--  Ordem das posições no mapa, por CD.
--  Goiânia: 1A, 1B, 2A, 2B...   São Paulo: A1, A2, A3, B1, B2... (alfabética)
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Precisa do multi_cd.sql já importado. Substitui a migration 2026_09_28_000002.
-- =============================================================================

ALTER TABLE `depositos`
  ADD COLUMN `ordem_posicoes` VARCHAR(20) NOT NULL DEFAULT 'nivel' AFTER `nome`;

UPDATE `depositos` SET `ordem_posicoes` = 'alfabetica' WHERE `id` = 2;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_28_000002_deposito_ordem_posicoes', 10) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_28_000002_deposito_ordem_posicoes'
);
