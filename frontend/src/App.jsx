import { useState } from "react";
import StockMap from "./components/StockMap";
import RequestForm from "./components/RequestForm";
import PickerDashboard from "./components/PickerDashboard";
import ImportScreen from "./components/ImportScreen";
import InventoryScreen from "./components/InventoryScreen";
import MovementScreen from "./components/MovementScreen";

const ABAS = [
  { id: "mapa", label: "Mapa do Estoque", icone: "🗺️", componente: StockMap },
  { id: "movimentar", label: "Movimentação", icone: "🔄", componente: MovementScreen },
  { id: "solicitar", label: "Nova Solicitação", icone: "📝", componente: RequestForm },
  { id: "separar", label: "Painel do Separador", icone: "📦", componente: PickerDashboard },
  { id: "contagem", label: "Contagem", icone: "✓", componente: InventoryScreen },
  { id: "importar", label: "Importar Estoque", icone: "⬆️", componente: ImportScreen },
];

export default function App() {
  const [aba, setAba] = useState("mapa");
  const Ativo = ABAS.find((a) => a.id === aba).componente;

  return (
    <div className="min-h-screen pb-16">
      <header className="mx-auto max-w-6xl px-4 pt-6">
        {/* Marca */}
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-500 text-xl shadow-lg shadow-indigo-500/30">
            📦
          </div>
          <div>
            <h1 className="text-lg font-extrabold leading-tight text-slate-800">
              Endereçamento de Estoque
            </h1>
            <p className="text-xs font-medium text-slate-400">Depósito Flash</p>
          </div>
        </div>

        {/* Menu em pílulas */}
        <nav className="card-nuvem mt-5 flex flex-wrap gap-1 p-1.5">
          {ABAS.map((a) => {
            const ativo = aba === a.id;
            return (
              <button
                key={a.id}
                onClick={() => setAba(a.id)}
                className={`flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-all ${
                  ativo
                    ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-lg shadow-indigo-500/30"
                    : "text-slate-500 hover:bg-slate-50 hover:text-indigo-600"
                }`}
              >
                <span className="text-xs">{a.icone}</span>
                {a.label}
              </button>
            );
          })}
        </nav>
      </header>

      <main className="mt-6">
        <Ativo />
      </main>
    </div>
  );
}
