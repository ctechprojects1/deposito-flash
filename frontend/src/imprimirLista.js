/**
 * Abre uma janela só com a lista e chama a impressão (o navegador oferece
 * "Salvar como PDF"). Usado pelas listas de separação e de embalagem.
 */

export const esc = (s) =>
  String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

const CSS = `
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #1e293b; margin: 18px; font-size: 12px; }
  h1 { font-size: 20px; margin: 0 0 2px; }
  .sub { color: #64748b; margin-bottom: 10px; }
  .grupo { background: #1e2a78; color: #fff; font-weight: bold; padding: 4px 8px; margin-top: 10px; border-radius: 4px; }
  .linha { display: flex; gap: 10px; align-items: flex-start; border-bottom: 1px solid #e2e8f0; padding: 7px 2px; page-break-inside: avoid; }
  .caixa { width: 14px; height: 14px; border: 1.5px solid #334155; border-radius: 2px; flex: none; margin-top: 2px; }
  .corpo { flex: 1; min-width: 0; }
  .nome { font-weight: bold; }
  .cliente { color: #1e3a8a; font-weight: bold; }
  .meta { color: #64748b; font-size: 11px; margin-top: 1px; }
  .forte { color: #1e2a78; font-weight: bold; }
  .alerta { color: #c62828; font-weight: bold; }
  .qtd { flex: none; min-width: 34px; height: 30px; border: 2px solid #1e293b; border-radius: 5px; font-size: 16px; font-weight: bold; display: flex; align-items: center; justify-content: center; }
  @page { margin: 12mm; }
`;

export function imprimirLista(titulo, corpoHtml) {
  const w = window.open("", "_blank");
  if (!w) {
    alert("O navegador bloqueou a janela de impressão. Libere pop-ups para este site e tente de novo.");
    return;
  }
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(titulo)}</title><style>${CSS}</style></head><body>${corpoHtml}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 300);
}
