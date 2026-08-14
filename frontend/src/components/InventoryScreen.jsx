import { useEffect, useMemo, useState } from "react";
import {
  fetchContagens,
  criarContagem,
  fetchContagem,
  salvarContagemItens,
  fetchDivergencias,
  finalizarContagem,
  cancelarContagem,
} from "../services/api";

export default function InventoryScreen() {
  const [contagens, setContagens] = useState([]);
  const [ativa, setAtiva] = useState(null); // objeto da contagem aberta (com endereços)
  const [loading, setLoading] = useState(true);
  const [erro, setErro] = useState(null);

  async function carregarLista() {
    setLoading(true);
    try {
      setContagens(await fetchContagens());
    } catch (e) {
      setErro("Falha ao carregar contagens.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    carregarLista();
  }, []);

  async function abrir(id) {
    setErro(null);
    try {
      setAtiva(await fetchContagem(id));
    } catch (e) {
      setErro("Falha ao abrir a contagem.");
    }
  }

  async function recarregarAtiva() {
    if (ativa) setAtiva(await fetchContagem(ativa.id));
    carregarLista();
  }

  if (loading) {
    return <div className="p-8 text-center text-gray-500">Carregando...</div>;
  }

  return (
    <div className="mx-auto max-w-5xl p-4">
      {erro && (
        <div className="mb-4 rounded-lg bg-red-100 p-3 text-sm text-red-800">{erro}</div>
      )}

      {!ativa ? (
        <ListaContagens
          contagens={contagens}
          onAbrir={abrir}
          onCriou={(c) => {
            carregarLista();
            abrir(c.id);
          }}
        />
      ) : (
        <DetalheContagem
          contagem={ativa}
          onVoltar={() => {
            setAtiva(null);
            carregarLista();
          }}
          onMudou={recarregarAtiva}
        />
      )}
    </div>
  );
}

/* --------------------------- Lista + nova contagem --------------------------- */

function ListaContagens({ contagens, onAbrir, onCriou }) {
  const [descricao, setDescricao] = useState("");
  const [escopo, setEscopo] = useState("");
  const [criando, setCriando] = useState(false);
  const [erro, setErro] = useState(null);

  const temAberta = contagens.some((c) => c.status === "aberta");

  async function iniciar() {
    setCriando(true);
    setErro(null);
    try {
      const c = await criarContagem({
        descricao: descricao.trim() || null,
        escopo_time: escopo.trim() || null,
      });
      onCriou(c);
    } catch (e) {
      setErro(e?.response?.data?.message || "Falha ao iniciar contagem.");
    } finally {
      setCriando(false);
    }
  }

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-800">Contagem / Inventário</h1>

      {erro && (
        <div className="mb-4 rounded-lg bg-red-100 p-3 text-sm text-red-800">{erro}</div>
      )}

      {!temAberta && (
        <div className="mb-6 rounded-xl border border-gray-200 bg-white p-5">
          <h2 className="mb-3 font-semibold text-gray-700">Iniciar nova contagem</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            <input
              value={descricao}
              onChange={(e) => setDescricao(e.target.value)}
              placeholder="Descrição (ex: Balanço Agosto)"
              className="rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <input
              value={escopo}
              onChange={(e) => setEscopo(e.target.value)}
              placeholder="Time específico (opcional — vazio = geral)"
              className="rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <button
            onClick={iniciar}
            disabled={criando}
            className="mt-3 rounded-lg bg-blue-600 px-5 py-2 font-semibold text-white hover:bg-blue-700 disabled:bg-gray-300"
          >
            {criando ? "Iniciando..." : "Iniciar Contagem"}
          </button>
          <p className="mt-2 text-xs text-gray-500">
            Ao iniciar, o sistema congela o estoque atual como base para comparação.
          </p>
        </div>
      )}

      <h2 className="mb-3 font-semibold text-gray-700">Contagens</h2>
      {contagens.length === 0 && (
        <p className="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400">
          Nenhuma contagem ainda.
        </p>
      )}
      <div className="space-y-2">
        {contagens.map((c) => (
          <button
            key={c.id}
            onClick={() => onAbrir(c.id)}
            className="flex w-full items-center justify-between rounded-lg border border-gray-200 bg-white p-4 text-left hover:bg-gray-50"
          >
            <div>
              <span className="font-semibold text-gray-800">
                #{c.id} {c.descricao || "Contagem"}
              </span>
              {c.escopo_time && (
                <span className="ml-2 text-xs text-gray-500">({c.escopo_time})</span>
              )}
              <div className="text-xs text-gray-500">
                {c.criada_em} · {c.itens_contados}/{c.total_itens} itens contados
              </div>
            </div>
            <StatusBadge status={c.status} />
          </button>
        ))}
      </div>
    </div>
  );
}

/* ------------------------------ Detalhe/contar ------------------------------ */

function DetalheContagem({ contagem, onVoltar, onMudou }) {
  const [enderecoSel, setEnderecoSel] = useState(null);
  const [busca, setBusca] = useState("");
  const [mostrarDiverg, setMostrarDiverg] = useState(false);
  const aberta = contagem.status === "aberta";

  const enderecos = contagem.enderecos ?? [];
  const filtrados = useMemo(
    () =>
      enderecos.filter((e) =>
        e.endereco?.toLowerCase().includes(busca.toLowerCase())
      ),
    [enderecos, busca]
  );

  const pct = contagem.total_itens
    ? Math.round((contagem.itens_contados / contagem.total_itens) * 100)
    : 0;

  return (
    <div>
      <button onClick={onVoltar} className="mb-3 text-sm text-blue-600 hover:underline">
        ← Voltar
      </button>

      <div className="mb-4 rounded-xl border border-gray-200 bg-white p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold text-gray-800">
              #{contagem.id} {contagem.descricao || "Contagem"}
            </h1>
            <p className="text-sm text-gray-500">
              {contagem.escopo_time ? `Time: ${contagem.escopo_time}` : "Geral"} ·{" "}
              {contagem.itens_contados}/{contagem.total_itens} itens ({pct}%)
            </p>
          </div>
          <StatusBadge status={contagem.status} />
        </div>
        <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
          <div className="h-full bg-blue-500" style={{ width: `${pct}%` }} />
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          <button
            onClick={() => setMostrarDiverg(true)}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
          >
            Ver divergências
          </button>
          {aberta && (
            <FinalizarBotao contagem={contagem} onMudou={onMudou} onVoltar={onVoltar} />
          )}
        </div>
      </div>

      {aberta ? (
        <div className="grid gap-4 md:grid-cols-[280px_1fr]">
          {/* Lista de endereços */}
          <div className="rounded-xl border border-gray-200 bg-white p-3">
            <input
              value={busca}
              onChange={(e) => setBusca(e.target.value)}
              placeholder="Buscar endereço..."
              className="mb-2 w-full rounded border border-gray-300 px-2 py-1.5 text-sm focus:outline-none"
            />
            <div className="max-h-[60vh] space-y-1 overflow-y-auto">
              {filtrados.map((e) => (
                <button
                  key={e.location_id}
                  onClick={() => setEnderecoSel(e.location_id)}
                  className={`flex w-full items-center justify-between rounded px-3 py-2 text-left text-sm ${
                    enderecoSel === e.location_id
                      ? "bg-blue-100 text-blue-800"
                      : "hover:bg-gray-50"
                  }`}
                >
                  <span>{e.endereco}</span>
                  <span
                    className={`text-xs ${
                      e.contados === e.total ? "text-green-600" : "text-gray-400"
                    }`}
                  >
                    {e.contados}/{e.total}
                  </span>
                </button>
              ))}
            </div>
          </div>

          {/* Painel de contagem do endereço */}
          <div>
            {enderecoSel ? (
              <PainelEndereco
                key={enderecoSel}
                endereco={enderecos.find((e) => e.location_id === enderecoSel)}
                contagemId={contagem.id}
                onSalvou={onMudou}
              />
            ) : (
              <div className="flex h-40 items-center justify-center rounded-xl border border-dashed border-gray-300 text-gray-400">
                Selecione um endereço para contar
              </div>
            )}
          </div>
        </div>
      ) : (
        <p className="rounded-lg bg-gray-50 p-4 text-sm text-gray-600">
          Esta contagem está <strong>{contagem.status}</strong>. Veja as divergências
          registradas no botão acima.
        </p>
      )}

      {mostrarDiverg && (
        <DivergenciasModal
          contagemId={contagem.id}
          onClose={() => setMostrarDiverg(false)}
        />
      )}
    </div>
  );
}

function PainelEndereco({ endereco, contagemId, onSalvou }) {
  const [valores, setValores] = useState(() =>
    Object.fromEntries(endereco.itens.map((i) => [i.item_id, String(i.qtd_contada)]))
  );
  const [salvando, setSalvando] = useState(false);
  const [ok, setOk] = useState(false);

  async function salvar() {
    setSalvando(true);
    setOk(false);
    try {
      const itens = endereco.itens.map((i) => ({
        item_id: i.item_id,
        qtd_contada: Number(valores[i.item_id] || 0),
      }));
      await salvarContagemItens(contagemId, itens);
      setOk(true);
      onSalvou();
    } finally {
      setSalvando(false);
    }
  }

  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
      <h3 className="mb-3 font-bold text-gray-800">{endereco.endereco}</h3>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-gray-500">
            <th className="pb-2 font-medium">Produto</th>
            <th className="pb-2 text-right font-medium">Sistema</th>
            <th className="pb-2 text-right font-medium">Contado</th>
          </tr>
        </thead>
        <tbody>
          {endereco.itens.map((i) => (
            <tr key={i.item_id} className="border-b last:border-0">
              <td className="py-2">
                <div className="text-gray-800">{i.produto}</div>
                <div className="font-mono text-xs text-gray-400">{i.codigo}</div>
              </td>
              <td className="py-2 text-right text-gray-600">{i.qtd_sistema}</td>
              <td className="py-2 text-right">
                <input
                  type="number"
                  min="0"
                  step="any"
                  value={valores[i.item_id]}
                  onChange={(e) =>
                    setValores((v) => ({ ...v, [i.item_id]: e.target.value }))
                  }
                  className="w-24 rounded border border-gray-300 px-2 py-1 text-right focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="mt-4 flex items-center gap-3">
        <button
          onClick={salvar}
          disabled={salvando}
          className="rounded-lg bg-green-600 px-5 py-2 font-semibold text-white hover:bg-green-700 disabled:bg-gray-300"
        >
          {salvando ? "Salvando..." : "Salvar endereço"}
        </button>
        {ok && <span className="text-sm text-green-600">✓ Salvo</span>}
      </div>
    </div>
  );
}

function FinalizarBotao({ contagem, onMudou, onVoltar }) {
  const [busy, setBusy] = useState(false);

  async function finalizar() {
    if (
      !window.confirm(
        "Finalizar a contagem? O estoque será AJUSTADO para as quantidades contadas. Esta ação não pode ser desfeita."
      )
    )
      return;
    setBusy(true);
    try {
      const r = await finalizarContagem(contagem.id);
      alert(r.message);
      onVoltar();
    } catch (e) {
      alert(e?.response?.data?.message || "Erro ao finalizar.");
    } finally {
      setBusy(false);
    }
  }

  async function cancelar() {
    if (!window.confirm("Cancelar a contagem? Nada será ajustado no estoque.")) return;
    setBusy(true);
    try {
      await cancelarContagem(contagem.id);
      onVoltar();
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <button
        onClick={finalizar}
        disabled={busy}
        className="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:bg-gray-300"
      >
        Finalizar e ajustar estoque
      </button>
      <button
        onClick={cancelar}
        disabled={busy}
        className="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
      >
        Cancelar
      </button>
    </>
  );
}

function DivergenciasModal({ contagemId, onClose }) {
  const [dados, setDados] = useState(null);

  useEffect(() => {
    fetchDivergencias(contagemId).then(setDados).catch(() => setDados({ itens: [] }));
  }, [contagemId]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        className="max-h-[80vh] w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b p-4">
          <h2 className="text-lg font-bold text-gray-800">Divergências</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-700">✕</button>
        </div>
        <div className="max-h-[65vh] overflow-y-auto p-4">
          {!dados ? (
            <p className="text-center text-gray-400">Carregando...</p>
          ) : dados.itens.length === 0 ? (
            <p className="py-6 text-center text-green-600">
              Nenhuma divergência 🎉 (o que foi contado bateu com o sistema)
            </p>
          ) : (
            <>
              <p className="mb-3 text-sm text-gray-600">
                {dados.total_divergencias} divergência(s) · {dados.sobras} sobra(s) ·{" "}
                {dados.faltas} falta(s)
              </p>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-gray-500">
                    <th className="pb-2 font-medium">Endereço</th>
                    <th className="pb-2 font-medium">Produto</th>
                    <th className="pb-2 text-right font-medium">Sist.</th>
                    <th className="pb-2 text-right font-medium">Cont.</th>
                    <th className="pb-2 text-right font-medium">Dif.</th>
                  </tr>
                </thead>
                <tbody>
                  {dados.itens.map((d, i) => (
                    <tr key={i} className="border-b last:border-0">
                      <td className="py-2 text-gray-600">{d.endereco}</td>
                      <td className="py-2 text-gray-800">{d.produto}</td>
                      <td className="py-2 text-right text-gray-500">{d.qtd_sistema}</td>
                      <td className="py-2 text-right text-gray-800">{d.qtd_contada}</td>
                      <td
                        className={`py-2 text-right font-semibold ${
                          d.tipo === "sobra" ? "text-blue-600" : "text-red-600"
                        }`}
                      >
                        {d.diferenca > 0 ? "+" : ""}
                        {d.diferenca}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function StatusBadge({ status }) {
  const cores = {
    aberta: "bg-yellow-100 text-yellow-800",
    finalizada: "bg-green-100 text-green-800",
    cancelada: "bg-gray-200 text-gray-600",
  };
  return (
    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${cores[status] || ""}`}>
      {status}
    </span>
  );
}
