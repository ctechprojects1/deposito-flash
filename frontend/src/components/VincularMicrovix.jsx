import { useState } from "react";
import { useAuth } from "../AuthContext";
import { buscarCatalogo, vincularMicrovix, vincularProdutoMicrovix } from "../services/api";

const msgErro = (e, padrao) => e?.response?.data?.message || e?.message || padrao;
const un = (n) => Number(n || 0).toLocaleString("pt-BR");

/**
 * Admin: liga ao Microvix os produtos que ainda não estão nele (vieram da
 * planilha da Shopee ou foram cadastrados à mão), para o Microvix ser a
 * única base de produtos. Primeiro analisa (não muda nada), depois aplica;
 * o que não for achado sozinho é ligado à mão, escolhendo no Microvix.
 */
export default function VincularMicrovix() {
  const { hasPerm } = useAuth();
  const [plano, setPlano] = useState(null);
  const [ocupado, setOcupado] = useState(null);
  const [erro, setErro] = useState(null);
  const [msg, setMsg] = useState(null);

  if (!hasPerm("admin")) return null;

  async function analisar() {
    setErro(null);
    setOcupado("Analisando...");
    try {
      setPlano(await vincularMicrovix(false));
    } catch (e) {
      setErro(msgErro(e, "Não foi possível analisar."));
    } finally {
      setOcupado(null);
    }
  }

  async function aplicar() {
    const n = plano.vincular.length + plano.juntar.length;
    if (
      !window.confirm(
        `Ligar ${n} produto(s) ao Microvix?\n\nEles passam a ter o código interno, a referência e a descrição do Microvix.` +
          (plano.juntar.length ? `\n${plano.juntar.length} duplicado(s) viram um só (estoque e histórico passam para o produto do Microvix).` : "") +
          "\n\nO SKU antigo da Shopee continua valendo nas vendas."
      )
    )
      return;
    setErro(null);
    setOcupado("Ligando ao Microvix...");
    try {
      const r = await vincularMicrovix(true);
      setMsg(r.message);
      setPlano(await vincularMicrovix(false));
    } catch (e) {
      setErro(msgErro(e, "Não foi possível aplicar o vínculo."));
    } finally {
      setOcupado(null);
    }
  }

  function ligadoManual(productId, mensagem) {
    setMsg(mensagem);
    setPlano((p) => ({
      ...p,
      ambiguos: p.ambiguos.filter((l) => l.product_id !== productId),
      nao_encontrados: p.nao_encontrados.filter((l) => l.product_id !== productId),
      total_sem_vinculo: p.total_sem_vinculo - 1,
    }));
  }

  const automaticos = plano ? plano.vincular.length + plano.juntar.length : 0;
  const manuais = plano ? [...plano.ambiguos, ...plano.nao_encontrados] : [];

  return (
    <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4">
      <h3 className="text-sm font-bold text-slate-700">Microvix como base única — ligar produtos</h3>
      <p className="mt-1 text-xs text-slate-600">
        Liga ao cadastro do Microvix os produtos que vieram da planilha da Shopee (ou foram cadastrados à mão): pelo código de
        barras, pelo EAN da Shopee ou pela referência igual ao SKU. Eles ficam com código, referência e descrição do Microvix; o
        SKU antigo continua valendo nas vendas. Rode "Sincronizar agora" antes.
      </p>

      <div className="mt-3 flex flex-wrap gap-2">
        <button type="button" onClick={analisar} disabled={!!ocupado} className="btn-ghost">
          {ocupado === "Analisando..." ? ocupado : plano ? "Analisar de novo" : "Analisar produtos"}
        </button>
        {plano && automaticos > 0 && (
          <button type="button" onClick={aplicar} disabled={!!ocupado} className="btn-nuvem">
            {ocupado === "Ligando ao Microvix..." ? ocupado : `Ligar ${automaticos} automaticamente`}
          </button>
        )}
      </div>

      {erro && <div className="mt-3 rounded-xl bg-rose-100 p-2 text-sm text-rose-800">{erro}</div>}
      {msg && <div className="mt-3 rounded-xl bg-emerald-100 p-2 text-sm text-emerald-800">{msg}</div>}

      {plano && (
        <div className="mt-3 space-y-3 text-sm">
          {plano.total_sem_vinculo === 0 ? (
            <div className="rounded-xl bg-emerald-50 p-3 font-semibold text-emerald-800">
              Todos os produtos já estão ligados ao Microvix.
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <Contador rotulo="Ligar sozinho" n={plano.vincular.length} cor="text-emerald-700" />
              <Contador rotulo="Duplicados a juntar" n={plano.juntar.length} cor="text-sky-700" />
              <Contador rotulo="Mais de uma opção" n={plano.ambiguos.length} cor="text-amber-700" />
              <Contador rotulo="Não achados" n={plano.nao_encontrados.length} cor="text-rose-700" />
            </div>
          )}

          {automaticos > 0 && (
            <details className="rounded-xl bg-white p-3 shadow-sm">
              <summary className="cursor-pointer text-xs font-bold text-slate-700">Ver o que será ligado sozinho ({automaticos})</summary>
              <div className="mt-2 max-h-72 space-y-1 overflow-y-auto text-xs">
                {[...plano.vincular, ...plano.juntar].map((l) => (
                  <div key={l.product_id} className="border-b border-slate-100 py-1">
                    <div className="text-slate-500 line-through">
                      {l.nome} · {l.sku || l.codigo || "sem SKU"}
                    </div>
                    <div className="font-semibold text-slate-800">
                      → {l.novo_nome} · cód. {l.novo_codigo}
                      {l.nova_referencia && ` · ref. ${l.nova_referencia}`}
                      {l.juntar_com && <span className="ml-1 text-sky-700">(junta com o produto já cadastrado)</span>}
                    </div>
                  </div>
                ))}
              </div>
            </details>
          )}

          {manuais.length > 0 && (
            <div className="rounded-xl bg-white p-3 shadow-sm">
              <div className="text-xs font-bold text-slate-700">Ligar à mão ({manuais.length})</div>
              <p className="text-[11px] text-slate-500">Procure o produto no Microvix e clique em "Ligar".</p>
              <div className="mt-2 max-h-[28rem] space-y-2 overflow-y-auto">
                {manuais.map((l) => (
                  <LigarManual key={l.product_id} linha={l} onLigado={ligadoManual} />
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function Contador({ rotulo, n, cor }) {
  return (
    <div className="rounded-xl bg-white p-2 text-center shadow-sm">
      <div className={`text-xl font-extrabold ${cor}`}>{un(n)}</div>
      <div className="text-[11px] text-slate-500">{rotulo}</div>
    </div>
  );
}

/** Um produto sem vínculo: busca no Microvix e liga ao escolhido. */
function LigarManual({ linha, onLigado }) {
  const [aberto, setAberto] = useState(false);
  const [q, setQ] = useState("");
  const [res, setRes] = useState(null);
  const [ocupado, setOcupado] = useState(false);
  const [erro, setErro] = useState(null);

  function abrir() {
    setAberto(true);
    if (linha.opcoes) {
      setRes(linha.opcoes.map((o) => ({ cod_produto: o.cod_produto, nome: o.nome, sku: o.referencia })));
    } else {
      setQ(linha.nome);
      buscar(linha.nome);
    }
  }

  async function buscar(termo) {
    if (!termo.trim()) return;
    setErro(null);
    setOcupado(true);
    try {
      const r = await buscarCatalogo(termo.trim());
      setRes([...r.data, ...r.sugestoes].filter((p) => p.cod_produto));
    } catch (e) {
      setErro(msgErro(e, "Falha na busca."));
    } finally {
      setOcupado(false);
    }
  }

  async function ligar(p) {
    if (!window.confirm(`Ligar "${linha.nome}" a:\n${p.nome} (cód. ${p.cod_produto})?`)) return;
    setErro(null);
    setOcupado(true);
    try {
      const r = await vincularProdutoMicrovix(linha.product_id, p.cod_produto);
      onLigado(linha.product_id, r.message);
    } catch (e) {
      setErro(msgErro(e, "Não foi possível ligar."));
      setOcupado(false);
    }
  }

  return (
    <div className="rounded-lg border border-slate-100 p-2">
      <div className="flex flex-wrap items-center gap-2">
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold text-slate-800">{linha.nome}</div>
          <div className="text-[11px] text-slate-500">
            {linha.sku ? `SKU ${linha.sku}` : linha.codigo ? `código ${linha.codigo}` : "sem SKU"}
            {linha.barras && ` · barras ${linha.barras}`} · saldo {un(linha.saldo)}
            {linha.opcoes && <span className="ml-1 font-semibold text-amber-700">· {linha.opcoes.length} opções no Microvix</span>}
          </div>
        </div>
        {!aberto && (
          <button type="button" onClick={abrir} className="text-xs font-semibold text-indigo-600 hover:underline">
            Escolher no Microvix
          </button>
        )}
      </div>

      {aberto && (
        <div className="mt-2">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              buscar(q);
            }}
            className="flex gap-2"
          >
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nome, referência ou código" className="input-nuvem py-1.5 text-sm" />
            <button type="submit" disabled={ocupado} className="btn-ghost whitespace-nowrap px-3 py-1.5 text-xs">
              Buscar
            </button>
          </form>
          {erro && <div className="mt-1 text-xs text-rose-700">{erro}</div>}
          {ocupado && <div className="mt-1 text-xs text-slate-500">Aguarde...</div>}
          {res && !ocupado && (
            <div className="mt-1 space-y-1">
              {res.length === 0 && <div className="text-xs text-slate-500">Nada encontrado no Microvix.</div>}
              {res.slice(0, 10).map((p) => (
                <div key={p.cod_produto} className="flex items-center gap-2 rounded-md bg-slate-50 px-2 py-1 text-xs">
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-medium text-slate-800">{p.nome}</div>
                    <div className="font-mono text-[11px] text-slate-500">
                      cód. {p.cod_produto}
                      {p.sku && ` · ref. ${p.sku}`}
                    </div>
                  </div>
                  <button type="button" onClick={() => ligar(p)} className="rounded-full bg-indigo-600 px-3 py-1 font-semibold text-white hover:bg-indigo-700">
                    Ligar
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
