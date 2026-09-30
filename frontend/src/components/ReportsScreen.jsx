import { useState } from "react";
import { relatorioProdutoLocalizacao } from "../services/api";
import HistoricoEstoque from "./HistoricoEstoque";

// Lista de relatórios disponíveis (fácil de crescer no futuro).
const RELATORIOS = [
  { id: "produto_localizacao", label: "Produto × Localização" },
  { id: "historico", label: "Histórico de estoque" },
];

export default function ReportsScreen() {
  const [relatorio, setRelatorio] = useState("produto_localizacao");

  return (
    <div className={`mx-auto px-4 ${relatorio === "historico" ? "max-w-6xl" : "max-w-4xl"}`}>
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
      {relatorio === "historico" && <HistoricoEstoque />}
    </div>
  );
}

function ProdutoLocalizacao() {
  const [busca, setBusca] = useState("");
  const [buscado, setBuscado] = useState("");
  const [resultados, setResultados] = useState(null); // encontrados
  const [sugestoes, setSugestoes] = useState([]); // parecidos
  const [escolhida, setEscolhida] = useState(null); // sugestão aberta
  const [loading, setLoading] = useState(false);

  async function buscar(e) {
    e?.preventDefault();
    if (!busca.trim()) return;
    setLoading(true);
    setEscolhida(null);
    try {
      const r = await relatorioProdutoLocalizacao(busca.trim());
      setResultados(r.data);
      setSugestoes(r.sugestoes);
      setBuscado(busca.trim());
    } finally {
      setLoading(false);
    }
  }

  const cards = escolhida ? [escolhida, ...(resultados ?? [])] : resultados ?? [];

  return (
    <div>
      <form onSubmit={buscar} className="card-nuvem mb-5 flex flex-col gap-3 p-5 sm:flex-row">
        <input
          value={busca}
          onChange={(e) => setBusca(e.target.value)}
          placeholder="Código Microvix, código de barras ou descrição (pode ser parte ou com erro)..."
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

      {/* Parecidos: "você quis dizer?" */}
      {resultados !== null && sugestoes.length > 0 && (
        <div className={`card-nuvem mb-5 p-4 ${resultados.length === 0 ? "border border-amber-200 bg-amber-50/60" : ""}`}>
          <div className="mb-2 text-sm font-bold text-slate-700">
            {resultados.length === 0 ? (
              <>Nada exato para “{buscado}”. Você quis dizer:</>
            ) : (
              <>Também parecidos com “{buscado}”:</>
            )}
          </div>
          <div className="flex flex-col gap-1.5">
            {sugestoes
              .filter((p) => p.product_id !== escolhida?.product_id)
              .map((p) => (
                <button
                  key={p.product_id}
                  onClick={() => setEscolhida(p)}
                  className="flex items-center justify-between gap-3 rounded-xl bg-white px-3 py-2 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-slate-800">{p.nome}</span>
                    <span className="font-mono text-xs text-slate-400">cód. {p.codigo_microvix}{p.codigo_barras && ` · barras ${p.codigo_barras}`}</span>
                  </span>
                  <span className="whitespace-nowrap text-xs font-semibold text-indigo-600">{p.total} un · ver</span>
                </button>
              ))}
          </div>
        </div>
      )}

      {resultados && resultados.length === 0 && sugestoes.length === 0 && (
        <p className="py-8 text-center text-sm text-slate-400">Nenhum produto encontrado, nem parecido.</p>
      )}

      <div className="space-y-4">
        {cards.map((p) => (
          <div key={p.product_id} className={`card-nuvem p-5 ${p === escolhida ? "ring-2 ring-amber-300" : ""}`}>
            <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                {p === escolhida && (
                  <span className="mb-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800">sugestão escolhida</span>
                )}
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
