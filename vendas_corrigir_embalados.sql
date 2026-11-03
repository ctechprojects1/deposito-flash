-- =============================================================================
--  Vendas: destrava pedidos marcados como "embalados" ANTES de serem separados.
--  Eles sumiam da lista de embalagem ("0 pacotes · N já embalados").
--  Volta para "a embalar" todo pedido marcado embalado antes da separação
--  (ou que ainda nem foi separado). Os embalados de verdade (depois de
--  separados) não mudam.
--  Importe no phpMyAdmin, no banco ecoman77_deposito (clique nele na coluna
--  da esquerda ANTES de Importar). Pode rodar de novo.
-- =============================================================================

-- Conferência ANTES: quantos vão voltar para a lista, por CD e canal
SELECT `deposito_id`, `canal`, COUNT(*) AS `voltam_para_embalagem`
FROM `vendas_pedidos`
WHERE `embalado_em` IS NOT NULL
  AND (`separado_em` IS NULL OR `embalado_em` < `separado_em`)
GROUP BY `deposito_id`, `canal`;

UPDATE `vendas_pedidos`
SET `embalado_em` = NULL, `embalado_por_id` = NULL
WHERE `embalado_em` IS NOT NULL
  AND (`separado_em` IS NULL OR `embalado_em` < `separado_em`);
