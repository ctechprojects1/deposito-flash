import { useEffect, useMemo, useState } from "react";
import { fetchLocations } from "../services/api";
import LocationModal from "./LocationModal";

/**
 * Limiares de estoque para a cor do bloco.
 * A métrica usada é a quantidade total guardada no local.
 */
const LIMITE_BAIXO = 10;

/**
 * Retorna as classes Tailwind do bloco conforme a quantidade total:
 *   0            -> Vermelho (vazio)
 *   1 a 10       -> Amarelo  (estoque baixo)
 *   acima de 10  -> Verde    (estoque ok)
 */
function corDoBloco(quantidade) {
  if (quantidade <= 0) {
    return "bg-red-500 hover:bg-red-600 border-red-700";
  }
  if (quantidade < LIMITE_BAIXO) {
    return "bg-yellow-400 hover:bg-yellow-500 border-yellow-600 text-gray-900";
  }
  return "bg-green-500 hover:bg-green-600 border-green-700";
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
      const dados = await fetchLocations();
      setLocations(dados);
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

  // Descobre o tamanho do grid a partir das coordenadas dos locais.
  const { colunas, linhas } = useMemo(() => {
    const maxX = Math.max(1, ...locations.map((l) => l.eixo_x || 0));
    const maxY = Math.max(1, ...locations.map((l) => l.eixo_y || 0));
    return { colunas: maxX, linhas: maxY };
  }, [locations]);

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center text-gray-500">
        Carregando mapa do armazém...
      </div>
    );
  }

  if (erro) {
    return (
      <div className="flex h-64 flex-col items-center justify-center gap-3">
        <p className="text-red-600">{erro}</p>
        <button
          onClick={carregar}
          className="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700"
        >
          Tentar novamente
        </button>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl p-4">
      <header className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-2xl font-bold text-gray-800">
          Mapa do Armazém
        </h1>
        <Legenda />
      </header>

      {/* Planta do armazém em CSS Grid.
          Cada bloco é posicionado por eixo_x (coluna) e eixo_y (linha). */}
      <div
        className="grid gap-3 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-4"
        style={{
          gridTemplateColumns: `repeat(${colunas}, minmax(90px, 1fr))`,
          gridTemplateRows: `repeat(${linhas}, minmax(90px, auto))`,
        }}
      >
        {locations.map((local) => {
          const quantidade = Number(local.total_quantidade ?? 0);
          return (
            <button
              key={local.id}
              onClick={() => setSelecionado(local)}
              title={`${local.nome} — ${quantidade} un.`}
              style={{
                // Se o local tem coordenadas, posiciona no grid; senão fluxo normal.
                gridColumn: local.eixo_x ? local.eixo_x : "auto",
                gridRow: local.eixo_y ? local.eixo_y : "auto",
              }}
              className={`flex flex-col items-center justify-center rounded-lg border-2 p-2 text-center font-semibold text-white shadow-sm transition-transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-blue-400 ${corDoBloco(
                quantidade
              )}`}
            >
              <span className="text-sm leading-tight">{local.nome}</span>
              <span className="mt-1 text-xs opacity-90">
                {local.total_itens} item(s)
              </span>
              <span className="text-xs opacity-90">{quantidade} un.</span>
            </button>
          );
        })}
      </div>

      {selecionado && (
        <LocationModal
          location={selecionado}
          onClose={() => setSelecionado(null)}
        />
      )}
    </div>
  );
}

/**
 * Legenda das cores.
 */
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
