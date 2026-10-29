/**
 * Lê a planilha de pedidos da Shopee (Meus Pedidos -> A Enviar -> Exportar,
 * arquivo "Order.toship...xlsx") ou do TikTok Shop (Pedidos -> Para enviar ->
 * Exportar, arquivo "Para enviar pedido-....xlsx") no navegador e devolve os
 * pedidos com só o que a separação e a embalagem precisam — CPF, telefone e
 * endereço do comprador não saem do computador.
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

// Colunas da exportação "Para enviar" do TikTok Shop (cabeçalho em inglês,
// 2ª linha com a descrição de cada coluna, datas em mês/dia/ano).
const TIKTOK = {
  pedido: "Order ID",
  status: "Order Status",
  subst: "Order Substatus",
  rastreio: "Tracking ID",
  envio: "Delivery Option",
  criado_em: "Created Time",
  pago_em: "Paid Time",
  sku: "Seller SKU",
  nome: "Product Name",
  variacao: "Variation",
  quantidade: "Quantity",
  devolvida: "Sku Quantity of return",
  cliente: "Recipient",
};

/** "10/28/2026 7:08:13 PM" (TikTok) -> "2026-10-28 19:08:13". */
function dataTiktok(v) {
  const m = String(v || "").trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?)?/i);
  if (!m) return "";
  let [, mes, dia, ano, h = "0", min = "00", seg = "00", ap] = m;
  let hora = parseInt(h, 10);
  if (ap) {
    ap = ap.toUpperCase();
    if (ap === "PM" && hora < 12) hora += 12;
    if (ap === "AM" && hora === 12) hora = 0;
  }
  const d2 = (n) => String(n).padStart(2, "0");
  return `${ano}-${d2(mes)}-${d2(dia)} ${d2(hora)}:${min}:${seg}`;
}

/**
 * Converte as linhas (1ª = cabeçalho) em pedidos agrupados.
 * @returns {{ canal: "shopee"|"tiktok", pedidos: object[] }}
 */
export function pedidosDasLinhas(linhas) {
  const cab = (linhas[0] || []).map((c) => String(c).trim());
  const tiktok = cab.includes(TIKTOK.pedido) && cab.includes(TIKTOK.sku);
  const mapa = tiktok ? TIKTOK : SHOPEE;
  const col = Object.fromEntries(Object.entries(mapa).map(([k, nome]) => [k, cab.indexOf(nome)]));
  if (col.pedido < 0 || col.quantidade < 0 || col.nome < 0) {
    throw new Error(
      "Não reconheci a planilha. Use a exportação de pedidos: na Shopee, Meus Pedidos → A Enviar → Exportar (\"Order.toship...xlsx\"); no TikTok, Pedidos → Para enviar → Exportar (\"Para enviar pedido-...xlsx\")."
    );
  }

  const val = (l, k) => (col[k] >= 0 ? String(l[col[k]] ?? "").trim() : "");
  const porPedido = new Map();
  for (const l of linhas.slice(1)) {
    const id = val(l, "pedido");
    // TikTok: a 2ª linha é a descrição das colunas ("Platform unique order ID.").
    if (!id || (tiktok && !/^\d+$/.test(id))) continue;
    const qtd = (parseInt(val(l, "quantidade"), 10) || 0) - (parseInt(val(l, "devolvida"), 10) || 0);
    if (!porPedido.has(id)) {
      const status = tiktok ? [val(l, "status"), val(l, "subst")].filter(Boolean).join(" · ") : val(l, "status");
      porPedido.set(id, {
        pedido: id,
        status: status.slice(0, 60),
        rastreio: val(l, "rastreio"),
        envio: val(l, "envio"),
        cliente: val(l, "cliente"),
        criado_em: tiktok ? dataTiktok(val(l, "criado_em")) : val(l, "criado_em"),
        pago_em: tiktok ? dataTiktok(val(l, "pago_em")) : val(l, "pago_em"),
        itens: [],
      });
    }
    // Rastreio pode vir só em uma das linhas do pedido.
    if (!porPedido.get(id).rastreio && val(l, "rastreio")) porPedido.get(id).rastreio = val(l, "rastreio");
    if (qtd > 0) {
      const variacao = val(l, "variacao");
      porPedido.get(id).itens.push({
        sku_principal: tiktok ? "" : val(l, "sku_principal"),
        sku: val(l, "sku"),
        nome: val(l, "nome").slice(0, 255) || "Produto sem nome",
        // TikTok manda "Padrão" quando o anúncio não tem variação.
        variacao: /^(padr[aã]o|default)$/i.test(variacao) ? "" : variacao,
        quantidade: qtd,
      });
    }
  }

  // Pedido sem item com quantidade (tudo devolvido) não entra.
  return { canal: tiktok ? "tiktok" : "shopee", pedidos: [...porPedido.values()].filter((p) => p.itens.length > 0) };
}

export async function lerPlanilhaVendas(arquivo) {
  const XLSX = await import("xlsx");
  const wb = XLSX.read(await arquivo.arrayBuffer(), { type: "array" });
  const linhas = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, defval: "", raw: false });
  const r = pedidosDasLinhas(linhas);
  if (!r.pedidos.length) throw new Error("Não achei nenhum pedido com quantidade nesta planilha.");
  return r;
}
