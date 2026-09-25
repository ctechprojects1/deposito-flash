import { useEffect, useMemo, useState } from "react";
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
  const { user, loading, logout, hasPerm } = useAuth();

  // Abas que o usuário pode ver.
  const abas = useMemo(() => (user ? ABAS.filter((a) => hasPerm(a.perm)) : []), [user]);
  const [aba, setAba] = useState(null);

  // Quantas solicitações aguardam separação (badge na aba + título do navegador).
  const podeSeparar = !!user && hasPerm("separar");
  const [aguardando, setAguardando] = useState(0);
  const contarAguardando = async () => setAguardando((await fetchSolicitacoes("pendente")).length);

  useEffect(() => {
    if (podeSeparar) contarAguardando().catch(() => {});
    else setAguardando(0);
  }, [podeSeparar]);
  useAutoRefresh(contarAguardando, 20000, podeSeparar);

  useEffect(() => {
    document.title = (aguardando > 0 ? `(${aguardando}) ` : "") + "Flash · Endereçamento de Estoque";
  }, [aguardando]);

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
          <div className="flex items-center gap-3">
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
        {Ativo ? (
          <Ativo />
        ) : (
          <div className="mx-auto max-w-md card-nuvem mt-10 p-8 text-center text-slate-500">
            Você ainda não tem permissões atribuídas. Fale com o administrador.
          </div>
        )}
      </main>
    </div>
  );
}
