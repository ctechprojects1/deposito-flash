-- =============================================================================
--  Separação: item "não separado" com motivo (sem estoque, não encontrado,
--  pedido errado...). Não dá baixa e não impede finalizar.
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_09_30_000001.
-- =============================================================================

ALTER TABLE `withdrawal_items`
  ADD COLUMN `nao_separado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `retirado_em`,
  ADD COLUMN `motivo_nao_separado` VARCHAR(255) NULL DEFAULT NULL AFTER `nao_separado`;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_30_000001_withdrawal_items_nao_separado', 12) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_30_000001_withdrawal_items_nao_separado'
);
