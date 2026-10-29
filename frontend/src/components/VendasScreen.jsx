import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../AuthContext";
import { rotuloCD } from "../rotuloCD";
import { lerPlanilhaVendas } from "../vendasPlanilha";
import { esc, imprimirLista } from "../imprimirLista";
import { refProduto } from "../refProduto";
import { importarVendas, listaEmbalagem, marcarEmbalados, painelVendas, separarVendas } from "../services/api";

const msgErro = (e, padrao) => e?.response?.data?.message || e?.message || padrao;
const agora = () => new Date().toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" });
const un = (n) => `${Number(n || 0).toLocaleString("pt-BR")} un`;

/** Sem saldo neste CD mas tem no CD do mesmo lugar: "Tem no CD Goiânia: SANTOS 1A (5)". */
function outroCd(l) {
  const o = l.outros_cds || [];
  if (!o.length) return null;
  return `Tem no ${rotuloCD(o[0].cd)}: ${o.map((x) => `${x.nome} (${x.quantidade})`).join(", ")} — escolha no Painel do Separador`;
}

/** Códigos da linha: cód. interno, referência do sistema e SKU do anúncio (sem repetir). */
function codigos(l) {
  const ref = refProduto(l);
  const anuncio = (l.skus_venda || []).filter((s) => s !== l.sku && s !== l.codigo_microvix);
  return [
    l.codigo_microvix && `cód. ${l.codigo_microvix}`,
    ref && `ref. ${ref}`,
    ...anuncio.map((s) => `SKU anúncio ${s}`),
  ].filter(Boolean);
}

/**
 * Vendas da Shopee: sobe a planilha de pedidos "A Enviar", gera a separação
 * (vai para o Painel do Separador; a baixa sai de cada endereço ao finalizar)
 * e a lista de embalagem (um pacote por pedido, com rastreio e espaço para a NF).
 */
export default function VendasScreen() {
  const [painel, setPainel] = useState(null);
  const [erro, setErro] = useState(null);

  async function carregar() {
    try {
      setPainel(await painelVendas());
    } catch (e) {
      setErro(msgErro(e, "Não foi possível carregar as vendas."));
    }
  }

  useEffect(() => {
    carregar();
  }, []);

  return (
    <div className="mx-auto max-w-5xl space-y-5 p-4">
      <div>
        <h1 className="text-2xl font-extrabold text-slate-800">Vendas Shopee</h1>
        <p className="text-sm text-slate-500">
          Suba a planilha de pedidos da Shopee para gerar a <b>separação</b> (por endereço) e a <b>lista de embalagem</b> (por pacote).
        </p>
      </div>

      {erro && <div className="rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}

      <Importar onImportado={setPainel} />
      <Resumo painel={painel} />
      <Separacao painel={painel} onCriada={setPainel} />
      <Embalagem painel={painel} onMudou={carregar} />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Importar({ onImportado }) {
  const [arquivo, setArquivo] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState(null);
  const [erro, setErro] = useState(null);

  async function enviar() {
    setErro(null);
    setResultado(null);
    setEnviando(true);
    try {
      const pedidos = await lerPlanilhaVendas(arquivo);
      const r = await importarVendas("shopee", pedidos);
      setResultado(r.resumo);
      onImportado(r);
    } catch (e) {
      setErro(msgErro(e, "Falha ao ler a planilha."));
    } finally {
      setEnviando(false);
    }
  }

  return (
    <section className="card-nuvem p-5">
      <h2 className="text-lg font-bold text-slate-800">1. Subir planilha de pedidos</h2>
      <p className="mt-1 text-xs text-slate-500">
        Na Shopee: <b>Meus Pedidos → A Enviar → Exportar</b> e baixe o <code>Order.toship…xlsx</code> no Histórico de
        Relatórios. Pode subir a mesma planilha mais de uma vez: os pedidos são atualizados (o rastreio entra quando
        aparecer), nunca duplicados. CPF, telefone e endereço do comprador não são enviados ao sistema.
      </p>
      <div className="mt-3 flex flex-wrap items-center gap-3">
        <input type="file" accept=".xlsx,.xls,.csv" onChange={(e) => setArquivo(e.target.files?.[0] ?? null)} className="text-sm" />
        <button type="button" onClick={enviar} disabled={!arquivo || enviando} className="btn-nuvem">
          {enviando ? "Lendo planilha..." : "Subir vendas"}
        </button>
      </div>
      {erro && <p className="mt-3 rounded-lg bg-rose-100 p-2 text-sm text-rose-800">{erro}</p>}
      {resultado && (
        <p className="mt-3 rounded-lg bg-emerald-100 p-2 text-sm text-emerald-800">
          {resultado.novos} pedido(s) novo(s), {resultado.atualizados} atualizado(s)
          {resultado.ja_separados > 0 && ` (${resultado.ja_separados} já separados: só status/rastreio)`}.
          {resultado.itens_sem_produto > 0 && ` ${resultado.itens_sem_produto} item(ns) sem cadastro no sistema.`}
        </p>
      )}
    </section>
  );
}

/* ------------------------------------------------------------------ */

function Resumo({ painel }) {
  if (!painel) return null;
  const caixas = [
    { rotulo: "Pedidos a separar", valor: painel.a_separar, extra: un(painel.a_separar_unidades), cor: "text-indigo-600" },
    { rotulo: "Itens sem cadastro", valor: painel.itens_sem_produto, extra: "não entram na baixa", cor: painel.itens_sem_produto ? "text-rose-600" : "text-slate-400" },
    { rotulo: "Pacotes a embalar", valor: painel.a_embalar, extra: `${painel.sem_rastreio} sem rastreio`, cor: "text-emerald-600" },
  ];
  return (
    <div className="grid gap-3 sm:grid-cols-3">
      {caixas.map((c) => (
        <div key={c.rotulo} className="card-nuvem p-4">
          <div className={`text-3xl font-extrabold ${c.cor}`}>{c.valor}</div>
          <div className="text-sm font-semibold text-slate-700">{c.rotulo}</div>
          <div className="text-xs text-slate-400">{c.extra}</div>
        </div>
      ))}
      {painel.ultima_importacao && (
        <p className="text-xs text-slate-400 sm:col-span-3">Última atualização das vendas: {painel.ultima_importacao}</p>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Separacao({ painel, onCriada }) {
  const { deposito } = useAuth();
  const [modo, setModo] = useState("novos");
  const [pagoApos, setPagoApos] = useState("");
  const [lista, setLista] = useState(null);
  const [carregando, setCarregando] = useState(false);
  const [msg, setMsg] = useState(null);
  const [erro, setErro] = useState(null);
  const [verPedidos, setVerPedidos] = useState(false);

  async function gerar(simular) {
    setErro(null);
    setMsg(null);
    if (modo === "pagos_apos" && !pagoApos) return setErro("Escolha o horário de pagamento.");
    if (!simular && !window.confirm("Criar a separação destes pedidos?\n\nEla vai para o Painel do Separador e a baixa no estoque sai de cada endereço quando o separador finalizar.\nSe é só para conferir, use \"Só ver a lista\".")) return;

    setCarregando(true);
    try {
      const r = await separarVendas({ modo, pago_apos: modo === "pagos_apos" ? pagoApos : undefined, simular });
      setLista(r);
      if (!simular) {
        setMsg(r.message);
        onCriada(r);
      }
    } catch (e) {
      setErro(msgErro(e, "Não foi possível montar a separação."));
    } finally {
      setCarregando(false);
    }
  }

  const grupos = useMemo(() => {
    const g = [];
    for (const l of lista?.linhas ?? []) {
      const nome = l.time || l.endereco || "Sem endereço com saldo";
      if (!g.length || g[g.length - 1].nome !== nome) g.push({ nome, linhas: [] });
      g[g.length - 1].linhas.push(l);
    }
    return g;
  }, [lista]);

  function imprimir() {
    const cd = deposito ? rotuloCD(deposito.nome) : "";
    let h = `<h1>Lista de Separação — ${esc(cd)}</h1><div class="sub">${lista.pedidos} pedidos · ${lista.unidades} unidades · ${
      lista.simulacao ? "SÓ CONFERÊNCIA (não gerou separação)" : `separação criada`
    } · impresso em ${esc(agora())}</div>`;
    for (const g of grupos) {
      h += `<div class="grupo">📍 ${esc(g.nome)}</div>`;
      for (const l of g.linhas) {
        h += `<div class="linha"><div class="caixa"></div><div class="corpo"><div class="nome">${esc(l.nome)}</div><div class="meta">${
          l.endereco ? esc(l.endereco) : '<span class="alerta">SEM ENDEREÇO</span>'
        }${codigos(l).map((c) => ` · ${esc(c)}`).join("")}</div>${
          outroCd(l) ? `<div class="meta forte">${esc(outroCd(l))}</div>` : ""
        }<div class="meta forte">estoque ${l.estoque} · retira ${l.retira} · fica ${l.fica}</div></div><div class="qtd">${l.retira}</div></div>`;
      }
    }
    if (lista.sem_produto?.length) {
      h += `<div class="grupo" style="background:#c62828">Sem cadastro no sistema — separar à parte</div>`;
      for (const s of lista.sem_produto) {
        h += `<div class="linha"><div class="caixa"></div><div class="corpo"><div class="nome">${esc(s.nome)}</div><div class="meta">SKU ${esc(s.sku)} · pedido ${esc(s.pedido)}</div></div><div class="qtd">${s.quantidade}</div></div>`;
      }
    }
    imprimirLista("Lista de separação", h);
  }

  return (
    <section className="card-nuvem p-5">
      <h2 className="text-lg font-bold text-slate-800">2. Separação</h2>
      <p className="mt-1 text-xs text-slate-500">
        Junta os pedidos ainda não separados, soma por produto e diz de qual endereço tirar. "Gerar separação" cria uma
        separação no <b>Painel do Separador</b>; a baixa no estoque acontece quando o separador finaliza. Cada pedido só
        entra em uma separação.
      </p>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" onClick={() => setModo("novos")} className={`rounded-full px-3 py-1 text-xs font-semibold ${modo === "novos" ? "bg-indigo-600 text-white" : "bg-slate-100 text-slate-600"}`}>
          Todos ainda não separados
        </button>
        <button type="button" onClick={() => setModo("pagos_apos")} className={`rounded-full px-3 py-1 text-xs font-semibold ${modo === "pagos_apos" ? "bg-indigo-600 text-white" : "bg-slate-100 text-slate-600"}`}>
          Pagos depois de...
        </button>
        {modo === "pagos_apos" && (
          <input type="datetime-local" value={pagoApos} onChange={(e) => setPagoApos(e.target.value)} className="rounded-lg border border-slate-200 px-2 py-1 text-sm" />
        )}
      </div>

      <div className="mt-3 flex flex-wrap gap-2">
        <button type="button" onClick={() => gerar(true)} disabled={carregando || !painel?.a_separar} className="btn-ghost">
          👁️ Só ver a lista (não cria nada)
        </button>
        <button type="button" onClick={() => gerar(false)} disabled={carregando || !painel?.a_separar} className="btn-nuvem">
          {carregando ? "Montando..." : "Gerar separação"}
        </button>
        {painel?.pedidos_a_separar?.length > 0 && (
          <button type="button" onClick={() => setVerPedidos((v) => !v)} className="text-xs font-semibold text-indigo-600 hover:underline">
            {verPedidos ? "Esconder pedidos" : `Ver os ${painel.pedidos_a_separar.length} pedidos a separar`}
          </button>
        )}
      </div>

      {erro && <p className="mt-3 rounded-lg bg-rose-100 p-2 text-sm text-rose-800">{erro}</p>}
      {msg && <p className="mt-3 rounded-lg bg-emerald-100 p-2 text-sm text-emerald-800">{msg}</p>}

      {verPedidos && (
        <div className="mt-3 max-h-80 overflow-y-auto rounded-xl border border-slate-100">
          {painel.pedidos_a_separar.map((p) => (
            <div key={p.id} className="border-b border-slate-100 px-3 py-2 text-xs">
              <div className="font-mono text-slate-500">
                pedido {p.pedido}
                {p.pago_em && ` · pago ${p.pago_em}`}
              </div>
              {p.itens.map((it, i) => (
                <div key={i} className={it.cadastrado ? "text-slate-700" : "font-semibold text-rose-600"}>
                  {it.quantidade}× {it.nome} <span className="font-mono text-slate-400">· SKU {it.sku || "—"}</span>
                  {!it.cadastrado && " · sem cadastro"}
                </div>
              ))}
            </div>
          ))}
        </div>
      )}

      {lista && (
        <div className="mt-4">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div className="text-sm font-semibold text-slate-700">
              {lista.simulacao ? "Conferência (nada foi criado)" : `Separação #${lista.separacao_id}`} · {lista.pedidos} pedidos · {un(lista.unidades)}
            </div>
            <button type="button" onClick={imprimir} className="btn-ghost">
              🖨️ Imprimir / PDF
            </button>
          </div>
          {grupos.map((g) => (
            <div key={g.nome} className="mb-2">
              <div className="rounded-lg bg-indigo-900 px-3 py-1 text-xs font-bold text-white">📍 {g.nome}</div>
              {g.linhas.map((l, i) => (
                <div key={i} className="flex items-center gap-3 border-b border-slate-100 px-2 py-2">
                  <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-slate-800">{l.nome}</div>
                    <div className="text-xs text-slate-500">
                      {l.endereco || <span className="font-semibold text-rose-600">sem endereço com saldo</span>}
                      {codigos(l).map((c) => ` · ${c}`).join("")}
                    </div>
                    {outroCd(l) && <div className="text-xs font-semibold text-sky-700">{outroCd(l)}</div>}
                    <div className="text-xs font-bold text-indigo-900">
                      estoque {l.estoque} · retira {l.retira} · fica {l.fica}
                    </div>
                  </div>
                  <div className="rounded-lg bg-amber-100 px-3 py-1 text-lg font-extrabold text-amber-800">{l.retira}</div>
                </div>
              ))}
            </div>
          ))}
          {lista.sem_produto?.length > 0 && (
            <div className="mt-2 rounded-xl bg-rose-50 p-3 text-xs text-rose-800">
              <div className="mb-1 font-bold">Sem cadastro no sistema ({lista.sem_produto.length}) — não entram na baixa, separar à parte:</div>
              {lista.sem_produto.map((s, i) => (
                <div key={i}>
                  {s.quantidade}× {s.nome} · SKU {s.sku || "—"} · pedido {s.pedido}
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </section>
  );
}

/* ------------------------------------------------------------------ */

function Embalagem({ painel, onMudou }) {
  const { deposito, user } = useAuth();
  const [soNovos, setSoNovos] = useState(true);
  const [ordem, setOrdem] = useState("cliente");
  const [dados, setDados] = useState(null);
  const [carregando, setCarregando] = useState(false);
  const [msg, setMsg] = useState(null);
  const [erro, setErro] = useState(null);

  async function carregar() {
    setErro(null);
    setCarregando(true);
    try {
      setDados(await listaEmbalagem({ todos: soNovos ? 0 : 1, ordem }));
    } catch (e) {
      setErro(msgErro(e, "Não foi possível montar a lista de embalagem."));
    } finally {
      setCarregando(false);
    }
  }

  // Recarrega a lista aberta quando muda o filtro/ordem ou chegam vendas novas.
  useEffect(() => {
    if (dados) carregar();
  }, [soNovos, ordem, painel?.ultima_importacao]);

  async function alternar(p) {
    try {
      const r = await marcarEmbalados([p.id], p.embalado);
      setMsg(r.message);
      await carregar();
      onMudou();
    } catch (e) {
      setErro(msgErro(e, "Não foi possível atualizar o pacote."));
    }
  }

  function imprimir(marcar) {
    const pacotes = dados.pacotes;
    const cd = deposito ? rotuloCD(deposito.nome) : "";
    let h = `<h1>Lista de Embalagem — ${esc(cd)}</h1><div class="sub">${pacotes.length} pacotes · ${dados.itens} itens · impresso em ${esc(agora())}${
      user?.name ? ` por ${esc(user.name)}` : ""
    }</div>`;
    for (const p of pacotes) {
      const itens = p.itens.map((it) => `${esc(it.nome)} (${it.quantidade} un)`).join("<br>");
      const skus = p.itens.map((it) => it.sku).filter(Boolean).join(" · ");
      h += `<div class="linha"><div class="caixa"></div><div class="corpo"><div class="cliente">${esc(p.cliente || "(sem nome)")}</div><div class="nome">${itens}</div>${
        skus ? `<div class="meta">SKU ${esc(skus)}</div>` : ""
      }<div class="meta">${p.rastreio ? `<b>${esc(p.rastreio)}</b>` : '<span class="alerta">SEM RASTREIO</span>'} · ${esc(p.envio || "")} · pedido ${esc(p.pedido)}</div><div class="meta">NF: ______________</div></div><div class="qtd">${p.quantidade}</div></div>`;
    }
    imprimirLista("Lista de embalagem", h);

    if (marcar) {
      const ids = pacotes.filter((p) => !p.embalado).map((p) => p.id);
      if (ids.length)
        marcarEmbalados(ids)
          .then((r) => {
            setMsg(`${r.message} Para refazer algum, marque "Mostrar também os já embalados" e desmarque o pacote.`);
            carregar();
            onMudou();
          })
          .catch((e) => setErro(msgErro(e, "Não foi possível marcar como embalados.")));
    }
  }

  return (
    <section className="card-nuvem p-5">
      <h2 className="text-lg font-bold text-slate-800">3. Embalagem</h2>
      <p className="mt-1 text-xs text-slate-500">
        Um pacote por pedido, com cliente, rastreio e espaço para a NF (a NF não vem na planilha da Shopee). Para pegar os
        rastreios, suba de novo a planilha depois que a Shopee processar os pedidos. Os sem rastreio entram marcados.
      </p>

      <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
        <label className="flex items-center gap-2">
          <input type="checkbox" checked={!soNovos} onChange={(e) => setSoNovos(!e.target.checked)} />
          Mostrar também os já embalados
        </label>
        <span className="text-xs font-semibold text-slate-500">Ordenar por:</span>
        {[
          ["cliente", "Cliente (A-Z)"],
          ["produto", "Produto"],
          ["rastreio", "Rastreio"],
        ].map(([o, r]) => (
          <button key={o} type="button" onClick={() => setOrdem(o)} className={`rounded-full px-3 py-1 text-xs font-semibold ${ordem === o ? "bg-emerald-600 text-white" : "bg-slate-100 text-slate-600"}`}>
            {r}
          </button>
        ))}
      </div>

      <div className="mt-3 flex flex-wrap gap-2">
        <button type="button" onClick={carregar} disabled={carregando} className="btn-ghost">
          {carregando ? "Carregando..." : dados ? "Atualizar lista" : `Ver lista (${painel?.a_embalar ?? 0} pacotes)`}
        </button>
        {dados?.pacotes?.length > 0 && (
          <>
            <button type="button" onClick={() => imprimir(true)} className="btn-nuvem">
              🖨️ Gerar PDF e marcar como embalados
            </button>
            <button type="button" onClick={() => imprimir(false)} className="text-xs font-semibold text-slate-500 hover:underline">
              Só imprimir (sem marcar)
            </button>
          </>
        )}
      </div>

      {erro && <p className="mt-3 rounded-lg bg-rose-100 p-2 text-sm text-rose-800">{erro}</p>}
      {msg && <p className="mt-3 rounded-lg bg-emerald-100 p-2 text-sm text-emerald-800">{msg}</p>}

      {dados && (
        <div className="mt-4">
          <div className="mb-2 text-xs text-slate-500">
            {dados.pacotes.length} pacotes · {dados.itens} itens · {dados.com_rastreio} com rastreio
            {dados.sem_rastreio > 0 && <span className="font-semibold text-amber-600"> · {dados.sem_rastreio} sem rastreio</span>}
            {soNovos && dados.ja_embalados > 0 && ` · ${dados.ja_embalados} já embalados (escondidos)`}
          </div>
          {dados.pacotes.length === 0 && <p className="py-6 text-center text-sm text-slate-400">Nenhum pacote a embalar.</p>}
          {dados.pacotes.map((p) => (
            <div key={p.id} className={`flex items-start gap-3 border-b border-slate-100 px-2 py-2 ${p.embalado ? "opacity-50" : ""}`}>
              <input type="checkbox" checked={p.embalado} onChange={() => alternar(p)} title={p.embalado ? "Voltar para a lista" : "Marcar como embalado"} className="mt-1 h-4 w-4" />
              <div className="min-w-0 flex-1">
                <div className="text-sm font-bold text-indigo-800">{p.cliente || "(sem nome)"}</div>
                {p.itens.map((it, i) => (
                  <div key={i} className="text-sm text-slate-800">
                    {it.nome} <b>({it.quantidade} un)</b>
                    {it.sku && <span className="ml-1 font-mono text-xs text-slate-400">SKU {it.sku}</span>}
                  </div>
                ))}
                <div className="text-xs text-slate-500">
                  {p.rastreio ? <b className="text-slate-700">{p.rastreio}</b> : <b className="text-rose-600">SEM RASTREIO</b>} · {p.envio} · pedido{" "}
                  <span className="font-mono">{p.pedido}</span>
                  {!p.separado && <span className="ml-1 font-semibold text-amber-600">· ainda não separado</span>}
                </div>
              </div>
              <div className="rounded-lg border-2 border-slate-700 px-2 text-lg font-extrabold">{p.quantidade}</div>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
