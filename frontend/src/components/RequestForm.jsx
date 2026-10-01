import { useMemo, useState } from "react";
import { extrairDocumento, criarSolicitacao } from "../services/api";
import BuscaProduto from "./BuscaProduto";

const TIPOS = { pedido: "Pedido de venda", nfe: "NF-e", documento_interno: "Documento interno" };

/**
 * Nova Solicitação: sobe a nota/pedido em PDF -> o sistema lê os itens ->
 * checklist: marca só o que sai do depósito e confirma a quantidade -> envia.
 */
export default function RequestForm() {
  const [arquivo, setArquivo] = useState(null);
  const [lendo, setLendo] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState(null);
  const [sucesso, setSucesso] = useState(null);

  const [doc, setDoc] = useState(null); // { tipo, numero, destino, itens }
  const [sel, setSel] = useState({}); // codigo -> { marcado, quantidade }
  const [destino, setDestino] = useState("");
  const [observacao, setObservacao] = useState("");

  async function lerDocumento() {
    if (!arquivo) return;
    setErro(null);
    setSucesso(null);
    setLendo(true);
    try {
      const r = await extrairDocumento(arquivo);
      setDoc(r);
      setDestino(r.destino || "");
      // Ninguém começa marcado: o operador escolhe o que sai do depósito.
      const inicial = {};
      r.itens.forEach((it) => {
        const sugerida = it.estoque_total > 0 ? Math.min(it.quantidade, it.estoque_total) : it.quantidade;
        inicial[it.codigo] = { marcado: false, quantidade: String(sugerida) };
      });
      setSel(inicial);
    } catch (e) {
      setErro(e?.response?.data?.message || "Não foi possível ler o documento.");
    } finally {
      setLendo(false);
    }
  }

  /**
   * Item da nota que não bateu com o depósito (SKU diferente/errado): o
   * operador escolhe o produto certo do depósito (sugestão ou busca).
   */
  function vincular(codigo, produto) {
    const item = doc.itens.find((it) => it.codigo === codigo);
    const original = item.original ?? item;
    setDoc((d) => ({
      ...d,
      itens: d.itens.map((it) =>
        it.codigo !== codigo
          ? it
          : {
              ...original,
              original,
              vinculado: true,
              no_deposito: true,
              product_id: produto.product_id,
              nome_deposito: produto.nome,
              codigo_deposito: produto.codigo_microvix,
              estoque_total: produto.total,
              locais: produto.localizacoes
                .map((l) => ({ location_id: l.location_id, nome: l.endereco, quantidade: l.quantidade }))
                .sort((a, b) => a.quantidade - b.quantidade),
            }
      ),
    }));
    const qtd = produto.total > 0 ? Math.min(original.quantidade, produto.total) : original.quantidade;
    setSel((s) => ({ ...s, [codigo]: { marcado: true, quantidade: String(qtd) } }));
  }

  function desvincular(codigo) {
    setDoc((d) => ({ ...d, itens: d.itens.map((it) => (it.codigo === codigo && it.original ? it.original : it)) }));
    setSel((s) => ({ ...s, [codigo]: { ...s[codigo], marcado: false } }));
  }

  function recomecar() {
    setDoc(null);
    setSel({});
    setArquivo(null);
    setDestino("");
    setObservacao("");
    setErro(null);
  }

  const marcados = useMemo(
    () => (doc?.itens ?? []).filter((it) => it.no_deposito && sel[it.codigo]?.marcado),
    [doc, sel]
  );

  // Problemas que impedem o envio.
  const problemas = marcados.filter((it) => {
    const q = Number(sel[it.codigo].quantidade);
    return !(q > 0) || q > it.quantidade;
  });

  async function enviar() {
    setErro(null);
    if (!destino.trim()) return setErro("Informe o destino.");
    if (marcados.length === 0) return setErro("Marque pelo menos um item que sai do depósito.");
    if (problemas.length) return setErro("Confira as quantidades marcadas em vermelho.");

    setEnviando(true);
    try {
      const r = await criarSolicitacao({
        destino: destino.trim(),
        observacao: observacao.trim(),
        tipo_documento: doc.tipo,
        numero_documento: doc.numero,
        anexo_nota: arquivo,
        itens: marcados.map((it) => ({
          product_id: it.product_id,
          codigo_microvix: it.codigo_deposito ?? it.codigo,
          descricao: it.descricao,
          quantidade_documento: it.quantidade,
          quantidade_solicitada: Number(sel[it.codigo].quantidade),
        })),
      });
      setSucesso(`Solicitação #${r.data.id} enviada para separação (${marcados.length} item(ns)).`);
      recomecar();
    } catch (e) {
      setErro(e?.response?.data?.message || "Erro ao enviar a solicitação.");
    } finally {
      setEnviando(false);
    }
  }

  return (
    <div className="mx-auto max-w-4xl px-4">
      <h1 className="mb-4 text-2xl font-extrabold text-slate-800">Nova Solicitação</h1>

      {(lendo || enviando) && (
        <Carregando
          titulo={lendo ? "Lendo o documento..." : "Enviando solicitação..."}
          subtitulo={lendo ? "Pode levar até 1 minuto, dependendo do tamanho da nota." : null}
        />
      )}

      {erro && <div className="mb-4 rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}
      {sucesso && <div className="mb-4 rounded-xl bg-emerald-100 p-3 text-sm text-emerald-800">{sucesso}</div>}

      {/* 1) Documento */}
      {!doc && (
        <div className="card-nuvem p-5">
          <label className="mb-1 block font-semibold text-slate-700">Nota ou pedido de venda (PDF)</label>
          <p className="mb-3 text-sm text-slate-500">
            O sistema lê os itens do documento e você marca quais saem do depósito.
          </p>
          <input
            type="file"
            accept="application/pdf,.pdf"
            onChange={(e) => {
              setArquivo(e.target.files?.[0] ?? null);
              setSucesso(null);
            }}
            className="block w-full text-sm text-slate-600 file:mr-4 file:rounded-full file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
          />
          <button onClick={lerDocumento} disabled={!arquivo || lendo} className="btn-nuvem mt-4 w-full py-3">
            Ler documento
          </button>
        </div>
      )}

      {/* 2) Checklist */}
      {doc && (
        <div className="space-y-4">
          <div className="card-nuvem p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                {doc.tipo && <span className="chip">{TIPOS[doc.tipo] || doc.tipo}</span>}
                {doc.numero && <span className="chip">Nº {doc.numero}</span>}
                <span className="chip">{doc.itens.length} item(ns) no documento</span>
              </div>
              <button onClick={recomecar} className="text-sm font-medium text-indigo-600 hover:underline">
                Trocar documento
              </button>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-sm font-semibold text-slate-700">Destino</label>
                <input value={destino} onChange={(e) => setDestino(e.target.value)} className="input-nuvem" placeholder="Cliente / loja de destino" />
              </div>
              <div>
                <label className="mb-1 block text-sm font-semibold text-slate-700">
                  Observação <span className="font-normal text-slate-400">(opcional)</span>
                </label>
                <input value={observacao} onChange={(e) => setObservacao(e.target.value)} className="input-nuvem" />
              </div>
            </div>
          </div>

          <div className="card-nuvem divide-y divide-slate-100">
            {doc.itens.map((it) => (
              <LinhaChecklist
                key={it.codigo}
                item={it}
                estado={sel[it.codigo]}
                onChange={(patch) => setSel((s) => ({ ...s, [it.codigo]: { ...s[it.codigo], ...patch } }))}
                onVincular={(produto) => vincular(it.codigo, produto)}
                onDesvincular={() => desvincular(it.codigo)}
              />
            ))}
          </div>

          <div className="card-nuvem sticky bottom-4 flex flex-wrap items-center justify-between gap-3 p-4">
            <span className="text-sm text-slate-600">
              <strong className="text-slate-800">{marcados.length}</strong> item(ns) marcados para retirar
              {problemas.length > 0 && <span className="ml-2 font-semibold text-rose-600">· {problemas.length} com quantidade inválida</span>}
            </span>
            <button onClick={enviar} disabled={enviando || marcados.length === 0} className="btn-nuvem">
              Enviar solicitação
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * Mesma regra do servidor: o endereço de menor saldo que comporte tudo;
 * se nenhum comporta, tira tudo do maior e repete com o que falta.
 */
function alocar(locais, qtd) {
  const livres = [...locais].sort((a, b) => a.quantidade - b.quantidade);
  const partes = [];
  let resta = qtd;
  while (resta > 0 && livres.length) {
    const cobre = livres.find((l) => l.quantidade >= resta);
    if (cobre) {
      partes.push({ nome: cobre.nome, quantidade: resta });
      return partes;
    }
    const maior = livres.pop();
    partes.push({ nome: maior.nome, quantidade: maior.quantidade });
    resta -= maior.quantidade;
  }
  return partes;
}

function LinhaChecklist({ item, estado, onChange, onVincular, onDesvincular }) {
  const marcado = !!estado?.marcado;
  const qtd = Number(estado?.quantidade);
  const invalida = marcado && (!(qtd > 0) || qtd > item.quantidade);
  const semSaldo = marcado && qtd > item.estoque_total;
  const partes = alocar(item.locais, qtd || 0);

  if (!item.no_deposito) {
    return <LinhaNaoEncontrada item={item} onVincular={onVincular} />;
  }

  return (
    <div className={`flex flex-wrap items-center gap-3 p-3 transition ${marcado ? "bg-indigo-50/50" : ""}`}>
      <button
        onClick={() => onChange({ marcado: !marcado })}
        title={marcado ? "Desmarcar" : "Retirar do depósito"}
        className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 transition ${
          marcado ? "border-indigo-500 bg-gradient-to-r from-sky-500 to-indigo-500 text-white" : "border-slate-300 bg-white"
        }`}
      >
        {marcado && (
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
          </svg>
        )}
      </button>

      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-medium text-slate-800">{item.descricao}</div>
        <div className="font-mono text-xs text-slate-400">cód. {item.codigo}</div>
        {item.vinculado && (
          <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs">
            <span className="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">
              vinculado: {item.nome_deposito} (cód. {item.codigo_deposito})
            </span>
            <button onClick={onDesvincular} className="font-semibold text-indigo-600 hover:underline">desfazer</button>
          </div>
        )}
        <div className="text-xs text-slate-500">
          Depósito: {item.estoque_total} un
          {partes.length > 0 && " · retirar de " + partes.map((p) => `${p.nome} (${p.quantidade})`).join(" + ")}
          {item.locais.length > 1 && ` +${item.locais.length - 1} endereço(s)`}
        </div>
      </div>

      <div className="text-right text-xs text-slate-500">
        na nota
        <div className="text-sm font-semibold text-slate-700">{item.quantidade}</div>
      </div>

      <div className="w-24">
        <input
          type="number"
          min="0"
          step="any"
          disabled={!marcado}
          value={estado?.quantidade ?? ""}
          onChange={(e) => onChange({ quantidade: e.target.value })}
          className={`w-full rounded-xl border px-2 py-1.5 text-right text-sm shadow-sm focus:outline-none focus:ring-2 disabled:bg-slate-50 disabled:text-slate-400 ${
            invalida ? "border-rose-300 focus:ring-rose-200" : "border-slate-200 focus:ring-indigo-200"
          }`}
          title="Quantidade que sai do depósito"
        />
        {invalida && <div className="mt-0.5 text-[11px] text-rose-600">máx. {item.quantidade}</div>}
        {!invalida && semSaldo && <div className="mt-0.5 text-[11px] text-amber-600">saldo {item.estoque_total}</div>}
      </div>
    </div>
  );
}

/**
 * Item da nota que não bateu com nenhum produto do depósito: mostra os
 * parecidos (SKU com erro, código diferente, descrição) para vincular,
 * ou deixa procurar manualmente.
 */
function LinhaNaoEncontrada({ item, onVincular }) {
  const [procurando, setProcurando] = useState(false);
  const sugestoes = item.sugestoes ?? [];

  return (
    <div className="p-3">
      <div className="flex items-center gap-3">
        <span className="h-7 w-7 shrink-0 rounded-full border-2 border-dashed border-slate-300" />
        <div className="min-w-0 flex-1 opacity-60">
          <div className="truncate text-sm text-slate-700">{item.descricao}</div>
          <div className="font-mono text-xs text-slate-400">cód. {item.codigo}</div>
        </div>
        <span className="text-xs text-slate-500">{item.quantidade} un</span>
        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500">não está no depósito</span>
      </div>

      <div className="ml-10 mt-2 flex flex-wrap items-center gap-1.5">
        {sugestoes.length > 0 && <span className="text-xs font-semibold text-amber-700">Parecidos no depósito:</span>}
        {sugestoes.map((p) => (
          <button
            key={p.product_id}
            onClick={() => onVincular(p)}
            title="Usar este produto do depósito"
            className="rounded-full bg-white px-3 py-1 text-left text-xs shadow-sm ring-1 ring-amber-200 transition hover:-translate-y-0.5 hover:bg-amber-50"
          >
            <span className="font-semibold text-slate-700">{p.nome}</span>
            <span className="ml-1 font-mono text-slate-400">cód. {p.codigo_microvix}</span>
            <span className="ml-1 font-bold text-indigo-600">{p.total} un</span>
          </button>
        ))}
        {!procurando ? (
          <button onClick={() => setProcurando(true)} className="text-xs font-semibold text-indigo-600 hover:underline">
            {sugestoes.length ? "procurar outro" : "procurar no depósito"}
          </button>
        ) : (
          <BuscaProduto
            className="w-full sm:w-96"
            autoFocus
            placeholder="Código ou descrição do produto no depósito..."
            onSelecionar={(p) => {
              setProcurando(false);
              onVincular(p);
            }}
          />
        )}
      </div>
    </div>
  );
}

/** Tela de espera por cima de tudo (leitura do PDF pode demorar). */
function Carregando({ titulo, subtitulo }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/70 backdrop-blur-sm">
      <div className="card-nuvem flex flex-col items-center gap-3 px-8 py-6 text-center">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-500" />
        <div className="font-bold text-slate-800">{titulo}</div>
        {subtitulo && <div className="max-w-xs text-sm text-slate-500">{subtitulo}</div>}
      </div>
    </div>
  );
}
