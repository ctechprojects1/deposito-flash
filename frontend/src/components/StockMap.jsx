import { useEffect, useMemo, useState } from "react";
import { fetchLocations } from "../services/api";
import LocationModal from "./LocationModal";

const LIMITE_BAIXO = 10;

/** Cor do bloco conforme a quantidade total no endereço. */
function corDoBloco(quantidade) {
  if (quantidade <= 0) return "bg-red-500 hover:bg-red-600 border-red-700 text-white";
  if (quantidade < LIMITE_BAIXO)
    return "bg-yellow-400 hover:bg-yellow-500 border-yellow-600 text-gray-900";
  return "bg-green-500 hover:bg-green-600 border-green-700 text-white";
}

/** Extrai nível (número) e lado (A/B) da posição "1A", "2B"... */
function parsePosicao(esteira) {
  const m = String(esteira || "").match(/(\d+)\s*([A-Za-z]?)/);
  return {
    nivel: m ? parseInt(m[1], 10) : 0,
    lado: m && m[2] ? m[2].toUpperCase() : "A",
  };
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

  // Agrupa por Time (corredor). Se não houver corredor, cai no nome.
  const times = useMemo(() => {
    const grupos = new Map();
    for (const loc of locations) {
      const time = loc.corredor || loc.nome || "—";
      if (!grupos.has(time)) grupos.set(time, []);
      grupos.get(time).push(loc);
    }
    // Ordena os Times pela menor coordenada x (mantém a ordem da parede).
    return Array.from(grupos.entries())
      .map(([time, locs]) => ({
        time,
        locs,
        ordem: Math.min(...locs.map((l) => l.eixo_x || 9999)),
      }))
      .sort((a, b) => a.ordem - b.ordem || a.time.localeCompare(b.time));
  }, [locations]);

  if (loading) {
    return <div className="flex h-64 items-center justify-center text-gray-500">Carregando mapa...</div>;
  }
  if (erro) {
    return (
      <div className="flex h-64 flex-col items-center justify-center gap-3">
        <p className="text-red-600">{erro}</p>
        <button onClick={carregar} className="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
          Tentar novamente
        </button>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl p-4">
      <header className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Mapa do Armazém</h1>
          <p className="text-sm text-gray-500">{times.length} times · {locations.length} endereços</p>
        </div>
        <Legenda />
      </header>

      {/* Times em cartões que fluem verticalmente (responsivo). */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {times.map(({ time, locs }) => (
          <CartaoTime key={time} time={time} locs={locs} onSelecionar={setSelecionado} />
        ))}
      </div>

      {selecionado && (
        <LocationModal location={selecionado} onClose={() => setSelecionado(null)} />
      )}
    </div>
  );
}

/** Cartão de um Time com sua mini-grade de posições (A | B por nível). */
function CartaoTime({ time, locs, onSelecionar }) {
  // Indexa por "nivel-lado" e descobre níveis presentes.
  const porChave = new Map();
  const niveis = new Set();
  let temB = false;
  for (const l of locs) {
    const { nivel, lado } = parsePosicao(l.esteira);
    porChave.set(`${nivel}-${lado}`, l);
    niveis.add(nivel);
    if (lado === "B") temB = true;
  }
  const niveisOrd = Array.from(niveis).sort((a, b) => a - b);
  const lados = temB ? ["A", "B"] : ["A"];

  const totalTime = locs.reduce((s, l) => s + Number(l.total_quantidade || 0), 0);

  return (
    <div className="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
      <div className="mb-2 flex items-baseline justify-between">
        <h3 className="font-bold text-gray-800">{time}</h3>
        <span className="text-xs text-gray-400">{totalTime} un.</span>
      </div>

      <div className="space-y-1.5">
        {niveisOrd.map((nivel) => (
          <div key={nivel} className="flex gap-1.5">
            {lados.map((lado) => {
              const loc = porChave.get(`${nivel}-${lado}`);
              if (!loc) {
                return <div key={lado} className="h-12 flex-1 rounded-lg border border-dashed border-gray-200" />;
              }
              const q = Number(loc.total_quantidade || 0);
              return (
                <button
                  key={lado}
                  onClick={() => onSelecionar(loc)}
                  title={`${loc.nome} — ${q} un.`}
                  className={`flex h-12 flex-1 flex-col items-center justify-center rounded-lg border-2 text-center transition hover:scale-[1.03] focus:outline-none focus:ring-2 focus:ring-blue-400 ${corDoBloco(q)}`}
                >
                  <span className="text-xs font-bold leading-none">{loc.esteira}</span>
                  <span className="mt-0.5 text-[10px] leading-none opacity-90">{q}</span>
                </button>
              );
            })}
          </div>
        ))}
      </div>
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
