/**
 * Lê a planilha de pedidos da Shopee (Meus Pedidos -> A Enviar -> Exportar,
 * arquivo "Order.toship...xlsx") no navegador e devolve os pedidos com só o
 * que a separação e a embalagem precisam — CPF, telefone e endereço do
 * comprador não saem do computador.
 * A biblioteca de planilhas só é baixada quando alguém importa.
 */

// Colunas da exportação de pedidos da Shopee (mesmos nomes do app de Contagem).
const SHOPEE = {
  pedido: "ID do pedido",
  status: "Status do pedido",
  rastreio: "Número de rastreamento",
  envio: "Opção de envio",
  criado_em: "Data de criação do pedido",
  pago_em: "Hora do pagamento do pedido",
  sku_principal: "Nº de referência do SKU principal",
  nome: "Nome do Produto",
  sku: "Número de referência SKU",
  variacao: "Nome da variação",
  quantidade: "Quantidade",
  devolvida: "Returned quantity",
  cliente: "Nome do destinatário",
};

/** Converte as linhas (1ª = cabeçalho) em pedidos agrupados. */
export function pedidosDasLinhas(linhas) {
  const cab = (linhas[0] || []).map((c) => String(c).trim());
  const col = Object.fromEntries(Object.entries(SHOPEE).map(([k, nome]) => [k, cab.indexOf(nome)]));
  if (col.pedido < 0 || col.quantidade < 0 || col.nome < 0) {
    throw new Error(
      'Não reconheci a planilha. Use a exportação de pedidos da Shopee: Meus Pedidos → A Enviar → Exportar (arquivo "Order.toship...xlsx").'
    );
  }

  const val = (l, k) => (col[k] >= 0 ? String(l[col[k]] ?? "").trim() : "");
  const porPedido = new Map();
  for (const l of linhas.slice(1)) {
    const id = val(l, "pedido");
    if (!id) continue;
    const qtd = (parseInt(val(l, "quantidade"), 10) || 0) - (parseInt(val(l, "devolvida"), 10) || 0);
    if (!porPedido.has(id)) {
      porPedido.set(id, {
        pedido: id,
        status: val(l, "status"),
        rastreio: val(l, "rastreio"),
        envio: val(l, "envio"),
        cliente: val(l, "cliente"),
        criado_em: val(l, "criado_em"),
        pago_em: val(l, "pago_em"),
        itens: [],
      });
    }
    if (qtd > 0) {
      porPedido.get(id).itens.push({
        sku_principal: val(l, "sku_principal"),
        sku: val(l, "sku"),
        nome: val(l, "nome").slice(0, 255) || "Produto sem nome",
        variacao: val(l, "variacao"),
        quantidade: qtd,
      });
    }
  }

  // Pedido sem item com quantidade (tudo devolvido) não entra.
  return [...porPedido.values()].filter((p) => p.itens.length > 0);
}

export async function lerPlanilhaVendas(arquivo) {
  const XLSX = await import("xlsx");
  const wb = XLSX.read(await arquivo.arrayBuffer(), { type: "array" });
  const linhas = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, defval: "", raw: false });
  const pedidos = pedidosDasLinhas(linhas);
  if (!pedidos.length) throw new Error("Não achei nenhum pedido com quantidade nesta planilha.");
  return pedidos;
}
