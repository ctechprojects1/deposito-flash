-- =============================================================================
--  Feature B — Autenticação e Permissões
--  Importe no phpMyAdmin, no banco ecoman77_deposito. RODAR UMA VEZ.
--  Substitui a migration 2026_09_22_000002 (que precisaria de terminal).
-- =============================================================================

SET NAMES utf8mb4;

-- Colunas novas em users: permissões (JSON) e token de API.
ALTER TABLE `users`
  ADD COLUMN `permissions` JSON NULL AFTER `role`,
  ADD COLUMN `api_token` VARCHAR(64) NULL AFTER `remember_token`,
  ADD UNIQUE KEY `users_api_token_unique` (`api_token`);

-- Permissões padrão dos usuários já existentes (o admin, role=admin, já tem acesso total).
UPDATE `users` SET `permissions` = JSON_ARRAY('ver_mapa','solicitar')
  WHERE `email` = 'solicitante@sistema.local';
UPDATE `users` SET `permissions` = JSON_ARRAY('ver_mapa','separar')
  WHERE `email` = 'separador@sistema.local';

-- Registra a migration (evita reexecução por `php artisan migrate`).
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_22_000002_add_auth_to_users_table', 4) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_22_000002_add_auth_to_users_table'
);
