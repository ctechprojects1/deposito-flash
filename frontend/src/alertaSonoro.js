/**
 * Aviso sonoro de nova separação (sem arquivo de áudio: gera o "ding-dong"
 * com Web Audio). O navegador só libera som depois de um clique na página,
 * por isso o áudio é "destravado" no primeiro clique do usuário.
 */
let ctx = null;

function contexto() {
  if (!ctx) {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
  }
  return ctx;
}

/** Chamar uma vez no início: destrava o áudio no primeiro clique/tecla. */
export function prepararAudio() {
  const destravar = () => {
    const c = contexto();
    if (c && c.state === "suspended") c.resume();
  };
  window.addEventListener("pointerdown", destravar, { passive: true });
  window.addEventListener("keydown", destravar);
}

function nota(c, freq, inicio, duracao) {
  const osc = c.createOscillator();
  const ganho = c.createGain();
  osc.type = "sine";
  osc.frequency.value = freq;
  ganho.gain.setValueAtTime(0.0001, inicio);
  ganho.gain.exponentialRampToValueAtTime(0.35, inicio + 0.02);
  ganho.gain.exponentialRampToValueAtTime(0.0001, inicio + duracao);
  osc.connect(ganho).connect(c.destination);
  osc.start(inicio);
  osc.stop(inicio + duracao + 0.05);
}

/** "Ding-dong" duas vezes. */
export function tocarAlerta() {
  const c = contexto();
  if (!c) return;
  if (c.state === "suspended") c.resume();
  const t = c.currentTime + 0.05;
  [0, 0.9].forEach((d) => {
    nota(c, 1046.5, t + d, 0.45); // dó
    nota(c, 784, t + d + 0.28, 0.6); // sol
  });
}

/** Notificação do sistema (aparece mesmo com a aba minimizada), se permitida. */
export function notificar(titulo, corpo) {
  try {
    if ("Notification" in window && Notification.permission === "granted") {
      const n = new Notification(titulo, { body: corpo, icon: "/logo-flash.png", tag: "nova-separacao" });
      n.onclick = () => {
        window.focus();
        n.close();
      };
    }
  } catch {
    /* navegador sem suporte */
  }
}

export async function pedirPermissaoNotificacao() {
  try {
    if ("Notification" in window && Notification.permission === "default") {
      await Notification.requestPermission();
    }
  } catch {
    /* ignora */
  }
}
