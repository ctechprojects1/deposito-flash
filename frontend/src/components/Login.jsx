import { useState } from "react";
import { useAuth } from "../AuthContext";

export default function Login() {
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [erro, setErro] = useState(null);
  const [entrando, setEntrando] = useState(false);

  async function enviar(e) {
    e.preventDefault();
    setErro(null);
    setEntrando(true);
    try {
      await login(email.trim(), password);
    } catch (err) {
      const msg =
        err?.response?.data?.errors?.email?.[0] ||
        err?.response?.data?.message ||
        "Não foi possível entrar.";
      setErro(msg);
    } finally {
      setEntrando(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex flex-col items-center gap-3">
          <img src="/logo-flash.png" alt="Flash Universo de Produtos" className="h-24 w-auto object-contain" />
          <div className="text-center">
            <h1 className="text-lg font-extrabold text-slate-800">Endereçamento de Estoque</h1>
          </div>
        </div>

        <form onSubmit={enviar} className="card-nuvem space-y-4 p-6">
          <h2 className="text-center text-lg font-bold text-slate-700">Entrar</h2>

          {erro && <div className="rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}

          <div>
            <label className="mb-1 block text-sm font-semibold text-slate-700">E-mail</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoFocus
              className="input-nuvem"
              placeholder="voce@empresa.com"
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-semibold text-slate-700">Senha</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="input-nuvem"
              placeholder="••••••••"
            />
          </div>

          <button type="submit" disabled={entrando || !email || !password} className="btn-nuvem w-full py-3">
            {entrando ? "Entrando..." : "Entrar"}
          </button>
        </form>
      </div>
    </div>
  );
}
