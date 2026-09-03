import { useState } from "react";
import { importarEstoque } from "../services/api";

export default function ImportScreen() {
  const [arquivo, setArquivo] = useState(null);
  const [somar, setSomar] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState(null);
  const [erro, setErro] = useState(null);

  async function enviar(e) {
    e.preventDefault();
    setErro(null);
    setResultado(null);

    if (!arquivo) {
      setErro("Selecione um arquivo CSV.");
      return;
    }

    setEnviando(true);
    try {
      const res = await importarEstoque(arquivo, somar);
      setResultado(res);
    } catch (err) {
      setErro(err?.response?.data?.message || "Falha ao importar o arquivo.");
    } finally {
      setEnviando(false);
    }
  }

  return (
    <div className="mx-auto max-w-2xl p-4">
      <h1 className="mb-2 text-2xl font-extrabold text-slate-800">
        Importar Estoque (CSV)
      </h1>
      <p className="mb-6 text-sm text-gray-500">
        A planilha precisa ter as colunas:{" "}
        <code className="rounded bg-gray-100 px-1 font-mono">Time</code>,{" "}
        <code className="rounded bg-gray-100 px-1 font-mono">Codigo_Produto</code>,{" "}
        <code className="rounded bg-gray-100 px-1 font-mono">Nome</code>,{" "}
        <code className="rounded bg-gray-100 px-1 font-mono">Quantidade</code>.
        O sistema cria automaticamente os Times e produtos que ainda não existem.
      </p>

      {erro && (
        <div className="mb-4 rounded-lg bg-red-100 p-3 text-sm text-red-800">
          {erro}
        </div>
      )}

      <form onSubmit={enviar} className="card-nuvem space-y-5 p-5">
        <div>
          <label className="mb-1 block font-semibold text-gray-700">
            Arquivo CSV
          </label>
          <input
            type="file"
            accept=".csv,.txt,text/csv"
            onChange={(e) => {
              setArquivo(e.target.files?.[0] ?? null);
              setResultado(null);
            }}
            className="block w-full text-sm text-gray-600 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-blue-700 hover:file:bg-blue-100"
          />
          {arquivo && (
            <p className="mt-1 text-xs text-gray-500">
              {arquivo.name} ({(arquivo.size / 1024).toFixed(0)} KB)
            </p>
          )}
        </div>

        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={somar}
            onChange={(e) => setSomar(e.target.checked)}
            className="h-4 w-4"
          />
          Somar às quantidades já existentes (em vez de sobrescrever)
        </label>

        <button type="submit" disabled={enviando || !arquivo} className="btn-nuvem w-full py-3">
          {enviando ? "Importando..." : "Importar"}
        </button>
      </form>

      {resultado && (
        <div className="mt-6 rounded-xl border border-green-200 bg-green-50 p-5">
          <h2 className="mb-3 font-bold text-green-800">Importação concluída ✓</h2>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Contador rotulo="Linhas OK" valor={resultado.importados} cor="text-green-700" />
            <Contador rotulo="Ignoradas" valor={resultado.ignorados} cor="text-yellow-700" />
            <Contador rotulo="Locais" valor={resultado.locais} cor="text-gray-700" />
            <Contador rotulo="Produtos" valor={resultado.produtos} cor="text-gray-700" />
          </div>

          {resultado.erros?.length > 0 && (
            <div className="mt-4">
              <p className="mb-1 text-sm font-semibold text-yellow-800">
                Avisos ({resultado.erros.length}):
              </p>
              <ul className="max-h-40 overflow-y-auto rounded border border-yellow-200 bg-white p-2 text-xs text-gray-600">
                {resultado.erros.map((e, i) => (
                  <li key={i}>{e}</li>
                ))}
              </ul>
            </div>
          )}

          <p className="mt-4 text-sm text-gray-600">
            Abra a aba <strong>Mapa do Estoque</strong> para ver o resultado.
          </p>
        </div>
      )}
    </div>
  );
}

function Contador({ rotulo, valor, cor }) {
  return (
    <div className="card-nuvem p-3 text-center">
      <div className={`text-2xl font-extrabold ${cor}`}>{valor}</div>
      <div className="text-xs text-slate-500">{rotulo}</div>
    </div>
  );
}
