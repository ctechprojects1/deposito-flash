-- =============================================================================
--  CDs no mesmo lugar físico: Goiânia e CD - Commerce.
--  Na separação de um deles (ex.: Vendas Shopee no Commerce), o separador pode
--  escolher tirar o item de um endereço do outro; a baixa sai de lá.
--  Para ligar outros CDs no futuro, dê a eles o mesmo `local`.
--  Importe no phpMyAdmin, no banco ecoman77_deposito (clique nele na coluna
--  da esquerda ANTES de Importar). RODAR UMA VEZ.
--  Substitui a migration 2026_10_30_000001.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `depositos`
  ADD COLUMN `local` VARCHAR(60) NULL DEFAULT NULL AFTER `nome`;

UPDATE `depositos` SET `local` = 'Goiânia' WHERE `nome` IN ('Goiânia', 'CD - Commerce');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_10_30_000001_depositos_mesmo_local', 18) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_30_000001_depositos_mesmo_local'
);

-- Conferência: os dois devem aparecer com local = Goiânia
SELECT `id`, `nome`, `local` FROM `depositos` ORDER BY `id`;
