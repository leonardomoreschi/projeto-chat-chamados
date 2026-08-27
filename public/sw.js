/**
 * Service Worker do Chat Interno — só notificações.
 *
 * De propósito NÃO existe handler de 'fetch': sem ele o SW não intercepta
 * nenhuma requisição, então não há risco de servir HTML/JS velho de cache nem
 * de quebrar upload, login ou WebSocket.
 *
 * Este arquivo NÃO fala Web Push (não há handler de 'push'). Quem dispara o
 * pop-up é a própria aba, via `registro.showNotification()` em
 * `avisoDoSistema()` (public/assets/js/utils.js). O SW entra por dois motivos:
 *
 *   1. `showNotification` é o único caminho que funciona no Android e o mais
 *      resistente quando a aba perde prioridade em segundo plano;
 *   2. `notificationclick` roda fora da página, então dá para FOCAR a aba que
 *      já está aberta em vez de abrir uma nova a cada clique.
 *
 * Requer contexto seguro (HTTPS ou localhost). Em http://<ip>:8188 o navegador
 * nem registra este arquivo — a degradação está em `utils.js`.
 */

const URL_PADRAO = '/notificacoes';

self.addEventListener('install', function () {
    // Assume o controle sem esperar as abas antigas fecharem.
    self.skipWaiting();
});

self.addEventListener('activate', function (evento) {
    evento.waitUntil(self.clients.claim());
});

self.addEventListener('notificationclick', function (evento) {
    evento.notification.close();

    const destino = (evento.notification.data && evento.notification.data.url) || URL_PADRAO;

    evento.waitUntil((async function () {
        const janelas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Já existe aba do sistema aberta: foca ela e navega por dentro, em vez
        // de abrir uma quinta janela do mesmo chat.
        for (const janela of janelas) {
            if (!janela.url) continue;

            try {
                const mesmaOrigem = new URL(janela.url).origin === self.location.origin;
                if (!mesmaOrigem) continue;

                await janela.focus();
                if ('navigate' in janela) {
                    await janela.navigate(destino);
                }
                return;
            } catch (_) {
                // navigate() é recusado em alguns estados; o focus já valeu
                return;
            }
        }

        if (self.clients.openWindow) {
            await self.clients.openWindow(destino);
        }
    })());
});
