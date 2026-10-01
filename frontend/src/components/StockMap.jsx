import { useEffect, useMemo, useState } from "react";
import { fetchLocations } from "../services/api";
import { useAuth } from "../AuthContext";
import LocationModal from "./LocationModal";
import useAutoRefresh from "../hooks/useAutoRefresh";
import AddressManager from "./AddressManager";
import BuscaProduto from "./BuscaProduto";

const LIMITE_BAIXO = 10;

/** Classes de cor da célula (gradiente) conforme a quantidade. */
function corDaCelula(quantidade) {
  if (quantidade <= 0) return "bg-gradient-to-br from-rose-400 to-red-500 text-white";
  if (quantidade < LIMITE_BAIXO) return "bg-gradient-to-br from-amber-300 to-amber-400 text-amber-900";
  return "bg-gradient-to-br from-emerald-400 to-green-500 text-white";
}

/** Extrai nível (número) e lado (A/B) da posição "1A", "2B"... */
function parsePosicao(esteira) {
  const m = String(esteira || "").match(/(\d+)\s*([A-Za-z]?)/);
  return { nivel: m ? parseInt(m[1], 10) : 0, lado: m && m[2] ? m[2].toUpperCase() : "" };
}

/** Ordem "humana": A2 antes de A10, Rua 2 antes de Rua 10. */
const natural = (a, b) => String(a).localeCompare(String(b), "pt-BR", { numeric: true, sensitivity: "base" });

export default function StockMap() {
  const { hasPerm, deposito } = useAuth();
  // Ordem das posições é por CD: Goiânia 1A, 1B, 2A... / São Paulo A1, A2, A3, B1...
  const alfabetica = deposito?.ordem_posicoes === "alfabetica";
  const podeGerenciar = hasPerm("gerenciar_enderecos");
  const podeExcluir = hasPerm("excluir_enderecos");
  const [locations, setLocations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [erro, setErro] = useState(null);
  const [selecionado, setSelecionado] = useState(null);
  const [gerenciar, setGerenciar] = useState(false);
  // Produto buscado: o mapa destaca os endereços onde ele está.
  const [destaque, setDestaque] = useState(null);
  const locsDestaque = useMemo(
    () => new Map((destaque?.localizacoes ?? []).map((l) => [l.location_id, l.quantidade])),
    [destaque]
  );

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

  // Atualiza os saldos sozinho (movimentações/baixas de outros usuários).
  // Pausa com um popup aberto, pra não mexer no que o operador está vendo.
  useAutoRefresh(async () => {
    setLocations(await fetchLocations());
  }, 30000, !selecionado && !gerenciar);

  const posicoes = useMemo(() => {
    const set = new Set();
    locations.forEach((l) => l.esteira && set.add(l.esteira));
    if (alfabetica) return Array.from(set).sort(natural);
    return Array.from(set).sort((a, b) => {
      const pa = parsePosicao(a), pb = parsePosicao(b);
      return pa.nivel - pb.nivel || pa.lado.localeCompare(pb.lado);
    });
  }, [locations, alfabetica]);

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
    const lista = Array.from(grupos.values());
    if (alfabetica) return lista.sort((a, b) => natural(a.time, b.time));
    return lista.sort((a, b) => a.ordem - b.ordem || a.time.localeCompare(b.time));
  }, [locations, alfabetica]);

  // Só bloqueia a tela na PRIMEIRA carga; recargas mantêm o mapa (e o modal aberto).
  if (loading && locations.length === 0)
    return <div className="flex h-64 items-center justify-center text-slate-400">Carregando mapa...</div>;
  if (erro)
    return (
      <div className="mx-auto mt-10 flex max-w-md flex-col items-center gap-3 card-nuvem p-8 text-center">
        <p className="text-rose-600">{erro}</p>
        <button onClick={carregar} className="btn-nuvem">Tentar novamente</button>
      </div>
    );

  return (
    <div className="mx-auto max-w-6xl px-4">
      <div className="mb-4 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="text-xl font-extrabold text-slate-800">Mapa do Armazém</h2>
          <p className="text-sm text-slate-400">{times.length} {alfabetica ? "ruas" : "times"} · {locations.length} endereços</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <BuscaProduto onSelecionar={setDestaque} placeholder="Buscar produto no mapa (código ou descrição)" alinhar="right" />
          <Legenda />
          {(podeGerenciar || podeExcluir) && (
            <button onClick={() => setGerenciar(true)} className="btn-nuvem">
              Gerenciar endereços
            </button>
          )}
        </div>
      </div>

      {destaque && (
        <div className="card-nuvem mb-4 flex flex-wrap items-center justify-between gap-3 border border-indigo-200 p-4">
          <div className="min-w-0">
            <div className="truncate font-bold text-slate-800">{destaque.nome}</div>
            <div className="font-mono text-xs text-slate-400">
              cód. {destaque.codigo_microvix} · {destaque.total} un em {destaque.localizacoes.length} endereço(s)
            </div>
            <div className="mt-2 flex flex-wrap gap-1.5">
              {destaque.localizacoes.map((l) => (
                <button
                  key={l.location_id}
                  onClick={() => {
                    const loc = locations.find((x) => x.id === l.location_id);
                    if (loc) setSelecionado(loc);
                  }}
                  className="rounded-full bg-gradient-to-r from-sky-500 to-indigo-500 px-3 py-1 text-xs font-bold text-white shadow-md shadow-indigo-500/30 transition hover:-translate-y-0.5"
                >
                  {l.endereco} · {l.quantidade}
                </button>
              ))}
              {destaque.localizacoes.length === 0 && <span className="text-xs text-slate-400">sem saldo em nenhum endereço</span>}
            </div>
          </div>
          <button onClick={() => setDestaque(null)} className="btn-ghost">Limpar busca</button>
        </div>
      )}

      <div className="card-nuvem overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="bg-slate-50/80 text-slate-500 backdrop-blur">
                <th className="sticky left-0 z-20 bg-slate-50/95 px-4 py-3 text-left font-semibold backdrop-blur">
                  {alfabetica ? "Rua" : "Time"}
                </th>
                {posicoes.map((p) => (
                  <th key={p} className="min-w-[58px] px-2 py-3 text-center text-xs font-bold uppercase tracking-wide">
                    {p}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {times.map((t) => (
                <tr key={t.time} className="group border-t border-slate-100 transition hover:bg-indigo-50/40">
                  <th className="sticky left-0 z-10 whitespace-nowrap bg-white/95 px-4 py-2 text-left backdrop-blur group-hover:bg-indigo-50/60">
                    <span className="font-bold text-slate-800">{t.time}</span>
                    <span className="ml-2 text-xs font-medium text-slate-400">{t.total} un.</span>
                  </th>
                  {posicoes.map((p) => {
                    const loc = t.porPos[p];
                    if (!loc)
                      return <td key={p} className="px-1 py-1"><div className="h-9 rounded-lg border border-dashed border-slate-100" /></td>;
                    const q = Number(loc.total_quantidade || 0);
                    const achado = destaque && locsDestaque.has(loc.id);
                    return (
                      <td key={p} className="px-1 py-1">
                        <button
                          onClick={() => setSelecionado(loc)}
                          title={achado ? `${loc.nome} — ${locsDestaque.get(loc.id)} un. de ${destaque.nome}` : `${loc.nome} — ${q} un.`}
                          className={`h-9 w-full rounded-lg text-sm font-bold shadow-sm transition hover:scale-[1.04] hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-300 ${
                            achado
                              ? "scale-[1.06] bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/40 ring-2 ring-indigo-300"
                              : `${corDaCelula(q)} ${destaque ? "opacity-25" : ""}`
                          }`}
                        >
                          {achado ? locsDestaque.get(loc.id) : q}
                        </button>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {selecionado && (
        <LocationModal
          location={selecionado}
          onClose={() => setSelecionado(null)}
          onChanged={carregar}
          podeGerenciar={podeGerenciar}
          podeExcluir={podeExcluir}
        />
      )}

      {gerenciar && (
        <AddressManager
          times={times.map((t) => t.time)}
          alfabetica={alfabetica}
          enderecos={locations}
          podeGerenciar={podeGerenciar}
          podeExcluir={podeExcluir}
          onClose={() => setGerenciar(false)}
          onCreated={carregar}
        />
      )}
    </div>
  );
}

function Legenda() {
  const itens = [
    { cor: "from-rose-400 to-red-500", texto: "Vazio (0)" },
    { cor: "from-amber-300 to-amber-400", texto: `Baixo (< ${LIMITE_BAIXO})` },
    { cor: "from-emerald-400 to-green-500", texto: `Ok (≥ ${LIMITE_BAIXO})` },
  ];
  return (
    <div className="flex flex-wrap items-center gap-2">
      {itens.map((i) => (
        <span key={i.texto} className="chip">
          <span className={`inline-block h-3 w-3 rounded-full bg-gradient-to-br ${i.cor}`} />
          {i.texto}
        </span>
      ))}
    </div>
  );
}
