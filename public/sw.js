/**
 * Service Worker do Chat Interno — só notificações.
 *
 * De propósito NÃO existe handler de 'fetch': sem ele o SW não intercepta
 * nenhuma requisição, então não há risco de servir HTML/JS velho de cache nem
 * de quebrar upload, login ou WebSocket. Cache offline fica para outra etapa.
 *
 * Requer contexto seguro (HTTPS ou localhost). Em http://<ip>:8188 o navegador
 * nem registra este arquivo — quem cuida dessa degradação é o push.js.
 */

const URL_PADRAO = '/notificacoes';

self.addEventListener('install', function () {
    // Assume o controle sem esperar as abas antigas fecharem.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    let dados = {};
    try {
        dados = event.data ? event.data.json() : {};
    } catch (_) {
        dados = { titulo: 'Chat Interno', corpo: event.data ? event.data.text() : '' };
    }

    const titulo = String(dados.titulo || 'Chat Interno');
    const opcoes = {
        body: String(dados.corpo || ''),
        // A tag colapsa avisos do mesmo fato (ou da mesma conversa) num popup só.
        tag: String(dados.tag || 'chat-interno'),
        renotify: false,
        icon: '/assets/img/icone-192.png',
        badge: '/assets/img/badge-72.png',
        data: {
            url: String(dados.url || URL_PADRAO),
            notificacao_id: Number(dados.notificacao_id || 0),
            origem: String(dados.origem || 'sistema'),
        },
    };

    event.waitUntil((async function () {
        const janelas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        // Mesmo critério do appAtivo() do front: visível E com foco. Uma janela
        // atrás de outro aplicativo continua 'visible', e um toast ali dentro
        // ninguém veria — esse caso precisa do popup do SO.
        const ativa = janelas.find(function (cliente) {
            return cliente.visibilityState === 'visible' && cliente.focused;
        });

        // O worker já descarta push de quem tem aba ativa, mas existe uma
        // janela de ~2s entre enfileirar e enviar em que o usuário pode voltar
        // para a aba. Nesse caso o aviso vira toast in-page, não popup do SO,
        // para não duplicar o que o WebSocket já mostrou.
        if (ativa) {
            ativa.postMessage({ tipo: 'push_recebido', payload: dados });
            return;
        }

        return self.registration.showNotification(titulo, opcoes);
    })());
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const dados = event.notification.data || {};
    const destino = new URL(String(dados.url || URL_PADRAO), self.location.origin);

    event.waitUntil((async function () {
        const janelas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Reaproveita uma aba já aberta em vez de abrir a décima janela do app.
        for (const janela of janelas) {
            let atual;
            try {
                atual = new URL(janela.url);
            } catch (_) {
                continue;
            }
            if (atual.origin !== destino.origin) {
                continue;
            }

            await janela.focus();
            const mesmaPagina = atual.pathname + atual.search === destino.pathname + destino.search;
            if (!mesmaPagina && 'navigate' in janela) {
                try {
                    await janela.navigate(destino.href);
                } catch (_) {
                    // Alguns navegadores recusam navigate() em aba não controlada.
                }
            }
            return;
        }

        await self.clients.openWindow(destino.href);
    })());
});

/**
 * O navegador pode rotacionar a inscrição por conta própria. Sem re-registrar,
 * o endpoint no banco vira lixo e o usuário para de receber push em silêncio.
 */
self.addEventListener('pushsubscriptionchange', function (event) {
    event.waitUntil((async function () {
        try {
            const resposta = await fetch('/api/push/chave-publica', { credentials: 'same-origin' });
            if (!resposta.ok) return;

            const cfg = await resposta.json();
            if (!cfg || !cfg.chave) return;

            const nova = await self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlParaUint8Array(cfg.chave),
            });

            await fetch('/api/push/inscrever', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(nova.toJSON()),
            });
        } catch (_) {
            // Silencioso: o push.js reinscreve no próximo carregamento de página.
        }
    })());
});

function base64UrlParaUint8Array(base64Url) {
    const preenchimento = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = (base64Url + preenchimento).replace(/-/g, '+').replace(/_/g, '/');
    const bruto = self.atob(base64);
    const saida = new Uint8Array(bruto.length);
    for (let i = 0; i < bruto.length; i++) {
        saida[i] = bruto.charCodeAt(i);
    }
    return saida;
}
