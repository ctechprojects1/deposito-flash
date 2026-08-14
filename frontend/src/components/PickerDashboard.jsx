import { useCallback, useEffect, useState } from "react";
import {
  fetchSolicitacoes,
  iniciarSeparacao,
  confirmarRetirada,
} from "../services/api";

// Em produção, viria do usuário autenticado (separador logado).
const SEPARADOR_ID = 2;

export default function PickerDashboard() {
  const [pendentes, setPendentes] = useState([]);
  const [emSeparacao, setEmSeparacao] = useState([]);
  const [loading, setLoading] = useState(true);
  const [acao, setAcao] = useState(null); // id em processamento
  const [erro, setErro] = useState(null);

  const carregar = useCallback(async () => {
    try {
      setLoading(true);
      setErro(null);
      const [p, e] = await Promise.all([
        fetchSolicitacoes("pendente"),
        fetchSolicitacoes("em_separacao"),
      ]);
      setPendentes(p);
      setEmSeparacao(e);
    } catch (err) {
      setErro("Não foi possível carregar as solicitações.");
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    carregar();
  }, [carregar]);

  async function iniciar(id) {
    setAcao(id);
    setErro(null);
    try {
      await iniciarSeparacao(id, SEPARADOR_ID);
      await carregar();
    } catch (err) {
      setErro(err?.response?.data?.message || "Erro ao iniciar separação.");
    } finally {
      setAcao(null);
    }
  }

  async function confirmar(id) {
    setAcao(id);
    setErro(null);
    try {
      await confirmarRetirada(id);
      await carregar();
    } catch (err) {
      setErro(err?.response?.data?.message || "Erro ao confirmar retirada.");
    } finally {
      setAcao(null);
    }
  }

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center text-gray-500">
        Carregando solicitações...
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl p-4">
      <header className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Painel do Separador</h1>
        <button
          onClick={carregar}
          className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
        >
          Atualizar
        </button>
      </header>

      {erro && (
        <div className="mb-4 rounded-lg bg-red-100 p-3 text-sm text-red-800">
          {erro}
        </div>
      )}

      {/* Kanban de duas colunas */}
      <div className="grid gap-6 md:grid-cols-2">
        <Coluna titulo="Pendentes" cor="bg-yellow-400" total={pendentes.length}>
          {pendentes.length === 0 && <Vazio texto="Nenhuma solicitação pendente." />}
          {pendentes.map((s) => (
            <Card key={s.id} solicitacao={s}>
              <button
                onClick={() => iniciar(s.id)}
                disabled={acao === s.id}
                className="w-full rounded-lg bg-blue-600 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:bg-gray-300"
              >
                {acao === s.id ? "Iniciando..." : "Iniciar Separação"}
              </button>
            </Card>
          ))}
        </Coluna>

        <Coluna titulo="Em Separação" cor="bg-blue-500" total={emSeparacao.length}>
          {emSeparacao.length === 0 && <Vazio texto="Nada em separação." />}
          {emSeparacao.map((s) => (
            <Card key={s.id} solicitacao={s} mostrarLocais>
              <button
                onClick={() => confirmar(s.id)}
                disabled={acao === s.id}
                className="w-full rounded-lg bg-green-600 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:bg-gray-300"
              >
                {acao === s.id ? "Confirmando..." : "Confirmar Retirada"}
              </button>
            </Card>
          ))}
        </Coluna>
      </div>
    </div>
  );
}

function Coluna({ titulo, cor, total, children }) {
  return (
    <div className="rounded-xl bg-gray-100 p-4">
      <div className="mb-4 flex items-center gap-2">
        <span className={`h-3 w-3 rounded-full ${cor}`} />
        <h2 className="font-bold text-gray-700">{titulo}</h2>
        <span className="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-gray-600">
          {total}
        </span>
      </div>
      <div className="space-y-3">{children}</div>
    </div>
  );
}

function Vazio({ texto }) {
  return (
    <p className="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400">
      {texto}
    </p>
  );
}

/**
 * Card de solicitação. Quando `mostrarLocais`, exibe para cada item
 * em qual Time o separador deve ir buscar.
 */
function Card({ solicitacao, mostrarLocais = false, children }) {
  return (
    <div className="rounded-lg bg-white p-4 shadow-sm">
      <div className="mb-2 flex items-center justify-between">
        <span className="text-xs font-semibold text-gray-400">
          #{solicitacao.id}
        </span>
        <span className="text-xs text-gray-500">{solicitacao.criada_em}</span>
      </div>

      <p className="text-sm text-gray-800">
        <span className="font-semibold">Destino:</span> {solicitacao.destino}
      </p>
      <p className="mb-3 text-sm text-gray-600">
        <span className="font-semibold">Solicitante:</span>{" "}
        {solicitacao.solicitante}
      </p>

      <ul className="mb-3 space-y-2">
        {solicitacao.itens.map((item) => (
          <li
            key={item.item_id}
            className="rounded border border-gray-100 bg-gray-50 p-2 text-sm"
          >
            <div className="flex justify-between">
              <span className="font-medium text-gray-800">{item.produto}</span>
              <span className="font-semibold text-gray-700">
                {item.quantidade_solicitada} un.
              </span>
            </div>
            <span className="text-xs text-gray-400">{item.codigo_microvix}</span>

            {/* Onde ir buscar */}
            {mostrarLocais && (
              <div className="mt-1">
                {item.locais_disponiveis?.length > 0 ? (
                  <p className="text-xs text-blue-700">
                    📍 Ir ao Time:{" "}
                    <span className="font-bold">
                      {item.locais_disponiveis[0].nome}
                    </span>{" "}
                    ({item.locais_disponiveis[0].quantidade} un. disponível)
                  </p>
                ) : (
                  <p className="text-xs text-red-600">
                    ⚠ Sem estoque disponível para este item.
                  </p>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>

      {solicitacao.anexo_url && (
        <a
          href={solicitacao.anexo_url}
          target="_blank"
          rel="noreferrer"
          className="mb-3 block text-xs font-medium text-blue-600 hover:underline"
        >
          Ver Nota de Saída ↗
        </a>
      )}

      {children}
    </div>
  );
}
