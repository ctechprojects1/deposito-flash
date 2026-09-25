-- =============================================================================
--  Solicitação a partir de nota/pedido + separação por checklist
--  (iniciar, pausar, retomar, finalizar; admin reabre).
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_09_25_000001.
-- =============================================================================

SET NAMES utf8mb4;

-- status deixa de ser ENUM para aceitar "pausada"
ALTER TABLE `withdrawal_requests`
  MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'pendente',
  ADD COLUMN `tipo_documento` VARCHAR(30) NULL DEFAULT NULL AFTER `destino`,
  ADD COLUMN `numero_documento` VARCHAR(100) NULL DEFAULT NULL AFTER `tipo_documento`,
  ADD COLUMN `iniciada_em` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `finalizada_em` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `reaberta_em` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `reaberta_por_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD CONSTRAINT `withdrawal_requests_reaberta_por_id_foreign`
    FOREIGN KEY (`reaberta_por_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `withdrawal_items`
  ADD COLUMN `codigo_microvix` VARCHAR(60) NULL DEFAULT NULL AFTER `product_id`,
  ADD COLUMN `descricao` VARCHAR(255) NULL DEFAULT NULL AFTER `codigo_microvix`,
  ADD COLUMN `quantidade_documento` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `descricao`,
  ADD COLUMN `retirado` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `retirado_em` TIMESTAMP NULL DEFAULT NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_25_000001_separacao_checklist', 7) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_25_000001_separacao_checklist'
);
