import { useEffect, useState } from "react";
import { relatorioHistorico } from "../services/api";

// Cor do chip por tipo de alteração.
const COR_TIPO = {
  separacao: "bg-rose-100 text-rose-700",
  estorno: "bg-amber-100 text-amber-800",
  movimentacao: "bg-sky-100 text-sky-700",
  contagem: "bg-violet-100 text-violet-700",
  adicao: "bg-emerald-100 text-emerald-700",
  importacao: "bg-emerald-100 text-emerald-700",
  replicacao: "bg-emerald-100 text-emerald-700",
  zerar_endereco: "bg-red-100 text-red-700",
  zerar_geral: "bg-red-100 text-red-700",
  remocao: "bg-slate-200 text-slate-700",
};

const hoje = () => new Date().toISOString().slice(0, 10);
const diasAtras = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);
const fmt = (n) => Number(n).toLocaleString("pt-BR", { maximumFractionDigits: 2 });

/**
 * Histórico de estoque: toda alteração de saldo do CD (baixas de separação,
 * movimentações, ajustes, contagens, zeragens, importações...).
 */
export default function HistoricoEstoque() {
  const [filtros, setFiltros] = useState({ de: diasAtras(7), ate: hoje(), produto: "", endereco: "", tipo: "", user_id: "" });
  const [linhas, setLinhas] = useState([]);
  const [meta, setMeta] = useState({ total: 0, tem_mais: false, pagina: 1, tipos: {}, usuarios: [] });
  const [carregando, setCarregando] = useState(false);
  const [erro, setErro] = useState(null);

  async function buscar(pagina = 1) {
    setCarregando(true);
    setErro(null);
    try {
      const params = Object.fromEntries(Object.entries({ ...filtros, pagina }).filter(([, v]) => v !== ""));
      const r = await relatorioHistorico(params);
      setLinhas((atual) => (pagina === 1 ? r.data : [...atual, ...r.data]));
      setMeta({ total: r.total, tem_mais: r.tem_mais, pagina: r.pagina, tipos: r.tipos, usuarios: r.usuarios });
    } catch (e) {
      setErro(e?.response?.data?.message || "Não foi possível carregar o histórico.");
    } finally {
      setCarregando(false);
    }
  }

  useEffect(() => {
    buscar(1);
  }, []);

  const set = (campo) => (e) => setFiltros((f) => ({ ...f, [campo]: e.target.value }));

  function exportarCsv() {
    const cab = ["Data", "Endereço", "Código", "Produto", "Tipo", "Antes", "Depois", "Diferença", "Usuário", "Referência", "Observação"];
    const q = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
    const csv = [cab, ...linhas.map((l) => [l.data, l.endereco, l.codigo, l.produto, l.tipo_label, fmt(l.antes), fmt(l.depois), fmt(l.diferenca), l.usuario, l.referencia, l.observacao])]
      .map((r) => r.map(q).join(";"))
      .join("\r\n");
    const url = URL.createObjectURL(new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `historico-estoque-${filtros.de}-a-${filtros.ate}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          buscar(1);
        }}
        className="card-nuvem mb-4 grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3"
      >
        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">De</label>
          <input type="date" value={filtros.de} onChange={set("de")} className="input-nuvem" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">Até</label>
          <input type="date" value={filtros.ate} onChange={set("ate")} className="input-nuvem" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">Usuário</label>
          <select value={filtros.user_id} onChange={set("user_id")} className="input-nuvem">
            <option value="">Todos</option>
            {meta.usuarios.map((u) => (
              <option key={u.id} value={u.id}>{u.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">Produto</label>
          <input value={filtros.produto} onChange={set("produto")} placeholder="Código ou descrição" className="input-nuvem" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">Endereço</label>
          <input value={filtros.endereco} onChange={set("endereco")} placeholder="Ex.: CORINTHIANS 5A" className="input-nuvem" />
        </div>
        <div className="flex items-end gap-2">
          <button type="submit" disabled={carregando} className="btn-nuvem flex-1">
            {carregando ? "Buscando..." : "Filtrar"}
          </button>
          <button type="button" onClick={exportarCsv} disabled={linhas.length === 0} className="btn-ghost whitespace-nowrap">
            Exportar CSV
          </button>
        </div>

        {/* Tipo em chips */}
        <div className="flex flex-wrap gap-1.5 sm:col-span-2 lg:col-span-3">
          {[["", "Todos os tipos"], ...Object.entries(meta.tipos)].map(([id, rotulo]) => (
            <button
              key={id || "todos"}
              type="button"
              onClick={() => setFiltros((f) => ({ ...f, tipo: id }))}
              className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                filtros.tipo === id
                  ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-md shadow-indigo-500/30"
                  : "bg-slate-100 text-slate-600 hover:bg-slate-200"
              }`}
            >
              {rotulo}
            </button>
          ))}
        </div>
      </form>

      {erro && <div className="mb-4 rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}

      <div className="mb-2 text-sm text-slate-500">
        {meta.total} alteração(ões) no período {linhas.length < meta.total && `· mostrando ${linhas.length}`}
      </div>

      {linhas.length === 0 && !carregando ? (
        <p className="card-nuvem py-10 text-center text-sm text-slate-400">Nenhuma alteração de estoque com esses filtros.</p>
      ) : (
        <div className="card-nuvem overflow-x-auto">
          <table className="w-full min-w-[820px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-xs text-slate-500">
                <th className="px-3 py-2 font-semibold">Data</th>
                <th className="px-3 py-2 font-semibold">Endereço</th>
                <th className="px-3 py-2 font-semibold">Produto</th>
                <th className="px-3 py-2 font-semibold">Tipo</th>
                <th className="px-3 py-2 text-right font-semibold">Antes → Depois</th>
                <th className="px-3 py-2 text-right font-semibold">Dif.</th>
                <th className="px-3 py-2 font-semibold">Usuário / origem</th>
              </tr>
            </thead>
            <tbody>
              {linhas.map((l) => (
                <tr key={l.id} className="border-b border-slate-50 align-top last:border-0">
                  <td className="whitespace-nowrap px-3 py-2 text-slate-600">{l.data}</td>
                  <td className="whitespace-nowrap px-3 py-2 font-semibold text-slate-800">{l.endereco}</td>
                  <td className="px-3 py-2">
                    <div className="text-slate-800">{l.produto}</div>
                    <div className="font-mono text-xs text-slate-400">cód. {l.codigo}</div>
                  </td>
                  <td className="px-3 py-2">
                    <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${COR_TIPO[l.tipo] || "bg-slate-100 text-slate-600"}`}>
                      {l.tipo_label}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right text-slate-600">
                    {fmt(l.antes)} → <strong className="text-slate-800">{fmt(l.depois)}</strong>
                  </td>
                  <td className={`whitespace-nowrap px-3 py-2 text-right font-bold ${l.diferenca < 0 ? "text-rose-600" : "text-emerald-600"}`}>
                    {l.diferenca > 0 ? "+" : ""}
                    {fmt(l.diferenca)}
                  </td>
                  <td className="px-3 py-2 text-xs text-slate-500">
                    <div className="font-semibold text-slate-700">{l.usuario || "—"}</div>
                    {l.referencia && <div>{l.referencia}</div>}
                    {l.observacao && <div className="italic">{l.observacao}</div>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta.tem_mais && (
        <div className="mt-4 text-center">
          <button onClick={() => buscar(meta.pagina + 1)} disabled={carregando} className="btn-ghost">
            {carregando ? "Carregando..." : "Carregar mais"}
          </button>
        </div>
      )}
    </div>
  );
}
