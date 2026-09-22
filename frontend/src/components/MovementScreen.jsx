import { useEffect, useMemo, useState } from "react";
import {
  fetchLocations,
  criarMovimentacao,
  fetchMovimentacoes,
} from "../services/api";

export default function MovementScreen() {
  const [modo, setModo] = useState("nova"); // "nova" | "relatorio"

  return (
    <div className="mx-auto max-w-4xl px-4">
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-slate-800">Movimentação</h1>
        <div className="flex gap-1 rounded-full bg-slate-100 p-1">
          <button
            onClick={() => setModo("nova")}
            className={`rounded-full px-4 py-1.5 text-sm font-semibold transition ${
              modo === "nova" ? "bg-white text-indigo-600 shadow" : "text-slate-500"
            }`}
          >
            Nova
          </button>
          <button
            onClick={() => setModo("relatorio")}
            className={`rounded-full px-4 py-1.5 text-sm font-semibold transition ${
              modo === "relatorio" ? "bg-white text-indigo-600 shadow" : "text-slate-500"
            }`}
          >
            Relatório
          </button>
        </div>
      </div>

      {modo === "nova" ? <FormMovimentacao /> : <RelatorioMovimentacoes />}
    </div>
  );
}

/* ------------------------------ Nova movimentação ------------------------------ */

function FormMovimentacao() {
  const [locais, setLocais] = useState([]);
  const [origemId, setOrigemId] = useState("");
  const [productId, setProductId] = useState("");
  const [destinoId, setDestinoId] = useState("");
  const [quantidade, setQuantidade] = useState("");
  const [motivo, setMotivo] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [feedback, setFeedback] = useState(null);

  async function carregarLocais() {
    try {
      setLocais(await fetchLocations());
    } catch (e) {
      setFeedback({ tipo: "erro", texto: "Falha ao carregar endereços." });
    }
  }

  useEffect(() => {
    carregarLocais();
  }, []);

  const origem = useMemo(
    () => locais.find((l) => String(l.id) === String(origemId)),
    [locais, origemId]
  );
  const produtosOrigem = origem?.produtos ?? [];
  const produtoSel = produtosOrigem.find((p) => String(p.product_id) === String(productId));
  const disponivel = produtoSel ? Number(produtoSel.quantidade) : 0;

  const valido =
    origemId && productId && destinoId && origemId !== destinoId &&
    Number(quantidade) > 0 && Number(quantidade) <= disponivel && motivo.trim();

  async function enviar(e) {
    e.preventDefault();
    setFeedback(null);
    setEnviando(true);
    try {
      await criarMovimentacao({
        product_id: Number(productId),
        origin_location_id: Number(origemId),
        destination_location_id: Number(destinoId),
        quantidade: Number(quantidade),
        motivo: motivo.trim(),
      });
      setFeedback({ tipo: "sucesso", texto: "Movimentação registrada com sucesso!" });
      setProductId("");
      setQuantidade("");
      setMotivo("");
      setDestinoId("");
      await carregarLocais(); // atualiza saldos
      setOrigemId("");
    } catch (err) {
      setFeedback({ tipo: "erro", texto: err?.response?.data?.message || "Erro ao movimentar." });
    } finally {
      setEnviando(false);
    }
  }

  return (
    <form onSubmit={enviar} className="card-nuvem space-y-5 p-5">
      {feedback && (
        <div
          className={`rounded-xl p-3 text-sm ${
            feedback.tipo === "sucesso" ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"
          }`}
        >
          {feedback.texto}
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {/* Origem */}
        <div>
          <label className="mb-1 block text-sm font-semibold text-slate-700">Origem</label>
          <SelectEndereco
            locais={locais}
            value={origemId}
            onChange={(v) => {
              setOrigemId(v);
              setProductId("");
            }}
            placeholder="De qual endereço..."
          />
        </div>

        {/* Destino */}
        <div>
          <label className="mb-1 block text-sm font-semibold text-slate-700">Destino</label>
          <SelectEndereco
            locais={locais}
            value={destinoId}
            onChange={setDestinoId}
            placeholder="Para qual endereço..."
          />
        </div>
      </div>

      {/* Produto (da origem) */}
      <div>
        <label className="mb-1 block text-sm font-semibold text-slate-700">Produto</label>
        <select
          value={productId}
          onChange={(e) => setProductId(e.target.value)}
          disabled={!origemId}
          className="input-nuvem disabled:bg-slate-50 disabled:text-slate-400"
        >
          <option value="">
            {origemId ? "Selecione o produto..." : "Escolha a origem primeiro"}
          </option>
          {produtosOrigem.map((p) => (
            <option key={p.product_id} value={p.product_id}>
              {p.nome} — {p.quantidade} disp.
            </option>
          ))}
        </select>
        {origemId && produtosOrigem.length === 0 && (
          <p className="mt-1 text-xs text-rose-600">Este endereço não tem produtos.</p>
        )}
      </div>

      {/* Quantidade */}
      <div>
        <label className="mb-1 block text-sm font-semibold text-slate-700">
          Quantidade {produtoSel && <span className="font-normal text-slate-400">(disponível: {disponivel})</span>}
        </label>
        <input
          type="number"
          min="0"
          step="any"
          max={disponivel || undefined}
          value={quantidade}
          onChange={(e) => setQuantidade(e.target.value)}
          disabled={!productId}
          className="input-nuvem disabled:bg-slate-50"
        />
        {productId && Number(quantidade) > disponivel && (
          <p className="mt-1 text-xs text-rose-600">Quantidade maior que o disponível.</p>
        )}
      </div>

      {/* Motivo (obrigatório) */}
      <div>
        <label className="mb-1 block text-sm font-semibold text-slate-700">
          Motivo <span className="text-rose-500">*</span>
        </label>
        <input
          type="text"
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          placeholder="Ex.: reorganização, agrupar mesmo produto, corrigir endereço..."
          className="input-nuvem"
        />
      </div>

      <button type="submit" disabled={enviando || !valido} className="btn-nuvem w-full py-3">
        {enviando ? "Movimentando..." : "Registrar movimentação"}
      </button>
    </form>
  );
}

/** Select de endereço agrupado por Time. */
function SelectEndereco({ locais, value, onChange, placeholder }) {
  const grupos = useMemo(() => {
    const m = new Map();
    for (const l of [...locais].sort((a, b) => (a.nome || "").localeCompare(b.nome || ""))) {
      const t = l.corredor || "Outros";
      if (!m.has(t)) m.set(t, []);
      m.get(t).push(l);
    }
    return Array.from(m.entries());
  }, [locais]);

  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className="input-nuvem">
      <option value="">{placeholder}</option>
      {grupos.map(([time, ls]) => (
        <optgroup key={time} label={time}>
          {ls.map((l) => (
            <option key={l.id} value={l.id}>
              {l.nome} ({l.total_quantidade} un.)
            </option>
          ))}
        </optgroup>
      ))}
    </select>
  );
}

/* ------------------------------ Relatório ------------------------------ */

function RelatorioMovimentacoes() {
  const [movs, setMovs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filtros, setFiltros] = useState({ produto: "", time: "", de: "", ate: "" });

  async function carregar() {
    setLoading(true);
    try {
      const limpos = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v));
      setMovs(await fetchMovimentacoes(limpos));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    carregar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="card-nuvem p-5">
      {/* Filtros */}
      <div className="mb-4 grid gap-2 sm:grid-cols-5">
        <input
          value={filtros.produto}
          onChange={(e) => setFiltros((f) => ({ ...f, produto: e.target.value }))}
          placeholder="Produto/código"
          className="input-nuvem sm:col-span-2"
        />
        <input
          value={filtros.time}
          onChange={(e) => setFiltros((f) => ({ ...f, time: e.target.value }))}
          placeholder="Time"
          className="input-nuvem"
        />
        <input
          type="date"
          value={filtros.de}
          onChange={(e) => setFiltros((f) => ({ ...f, de: e.target.value }))}
          className="input-nuvem"
        />
        <input
          type="date"
          value={filtros.ate}
          onChange={(e) => setFiltros((f) => ({ ...f, ate: e.target.value }))}
          className="input-nuvem"
        />
      </div>
      <button onClick={carregar} className="btn-ghost mb-4">Filtrar</button>

      {loading ? (
        <p className="py-8 text-center text-slate-400">Carregando...</p>
      ) : movs.length === 0 ? (
        <p className="py-8 text-center text-slate-400">Nenhuma movimentação encontrada.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-slate-500">
                <th className="pb-2 font-semibold">Data</th>
                <th className="pb-2 font-semibold">Produto</th>
                <th className="pb-2 font-semibold">De → Para</th>
                <th className="pb-2 text-right font-semibold">Qtd</th>
                <th className="pb-2 font-semibold">Motivo</th>
                <th className="pb-2 font-semibold">Usuário</th>
              </tr>
            </thead>
            <tbody>
              {movs.map((m) => (
                <tr key={m.id} className="border-b border-slate-50 last:border-0">
                  <td className="whitespace-nowrap py-2 text-slate-500">{m.data}</td>
                  <td className="py-2 text-slate-800">
                    {m.produto}
                    <span className="block font-mono text-xs text-slate-400">{m.codigo}</span>
                  </td>
                  <td className="whitespace-nowrap py-2 text-slate-600">
                    {m.origem} <span className="text-indigo-500">→</span> {m.destino}
                  </td>
                  <td className="py-2 text-right font-semibold text-slate-800">{m.quantidade}</td>
                  <td className="py-2 text-slate-600">{m.motivo}</td>
                  <td className="py-2 text-slate-500">{m.usuario}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
