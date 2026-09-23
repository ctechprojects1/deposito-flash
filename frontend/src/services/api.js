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

/* ===================== Relatórios ===================== */

export async function relatorioProdutoLocalizacao(q) {
  const { data } = await api.get("/reports/produto-localizacao", { params: { q } });
  return data.data ?? [];
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
