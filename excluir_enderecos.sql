-- =============================================================================
--  Exclusão de endereços (o histórico continua mostrando o nome).
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Precisa do multi_cd.sql já importado. Substitui a migration 2026_09_28_000001.
-- =============================================================================

ALTER TABLE `locations`
  ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_28_000001_locations_soft_delete', 9) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_28_000001_locations_soft_delete'
);
