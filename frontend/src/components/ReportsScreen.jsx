import { useState } from "react";
import { relatorioProdutoLocalizacao } from "../services/api";

// Lista de relatórios disponíveis (fácil de crescer no futuro).
const RELATORIOS = [
  { id: "produto_localizacao", label: "Produto × Localização" },
];

export default function ReportsScreen() {
  const [relatorio, setRelatorio] = useState("produto_localizacao");

  return (
    <div className="mx-auto max-w-4xl px-4">
      <h1 className="mb-4 text-2xl font-extrabold text-slate-800">Relatórios</h1>

      {/* Seletor de relatórios */}
      <div className="mb-5 flex flex-wrap gap-2">
        {RELATORIOS.map((r) => (
          <button
            key={r.id}
            onClick={() => setRelatorio(r.id)}
            className={`rounded-full px-4 py-2 text-sm font-semibold transition ${
              relatorio === r.id
                ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-md shadow-indigo-500/30"
                : "bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50"
            }`}
          >
            {r.label}
          </button>
        ))}
      </div>

      {relatorio === "produto_localizacao" && <ProdutoLocalizacao />}
    </div>
  );
}

function ProdutoLocalizacao() {
  const [busca, setBusca] = useState("");
  const [resultados, setResultados] = useState(null);
  const [loading, setLoading] = useState(false);

  async function buscar(e) {
    e?.preventDefault();
    if (!busca.trim()) return;
    setLoading(true);
    try {
      setResultados(await relatorioProdutoLocalizacao(busca.trim()));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      <form onSubmit={buscar} className="card-nuvem mb-5 flex flex-col gap-3 p-5 sm:flex-row">
        <input
          value={busca}
          onChange={(e) => setBusca(e.target.value)}
          placeholder="Buscar por código Microvix, código de barras ou descrição..."
          className="input-nuvem flex-1"
          autoFocus
        />
        <button type="submit" disabled={loading || !busca.trim()} className="btn-nuvem whitespace-nowrap">
          {loading ? "Buscando..." : "Buscar"}
        </button>
      </form>

      {resultados === null && (
        <p className="py-8 text-center text-sm text-slate-400">
          Digite um termo e clique em Buscar para ver onde o produto está guardado.
        </p>
      )}

      {resultados && resultados.length === 0 && (
        <p className="py-8 text-center text-sm text-slate-400">Nenhum produto encontrado.</p>
      )}

      <div className="space-y-4">
        {(resultados ?? []).map((p) => (
          <div key={p.product_id} className="card-nuvem p-5">
            <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <h3 className="text-lg font-bold text-slate-800">{p.nome}</h3>
                <div className="flex flex-wrap gap-x-3 font-mono text-xs text-slate-400">
                  {p.codigo_microvix && <span>MVX: {p.codigo_microvix}</span>}
                  {p.codigo_barras && <span>Barras: {p.codigo_barras}</span>}
                </div>
              </div>
              <div className="text-right">
                <div className="text-2xl font-extrabold text-indigo-600">{p.total}</div>
                <div className="text-xs text-slate-400">total em estoque</div>
              </div>
            </div>

            {p.localizacoes.length === 0 ? (
              <p className="text-sm text-slate-400">Sem endereços com este produto.</p>
            ) : (
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-slate-500">
                    <th className="pb-2 font-semibold">Localização</th>
                    <th className="pb-2 text-right font-semibold">Quantidade</th>
                  </tr>
                </thead>
                <tbody>
                  {p.localizacoes.map((l, i) => (
                    <tr key={i} className="border-b border-slate-50 last:border-0">
                      <td className="py-2 font-medium text-slate-800">{l.endereco}</td>
                      <td className="py-2 text-right font-semibold text-slate-800">{l.quantidade}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
