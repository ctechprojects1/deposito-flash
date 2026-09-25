import { useCallback, useEffect, useState } from "react";
import {
  fetchSolicitacoes,
  fetchSolicitacao,
  iniciarSeparacao,
  pausarSeparacao,
  atualizarItemSeparacao,
  finalizarSeparacao,
  reabrirSeparacao,
  abrirDocumentoSolicitacao,
} from "../services/api";
import { useAuth } from "../AuthContext";

const STATUS = {
  pendente: { rotulo: "Aguardando", cor: "bg-amber-100 text-amber-800" },
  em_separacao: { rotulo: "Em separação", cor: "bg-sky-100 text-sky-800" },
  pausada: { rotulo: "Pausada", cor: "bg-slate-200 text-slate-700" },
  concluida: { rotulo: "Finalizada", cor: "bg-emerald-100 text-emerald-800" },
};

const FILTROS = [
  { id: "abertas", rotulo: "Abertas", status: "pendente,em_separacao,pausada" },
  { id: "concluidas", rotulo: "Finalizadas", status: "concluida" },
];

const TIPOS = { pedido: "Pedido", nfe: "NF-e", documento_interno: "Doc. interno" };

/**
 * Painel do Separador: fila de solicitações + checklist de separação.
 * Iniciar / pausar / retomar enquanto não finalizar; reabrir só com admin.
 */
export default function PickerDashboard() {
  const [filtro, setFiltro] = useState("abertas");
  const [lista, setLista] = useState([]);
  const [carregando, setCarregando] = useState(true);
  const [erro, setErro] = useState(null);
  const [abertaId, setAbertaId] = useState(null);

  const carregar = useCallback(async () => {
    setCarregando(true);
    setErro(null);
    try {
      const f = FILTROS.find((x) => x.id === filtro);
      setLista(await fetchSolicitacoes(f.status));
    } catch {
      setErro("Não foi possível carregar as solicitações.");
    } finally {
      setCarregando(false);
    }
  }, [filtro]);

  useEffect(() => {
    if (!abertaId) carregar();
  }, [carregar, abertaId]);

  if (abertaId) {
    return <Checklist id={abertaId} onVoltar={() => setAbertaId(null)} />;
  }

  return (
    <div className="mx-auto max-w-5xl px-4">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-extrabold text-slate-800">Painel do Separador</h1>
        <div className="flex items-center gap-2">
          {FILTROS.map((f) => (
            <button
              key={f.id}
              onClick={() => setFiltro(f.id)}
              className={`rounded-full px-4 py-1.5 text-sm font-semibold transition ${
                filtro === f.id
                  ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-md shadow-indigo-200"
                  : "bg-white text-slate-600 shadow-sm hover:bg-slate-50"
              }`}
            >
              {f.rotulo}
            </button>
          ))}
          <button onClick={carregar} className="btn-ghost">Atualizar</button>
        </div>
      </header>

      {erro && <div className="mb-4 rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}

      {carregando ? (
        <div className="flex h-48 items-center justify-center text-slate-500">Carregando solicitações...</div>
      ) : lista.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-400">
          {filtro === "abertas" ? "Nenhuma solicitação aguardando separação." : "Nenhuma separação finalizada."}
        </p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {lista.map((s) => (
            <CardSolicitacao key={s.id} s={s} onAbrir={() => setAbertaId(s.id)} />
          ))}
        </div>
      )}
    </div>
  );
}

function CardSolicitacao({ s, onAbrir }) {
  const st = STATUS[s.status] ?? { rotulo: s.status, cor: "bg-slate-100 text-slate-600" };
  const pct = s.total_itens ? Math.round((s.itens_retirados / s.total_itens) * 100) : 0;

  return (
    <button onClick={onAbrir} className="card-nuvem block p-4 text-left transition hover:-translate-y-0.5 hover:shadow-lg">
      <div className="mb-2 flex items-center justify-between">
        <span className="text-sm font-bold text-slate-700">#{s.id}</span>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${st.cor}`}>{st.rotulo}</span>
      </div>
      <div className="truncate text-sm font-semibold text-slate-800">{s.destino}</div>
      <div className="mt-0.5 text-xs text-slate-500">
        {s.tipo_documento && `${TIPOS[s.tipo_documento] || s.tipo_documento} `}
        {s.numero_documento && `nº ${s.numero_documento} · `}
        {s.criada_em}
      </div>
      <div className="mt-1 text-xs text-slate-500">
        Solicitante: {s.solicitante}
        {s.separador && ` · Separador: ${s.separador}`}
      </div>

      <div className="mt-3">
        <div className="mb-1 flex justify-between text-xs text-slate-500">
          <span>{s.itens_retirados} de {s.total_itens} retirado(s)</span>
          <span>{pct}%</span>
        </div>
        <div className="h-2 overflow-hidden rounded-full bg-slate-100">
          <div className="h-full rounded-full bg-gradient-to-r from-sky-500 to-indigo-500" style={{ width: `${pct}%` }} />
        </div>
      </div>
    </button>
  );
}

function Checklist({ id, onVoltar }) {
  const { hasPerm } = useAuth();
  const [s, setS] = useState(null);
  const [ocupado, setOcupado] = useState(null); // texto da tela de espera
  const [itemOcupado, setItemOcupado] = useState(null);
  const [erro, setErro] = useState(null);
  const [aviso, setAviso] = useState(null);

  const carregar = useCallback(async () => {
    try {
      setS(await fetchSolicitacao(id));
    } catch {
      setErro("Não foi possível carregar a solicitação.");
    }
  }, [id]);

  useEffect(() => {
    carregar();
  }, [carregar]);

  async function executar(texto, fn) {
    setErro(null);
    setAviso(null);
    setOcupado(texto);
    try {
      await fn();
    } catch (e) {
      setErro(e?.response?.data?.message || "Não foi possível concluir a ação.");
      await carregar();
    } finally {
      setOcupado(null);
    }
  }

  async function alterarItem(item, payload) {
    setErro(null);
    setItemOcupado(item.item_id);
    try {
      setS(await atualizarItemSeparacao(id, item.item_id, payload));
    } catch (e) {
      setErro(e?.response?.data?.message || "Não foi possível atualizar o item.");
    } finally {
      setItemOcupado(null);
    }
  }

  if (!s) {
    return (
      <div className="mx-auto max-w-4xl px-4">
        {erro ? (
          <div className="rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>
        ) : (
          <div className="flex h-48 items-center justify-center text-slate-500">Carregando...</div>
        )}
      </div>
    );
  }

  const st = STATUS[s.status] ?? { rotulo: s.status, cor: "bg-slate-100 text-slate-600" };
  const emSeparacao = s.status === "em_separacao";
  const concluida = s.status === "concluida";
  const tudoMarcado = s.total_itens > 0 && s.itens_retirados === s.total_itens;

  return (
    <div className="mx-auto max-w-4xl px-4 pb-8">
      {ocupado && <Carregando titulo={ocupado} />}

      <button onClick={onVoltar} className="mb-3 text-sm font-medium text-indigo-600 hover:underline">
        ← Voltar para a fila
      </button>

      <div className="card-nuvem mb-4 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl font-extrabold text-slate-800">Solicitação #{s.id}</h1>
              <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${st.cor}`}>{st.rotulo}</span>
            </div>
            <div className="mt-1 text-sm font-semibold text-slate-700">{s.destino}</div>
            <div className="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500">
              {s.tipo_documento && (
                <span>{TIPOS[s.tipo_documento] || s.tipo_documento}{s.numero_documento && ` nº ${s.numero_documento}`}</span>
              )}
              <span>Criada {s.criada_em} por {s.solicitante}</span>
              {s.separador && <span>Separador: {s.separador}</span>}
              {s.iniciada_em && <span>Iniciada {s.iniciada_em}</span>}
              {s.finalizada_em && <span>Finalizada {s.finalizada_em}</span>}
              {s.reaberta_em && <span>Reaberta {s.reaberta_em} por {s.reaberta_por}</span>}
            </div>
            {s.observacao && <div className="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">{s.observacao}</div>}
          </div>
          {s.tem_documento && (
            <button
              className="btn-ghost text-sm"
              onClick={() => abrirDocumentoSolicitacao(id).catch(() => setErro("Não foi possível abrir o documento."))}
            >
              Ver documento
            </button>
          )}
        </div>

        <div className="mt-4">
          <div className="mb-1 flex justify-between text-xs text-slate-500">
            <span>{s.itens_retirados} de {s.total_itens} retirado(s)</span>
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full bg-gradient-to-r from-sky-500 to-indigo-500 transition-all"
              style={{ width: `${s.total_itens ? (s.itens_retirados / s.total_itens) * 100 : 0}%` }}
            />
          </div>
        </div>
      </div>

      {erro && <div className="mb-4 rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}
      {aviso && <div className="mb-4 rounded-xl bg-emerald-100 p-3 text-sm text-emerald-800">{aviso}</div>}

      {!emSeparacao && !concluida && (
        <div className="mb-4 rounded-xl bg-sky-50 p-3 text-sm text-sky-800">
          {s.status === "pausada" ? "Separação pausada. Retome para continuar marcando os itens." : "Inicie a separação para marcar os itens."}
        </div>
      )}

      <div className="card-nuvem mb-4 divide-y divide-slate-100">
        {s.itens.map((it) => (
          <ItemSeparacao
            key={it.item_id}
            item={it}
            editavel={emSeparacao}
            ocupado={itemOcupado === it.item_id}
            concluida={concluida}
            onAlterar={(payload) => alterarItem(it, payload)}
          />
        ))}
      </div>

      {/* Ações */}
      <div className="card-nuvem sticky bottom-4 flex flex-wrap items-center justify-end gap-3 p-4">
        {s.status === "pendente" && (
          <button className="btn-nuvem" onClick={() => executar("Iniciando separação...", async () => setS(await iniciarSeparacao(id)))}>
            Iniciar separação
          </button>
        )}
        {s.status === "pausada" && (
          <button className="btn-nuvem" onClick={() => executar("Retomando separação...", async () => setS(await iniciarSeparacao(id)))}>
            Retomar separação
          </button>
        )}
        {emSeparacao && (
          <>
            <button className="btn-ghost" onClick={() => executar("Pausando...", async () => setS(await pausarSeparacao(id)))}>
              Pausar
            </button>
            <button
              className="btn-ok"
              disabled={!tudoMarcado}
              title={tudoMarcado ? "" : "Marque todos os itens como retirados"}
              onClick={() => {
                if (!window.confirm("Finalizar a separação? O estoque dos itens será baixado e só um administrador poderá reabrir.")) return;
                executar("Finalizando e baixando o estoque...", async () => {
                  const r = await finalizarSeparacao(id);
                  setS(r.data);
                  setAviso(r.message || "Separação finalizada.");
                });
              }}
            >
              Finalizar separação
            </button>
          </>
        )}
        {concluida && hasPerm("admin") && (
          <button
            className="btn-nuvem"
            onClick={() => {
              if (!window.confirm("Reabrir esta separação? As quantidades baixadas voltam para o estoque.")) return;
              executar("Reabrindo separação...", async () => {
                const r = await reabrirSeparacao(id);
                setS(r.data);
                setAviso(r.message || "Separação reaberta.");
              });
            }}
          >
            Reabrir separação
          </button>
        )}
        {concluida && !hasPerm("admin") && (
          <span className="text-sm text-slate-500">Separação finalizada. Para reabrir, fale com um administrador.</span>
        )}
      </div>
    </div>
  );
}

function ItemSeparacao({ item, editavel, ocupado, concluida, onAlterar }) {
  const marcado = item.retirado;
  const localEscolhido = item.locais.find((l) => l.location_id === item.location_id);
  const semSaldo = !concluida && localEscolhido && localEscolhido.quantidade < item.quantidade_solicitada;

  return (
    <div className={`flex flex-wrap items-center gap-3 p-3 transition ${marcado ? "bg-emerald-50/60" : ""} ${ocupado ? "opacity-60" : ""}`}>
      <button
        disabled={!editavel || ocupado}
        onClick={() => onAlterar({ retirado: !marcado })}
        title={marcado ? "Desmarcar" : "Marcar como retirado"}
        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 transition disabled:cursor-not-allowed ${
          marcado ? "border-emerald-500 bg-gradient-to-r from-emerald-400 to-teal-500 text-white" : "border-slate-300 bg-white"
        }`}
      >
        {marcado && (
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
          </svg>
        )}
      </button>

      <div className="min-w-0 flex-1">
        <div className={`truncate text-sm font-medium ${marcado ? "text-slate-500 line-through" : "text-slate-800"}`}>
          {item.produto || item.descricao_documento}
        </div>
        <div className="font-mono text-xs text-slate-400">cód. {item.codigo_microvix}</div>
      </div>

      <div className="w-44">
        <div className="text-[11px] text-slate-500">Endereço</div>
        {editavel && !marcado ? (
          <select
            value={item.location_id ?? ""}
            disabled={ocupado}
            onChange={(e) => onAlterar({ location_id: e.target.value ? Number(e.target.value) : null })}
            className="w-full rounded-xl border border-slate-200 bg-white px-2 py-1.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-200"
          >
            {!item.location_id && <option value="">Escolha...</option>}
            {item.locais.map((l) => (
              <option key={l.location_id} value={l.location_id}>
                {l.nome} ({l.quantidade})
              </option>
            ))}
          </select>
        ) : (
          <div className="text-sm font-bold text-indigo-700">{item.endereco || "—"}</div>
        )}
        {semSaldo && <div className="mt-0.5 text-[11px] text-amber-600">saldo {localEscolhido.quantidade} no endereço</div>}
      </div>

      <div className="w-16 text-right">
        <div className="text-[11px] text-slate-500">{concluida ? "baixado" : "retirar"}</div>
        <div className="text-lg font-extrabold text-slate-800">
          {concluida ? item.quantidade_separada : item.quantidade_solicitada}
        </div>
        {item.quantidade_documento > 0 && item.quantidade_documento !== item.quantidade_solicitada && (
          <div className="text-[11px] text-slate-400">nota: {item.quantidade_documento}</div>
        )}
      </div>
    </div>
  );
}

function Carregando({ titulo }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/70 backdrop-blur-sm">
      <div className="card-nuvem flex flex-col items-center gap-3 px-8 py-6 text-center">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-500" />
        <div className="font-bold text-slate-800">{titulo}</div>
      </div>
    </div>
  );
}
