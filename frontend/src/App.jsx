import { useState } from "react";
import StockMap from "./components/StockMap";
import RequestForm from "./components/RequestForm";
import PickerDashboard from "./components/PickerDashboard";
import ImportScreen from "./components/ImportScreen";
import InventoryScreen from "./components/InventoryScreen";

const ABAS = [
  { id: "mapa", label: "Mapa do Estoque", componente: StockMap },
  { id: "solicitar", label: "Nova Solicitação", componente: RequestForm },
  { id: "separar", label: "Painel do Separador", componente: PickerDashboard },
  { id: "contagem", label: "Contagem", componente: InventoryScreen },
  { id: "importar", label: "Importar Estoque", componente: ImportScreen },
];

export default function App() {
  const [aba, setAba] = useState("mapa");
  const Ativo = ABAS.find((a) => a.id === aba).componente;

  return (
    <div className="min-h-screen">
      {/* Navbar simples (troque por um router + guards de perfil depois) */}
      <nav className="border-b bg-white shadow-sm">
        <div className="mx-auto flex max-w-6xl gap-1 px-4">
          {ABAS.map((a) => (
            <button
              key={a.id}
              onClick={() => setAba(a.id)}
              className={`border-b-2 px-4 py-3 text-sm font-medium transition ${
                aba === a.id
                  ? "border-blue-600 text-blue-600"
                  : "border-transparent text-gray-500 hover:text-gray-700"
              }`}
            >
              {a.label}
            </button>
          ))}
        </div>
      </nav>

      <Ativo />
    </div>
  );
}
