-- =============================================================================
--  Novos CDs: "Depósito Mangalo" e "Depósito Gyn Viva"
--  Começam sem endereços: crie os Times e posições em "Gerenciar endereços"
--  (com o CD selecionado no topo). Admin já enxerga os dois; para os demais
--  usuários, libere o CD na tela de Usuários.
--  Importe no phpMyAdmin, no banco ecoman77_deposito.
--  Pode rodar de novo sem duplicar.
--  Precisa do multi_cd.sql e do ordem_posicoes_sp.sql já importados.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO `depositos` (`nome`, `ordem_posicoes`, `ativo`, `created_at`, `updated_at`)
SELECT 'Depósito Mangalo', 'nivel', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `depositos` WHERE `nome` = 'Depósito Mangalo');

INSERT INTO `depositos` (`nome`, `ordem_posicoes`, `ativo`, `created_at`, `updated_at`)
SELECT 'Depósito Gyn Viva', 'nivel', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `depositos` WHERE `nome` = 'Depósito Gyn Viva');

-- Conferência: deve listar os dois CDs novos
SELECT `id`, `nome`, `ordem_posicoes`, `ativo`
FROM `depositos`
WHERE `nome` IN ('Depósito Mangalo', 'Depósito Gyn Viva')
ORDER BY `id`;
