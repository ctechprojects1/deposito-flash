/** "Goiânia" -> "CD Goiânia"; "CD - Commerce" fica como está. */
export function rotuloCD(nome) {
  return /^cd\b/i.test(String(nome || "").trim()) ? nome : `CD ${nome}`;
}
