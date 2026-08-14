import { useEffect, useMemo, useState } from "react";
import { fetchLocations } from "../services/api";
import LocationModal from "./LocationModal";

const LIMITE_BAIXO = 10;

/** Classes de cor da célula conforme a quantidade. */
function corDaCelula(quantidade) {
  if (quantidade <= 0) return "bg-red-500 hover:bg-red-600 text-white";
  if (quantidade < LIMITE_BAIXO) return "bg-yellow-400 hover:bg-yellow-500 text-gray-900";
  return "bg-green-500 hover:bg-green-600 text-white";
}

/** Extrai nível (número) e lado (A/B) da posição "1A", "2B"... */
function parsePosicao(esteira) {
  const m = String(esteira || "").match(/(\d+)\s*([A-Za-z]?)/);
  return { nivel: m ? parseInt(m[1], 10) : 0, lado: m && m[2] ? m[2].toUpperCase() : "" };
}

export default function StockMap() {
  const [locations, setLocations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [erro, setErro] = useState(null);
  const [selecionado, setSelecionado] = useState(null);

  async function carregar() {
    try {
      setLoading(true);
      setErro(null);
      setLocations(await fetchLocations());
    } catch (e) {
      setErro("Não foi possível carregar o mapa. Verifique a API.");
      console.error(e);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    carregar();
  }, []);

  // Colunas = todas as posições distintas, ordenadas por nível e lado.
  const posicoes = useMemo(() => {
    const set = new Set();
    locations.forEach((l) => l.esteira && set.add(l.esteira));
    return Array.from(set).sort((a, b) => {
      const pa = parsePosicao(a), pb = parsePosicao(b);
      return pa.nivel - pb.nivel || pa.lado.localeCompare(pb.lado);
    });
  }, [locations]);

  // Linhas = Times (corredor), cada um com um mapa posição -> endereço.
  const times = useMemo(() => {
    const grupos = new Map();
    for (const loc of locations) {
      const time = loc.corredor || loc.nome || "—";
      if (!grupos.has(time)) grupos.set(time, { time, porPos: {}, ordem: loc.eixo_x || 9999, total: 0 });
      const g = grupos.get(time);
      g.porPos[loc.esteira] = loc;
      g.total += Number(loc.total_quantidade || 0);
      g.ordem = Math.min(g.ordem, loc.eixo_x || 9999);
    }
    return Array.from(grupos.values()).sort((a, b) => a.ordem - b.ordem || a.time.localeCompare(b.time));
  }, [locations]);

  if (loading) return <div className="flex h-64 items-center justify-center text-gray-500">Carregando mapa...</div>;
  if (erro)
    return (
      <div className="flex h-64 flex-col items-center justify-center gap-3">
        <p className="text-red-600">{erro}</p>
        <button onClick={carregar} className="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">Tentar novamente</button>
      </div>
    );

  return (
    <div className="mx-auto max-w-full p-4">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Mapa do Armazém</h1>
          <p className="text-sm text-gray-500">{times.length} times · {locations.length} endereços</p>
        </div>
        <Legenda />
      </header>

      {/* Tabela: Times nas linhas, posições nas colunas. Rola na horizontal se precisar. */}
      <div className="overflow-x-auto rounded-xl border border-gray-200">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="bg-gray-100">
              <th className="sticky left-0 z-10 bg-gray-100 px-3 py-2 text-left font-semibold text-gray-700">
                Time
              </th>
              {posicoes.map((p) => (
                <th key={p} className="min-w-[56px] px-2 py-2 text-center font-semibold text-gray-600">
                  {p}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {times.map((t) => (
              <tr key={t.time} className="border-t border-gray-100">
                <th className="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-1.5 text-left font-semibold text-gray-800">
                  {t.time}
                  <span className="ml-2 text-xs font-normal text-gray-400">{t.total} un.</span>
                </th>
                {posicoes.map((p) => {
                  const loc = t.porPos[p];
                  if (!loc) return <td key={p} className="border-l border-gray-100 bg-gray-50/50" />;
                  const q = Number(loc.total_quantidade || 0);
                  return (
                    <td key={p} className="border-l border-gray-100 p-0.5">
                      <button
                        onClick={() => setSelecionado(loc)}
                        title={`${loc.nome} — ${q} un.`}
                        className={`h-9 w-full rounded font-semibold transition focus:outline-none focus:ring-2 focus:ring-blue-400 ${corDaCelula(q)}`}
                      >
                        {q}
                      </button>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {selecionado && <LocationModal location={selecionado} onClose={() => setSelecionado(null)} />}
    </div>
  );
}

function Legenda() {
  const itens = [
    { cor: "bg-red-500", texto: "Vazio (0)" },
    { cor: "bg-yellow-400", texto: `Baixo (< ${LIMITE_BAIXO})` },
    { cor: "bg-green-500", texto: `Ok (≥ ${LIMITE_BAIXO})` },
  ];
  return (
    <div className="flex items-center gap-4 text-sm text-gray-600">
      {itens.map((i) => (
        <span key={i.texto} className="flex items-center gap-1.5">
          <span className={`inline-block h-3 w-3 rounded ${i.cor}`} />
          {i.texto}
        </span>
      ))}
    </div>
  );
}
