import { createContext, useContext, useEffect, useState } from "react";
import { apiLogin, apiMe, apiLogout } from "./services/api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  // Ao carregar: se tiver token, busca o usuário.
  useEffect(() => {
    async function boot() {
      const token = localStorage.getItem("token");
      if (!token) {
        setLoading(false);
        return;
      }
      try {
        setUser(await apiMe());
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
    setUser(user);
  }

  async function logout() {
    await apiLogout();
    localStorage.removeItem("token");
    setUser(null);
  }

  // Verifica permissão (admin tem tudo — já vem resolvido do backend).
  function hasPerm(p) {
    return !!user && (user.permissions || []).includes(p);
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, hasPerm }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
