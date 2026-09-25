-- =============================================================================
--  Vários CDs no mesmo sistema (Goiânia + São Paulo), com dados separados.
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_09_26_000001.
--
--  Tudo o que já existe (endereços, estoque, movimentações, contagens,
--  solicitações) fica no CD 1 = Goiânia. O CD 2 = São Paulo começa vazio.
--  Usuários que já existem ficam liberados só em Goiânia (admin vê todos).
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `depositos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `depositos_nome_unique` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `depositos` (`id`, `nome`, `ativo`, `created_at`, `updated_at`) VALUES
  (1, 'Goiânia', 1, NOW(), NOW()),
  (2, 'São Paulo', 1, NOW(), NOW());

-- Endereços: nome único dentro de cada CD (SP pode ter "SANTOS 1A" também)
ALTER TABLE `locations`
  ADD COLUMN `deposito_id` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  DROP INDEX `locations_nome_unique`,
  ADD UNIQUE KEY `locations_deposito_id_nome_unique` (`deposito_id`, `nome`),
  ADD CONSTRAINT `locations_deposito_id_foreign` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`);

ALTER TABLE `withdrawal_requests`
  ADD COLUMN `deposito_id` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD CONSTRAINT `withdrawal_requests_deposito_id_foreign` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`);

ALTER TABLE `inventory_counts`
  ADD COLUMN `deposito_id` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD CONSTRAINT `inventory_counts_deposito_id_foreign` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`);

ALTER TABLE `movements`
  ADD COLUMN `deposito_id` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD CONSTRAINT `movements_deposito_id_foreign` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`);

-- CDs liberados por usuário
ALTER TABLE `users`
  ADD COLUMN `depositos` JSON NULL AFTER `permissions`;

UPDATE `users` SET `depositos` = JSON_ARRAY(1);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_26_000001_multi_deposito', 8) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_26_000001_multi_deposito'
);
