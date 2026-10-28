-- =============================================================================
--  Exclui os CDs "Depósito Mangalo" e "Depósito Gyn Viva" e cria o
--  "Depósito Comando" (começa sem endereços: crie os Times e posições em
--  "Gerenciar endereços" com o CD selecionado no topo).
--  Importe no phpMyAdmin, no banco ecoman77_deposito. Pode rodar de novo.
--
--  Segurança: um CD só é excluído se estiver VAZIO de movimento — nenhum item
--  com saldo, nenhuma separação, contagem, movimentação, histórico ou venda.
--  Endereços vazios dele são apagados junto. Se algum dos dois tiver
--  movimento, ele fica como está (veja a conferência no fim) e nada se perde.
--  Os CDs excluídos também saem da lista de acesso dos usuários.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

SET @mangalo = (SELECT `id` FROM `depositos` WHERE `nome` = 'Depósito Mangalo');
SET @gynviva = (SELECT `id` FROM `depositos` WHERE `nome` = 'Depósito Gyn Viva');

-- Pode excluir? (1 = sim, 0 = tem movimento, NULL = CD não existe)
SET @ok_mangalo = (
  SELECT NOT EXISTS (SELECT 1 FROM `stocks` s JOIN `locations` l ON l.`id` = s.`location_id` WHERE l.`deposito_id` = d.`id` AND s.`quantidade` <> 0)
     AND NOT EXISTS (SELECT 1 FROM `withdrawal_requests` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `inventory_counts` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `movements` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `stock_logs` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `vendas_pedidos` WHERE `deposito_id` = d.`id`)
  FROM `depositos` d WHERE d.`id` = @mangalo
);
SET @ok_gynviva = (
  SELECT NOT EXISTS (SELECT 1 FROM `stocks` s JOIN `locations` l ON l.`id` = s.`location_id` WHERE l.`deposito_id` = d.`id` AND s.`quantidade` <> 0)
     AND NOT EXISTS (SELECT 1 FROM `withdrawal_requests` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `inventory_counts` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `movements` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `stock_logs` WHERE `deposito_id` = d.`id`)
     AND NOT EXISTS (SELECT 1 FROM `vendas_pedidos` WHERE `deposito_id` = d.`id`)
  FROM `depositos` d WHERE d.`id` = @gynviva
);

-- Ids que de fato serão excluídos (0 = nenhum)
SET @del1 = IF(@ok_mangalo = 1, @mangalo, 0);
SET @del2 = IF(@ok_gynviva = 1, @gynviva, 0);

-- Registros de estoque zerados e endereços vazios dos CDs excluídos
DELETE s FROM `stocks` s
JOIN `locations` l ON l.`id` = s.`location_id`
WHERE l.`deposito_id` IN (@del1, @del2);

DELETE FROM `locations` WHERE `deposito_id` IN (@del1, @del2);

DELETE FROM `depositos` WHERE `id` IN (@del1, @del2);

-- Tira os CDs excluídos do acesso dos usuários (ex.: [1,5,6] -> [1])
UPDATE `users`
SET `depositos` = CONCAT('[', TRIM(BOTH ',' FROM
      REPLACE(REPLACE(
        CONCAT(',', REPLACE(REPLACE(REPLACE(`depositos`, ' ', ''), '[', ''), ']', ''), ','),
        CONCAT(',', @del1, ','), ','),
        CONCAT(',', @del2, ','), ',')
    ), ']')
WHERE `depositos` IS NOT NULL;

-- Novo CD
INSERT INTO `depositos` (`nome`, `ordem_posicoes`, `ativo`, `created_at`, `updated_at`)
SELECT 'Depósito Comando', 'nivel', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `depositos` WHERE `nome` = 'Depósito Comando');

COMMIT;

-- Conferência: deve aparecer SÓ o "Depósito Comando".
-- Se Mangalo ou Gyn Viva ainda aparecerem, é porque têm movimento: me avise.
SELECT d.`id`, d.`nome`,
       (SELECT COUNT(*) FROM `locations` l WHERE l.`deposito_id` = d.`id` AND l.`deleted_at` IS NULL) AS `enderecos`,
       (SELECT COALESCE(SUM(s.`quantidade`), 0) FROM `stocks` s JOIN `locations` l ON l.`id` = s.`location_id` WHERE l.`deposito_id` = d.`id`) AS `unidades_em_estoque`,
       (SELECT COUNT(*) FROM `withdrawal_requests` w WHERE w.`deposito_id` = d.`id`) AS `separacoes`,
       (SELECT COUNT(*) FROM `stock_logs` h WHERE h.`deposito_id` = d.`id`) AS `historico`
FROM `depositos` d
WHERE d.`nome` IN ('Depósito Mangalo', 'Depósito Gyn Viva', 'Depósito Comando')
ORDER BY d.`id`;
