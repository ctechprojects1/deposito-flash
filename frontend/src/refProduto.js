/** Referência (SKU) do produto para mostrar ao lado do código; null se não houver ou se for igual ao código. */
export function refProduto(p) {
  const ref = String(p?.sku || "").trim();
  return ref && ref !== p?.codigo_microvix ? ref : null;
}
