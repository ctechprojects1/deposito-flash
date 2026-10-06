import { useEffect, useState } from "react";
import { importarCatalogo, statusCatalogo } from "../services/api";
import { lerPlanilhaCatalogo } from "../catalogoPlanilha";

const FONTES = [
  { id: "shopee", rotulo: "Shopee" },
  { id: "tiktok", rotulo: "TikTok" },
  { id: "planilha", rotulo: "Outra planilha" },
];

/**
 * Seção do "Gerenciar endereços": importa planilhas de produtos (Shopee etc.)
 * como base de apoio para achar itens que não estão no Microvix.
 */
export default function BaseApoio() {
  const [status, setStatus] = useState(null);
  const [fonte, setFonte] = useState("shopee");
  const [arquivo, setArquivo] = useState(null);
  const [progresso, setProgresso] = useState(null); // texto da tela de espera
  const [msg, setMsg] = useState(null);
  const [erro, setErro] = useState(null);

  async function carregar() {
    try {
      setStatus(await statusCatalogo());
    } catch {
      setStatus([]);
    }
  }

  useEffect(() => {
    carregar();
  }, []);

  async function importar() {
    if (!arquivo) return;
    setErro(null);
    setMsg(null);
    setProgresso("Lendo a planilha...");
    try {
      const itens = await lerPlanilhaCatalogo(arquivo);
      if (itens.length === 0) throw new Error("Nenhum produto com SKU ou EAN encontrado na planilha.");
      const LOTE = 1000;
      for (let i = 0; i < itens.length; i += LOTE) {
        setProgresso(`Enviando ${Math.min(i + LOTE, itens.length).toLocaleString("pt-BR")} de ${itens.length.toLocaleString("pt-BR")} produtos...`);
        await importarCatalogo(fonte, itens.slice(i, i + LOTE));
      }
      setMsg(`${itens.length.toLocaleString("pt-BR")} produtos importados da base ${FONTES.find((f) => f.id === fonte)?.rotulo}.`);
      setArquivo(null);
      await carregar();
    } catch (e) {
      setErro(e?.response?.data?.message || e.message || "Falha ao importar a planilha.");
    } finally {
      setProgresso(null);
    }
  }

  return (
    <div className="rounded-xl border border-amber-100 bg-amber-50/50 p-4">
      {progresso && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-white/70 backdrop-blur-sm">
          <div className="card-nuvem flex flex-col items-center gap-3 px-8 py-6 text-center">
            <div className="h-10 w-10 animate-spin rounded-full border-4 border-amber-200 border-t-amber-500" />
            <div className="font-bold text-slate-800">{progresso}</div>
          </div>
        </div>
      )}

      <h3 className="text-sm font-bold text-slate-700">Base de apoio (Shopee / planilhas)</h3>
      <p className="mt-1 text-xs text-slate-600">
        Produtos que não estão no Microvix. Ao bipar ou digitar um código que o Microvix não conhece, o sistema procura aqui
        (SKU, SKU de referência, EAN ou nome). Reimportar a planilha atualiza, não duplica.
      </p>
      {status && status.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {status.map((s) => (
            <span key={s.fonte} className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 shadow-sm">
              {s.fonte}: {s.total.toLocaleString("pt-BR")} produtos · {s.atualizado}
            </span>
          ))}
        </div>
      )}

      <div className="mt-3 flex flex-wrap gap-1.5">
        {FONTES.map((f) => (
          <button
            key={f.id}
            type="button"
            onClick={() => setFonte(f.id)}
            className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
              fonte === f.id ? "bg-gradient-to-r from-amber-400 to-orange-500 text-white shadow-md shadow-orange-500/30" : "bg-white text-slate-600 shadow-sm"
            }`}
          >
            {f.rotulo}
          </button>
        ))}
      </div>
      <input
        type="file"
        accept=".xlsx,.xls,.csv"
        onChange={(e) => setArquivo(e.target.files?.[0] ?? null)}
        className="mt-2 block w-full text-xs text-slate-600 file:mr-3 file:rounded-full file:border-0 file:bg-white file:px-3 file:py-1.5 file:font-semibold file:text-amber-700 hover:file:bg-amber-100"
      />
      <button type="button" onClick={importar} disabled={!arquivo || !!progresso} className="btn-ghost mt-2">
        Importar planilha
      </button>

      {msg && <div className="mt-2 rounded-lg bg-emerald-100 p-2 text-xs text-emerald-800">{msg}</div>}
      {erro && <div className="mt-2 rounded-lg bg-rose-100 p-2 text-xs text-rose-800">{erro}</div>}
    </div>
  );
}
