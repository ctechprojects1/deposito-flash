import { useEffect, useState } from "react";
import { criarEnderecos, zerarEstoqueGeral, statusMicrovix, sincronizarMicrovix } from "../services/api";

// Posições comuns (níveis 1-5, lados A/B). Chips em vez de checkbox.
const PRESETS = [];
for (let n = 1; n <= 5; n++) for (const s of ["A", "B"]) PRESETS.push(`${n}${s}`);

/**
 * Popup para criar novos Times (colunas) e novas posições/linhas (1A, 1B...).
 * Se o Time já existir, apenas adiciona as posições novas.
 */
export default function AddressManager({ times = [], onClose, onCreated }) {
  const [time, setTime] = useState("");
  const [selecionadas, setSelecionadas] = useState([]);
  const [custom, setCustom] = useState("");
  const [salvando, setSalvando] = useState(false);
  const [msg, setMsg] = useState(null);
  const [erro, setErro] = useState(null);
  const [confirmGeral, setConfirmGeral] = useState("");
  const [zerandoGeral, setZerandoGeral] = useState(false);

  // Base de códigos de barras do Microvix
  const [mvx, setMvx] = useState(null);
  const [syncMvx, setSyncMvx] = useState(null);

  async function carregarStatusMvx() {
    try {
      setMvx(await statusMicrovix());
    } catch {
      setMvx(null);
    }
  }

  useEffect(() => {
    carregarStatusMvx();
  }, []);

  async function sincronizarMvx() {
    setErro(null);
    setSyncMvx("Sincronizando...");
    try {
      for (let i = 0; i < 40; i++) {
        const r = await sincronizarMicrovix();
        setSyncMvx(`Sincronizando... ${Number(r.total).toLocaleString("pt-BR")} códigos`);
        if (r.concluido) break;
      }
      await carregarStatusMvx();
    } catch (e) {
      setErro(e?.response?.data?.message || "Falha ao sincronizar com o Microvix.");
    } finally {
      setSyncMvx(null);
    }
  }

  async function zerarGeral() {
    if (confirmGeral.trim().toUpperCase() !== "ZERAR") return;
    setErro(null);
    setMsg(null);
    setZerandoGeral(true);
    try {
      const r = await zerarEstoqueGeral();
      setMsg(r.message);
      setConfirmGeral("");
      onCreated?.(); // recarrega o mapa
    } catch (e) {
      setErro(e?.response?.data?.message || "Erro ao zerar o estoque geral.");
    } finally {
      setZerandoGeral(false);
    }
  }

  useEffect(() => {
    function onKey(e) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const existe = times.some((t) => t.toLowerCase() === time.trim().toLowerCase());

  function toggle(p) {
    setSelecionadas((s) => (s.includes(p) ? s.filter((x) => x !== p) : [...s, p]));
  }

  function addCustom() {
    const p = custom.trim().toUpperCase();
    if (p && !selecionadas.includes(p) && !PRESETS.includes(p)) {
      setSelecionadas((s) => [...s, p]);
    }
    setCustom("");
  }

  async function criar() {
    setErro(null);
    setMsg(null);
    if (!time.trim()) return setErro("Informe o nome do Time.");
    if (selecionadas.length === 0) return setErro("Selecione ao menos uma posição.");

    setSalvando(true);
    try {
      const r = await criarEnderecos(time.trim(), selecionadas);
      setMsg(r.message);
      setSelecionadas([]);
      onCreated?.();
    } catch (e) {
      setErro(e?.response?.data?.message || "Erro ao criar endereços.");
    } finally {
      setSalvando(false);
    }
  }

  const customExtras = selecionadas.filter((p) => !PRESETS.includes(p));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="card-nuvem w-full max-w-lg overflow-hidden" onClick={(e) => e.stopPropagation()}>
        {/* Cabeçalho */}
        <div className="flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-sky-50 to-indigo-50 p-5">
          <div>
            <h2 className="text-xl font-extrabold text-slate-800">Gerenciar Endereços</h2>
            <p className="text-sm text-slate-500">Crie um Time novo ou adicione posições a um existente.</p>
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700" aria-label="Fechar">
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="max-h-[70vh] space-y-5 overflow-y-auto p-5">
          {erro && <div className="rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}
          {msg && <div className="rounded-xl bg-emerald-100 p-3 text-sm text-emerald-800">{msg}</div>}

          {/* Time */}
          <div>
            <label className="mb-1 block text-sm font-semibold text-slate-700">Time (coluna)</label>
            <input
              list="times-existentes"
              value={time}
              onChange={(e) => setTime(e.target.value)}
              placeholder="Ex.: SANTOS (novo) ou escolha um existente"
              className="input-nuvem"
            />
            <datalist id="times-existentes">
              {times.map((t) => (
                <option key={t} value={t} />
              ))}
            </datalist>
            <p className="mt-1 text-xs text-slate-400">
              {time.trim() === ""
                ? " "
                : existe
                ? "Time existente — as posições novas serão adicionadas a ele."
                : "Time novo — será criado."}
            </p>
          </div>

          {/* Posições */}
          <div>
            <label className="mb-2 block text-sm font-semibold text-slate-700">Posições (linhas)</label>
            <div className="flex flex-wrap gap-2">
              {PRESETS.map((p) => {
                const on = selecionadas.includes(p);
                return (
                  <button
                    key={p}
                    type="button"
                    onClick={() => toggle(p)}
                    className={`rounded-full px-3.5 py-1.5 text-sm font-semibold transition ${
                      on
                        ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-md shadow-indigo-500/30"
                        : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                    }`}
                  >
                    {p}
                  </button>
                );
              })}
              {customExtras.map((p) => (
                <button
                  key={p}
                  type="button"
                  onClick={() => toggle(p)}
                  className="rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 px-3.5 py-1.5 text-sm font-semibold text-white shadow-md"
                >
                  {p} ✕
                </button>
              ))}
            </div>

            {/* Posição customizada */}
            <div className="mt-3 flex gap-2">
              <input
                value={custom}
                onChange={(e) => setCustom(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && (e.preventDefault(), addCustom())}
                placeholder="Outra posição (ex.: 6A)"
                className="input-nuvem"
              />
              <button type="button" onClick={addCustom} className="btn-ghost whitespace-nowrap">
                Adicionar
              </button>
            </div>
          </div>

          {/* Base de códigos de barras do Microvix */}
          <div className="rounded-xl border border-sky-100 bg-sky-50/60 p-4">
            <h3 className="text-sm font-bold text-slate-700">Microvix — códigos de barras</h3>
            <p className="mt-1 text-xs text-slate-600">
              {mvx
                ? mvx.total > 0
                  ? `${Number(mvx.total).toLocaleString("pt-BR")} códigos na base · atualizada em ${mvx.ultima_sync}`
                  : "Base ainda não sincronizada. Sincronize antes de bipar produtos."
                : "Carregando..."}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              A busca já atualiza sozinha quando um código não é encontrado. Use o botão para forçar.
            </p>
            <button type="button" onClick={sincronizarMvx} disabled={!!syncMvx} className="btn-ghost mt-3">
              {syncMvx || "Sincronizar agora"}
            </button>
          </div>

          {/* Zona de perigo — zerar estoque geral */}
          <div className="rounded-xl border border-rose-200 bg-rose-50/60 p-4">
            <h3 className="text-sm font-bold text-rose-700">Zona de perigo</h3>
            <p className="mt-1 text-xs text-rose-600">
              Zerar o estoque geral coloca a quantidade de <strong>todos os endereços</strong> em 0.
              Não pode ser desfeito. Para confirmar, digite <strong>ZERAR</strong> abaixo.
            </p>
            <div className="mt-3 flex gap-2">
              <input
                value={confirmGeral}
                onChange={(e) => setConfirmGeral(e.target.value)}
                placeholder="Digite ZERAR"
                className="input-nuvem"
              />
              <button
                type="button"
                onClick={zerarGeral}
                disabled={zerandoGeral || confirmGeral.trim().toUpperCase() !== "ZERAR"}
                className="inline-flex items-center whitespace-nowrap rounded-full bg-gradient-to-r from-rose-500 to-red-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-rose-500/30 transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:from-slate-300 disabled:to-slate-300 disabled:shadow-none disabled:translate-y-0"
              >
                {zerandoGeral ? "Zerando..." : "Zerar estoque geral"}
              </button>
            </div>
          </div>
        </div>

        {/* Rodapé */}
        <div className="flex items-center justify-between border-t border-slate-100 bg-slate-50/70 px-5 py-3">
          <span className="text-sm text-slate-500">{selecionadas.length} posição(ões) selecionada(s)</span>
          <button onClick={criar} disabled={salvando} className="btn-nuvem">
            {salvando ? "Criando..." : "Criar endereços"}
          </button>
        </div>
      </div>
    </div>
  );
}
