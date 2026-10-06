/**
 * Lê planilhas de produtos (Shopee "mass update", exportação de estoque,
 * TikTok...) no navegador e devolve as linhas no formato da base de apoio.
 * A biblioteca de planilhas só é baixada quando alguém importa.
 */

const semAcento = (s) =>
  String(s ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .trim();

/** Primeira coluna cujo cabeçalho bate com algum dos nomes (string exata ou regex). */
function coluna(cab, nomes, ignorar = []) {
  for (const n of nomes) {
    const i = cab.findIndex((c, idx) => !ignorar.includes(idx) && (typeof n === "string" ? c === n : n.test(c)));
    if (i >= 0) return i;
  }
  return -1;
}

export async function lerPlanilhaCatalogo(arquivo) {
  const XLSX = await import("xlsx");
  const wb = XLSX.read(await arquivo.arrayBuffer(), { type: "array" });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const linhas = XLSX.utils.sheet_to_json(ws, { header: 1, defval: "", raw: false });

  // Cabeçalho: a 1ª linha (entre as 30 primeiras) que tenha SKU e nome/produto.
  let h = -1;
  for (let i = 0; i < Math.min(linhas.length, 30); i++) {
    const cab = linhas[i].map(semAcento);
    if (cab.some((c) => /^sku/.test(c)) && cab.some((c) => /nome|produto|descri|titulo/.test(c))) {
      h = i;
      break;
    }
  }
  if (h < 0) throw new Error("Não achei o cabeçalho da planilha. Ela precisa ter colunas de SKU e de Nome/Produto.");

  const cab = linhas[h].map(semAcento);
  let iNome = coluna(cab, ["nome do produto", "produto", "descricao do produto", "descricao", "titulo", "nome"]);
  const iVar = coluna(cab, ["variacao", "nome da variacao", "variante", "nome"], [iNome]);
  const iSkuPai = coluna(cab, ["sku de referencia", "sku pai", "sku principal", "referencia"]);
  const iSku = coluna(cab, ["sku", "sku da variacao", "sku variacao", "sku do vendedor"], [iSkuPai]);
  const iEan = coluna(cab, [/gtin/, /^ean/, /codigo de barras/]);
  const iId = coluna(cab, ["id do produto", "id"]);
  const iVarId = coluna(cab, ["variante identificador", "id da variacao", "id da variante"]);

  const v = (l, i) => (i >= 0 ? String(l[i] ?? "").trim() : "");
  const porChave = new Map();
  for (const l of linhas.slice(h + 1)) {
    const nome = v(l, iNome);
    const sku = v(l, iSku);
    const skuPai = v(l, iSkuPai);
    const ean = v(l, iEan);
    if (!nome || (!sku && !skuPai && !ean)) continue; // linhas de instrução/vazias
    const id = v(l, iId);
    const varId = v(l, iVarId);
    const chave = varId || (id ? `${id}|${sku}` : "") || sku || skuPai || ean;
    porChave.set(chave.slice(0, 191), {
      chave: chave.slice(0, 191),
      nome: nome.slice(0, 255),
      variacao: v(l, iVar).slice(0, 191) || null,
      sku: sku.slice(0, 100) || null,
      sku_pai: skuPai.slice(0, 100) || null,
      ean: ean.slice(0, 60) || null,
      externo_id: id.slice(0, 60) || null,
    });
  }
  return Array.from(porChave.values());
}
