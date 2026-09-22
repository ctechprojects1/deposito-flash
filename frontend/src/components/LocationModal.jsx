import { useEffect, useState } from "react";
import { fetchLocation, zerarEndereco } from "../services/api";

/**
 * Modal que lista os produtos e quantidades guardados em um local (Time).
 *
 * Recebe o `location` já clicado no mapa (que pode trazer os produtos embutidos),
 * mas revalida no backend para garantir dados atualizados ao abrir.
 * `onChanged` é chamado quando algo muda (ex: zerar estoque) para o mapa recarregar.
 */
export default function LocationModal({ location, onClose, onChanged }) {
  const [detalhe, setDetalhe] = useState(location);
  const [loading, setLoading] = useState(false);
  const [zerando, setZerando] = useState(false);

  async function zerar() {
    if (!window.confirm(`Zerar o estoque de ${detalhe.nome}? Todas as quantidades deste endereço vão para 0.`))
      return;
    setZerando(true);
    try {
      await zerarEndereco(detalhe.id);
      const atualizado = await fetchLocation(detalhe.id);
      if (atualizado) setDetalhe(atualizado);
      onChanged?.();
    } catch (e) {
      alert(e?.response?.data?.message || "Erro ao zerar o estoque.");
    } finally {
      setZerando(false);
    }
  }

  useEffect(() => {
    let ativo = true;
    async function recarregar() {
      try {
        setLoading(true);
        const atualizado = await fetchLocation(location.id);
        if (ativo && atualizado) setDetalhe(atualizado);
      } catch (e) {
        console.error(e);
      } finally {
        if (ativo) setLoading(false);
      }
    }
    recarregar();
    return () => {
      ativo = false;
    };
  }, [location.id]);

  // Fecha com a tecla ESC.
  useEffect(() => {
    function onKey(e) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const produtos = detalhe.produtos ?? [];

  return (
    // Overlay — clique fora fecha.
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="card-nuvem w-full max-w-lg overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Cabeçalho */}
        <div className="flex items-start justify-between border-b border-slate-100 bg-gradient-to-r from-sky-50 to-indigo-50 p-5">
          <div>
            <h2 className="text-xl font-extrabold text-slate-800">{detalhe.nome}</h2>
            <p className="text-sm text-slate-500">
              Time {detalhe.corredor || "—"} · Posição{" "}
              {detalhe.esteira || "—"}
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
            aria-label="Fechar"
          >
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Corpo */}
        <div className="max-h-[60vh] overflow-y-auto p-5">
          {loading && (
            <p className="py-4 text-center text-sm text-gray-400">
              Atualizando...
            </p>
          )}

          {!loading && produtos.length === 0 && (
            <p className="py-8 text-center text-gray-500">
              Nenhum item guardado neste local.
            </p>
          )}

          {produtos.length > 0 && (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b text-gray-500">
                  <th className="pb-2 font-medium">Código</th>
                  <th className="pb-2 font-medium">Produto</th>
                  <th className="pb-2 text-right font-medium">Qtd.</th>
                </tr>
              </thead>
              <tbody>
                {produtos.map((p) => (
                  <tr key={p.product_id} className="border-b last:border-0">
                    <td className="py-2 font-mono text-xs text-gray-600">
                      {p.codigo_microvix}
                    </td>
                    <td className="py-2 text-gray-800">{p.nome}</td>
                    <td className="py-2 text-right font-semibold text-gray-800">
                      {Number(p.quantidade)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* Rodapé */}
        <div className="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/70 px-5 py-3 text-sm text-slate-500">
          <span>{produtos.length} produto(s)</span>
          <div className="flex items-center gap-2">
            <button
              onClick={zerar}
              disabled={zerando || produtos.length === 0}
              className="inline-flex items-center rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {zerando ? "Zerando..." : "Zerar estoque"}
            </button>
            <button onClick={onClose} className="btn-nuvem px-4 py-2">Fechar</button>
          </div>
        </div>
      </div>
    </div>
  );
}
