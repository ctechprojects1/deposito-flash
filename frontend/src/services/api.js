import axios from "axios";

// Base da API Laravel. Em produção (HostGator) aponte para o domínio real
// via VITE_API_URL no .env do frontend.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "http://localhost/api",
  headers: {
    Accept: "application/json",
  },
});

// Anexa o token de autenticação (se houver) em toda requisição.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) config.headers.Authorization = `Bearer ${token}`;
  // CD selecionado no topo (Goiânia, São Paulo...): o backend filtra tudo por ele.
  const deposito = localStorage.getItem("deposito_id");
  if (deposito) config.headers["X-Deposito"] = deposito;
  return config;
});

// Se o token expirar/for inválido (401), limpa e volta pro login.
api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error?.response?.status === 401) {
      localStorage.removeItem("token");
      // avisa o app pra voltar ao login
      window.dispatchEvent(new Event("auth:logout"));
    }
    return Promise.reject(error);
  }
);

/* ===================== Autenticação ===================== */

export async function apiLogin(email, password) {
  const { data } = await api.post("/login", { email, password });
  return data; // { token, user }
}

export async function apiMe() {
  const { data } = await api.get("/me");
  return data.user;
}

export async function apiLogout() {
  try {
    await api.post("/logout");
  } catch (_) {
    /* ignora */
  }
}

/* ===================== Usuários (admin) ===================== */

export async function fetchPermissoes() {
  const { data } = await api.get("/permissoes");
  return data.data ?? [];
}

export async function fetchDepositos() {
  const { data } = await api.get("/depositos");
  return data.data ?? [];
}

export async function fetchUsuarios() {
  const { data } = await api.get("/users");
  return data.data ?? [];
}

export async function criarUsuario(payload) {
  const { data } = await api.post("/users", payload);
  return data;
}

export async function atualizarUsuario(id, payload) {
  const { data } = await api.put(`/users/${id}`, payload);
  return data;
}

export async function excluirUsuario(id) {
  const { data } = await api.delete(`/users/${id}`);
  return data;
}

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

/** Adiciona um produto a um endereço. */
export async function adicionarProdutoLocal(locationId, payload) {
  const { data } = await api.post(`/locations/${locationId}/produtos`, payload);
  return data;
}

/** Atualiza o saldo (quantidade) de um produto num endereço. */
export async function atualizarSaldoLocal(locationId, stockId, quantidade) {
  const { data } = await api.put(`/locations/${locationId}/produtos/${stockId}`, { quantidade });
  return data;
}

/** Replica produtos (com quantidades) para um endereço. */
export async function replicarParaLocal(locationId, itens) {
  const { data } = await api.post(`/locations/${locationId}/replicar`, { itens });
  return data;
}

/** Remove um produto (registro de estoque) de um endereço. */
export async function removerProdutoLocal(locationId, stockId) {
  const { data } = await api.delete(`/locations/${locationId}/produtos/${stockId}`);
  return data;
}

/** Zera TODO o estoque (todos os endereços). Ação destrutiva. */
export async function zerarEstoqueGeral() {
  const { data } = await api.post("/stock/zerar-tudo");
  return data;
}

/* ===================== Movimentação ===================== */

export async function criarMovimentacao(payload) {
  const { data } = await api.post("/movements", payload);
  return data;
}

export async function fetchMovimentacoes(filtros = {}) {
  const { data } = await api.get("/movements", { params: filtros });
  return data.data ?? [];
}

/* ===================== Microvix ===================== */

/** Consulta um código de barras (ou código interno) no Microvix. */
export async function consultarMicrovix(codigo) {
  const { data } = await api.get("/microvix/consultar", { params: { codigo } });
  return data; // { encontrado, produto, sync_pendente, mensagem }
}

/** Sincroniza um lote da base de códigos de barras. */
export async function sincronizarMicrovix() {
  const { data } = await api.post("/microvix/sincronizar");
  return data; // { paginas, upserts, concluido, total }
}

export async function statusMicrovix() {
  const { data } = await api.get("/microvix/status");
  return data; // { total, ultima_sync, configurado }
}

/* ===================== Relatórios ===================== */

export async function relatorioProdutoLocalizacao(q) {
  const { data } = await api.get("/reports/produto-localizacao", { params: { q } });
  return data.data ?? [];
}

/* ===================== Solicitação / Separação ===================== */

/** Lê a nota/pedido em PDF e devolve os itens cruzados com o depósito. */
export async function extrairDocumento(arquivo) {
  const form = new FormData();
  form.append("documento", arquivo);
  const { data } = await api.post("/withdrawal-requests/extrair", form, {
    headers: { "Content-Type": "multipart/form-data" },
    timeout: 300000, // leitura do PDF pode levar até alguns minutos
  });
  return data.data; // { tipo, numero, destino, itens: [...] }
}

/**
 * Envia a solicitação com os itens marcados no checklist.
 * payload = { destino, observacao?, tipo_documento?, numero_documento?, itens: [...], anexo_nota: File }
 */
export async function criarSolicitacao(payload) {
  const form = new FormData();
  form.append("destino", payload.destino);
  if (payload.observacao) form.append("observacao", payload.observacao);
  if (payload.tipo_documento) form.append("tipo_documento", payload.tipo_documento);
  if (payload.numero_documento) form.append("numero_documento", payload.numero_documento);
  payload.itens.forEach((it, i) => {
    form.append(`itens[${i}][product_id]`, it.product_id);
    form.append(`itens[${i}][codigo_microvix]`, it.codigo_microvix ?? "");
    form.append(`itens[${i}][descricao]`, it.descricao ?? "");
    form.append(`itens[${i}][quantidade_documento]`, it.quantidade_documento ?? 0);
    form.append(`itens[${i}][quantidade_solicitada]`, it.quantidade_solicitada);
  });
  form.append("anexo_nota", payload.anexo_nota);

  const { data } = await api.post("/withdrawal-requests", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

/** Fila de separação. status = "pendente,pausada" | "concluida" | "todas" */
export async function fetchSolicitacoes(status) {
  const { data } = await api.get("/withdrawal-requests", { params: status ? { status } : {} });
  return data.data ?? [];
}

export async function fetchSolicitacao(id) {
  const { data } = await api.get(`/withdrawal-requests/${id}`);
  return data.data;
}

/** Abre o PDF anexado numa nova aba (baixado com o token). */
export async function abrirDocumentoSolicitacao(id) {
  const aba = window.open("", "_blank");
  try {
    const { data } = await api.get(`/withdrawal-requests/${id}/documento`, { responseType: "blob" });
    const url = URL.createObjectURL(new Blob([data], { type: "application/pdf" }));
    if (aba) aba.location.href = url;
    else window.location.href = url;
  } catch (e) {
    aba?.close();
    throw e;
  }
}

/** Inicia ou retoma a separação (o separador é o usuário logado). */
export async function iniciarSeparacao(id) {
  const { data } = await api.post(`/withdrawal-requests/${id}/iniciar`);
  return data.data;
}

export async function pausarSeparacao(id) {
  const { data } = await api.post(`/withdrawal-requests/${id}/pausar`);
  return data.data;
}

/** Marca/desmarca o item ou troca o endereço. payload = { retirado?, location_id? } */
export async function atualizarItemSeparacao(id, itemId, payload) {
  const { data } = await api.put(`/withdrawal-requests/${id}/itens/${itemId}`, payload);
  return data.data;
}

/** Finaliza: baixa o estoque de todos os itens. */
export async function finalizarSeparacao(id) {
  const { data } = await api.post(`/withdrawal-requests/${id}/finalizar`);
  return data;
}

/** Admin: reabre uma separação finalizada (devolve o estoque). */
export async function reabrirSeparacao(id) {
  const { data } = await api.post(`/withdrawal-requests/${id}/reabrir`);
  return data;
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
