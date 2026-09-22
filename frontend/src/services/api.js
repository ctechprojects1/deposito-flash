import axios from "axios";

// Base da API Laravel. Em produção (HostGator) aponte para o domínio real
// via VITE_API_URL no .env do frontend.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "http://localhost/api",
  headers: {
    Accept: "application/json",
  },
});

/**
 * Busca todos os locais com seus produtos/quantidades (alimenta o mapa).
 */
export async function fetchLocations() {
  const { data } = await api.get("/locations");
  return data.data ?? [];
}

/**
 * Detalha um local específico.
 */
export async function fetchLocation(id) {
  const { data } = await api.get(`/locations/${id}`);
  return data.data;
}

/**
 * Cria endereços para um Time (uma ou mais posições).
 * @param {string} time
 * @param {string[]} posicoes  ex: ["1A","1B","2A"]
 */
export async function criarEnderecos(time, posicoes) {
  const { data } = await api.post("/locations", { time, posicoes });
  return data;
}

/** Zera o estoque de um endereço. */
export async function zerarEndereco(id) {
  const { data } = await api.post(`/locations/${id}/zerar`);
  return data;
}

/** Zera TODO o estoque (todos os endereços). Ação destrutiva. */
export async function zerarEstoqueGeral() {
  const { data } = await api.post("/stock/zerar-tudo");
  return data;
}

/**
 * Valida um código no Microvix e traz o nome + product_id local.
 */
export async function validarProduto(codigo) {
  const { data } = await api.get("/products/validar-microvix", {
    params: { codigo },
  });
  return data.data;
}

/**
 * Cria uma solicitação de retirada (multipart, por causa do anexo).
 * `payload` = { solicitante_id, destino, observacao?, itens: [...], anexo_nota: File }
 */
export async function criarSolicitacao(payload) {
  const form = new FormData();
  form.append("solicitante_id", payload.solicitante_id);
  form.append("destino", payload.destino);
  if (payload.observacao) form.append("observacao", payload.observacao);
  payload.itens.forEach((item, i) => {
    form.append(`itens[${i}][product_id]`, item.product_id);
    form.append(`itens[${i}][quantidade_solicitada]`, item.quantidade_solicitada);
  });
  form.append("anexo_nota", payload.anexo_nota);

  const { data } = await api.post("/withdrawal-requests", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

/**
 * Lista solicitações por status (fila do separador).
 */
export async function fetchSolicitacoes(status = "pendente") {
  const { data } = await api.get("/withdrawal-requests", { params: { status } });
  return data.data ?? [];
}

/**
 * Separador assume a solicitação.
 */
export async function iniciarSeparacao(id, separadorId) {
  const { data } = await api.post(`/withdrawal-requests/${id}/iniciar`, {
    separador_id: separadorId,
  });
  return data.data;
}

/**
 * Confirma a retirada → dispara a baixa no estoque.
 */
export async function confirmarRetirada(id) {
  const { data } = await api.post(`/withdrawal-requests/${id}/confirmar`);
  return data.data;
}

/* ===================== Contagem / Inventário ===================== */

export async function fetchContagens() {
  const { data } = await api.get("/counts");
  return data.data ?? [];
}

export async function criarContagem(payload) {
  const { data } = await api.post("/counts", payload);
  return data.data;
}

export async function fetchContagem(id) {
  const { data } = await api.get(`/counts/${id}`);
  return data.data;
}

export async function salvarContagemItens(id, itens) {
  const { data } = await api.post(`/counts/${id}/itens`, { itens });
  return data.data;
}

export async function fetchDivergencias(id) {
  const { data } = await api.get(`/counts/${id}/divergencias`);
  return data.data;
}

export async function finalizarContagem(id) {
  const { data } = await api.post(`/counts/${id}/finalizar`);
  return data;
}

export async function cancelarContagem(id) {
  const { data } = await api.post(`/counts/${id}/cancelar`);
  return data;
}

/**
 * Importa estoque via upload de CSV.
 * @param {File} arquivo  CSV (Time, Codigo_Produto, Nome, Quantidade)
 * @param {boolean} somar true = soma no existente; false = sobrescreve
 */
export async function importarEstoque(arquivo, somar = false) {
  const form = new FormData();
  form.append("arquivo", arquivo);
  form.append("somar", somar ? 1 : 0);

  const { data } = await api.post("/stock/import", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data.data;
}

export default api;
