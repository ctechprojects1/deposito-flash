import { useEffect, useState } from "react";
import {
  fetchUsuarios,
  fetchPermissoes,
  criarUsuario,
  atualizarUsuario,
  excluirUsuario,
} from "../services/api";

const PERM_LABELS = {
  ver_mapa: "Ver mapa",
  movimentar: "Movimentação",
  solicitar: "Solicitar retirada",
  separar: "Separação",
  contar: "Contagem / Inventário",
  importar: "Importar CSV",
  gerenciar_enderecos: "Gerenciar endereços",
  admin: "Administração (usuários)",
};

export default function UsersScreen() {
  const [usuarios, setUsuarios] = useState([]);
  const [permissoes, setPermissoes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editando, setEditando] = useState(null); // objeto user ou {} p/ novo
  const [erro, setErro] = useState(null);

  async function carregar() {
    setLoading(true);
    try {
      const [u, p] = await Promise.all([fetchUsuarios(), fetchPermissoes()]);
      setUsuarios(u);
      setPermissoes(p);
    } catch (e) {
      setErro("Falha ao carregar usuários.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    carregar();
  }, []);

  async function excluir(u) {
    if (!window.confirm(`Excluir o usuário ${u.name}?`)) return;
    try {
      await excluirUsuario(u.id);
      carregar();
    } catch (e) {
      alert(e?.response?.data?.message || "Erro ao excluir.");
    }
  }

  if (loading) return <div className="p-8 text-center text-slate-400">Carregando...</div>;

  return (
    <div className="mx-auto max-w-4xl px-4">
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-2xl font-extrabold text-slate-800">Usuários & Permissões</h1>
        <button onClick={() => setEditando({})} className="btn-nuvem">Novo usuário</button>
      </div>

      {erro && <div className="mb-4 rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}

      <div className="space-y-2">
        {usuarios.map((u) => (
          <div key={u.id} className="card-nuvem flex flex-wrap items-center justify-between gap-3 p-4">
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <span className="font-bold text-slate-800">{u.name}</span>
                {u.is_admin && <span className="chip">admin</span>}
                {!u.ativo && <span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-500">inativo</span>}
              </div>
              <div className="text-sm text-slate-500">{u.email}</div>
              <div className="mt-1 flex flex-wrap gap-1">
                {u.is_admin ? (
                  <span className="text-xs text-indigo-500">acesso total</span>
                ) : u.permissions.length === 0 ? (
                  <span className="text-xs text-slate-400">sem permissões</span>
                ) : (
                  u.permissions.map((p) => (
                    <span key={p} className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                      {PERM_LABELS[p] || p}
                    </span>
                  ))
                )}
              </div>
            </div>
            <div className="flex gap-2">
              <button onClick={() => setEditando(u)} className="btn-ghost">Editar</button>
              <button
                onClick={() => excluir(u)}
                className="inline-flex items-center rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-medium text-rose-600 transition hover:bg-rose-50"
              >
                Excluir
              </button>
            </div>
          </div>
        ))}
      </div>

      {editando && (
        <UserModal
          user={editando}
          permissoes={permissoes}
          onClose={() => setEditando(null)}
          onSaved={() => {
            setEditando(null);
            carregar();
          }}
        />
      )}
    </div>
  );
}

function UserModal({ user, permissoes, onClose, onSaved }) {
  const novo = !user.id;
  const [name, setName] = useState(user.name || "");
  const [email, setEmail] = useState(user.email || "");
  const [password, setPassword] = useState("");
  const [perms, setPerms] = useState(user.permissions || []);
  const [ativo, setAtivo] = useState(user.ativo ?? true);
  const [salvando, setSalvando] = useState(false);
  const [erro, setErro] = useState(null);

  function toggle(p) {
    setPerms((s) => (s.includes(p) ? s.filter((x) => x !== p) : [...s, p]));
  }

  async function salvar() {
    setErro(null);
    if (!name.trim() || !email.trim()) return setErro("Preencha nome e e-mail.");
    if (novo && password.length < 6) return setErro("Senha de no mínimo 6 caracteres.");

    setSalvando(true);
    try {
      const payload = { name: name.trim(), email: email.trim(), permissions: perms, ativo };
      if (password) payload.password = password;
      if (novo) await criarUsuario(payload);
      else await atualizarUsuario(user.id, payload);
      onSaved();
    } catch (e) {
      setErro(e?.response?.data?.message || "Erro ao salvar.");
    } finally {
      setSalvando(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="card-nuvem w-full max-w-lg overflow-hidden" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-sky-50 to-indigo-50 p-5">
          <h2 className="text-xl font-extrabold text-slate-800">{novo ? "Novo usuário" : "Editar usuário"}</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700">✕</button>
        </div>

        <div className="max-h-[70vh] space-y-4 overflow-y-auto p-5">
          {erro && <div className="rounded-xl bg-rose-100 p-3 text-sm text-rose-800">{erro}</div>}

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-semibold text-slate-700">Nome</label>
              <input value={name} onChange={(e) => setName(e.target.value)} className="input-nuvem" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-semibold text-slate-700">E-mail</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className="input-nuvem" />
            </div>
          </div>

          <div>
            <label className="mb-1 block text-sm font-semibold text-slate-700">
              Senha {novo ? "" : <span className="font-normal text-slate-400">(deixe em branco p/ manter)</span>}
            </label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className="input-nuvem" placeholder="••••••••" />
          </div>

          <div>
            <label className="mb-2 block text-sm font-semibold text-slate-700">Permissões</label>
            <div className="flex flex-wrap gap-2">
              {permissoes.map((p) => {
                const on = perms.includes(p);
                return (
                  <button
                    key={p}
                    type="button"
                    onClick={() => toggle(p)}
                    className={`rounded-full px-3.5 py-1.5 text-sm font-semibold transition ${
                      on
                        ? "bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-md shadow-indigo-500/30"
                        : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                    }`}
                  >
                    {PERM_LABELS[p] || p}
                  </button>
                );
              })}
            </div>
            {perms.includes("admin") && (
              <p className="mt-2 text-xs text-indigo-500">Com "Administração", o usuário tem acesso total.</p>
            )}
          </div>

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={ativo} onChange={(e) => setAtivo(e.target.checked)} className="h-4 w-4" />
            Usuário ativo (pode entrar no sistema)
          </label>
        </div>

        <div className="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3">
          <button onClick={onClose} className="btn-ghost">Cancelar</button>
          <button onClick={salvar} disabled={salvando} className="btn-nuvem">
            {salvando ? "Salvando..." : "Salvar"}
          </button>
        </div>
      </div>
    </div>
  );
}
