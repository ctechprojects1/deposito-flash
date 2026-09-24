import { useEffect, useState } from "react";
import {
  fetchLocation,
  fetchLocations,
  zerarEndereco,
  adicionarProdutoLocal,
  removerProdutoLocal,
  atualizarSaldoLocal,
  replicarParaLocal,
} from "../services/api";

/**
 * Modal que lista os produtos guardados num endereço e permite (com permissão
 * gerenciar_enderecos) adicionar novos produtos, excluir e zerar o estoque.
 */
export default function LocationModal({ location, onClose, onChanged, podeGerenciar = true }) {
  const [detalhe, setDetalhe] = useState(location);
  const [loading, setLoading] = useState(false);
  const [zerando, setZerando] = useState(false);

  // Form de novo produto
  const [mostrarForm, setMostrarForm] = useState(false);
  const [novo, setNovo] = useState({ nome: "", codigo_barras: "", codigo_microvix: "", quantidade: "" });
  const [salvando, setSalvando] = useState(false);
  const [erroForm, setErroForm] = useState(null);

  // Edição inline de saldo (mapa stock_id -> valor digitado)
  const [edicao, setEdicao] = useState({});
  const [salvandoSaldo, setSalvandoSaldo] = useState(null);

  // Replicar de outro endereço
  const [replicando, setReplicando] = useState(false);
  const [locaisTodos, setLocaisTodos] = useState([]);
  const [origemId, setOrigemId] = useState("");
  const [itensRep, setItensRep] = useState([]);
  const [salvandoRep, setSalvandoRep] = useState(false);
  const [erroRep, setErroRep] = useState(null);

  async function abrirReplicar() {
    setReplicando(true);
    setMostrarForm(false);
    setErroRep(null);
    try {
      setLocaisTodos(await fetchLocations());
    } catch (e) {
      setErroRep("Falha ao carregar os endereços.");
    }
  }

  function escolherOrigem(id) {
    setOrigemId(id);
    const origem = locaisTodos.find((l) => String(l.id) === String(id));
    setItensRep(
      (origem?.produtos ?? []).map((p) => ({
        product_id: p.product_id,
        nome: p.nome,
        codigo_microvix: p.codigo_microvix,
        quantidade: String(p.quantidade),
      }))
    );
  }

  async function confirmarReplicar() {
    setErroRep(null);
    const itens = itensRep
      .filter((i) => i.product_id)
      .map((i) => ({ product_id: i.product_id, quantidade: Number(i.quantidade) || 0 }));
    if (itens.length === 0) return setErroRep("Selecione um endereço de origem com produtos.");
    setSalvandoRep(true);
    try {
      await replicarParaLocal(detalhe.id, itens);
      setReplicando(false);
      setOrigemId("");
      setItensRep([]);
      await recarregar();
    } catch (e) {
      setErroRep(e?.response?.data?.message || "Erro ao replicar.");
    } finally {
      setSalvandoRep(false);
    }
  }

  async function salvarSaldo(p) {
    const val = edicao[p.stock_id];
    if (val === undefined) return;
    const q = Number(val);
    if (isNaN(q) || q < 0) return;
    setSalvandoSaldo(p.stock_id);
    try {
      await atualizarSaldoLocal(detalhe.id, p.stock_id, q);
      setEdicao((e) => {
        const n = { ...e };
        delete n[p.stock_id];
        return n;
      });
      await recarregar();
    } catch (e) {
      alert(e?.response?.data?.message || "Erro ao atualizar o saldo.");
    } finally {
      setSalvandoSaldo(null);
    }
  }

  async function recarregar() {
    const atualizado = await fetchLocation(detalhe.id);
    if (atualizado) setDetalhe(atualizado);
    onChanged?.();
  }

  useEffect(() => {
    let ativo = true;
    (async () => {
      try {
        setLoading(true);
        const atualizado = await fetchLocation(location.id);
        if (ativo && atualizado) setDetalhe(atualizado);
      } catch (e) {
        console.error(e);
      } finally {
        if (ativo) setLoading(false);
      }
    })();
    return () => {
      ativo = false;
    };
  }, [location.id]);

  useEffect(() => {
    function onKey(e) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  async function zerar() {
    if (!window.confirm(`Zerar o estoque de ${detalhe.nome}? Todas as quantidades deste endereço vão para 0.`))
      return;
    setZerando(true);
    try {
      await zerarEndereco(detalhe.id);
      await recarregar();
    } catch (e) {
      alert(e?.response?.data?.message || "Erro ao zerar o estoque.");
    } finally {
      setZerando(false);
    }
  }

  async function adicionar() {
    setErroForm(null);
    if (!novo.nome.trim()) return setErroForm("A descrição é obrigatória.");
    setSalvando(true);
    try {
      await adicionarProdutoLocal(detalhe.id, {
        nome: novo.nome.trim(),
        codigo_barras: novo.codigo_barras.trim() || null,
        codigo_microvix: novo.codigo_microvix.trim() || null,
        quantidade: novo.quantidade === "" ? 0 : Number(novo.quantidade),
      });
      setNovo({ nome: "", codigo_barras: "", codigo_microvix: "", quantidade: "" });
      setMostrarForm(false);
      await recarregar();
    } catch (e) {
      setErroForm(e?.response?.data?.message || "Erro ao adicionar o produto.");
    } finally {
      setSalvando(false);
    }
  }

  async function remover(p) {
    if (!window.confirm(`Remover "${p.nome}" deste endereço?`)) return;
    try {
      await removerProdutoLocal(detalhe.id, p.stock_id);
      await recarregar();
    } catch (e) {
      alert(e?.response?.data?.message || "Erro ao remover o produto.");
    }
  }

  const produtos = detalhe.produtos ?? [];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="card-nuvem w-full max-w-lg overflow-hidden" onClick={(e) => e.stopPropagation()}>
        {/* Cabeçalho */}
        <div className="flex items-start justify-between border-b border-slate-100 bg-gradient-to-r from-sky-50 to-indigo-50 p-5">
          <div>
            <h2 className="text-xl font-extrabold text-slate-800">{detalhe.nome}</h2>
            <p className="text-sm text-slate-500">
              Time {detalhe.corredor || "—"} · Posição {detalhe.esteira || "—"}
            </p>
          </div>
          <button onClick={onClose} className="rounded-full p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Fechar">
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Corpo */}
        <div className="max-h-[60vh] overflow-y-auto p-5">
          {loading && <p className="py-4 text-center text-sm text-slate-400">Atualizando...</p>}

          {!loading && produtos.length === 0 && (
            <p className="py-6 text-center text-slate-500">Nenhum item guardado neste endereço.</p>
          )}

          {produtos.length > 0 && (
            <ul className="space-y-2">
              {produtos.map((p) => (
                <li key={p.stock_id} className="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-2.5">
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-medium text-slate-800">{p.nome}</div>
                    <div className="flex flex-wrap gap-x-3 font-mono text-xs text-slate-400">
                      {p.codigo_microvix && <span>MVX: {p.codigo_microvix}</span>}
                      {p.codigo_barras && <span>Barras: {p.codigo_barras}</span>}
                    </div>
                  </div>
                  {podeGerenciar ? (
                    <div className="flex items-center gap-1">
                      <input
                        type="number"
                        min="0"
                        step="any"
                        value={edicao[p.stock_id] ?? String(p.quantidade)}
                        onChange={(e) => setEdicao((s) => ({ ...s, [p.stock_id]: e.target.value }))}
                        onKeyDown={(e) => e.key === "Enter" && salvarSaldo(p)}
                        className="w-20 rounded-lg border border-slate-200 px-2 py-1 text-right text-sm shadow-sm focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        title="Saldo (edite e salve)"
                      />
                      {edicao[p.stock_id] !== undefined &&
                        Number(edicao[p.stock_id]) !== Number(p.quantidade) && (
                          <button
                            onClick={() => salvarSaldo(p)}
                            disabled={salvandoSaldo === p.stock_id}
                            title="Salvar saldo"
                            className="rounded-full p-1.5 text-emerald-600 transition hover:bg-emerald-50 disabled:opacity-50"
                          >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                            </svg>
                          </button>
                        )}
                    </div>
                  ) : (
                    <span className="whitespace-nowrap font-semibold text-slate-800">{Number(p.quantidade)}</span>
                  )}
                  {podeGerenciar && (
                    <button
                      onClick={() => remover(p)}
                      title="Remover deste endereço"
                      className="rounded-full p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                    >
                      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}

          {/* Ações de gestão */}
          {podeGerenciar && (
            <div className="mt-4 space-y-3">
              {!mostrarForm && !replicando && (
                <div className="flex flex-col gap-2 sm:flex-row">
                  <button onClick={() => setMostrarForm(true)} className="btn-ghost flex-1">
                    Adicionar produto
                  </button>
                  <button onClick={abrirReplicar} className="btn-ghost flex-1">
                    Replicar de outro endereço
                  </button>
                </div>
              )}

              {mostrarForm && (
                <div className="rounded-xl border border-indigo-100 bg-indigo-50/40 p-3">
                  <h3 className="mb-2 text-sm font-bold text-slate-700">Novo produto</h3>
                  {erroForm && <div className="mb-2 rounded-lg bg-rose-100 p-2 text-xs text-rose-800">{erroForm}</div>}
                  <div className="space-y-2">
                    <input
                      value={novo.nome}
                      onChange={(e) => setNovo((n) => ({ ...n, nome: e.target.value }))}
                      placeholder="Descrição (obrigatório)"
                      className="input-nuvem"
                      autoFocus
                    />
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        value={novo.codigo_barras}
                        onChange={(e) => setNovo((n) => ({ ...n, codigo_barras: e.target.value }))}
                        placeholder="Código de barras (opcional)"
                        className="input-nuvem"
                      />
                      <input
                        value={novo.codigo_microvix}
                        onChange={(e) => setNovo((n) => ({ ...n, codigo_microvix: e.target.value }))}
                        placeholder="Cód. Microvix (opcional)"
                        className="input-nuvem"
                      />
                    </div>
                    <input
                      type="number"
                      min="0"
                      step="any"
                      value={novo.quantidade}
                      onChange={(e) => setNovo((n) => ({ ...n, quantidade: e.target.value }))}
                      placeholder="Quantidade (opcional, padrão 0)"
                      className="input-nuvem"
                    />
                  </div>
                  <div className="mt-3 flex justify-end gap-2">
                    <button onClick={() => { setMostrarForm(false); setErroForm(null); }} className="btn-ghost">Cancelar</button>
                    <button onClick={adicionar} disabled={salvando} className="btn-nuvem">
                      {salvando ? "Adicionando..." : "Adicionar"}
                    </button>
                  </div>
                </div>
              )}

              {replicando && (
                <div className="rounded-xl border border-indigo-100 bg-indigo-50/40 p-3">
                  <h3 className="mb-2 text-sm font-bold text-slate-700">Replicar de outro endereço</h3>
                  {erroRep && <div className="mb-2 rounded-lg bg-rose-100 p-2 text-xs text-rose-800">{erroRep}</div>}
                  <select value={origemId} onChange={(e) => escolherOrigem(e.target.value)} className="input-nuvem mb-2">
                    <option value="">Escolha o endereço de origem...</option>
                    {locaisTodos.filter((l) => l.id !== detalhe.id).map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.nome} ({l.total_itens} produto(s))
                      </option>
                    ))}
                  </select>
                  {origemId && itensRep.length === 0 && (
                    <p className="text-xs text-slate-500">Esse endereço não tem produtos.</p>
                  )}
                  {itensRep.length > 0 && (
                    <ul className="mb-2 max-h-56 space-y-1.5 overflow-y-auto">
                      {itensRep.map((it, idx) => (
                        <li key={it.product_id} className="flex items-center gap-2 rounded-lg bg-white p-2 text-sm ring-1 ring-slate-100">
                          <span className="min-w-0 flex-1 truncate text-slate-700">{it.nome}</span>
                          <input
                            type="number"
                            min="0"
                            step="any"
                            value={it.quantidade}
                            onChange={(e) => setItensRep((arr) => arr.map((x, i) => (i === idx ? { ...x, quantidade: e.target.value } : x)))}
                            className="w-20 rounded-lg border border-slate-200 px-2 py-1 text-right"
                          />
                          <button
                            onClick={() => setItensRep((arr) => arr.filter((_, i) => i !== idx))}
                            title="Não replicar este"
                            className="rounded-full p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                          >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                  <div className="mt-1 flex justify-end gap-2">
                    <button onClick={() => { setReplicando(false); setOrigemId(""); setItensRep([]); setErroRep(null); }} className="btn-ghost">Cancelar</button>
                    <button onClick={confirmarReplicar} disabled={salvandoRep || itensRep.length === 0} className="btn-nuvem">
                      {salvandoRep ? "Replicando..." : `Replicar ${itensRep.length} produto(s)`}
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Rodapé */}
        <div className="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/70 px-5 py-3 text-sm text-slate-500">
          <span>{produtos.length} produto(s)</span>
          <div className="flex items-center gap-2">
            {podeGerenciar && (
              <button
                onClick={zerar}
                disabled={zerando || produtos.length === 0}
                className="inline-flex items-center rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {zerando ? "Zerando..." : "Zerar estoque"}
              </button>
            )}
            <button onClick={onClose} className="btn-nuvem px-4 py-2">Fechar</button>
          </div>
        </div>
      </div>
    </div>
  );
}
