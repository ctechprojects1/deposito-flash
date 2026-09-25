import { useEffect, useRef } from "react";

/**
 * Chama `fn` a cada `ms` milissegundos, sem precisar de F5.
 * - Pausa enquanto a aba do navegador está escondida e atualiza ao voltar.
 * - Não empilha chamadas: se a anterior ainda não terminou, pula a vez.
 */
export default function useAutoRefresh(fn, ms, enabled = true) {
  const fnRef = useRef(fn);
  fnRef.current = fn;

  useEffect(() => {
    if (!enabled) return undefined;

    let timer = null;
    let rodando = false;

    const tick = async () => {
      if (document.hidden || rodando) return;
      rodando = true;
      try {
        await fnRef.current();
      } catch {
        /* falha momentânea: tenta de novo no próximo ciclo */
      } finally {
        rodando = false;
      }
    };

    const parar = () => {
      if (timer) clearInterval(timer);
      timer = null;
    };
    const iniciar = () => {
      parar();
      timer = setInterval(tick, ms);
    };
    const aoMudarVisibilidade = () => {
      if (document.hidden) {
        parar();
      } else {
        tick();
        iniciar();
      }
    };

    iniciar();
    document.addEventListener("visibilitychange", aoMudarVisibilidade);
    return () => {
      parar();
      document.removeEventListener("visibilitychange", aoMudarVisibilidade);
    };
  }, [ms, enabled]);
}
