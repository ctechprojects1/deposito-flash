/** "Goiânia" -> "CD Goiânia"; "CD - Commerce" e "Depósito Mangalo" ficam como estão. */
export function rotuloCD(nome) {
  return /^(cd|dep[oó]sito)\b/i.test(String(nome || "").trim()) ? nome : `CD ${nome}`;
}
