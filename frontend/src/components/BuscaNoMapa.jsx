import { useEffect, useRef, useState } from "react";
import { buscarProdutosMapa } from "../services/api";

/**
 * Caixa de busca do mapa: digita código ou parte da descrição (pode ter erro)
 * e escolhe o produto; o mapa destaca os endereços onde ele está.
 * Mostra os encontrados e, abaixo, os parecidos ("você quis dizer?").
 */
export default function BuscaNoMapa({ onSelecionar }) {
  const [termo, setTermo] = useState("");
  const [res, setRes] = useState(null); // { data, sugestoes }
  const [aberto, setAberto] = useState(false);
  const [buscando, setBuscando] = useState(false);
  const caixa = useRef(null);
  const seq = useRef(0);

  // Busca enquanto digita (espera o operador parar de digitar).
  useEffect(() => {
    const t = termo.trim();
    if (t.length < 2) {
      setRes(null);
      return undefined;
    }
    const id = ++seq.current;
    const timer = setTimeout(async () => {
      setBuscando(true);
      try {
        const r = await buscarProdutosMapa(t);
        if (id === seq.current) {
          setRes(r);
          setAberto(true);
        }
      } finally {
        if (id === seq.current) setBuscando(false);
      }
    }, 350);
    return () => clearTimeout(timer);
  }, [termo]);

  // Fecha ao clicar fora.
  useEffect(() => {
    function fora(e) {
      if (caixa.current && !caixa.current.contains(e.target)) setAberto(false);
    }
    document.addEventListener("mousedown", fora);
    return () => document.removeEventListener("mousedown", fora);
  }, []);

  function escolher(p) {
    onSelecionar(p);
    setAberto(false);
    setTermo("");
    setRes(null);
  }

  const vazio = res && res.data.length === 0 && res.sugestoes.length === 0;

  return (
    <div ref={caixa} className="relative w-full sm:w-80">
      <input
        value={termo}
        onChange={(e) => setTermo(e.target.value)}
        onFocus={() => res && setAberto(true)}
        onKeyDown={(e) => {
          if (e.key === "Enter" && res?.data.length === 1) escolher(res.data[0]);
          if (e.key === "Escape") setAberto(false);
        }}
        placeholder="Buscar produto no mapa (código ou descrição)"
        className="input-nuvem pr-9"
      />
      {buscando && (
        <span className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-indigo-200 border-t-indigo-500" />
      )}

      {aberto && res && (
        <div className="card-nuvem absolute right-0 z-40 mt-2 max-h-96 w-full min-w-[20rem] overflow-y-auto p-2 shadow-xl">
          {res.data.map((p) => (
            <Opcao key={p.product_id} p={p} onClick={() => escolher(p)} />
          ))}

          {res.sugestoes.length > 0 && (
            <>
              <div className={`px-2 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide ${res.data.length ? "text-slate-400" : "text-amber-700"}`}>
                {res.data.length ? "Parecidos" : "Nada exato. Você quis dizer:"}
              </div>
              {res.sugestoes.map((p) => (
                <Opcao key={p.product_id} p={p} parecido onClick={() => escolher(p)} />
              ))}
            </>
          )}

          {vazio && <div className="px-3 py-4 text-center text-sm text-slate-400">Nenhum produto encontrado neste CD, nem parecido.</div>}
        </div>
      )}
    </div>
  );
}

function Opcao({ p, parecido = false, onClick }) {
  return (
    <button
      onClick={onClick}
      className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-indigo-50 ${parecido ? "opacity-90" : ""}`}
    >
      <span className="min-w-0">
        <span className="block truncate text-sm font-semibold text-slate-800">{p.nome}</span>
        <span className="font-mono text-xs text-slate-400">cód. {p.codigo_microvix}</span>
      </span>
      <span className="whitespace-nowrap text-right text-xs">
        <span className="block font-bold text-indigo-600">{p.total} un</span>
        <span className="text-slate-400">{p.localizacoes.length} endereço(s)</span>
      </span>
    </button>
  );
}
