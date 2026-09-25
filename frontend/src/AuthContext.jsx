import { createContext, useContext, useEffect, useState } from "react";
import { apiLogin, apiMe, apiLogout } from "./services/api";

const AuthContext = createContext(null);

/** Mantém o CD salvo se o usuário ainda tiver acesso; senão, o primeiro liberado. */
function escolherDeposito(user) {
  const lista = user?.depositos ?? [];
  const salvo = Number(localStorage.getItem("deposito_id"));
  const id = lista.some((d) => d.id === salvo) ? salvo : lista[0]?.id ?? null;
  if (id) localStorage.setItem("deposito_id", String(id));
  else localStorage.removeItem("deposito_id");
  return id;
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [depositoId, setDepositoId] = useState(null);

  // Ao carregar: se tiver token, busca o usuário.
  useEffect(() => {
    async function boot() {
      const token = localStorage.getItem("token");
      if (!token) {
        setLoading(false);
        return;
      }
      try {
        const u = await apiMe();
        setDepositoId(escolherDeposito(u));
        setUser(u);
      } catch {
        localStorage.removeItem("token");
      } finally {
        setLoading(false);
      }
    }
    boot();

    // Interceptor de 401 dispara este evento.
    function onLogout() {
      setUser(null);
    }
    window.addEventListener("auth:logout", onLogout);
    return () => window.removeEventListener("auth:logout", onLogout);
  }, []);

  async function login(email, password) {
    const { token, user } = await apiLogin(email, password);
    localStorage.setItem("token", token);
    setDepositoId(escolherDeposito(user));
    setUser(user);
  }

  async function logout() {
    await apiLogout();
    localStorage.removeItem("token");
    setUser(null);
  }

  function trocarDeposito(id) {
    localStorage.setItem("deposito_id", String(id));
    setDepositoId(id);
  }

  const deposito = user?.depositos?.find((d) => d.id === depositoId) ?? null;

  // Verifica permissão (admin tem tudo — já vem resolvido do backend).
  function hasPerm(p) {
    return !!user && (user.permissions || []).includes(p);
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, hasPerm, deposito, trocarDeposito }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
