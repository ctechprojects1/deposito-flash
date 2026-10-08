-- =============================================================================
--  Excluir separação com justificativa (fica registrada em "Excluídas").
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_10_08_000001.
--  Depois: Usuários > marcar "Excluir separações" para quem pode excluir.
-- =============================================================================

ALTER TABLE `withdrawal_requests`
  ADD COLUMN `excluida_em` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `excluida_por_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN `motivo_exclusao` VARCHAR(255) NULL DEFAULT NULL,
  ADD CONSTRAINT `withdrawal_requests_excluida_por_id_foreign`
    FOREIGN KEY (`excluida_por_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_08_000001_withdrawal_requests_exclusao', 14) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_08_000001_withdrawal_requests_exclusao'
);
