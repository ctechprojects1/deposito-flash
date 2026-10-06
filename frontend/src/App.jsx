import { useEffect, useMemo, useRef, useState } from "react";
import { notificar, pedirPermissaoNotificacao, prepararAudio, tocarAlerta } from "./alertaSonoro";
import { rotuloCD } from "./rotuloCD";
import { useAuth } from "./AuthContext";
import Login from "./components/Login";
import StockMap from "./components/StockMap";
import RequestForm from "./components/RequestForm";
import PickerDashboard from "./components/PickerDashboard";
import ImportScreen from "./components/ImportScreen";
import InventoryScreen from "./components/InventoryScreen";
import MovementScreen from "./components/MovementScreen";
import ReportsScreen from "./components/ReportsScreen";
import UsersScreen from "./components/UsersScreen";
import useAutoRefresh from "./hooks/useAutoRefresh";
import { fetchSolicitacoes } from "./services/api";

const ABAS = [
  { id: "mapa", label: "Mapa do Estoque", perm: "ver_mapa", componente: StockMap },
  { id: "movimentar", label: "Movimentação", perm: "movimentar", componente: MovementScreen },
  { id: "solicitar", label: "Nova Solicitação", perm: "solicitar", componente: RequestForm },
  { id: "separar", label: "Painel do Separador", perm: "separar", componente: PickerDashboard },
  { id: "contagem", label: "Contagem", perm: "contar", componente: InventoryScreen },
  { id: "relatorios", label: "Relatórios", perm: "relatorios", componente: ReportsScreen },
  { id: "importar", label: "Importar Estoque", perm: "importar", componente: ImportScreen },
  { id: "usuarios", label: "Usuários", perm: "admin", componente: UsersScreen },
];

export default function App() {
  const { user, loading, logout, hasPerm, deposito, trocarDeposito } = useAuth();

  // Abas que o usuário pode ver.
  const abas = useMemo(() => (user ? ABAS.filter((a) => hasPerm(a.perm)) : []), [user]);
  const [aba, setAba] = useState(null);

  // Quantas solicitações aguardam separação (badge na aba + título do navegador).
  const podeSeparar = !!user && !!deposito && hasPerm("separar");
  const [aguardando, setAguardando] = useState(0);

  // Aviso sonoro: toca quando aparece na fila uma solicitação que ainda não
  // tinha sido vista (compara pelos números, não só pela quantidade).
  const [somAtivo, setSomAtivo] = useState(() => {
    try {
      return localStorage.getItem("som_separacao") !== "0";
    } catch {
      return true;
    }
  });
  const vistas = useRef(null); // Set de ids já vistos neste CD (null = primeira carga)
  const somRef = useRef(somAtivo);
  somRef.current = somAtivo;

  useEffect(() => prepararAudio(), []);

  const contarAguardando = async () => {
    const lista = await fetchSolicitacoes("pendente");
    setAguardando(lista.length);
    const novas = vistas.current ? lista.filter((s) => !vistas.current.has(s.id)) : [];
    vistas.current = new Set(lista.map((s) => s.id));
    if (novas.length && somRef.current) {
      tocarAlerta();
      const n = novas[0];
      notificar(
        novas.length > 1 ? `${novas.length} novas separações` : `Nova separação #${n.id}`,
        novas.length > 1 ? "Abra o Painel do Separador." : `${n.destino}${deposito ? ` · ${deposito.nome}` : ""}`
      );
    }
  };

  useEffect(() => {
    setAguardando(0);
    vistas.current = null; // trocou de CD: não apita pelo que já estava na fila
    if (podeSeparar) contarAguardando().catch(() => {});
  }, [podeSeparar, deposito?.id]);
  // Continua checando com a aba minimizada, para o aviso tocar mesmo assim.
  useAutoRefresh(contarAguardando, 20000, podeSeparar, { emSegundoPlano: true });

  function alternarSom() {
    const novo = !somAtivo;
    setSomAtivo(novo);
    try {
      localStorage.setItem("som_separacao", novo ? "1" : "0");
    } catch {
      /* ignora */
    }
    if (novo) {
      tocarAlerta(); // teste: o operador ouve como é o aviso
      pedirPermissaoNotificacao();
    }
  }

  useEffect(() => {
    document.title =
      (aguardando > 0 ? `(${aguardando}) ` : "") + "Flash · Endereçamento de Estoque" + (deposito ? ` · ${deposito.nome}` : "");
  }, [aguardando, deposito]);

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-slate-400">Carregando...</div>;
  }

  if (!user) return <Login />;

  // Aba ativa: a escolhida (se ainda permitida) ou a primeira disponível.
  const abaAtual = abas.find((a) => a.id === aba) || abas[0];
  const Ativo = abaAtual?.componente;

  return (
    <div className="min-h-screen pb-16">
      <header className="mx-auto max-w-6xl px-4 pt-6">
        {/* Marca + usuário */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <img src="/logo-flash.png" alt="Flash" className="h-12 w-auto object-contain" />
            <div>
              <h1 className="text-lg font-extrabold leading-tight text-slate-800">Endereçamento de Estoque</h1>
              <p className="text-xs font-medium text-slate-400">Flash Universo de Produtos</p>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <SeletorDeposito lista={user.depositos ?? []} atual={deposito} onTrocar={trocarDeposito} />
            {podeSeparar && (
              <button
                onClick={alternarSom}
                title={somAtivo ? "Aviso sonoro de nova separação: ligado (clique para silenciar)" : "Aviso sonoro desligado (clique para ligar)"}
                className={`flex h-9 w-9 items-center justify-center rounded-full shadow-md transition hover:-translate-y-0.5 ${
                  somAtivo ? "bg-gradient-to-r from-amber-400 to-orange-500 text-white shadow-orange-500/30" : "bg-white text-slate-400"
                }`}
              >
                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0a3 3 0 11-6 0" />
                  {!somAtivo && <path strokeLinecap="round" strokeWidth={2} d="M4 4l16 16" />}
                </svg>
              </button>
            )}
            <span className="hidden text-sm text-slate-500 sm:inline">
              Olá, <strong className="text-slate-700">{user.name}</strong>
            </span>
            <button onClick={logout} className="btn-ghost">Sair</button>
          </div>
        </div>

        {/* Menu em pílulas (só as abas permitidas) */}
        {abas.length > 0 && (
          <nav className="card-nuvem mt-5 flex flex-wrap gap-1 p-1.5">
            {abas.map((a) => {
              const ativo = abaAtual?.id === a.id;
              return (
                <button
                  key={a.id}
                  onClick={() => setAba(a.id)}
                  className={`rounded-full px-4 py-2 text-sm font-semibold transition-all ${
                    ativo
                      ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-lg shadow-indigo-500/30"
                      : "text-slate-500 hover:bg-slate-50 hover:text-indigo-600"
                  }`}
                >
                  {a.label}
                  {a.id === "separar" && aguardando > 0 && (
                    <span
                      title={`${aguardando} solicitação(ões) aguardando separação`}
                      className={`ml-2 inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-xs font-bold ${
                        ativo ? "bg-white text-indigo-600" : "bg-gradient-to-r from-rose-500 to-orange-400 text-white shadow shadow-rose-300"
                      }`}
                    >
                      {aguardando}
                    </span>
                  )}
                </button>
              );
            })}
          </nav>
        )}
      </header>

      <main className="mt-6">
        {!deposito ? (
          <div className="mx-auto max-w-md card-nuvem mt-10 p-8 text-center text-slate-500">
            Nenhum CD liberado para o seu usuário. Fale com o administrador.
          </div>
        ) : Ativo ? (
          // key = CD: ao trocar de CD a tela recarrega do zero com os dados do outro CD.
          <Ativo key={deposito.id} />
        ) : (
          <div className="mx-auto max-w-md card-nuvem mt-10 p-8 text-center text-slate-500">
            Você ainda não tem permissões atribuídas. Fale com o administrador.
          </div>
        )}
      </main>
    </div>
  );
}

// Cor de cada CD (pela ordem), pra ficar óbvio em qual CD se está trabalhando.
const CORES_CD = [
  "from-sky-500 to-indigo-500 shadow-indigo-500/30",
  "from-orange-400 to-rose-500 shadow-rose-500/30",
  "from-emerald-400 to-teal-500 shadow-teal-500/30",
  "from-fuchsia-500 to-purple-500 shadow-purple-500/30",
];

function SeletorDeposito({ lista, atual, onTrocar }) {
  if (lista.length === 0) return null;
  const cor = (d) => CORES_CD[(d.id - 1) % CORES_CD.length];

  if (lista.length === 1) {
    return (
      <span className={`rounded-full bg-gradient-to-r px-4 py-1.5 text-sm font-bold text-white shadow-lg ${cor(lista[0])}`}>
        {rotuloCD(lista[0].nome)}
      </span>
    );
  }

  return (
    <div className="card-nuvem flex items-center gap-1 p-1" title="CD em que você está trabalhando">
      {lista.map((d) => {
        const ativo = atual?.id === d.id;
        return (
          <button
            key={d.id}
            onClick={() => !ativo && onTrocar(d.id)}
            className={`rounded-full px-4 py-1.5 text-sm font-bold transition-all ${
              ativo ? `bg-gradient-to-r text-white shadow-lg ${cor(d)}` : "text-slate-500 hover:bg-slate-50 hover:text-slate-700"
            }`}
          >
            {rotuloCD(d.nome)}
          </button>
        );
      })}
    </div>
  );
}
