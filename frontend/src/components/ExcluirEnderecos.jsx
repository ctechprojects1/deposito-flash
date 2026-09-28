import { useMemo, useState } from "react";
import { excluirEnderecos } from "../services/api";

/**
 * Seção do "Gerenciar endereços": escolhe o Time, marca as posições
 * (ou o Time inteiro) e exclui. Só endereço vazio pode ser excluído.
 */
export default function ExcluirEnderecos({ enderecos = [], onExcluidos }) {
  const [time, setTime] = useState("");
  const [marcados, setMarcados] = useState([]);
  const [excluindo, setExcluindo] = useState(false);
  const [resultado, setResultado] = useState(null); // { ok, msg, bloqueados }

  const times = useMemo(() => {
    const set = new Set(enderecos.map((e) => e.corredor || e.nome));
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [enderecos]);

  const doTime = useMemo(
    () =>
      enderecos
        .filter((e) => (e.corredor || e.nome) === time)
        .sort((a, b) => String(a.esteira).localeCompare(String(b.esteira), undefined, { numeric: true })),
    [enderecos, time]
  );

  function escolherTime(t) {
    setTime(t === time ? "" : t);
    setMarcados([]);
    setResultado(null);
  }

  function toggle(id) {
    setMarcados((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
  }

  const todos = doTime.length > 0 && marcados.length === doTime.length;

  async function excluir() {
    const nomes = doTime.filter((e) => marcados.includes(e.id)).map((e) => e.nome);
    if (!window.confirm(`Excluir ${nomes.length} endereço(s)?\n\n${nomes.join(", ")}\n\nEles somem do mapa. O histórico continua guardado.`)) return;

    setExcluindo(true);
    setResultado(null);
    try {
      const r = await excluirEnderecos(marcados);
      setResultado({ ok: true, msg: r.message, bloqueados: r.bloqueados });
      setMarcados([]);
      onExcluidos?.();
    } catch (e) {
      const d = e?.response?.data;
      setResultado({ ok: false, msg: d?.message || "Erro ao excluir.", bloqueados: d?.bloqueados ?? [] });
    } finally {
      setExcluindo(false);
    }
  }

  return (
    <div className="rounded-xl border border-rose-200 bg-rose-50/60 p-4">
      <h3 className="text-sm font-bold text-rose-700">Excluir endereços</h3>
      <p className="mt-1 text-xs text-rose-600">
        Só dá para excluir endereço <strong>vazio</strong> e que não esteja em solicitação ou contagem em aberto.
      </p>

      {times.length === 0 ? (
        <p className="mt-3 text-xs text-slate-500">Nenhum endereço cadastrado.</p>
      ) : (
        <>
          <div className="mt-3 flex flex-wrap gap-1.5">
            {times.map((t) => (
              <button
                key={t}
                type="button"
                onClick={() => escolherTime(t)}
                className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                  time === t ? "bg-gradient-to-r from-rose-500 to-red-600 text-white shadow-md shadow-rose-500/30" : "bg-white text-slate-600 shadow-sm hover:bg-rose-50"
                }`}
              >
                {t}
              </button>
            ))}
          </div>

          {time && (
            <div className="mt-3">
              <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-600">Posições de {time}</span>
                <button
                  type="button"
                  onClick={() => setMarcados(todos ? [] : doTime.map((e) => e.id))}
                  className="text-xs font-semibold text-rose-600 hover:underline"
                >
                  {todos ? "Desmarcar todas" : "Marcar o Time inteiro"}
                </button>
              </div>
              <div className="flex flex-wrap gap-1.5">
                {doTime.map((e) => {
                  const on = marcados.includes(e.id);
                  const qtd = Number(e.total_quantidade || 0);
                  return (
                    <button
                      key={e.id}
                      type="button"
                      onClick={() => toggle(e.id)}
                      title={qtd > 0 ? `${qtd} un. em estoque` : "vazio"}
                      className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                        on ? "bg-rose-600 text-white shadow-md shadow-rose-500/30" : "bg-white text-slate-600 shadow-sm hover:bg-rose-50"
                      }`}
                    >
                      {e.esteira || e.nome}
                      {qtd > 0 && <span className={on ? "ml-1 text-rose-100" : "ml-1 text-amber-600"}>· {qtd} un</span>}
                    </button>
                  );
                })}
              </div>

              <button
                type="button"
                onClick={excluir}
                disabled={excluindo || marcados.length === 0}
                className="mt-3 inline-flex items-center rounded-full bg-gradient-to-r from-rose-500 to-red-600 px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-rose-500/30 transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:from-slate-300 disabled:to-slate-300 disabled:shadow-none disabled:translate-y-0"
              >
                {excluindo ? "Excluindo..." : `Excluir ${marcados.length || ""} endereço(s)`}
              </button>
            </div>
          )}
        </>
      )}

      {resultado && (
        <div className={`mt-3 rounded-xl p-3 text-xs ${resultado.ok ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"}`}>
          {resultado.msg}
          {resultado.bloqueados?.length > 0 && (
            <ul className="mt-1 list-disc pl-4">
              {resultado.bloqueados.map((b) => (
                <li key={b.id}>
                  <strong>{b.nome}</strong>: {b.motivo}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
