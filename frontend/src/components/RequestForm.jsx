import { useState } from "react";
import { validarProduto, criarSolicitacao } from "../services/api";

// Em produção, viria do usuário autenticado (contexto de auth).
const SOLICITANTE_ID = 1;

function linhaVazia() {
  return {
    codigo: "",
    product_id: null,
    nome: "",
    quantidade: "",
    status: "vazio", // vazio | validando | valido | invalido | nao_cadastrado
    mensagem: "",
  };
}

export default function RequestForm() {
  const [itens, setItens] = useState([linhaVazia()]);
  const [destino, setDestino] = useState("");
  const [observacao, setObservacao] = useState("");
  const [arquivo, setArquivo] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const [feedback, setFeedback] = useState(null); // {tipo, texto}

  function atualizarItem(index, campo, valor) {
    setItens((prev) =>
      prev.map((it, i) => (i === index ? { ...it, [campo]: valor } : it))
    );
  }

  // Valida o código no Microvix ao sair do campo.
  async function validarCodigo(index) {
    const item = itens[index];
    const codigo = item.codigo.trim();
    if (!codigo) return;

    atualizarItem(index, "status", "validando");
    try {
      const res = await validarProduto(codigo);
      if (!res.valido) {
        setItens((prev) =>
          prev.map((it, i) =>
            i === index
              ? { ...it, status: "invalido", nome: "", product_id: null, mensagem: res.mensagem || "Código inválido." }
              : it
          )
        );
        return;
      }
      // Válido no ERP, mas precisa existir localmente (ter product_id) para retirar.
      setItens((prev) =>
        prev.map((it, i) =>
          i === index
            ? {
                ...it,
                nome: res.nome || "",
                product_id: res.product_id,
                status: res.product_id ? "valido" : "nao_cadastrado",
                mensagem: res.product_id
                  ? ""
                  : "Produto existe no Microvix mas não está cadastrado no estoque.",
              }
            : it
        )
      );
    } catch (e) {
      atualizarItem(index, "status", "invalido");
      atualizarItem(index, "mensagem", "Falha ao validar o código.");
    }
  }

  function adicionarLinha() {
    setItens((prev) => [...prev, linhaVazia()]);
  }

  function removerLinha(index) {
    setItens((prev) => prev.filter((_, i) => i !== index));
  }

  function formValido() {
    const itensOk =
      itens.length > 0 &&
      itens.every(
        (it) => it.product_id && Number(it.quantidade) > 0
      );
    return itensOk && destino.trim() && arquivo;
  }

  async function enviar(e) {
    e.preventDefault();
    setFeedback(null);

    if (!formValido()) {
      setFeedback({ tipo: "erro", texto: "Preencha todos os campos e valide os códigos." });
      return;
    }

    setEnviando(true);
    try {
      await criarSolicitacao({
        solicitante_id: SOLICITANTE_ID,
        destino: destino.trim(),
        observacao: observacao.trim(),
        itens: itens.map((it) => ({
          product_id: it.product_id,
          quantidade_solicitada: Number(it.quantidade),
        })),
        anexo_nota: arquivo,
      });

      setFeedback({ tipo: "sucesso", texto: "Solicitação registrada com sucesso!" });
      // Limpa o formulário.
      setItens([linhaVazia()]);
      setDestino("");
      setObservacao("");
      setArquivo(null);
      e.target.reset();
    } catch (err) {
      const msg =
        err?.response?.data?.message || "Erro ao registrar a solicitação.";
      setFeedback({ tipo: "erro", texto: msg });
    } finally {
      setEnviando(false);
    }
  }

  return (
    <div className="mx-auto max-w-3xl p-4">
      <h1 className="mb-6 text-2xl font-extrabold text-slate-800">
        Nova Solicitação de Retirada
      </h1>

      {feedback && (
        <div
          className={`mb-4 rounded-lg p-3 text-sm ${
            feedback.tipo === "sucesso"
              ? "bg-green-100 text-green-800"
              : "bg-red-100 text-red-800"
          }`}
        >
          {feedback.texto}
        </div>
      )}

      <form onSubmit={enviar} className="card-nuvem space-y-6 p-5">
        {/* Itens */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <label className="font-semibold text-gray-700">Itens</label>
            <button
              type="button"
              onClick={adicionarLinha}
              className="text-sm font-medium text-blue-600 hover:text-blue-800"
            >
              + Adicionar item
            </button>
          </div>

          {itens.map((item, index) => (
            <ItemLinha
              key={index}
              item={item}
              podeRemover={itens.length > 1}
              onCodigo={(v) => atualizarItem(index, "codigo", v)}
              onValidar={() => validarCodigo(index)}
              onQuantidade={(v) => atualizarItem(index, "quantidade", v)}
              onRemover={() => removerLinha(index)}
            />
          ))}
        </div>

        {/* Destino */}
        <div>
          <label className="mb-1 block font-semibold text-gray-700">Destino</label>
          <input
            type="text"
            value={destino}
            onChange={(e) => setDestino(e.target.value)}
            placeholder="Ex.: Loja Centro, Evento X..."
            className="input-nuvem"
          />
        </div>

        {/* Observação */}
        <div>
          <label className="mb-1 block font-semibold text-gray-700">
            Observação <span className="font-normal text-gray-400">(opcional)</span>
          </label>
          <textarea
            value={observacao}
            onChange={(e) => setObservacao(e.target.value)}
            rows={2}
            className="input-nuvem"
          />
        </div>

        {/* Anexo da Nota */}
        <div>
          <label className="mb-1 block font-semibold text-gray-700">
            Nota de Saída (PDF ou imagem)
          </label>
          <input
            type="file"
            accept=".pdf,image/*"
            onChange={(e) => setArquivo(e.target.files?.[0] ?? null)}
            className="block w-full text-sm text-gray-600 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-blue-700 hover:file:bg-blue-100"
          />
          {arquivo && (
            <p className="mt-1 text-xs text-gray-500">
              {arquivo.name} ({(arquivo.size / 1024).toFixed(0)} KB)
            </p>
          )}
        </div>

        <button type="submit" disabled={enviando || !formValido()} className="btn-nuvem w-full py-3">
          {enviando ? "Enviando..." : "Registrar Solicitação"}
        </button>
      </form>
    </div>
  );
}

/**
 * Linha de item: código (com validação), nome auto e quantidade.
 */
function ItemLinha({ item, podeRemover, onCodigo, onValidar, onQuantidade, onRemover }) {
  const cores = {
    valido: "border-green-400",
    invalido: "border-red-400",
    nao_cadastrado: "border-yellow-400",
    validando: "border-blue-300",
    vazio: "border-gray-300",
  };

  return (
    <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3">
      <div className="grid grid-cols-12 gap-3">
        {/* Código */}
        <div className="col-span-4">
          <input
            type="text"
            value={item.codigo}
            onChange={(e) => onCodigo(e.target.value)}
            onBlur={onValidar}
            placeholder="Código Microvix"
            className={`w-full rounded border px-2 py-2 focus:outline-none focus:ring-1 focus:ring-blue-500 ${cores[item.status]}`}
          />
        </div>

        {/* Nome (auto) */}
        <div className="col-span-5">
          <input
            type="text"
            value={item.status === "validando" ? "Validando..." : item.nome}
            readOnly
            placeholder="Nome do produto (automático)"
            className="w-full cursor-not-allowed rounded border border-gray-200 bg-white px-2 py-2 text-gray-600"
          />
        </div>

        {/* Quantidade */}
        <div className="col-span-2">
          <input
            type="number"
            min="0"
            step="any"
            value={item.quantidade}
            onChange={(e) => onQuantidade(e.target.value)}
            placeholder="Qtd."
            className="w-full rounded border border-gray-300 px-2 py-2 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Remover */}
        <div className="col-span-1 flex items-center justify-center">
          {podeRemover && (
            <button
              type="button"
              onClick={onRemover}
              className="text-red-500 hover:text-red-700"
              title="Remover item"
            >
              ✕
            </button>
          )}
        </div>
      </div>

      {item.mensagem && (
        <p
          className={`mt-1 text-xs ${
            item.status === "nao_cadastrado" ? "text-yellow-700" : "text-red-600"
          }`}
        >
          {item.mensagem}
        </p>
      )}
    </div>
  );
}
