(function () {
    if (window.NotificationCenterUI) {
        return;
    }

    const state = {
        count: null,
        refreshTimer: null,
        socket: null,
        reconectarTimer: null,
        idsProcessados: new Set(),
    };

    function usuarioAtual() {
        return window.APP_USER || {};
    }

    function papelAtual() {
        return String(usuarioAtual().papel || '');
    }

    // Eventos que encerram algo sem sucesso ganham o timbre descendente.
    const EVENTOS_CANCELAMENTO = ['cancelado', 'recusado'];

    // Timbre por evento. O gate de papel é defesa em profundidade: o backend já
    // só cria as notificações de novo_agendamento/novo_chamado para ti e admin.
    function tipoSomDaNotificacao(notificacao) {
        const evento = String(notificacao.evento || '');
        const tipo = String(notificacao.tipo || '');
        const gestor = papelAtual() === 'admin' || papelAtual() === 'ti';

        // Item novo entrando na fila: timbre próprio, mais chamativo.
        if (evento === 'novo_agendamento') {
            return gestor ? 'agendamento' : null;
        }
        if (evento === 'novo_chamado') {
            return gestor ? 'chamado' : null;
        }
        if (EVENTOS_CANCELAMENTO.indexOf(evento) !== -1) {
            return 'cancelamento';
        }

        // Qualquer outra movimentação de chamado ou agendamento.
        if (tipo === 'chamado' || tipo === 'agendamento') {
            return 'atualizacao';
        }

        return 'geral';
    }

    function tocarSom(notificacao) {
        if (!window.SomNotificacoes) return;

        const tipoSom = tipoSomDaNotificacao(notificacao);
        if (!tipoSom) return;

        const id = Number(notificacao.id || 0);
        const chave = id > 0
            ? 'notif:' + id
            : 'notif:' + String(notificacao.chave_evento || notificacao.titulo || '');

        window.SomNotificacoes.tocar(tipoSom, chave);
    }

    function badgeElements() {
        return Array.from(document.querySelectorAll('[data-notification-badge]'));
    }

    function renderBadge(count) {
        state.count = Math.max(0, Number(count || 0));
        badgeElements().forEach(function (badge) {
            if (state.count > 0) {
                badge.textContent = String(state.count);
                badge.classList.remove('hidden');
            } else {
                badge.textContent = '';
                badge.classList.add('hidden');
            }
        });
    }

    function updateBadgeDelta(delta) {
        const atual = state.count === null ? 0 : state.count;
        renderBadge(atual + delta);
    }

    async function fetchResumo() {
        try {
            const res = await fetch('/api/notificacoes/resumo');
            if (!res.ok) return;
            const data = await res.json();
            renderBadge(data && typeof data.nao_lidas !== 'undefined' ? data.nao_lidas : 0);
        } catch (_) {
            // Sem fallback visível: o badge continua com o valor já renderizado.
        }
    }

    function mostrarToast(notificacao) {
        if (!notificacao) return;

        const titulo = String(notificacao.titulo || 'Nova notificação');
        const mensagem = String(notificacao.mensagem || '');
        const url = String(notificacao.url || '');

        const toast = document.createElement('div');
        toast.className = 'fixed bottom-6 right-6 z-50 w-[min(100vw-2rem,24rem)] rounded-2xl border border-indigo-500/30 bg-gray-900 text-white shadow-2xl shadow-black/30 overflow-hidden';
        toast.innerHTML =
            '<div class="px-4 py-3 flex items-start gap-3">' +
            '<div class="mt-0.5 h-8 w-8 shrink-0 rounded-xl bg-indigo-600 flex items-center justify-center text-white text-sm font-black">!</div>' +
            '<div class="min-w-0 flex-1">' +
            '<p class="text-sm font-semibold text-indigo-300 truncate">' + escapeHtml(titulo) + '</p>' +
            '<p class="mt-1 text-xs leading-relaxed text-gray-300">' + escapeHtml(mensagem) + '</p>' +
            '</div>' +
            (url ? '<a href="' + url + '" class="text-xs font-bold text-indigo-300 hover:text-indigo-200 shrink-0">Abrir</a>' : '') +
            '</div>';
        document.body.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 5000);
    }

    function notificar(notificacao) {
        if (!notificacao) return;

        // Uma notificação só é processada uma vez por aba, mesmo que chegue de
        // dois caminhos (socket da página + socket deste módulo) ou reentrega.
        const id = Number(notificacao.id || 0);
        if (id > 0) {
            if (state.idsProcessados.has(id)) return;
            state.idsProcessados.add(id);
        }

        tocarSom(notificacao);
        updateBadgeDelta(1);

        if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
            const n = new Notification(String(notificacao.titulo || 'Nova notificação'), {
                body: String(notificacao.mensagem || ''),
            });
            setTimeout(function () { n.close(); }, 5000);
            return;
        }

        mostrarToast(notificacao);
    }

    async function marcarComoLida(id) {
        if (!id) return;
        try {
            const res = await fetch('/api/notificacoes/' + id + '/lida', { method: 'PATCH' });
            if (!res.ok) return;
            await fetchResumo();
        } catch (_) {
            // silencioso
        }
    }

    async function marcarTodasComoLidas() {
        try {
            const res = await fetch('/api/notificacoes/lida', { method: 'PATCH' });
            if (!res.ok) return;
            await fetchResumo();
        } catch (_) {
            // silencioso
        }
    }

    // Páginas com socket próprio (chat.js, agendamentos.js) marcam
    // APP_USER.socketProprio e encaminham o evento por handleRealtimeNotification;
    // as demais ganham aqui uma conexão mínima (auth + notification_created).
    function conectarSocket() {
        const usuario = usuarioAtual();
        if (usuario.socketProprio || !usuario.id || !('WebSocket' in window)) {
            return;
        }
        if (state.socket && (state.socket.readyState === WebSocket.OPEN || state.socket.readyState === WebSocket.CONNECTING)) {
            return;
        }

        try {
            state.socket = new WebSocket('ws://' + window.location.hostname + ':8080');
        } catch (_) {
            return;
        }

        state.socket.onopen = function () {
            state.socket.send(JSON.stringify({
                type: 'auth',
                user_id: usuario.id,
                user_nome: usuario.nome || '',
                user_papel: usuario.papel || 'usuario',
                conversa_id: 0,
            }));
        };

        state.socket.onmessage = function (event) {
            try {
                const msg = JSON.parse(event.data);
                if (msg.type === 'notification_created') {
                    notificar(msg.notification);
                } else if (msg.type === 'sessao_encerrada') {
                    // Conta alterada pelo admin: o servidor fecha a conexão e a
                    // sessão HTTP já não vale mais.
                    state.sessaoEncerrada = true;
                    window.location.href = '/login';
                }
            } catch (_) {
                // payload inesperado: ignora
            }
        };

        state.socket.onclose = function () {
            if (state.sessaoEncerrada) return;
            if (state.reconectarTimer) clearTimeout(state.reconectarTimer);
            state.reconectarTimer = window.setTimeout(conectarSocket, 3000);
        };
    }

    function iniciar() {
        if (!badgeElements().length) {
            return;
        }

        fetchResumo();
        if (state.refreshTimer) {
            clearInterval(state.refreshTimer);
        }
        // Rede de segurança: o socket é a via principal, o poll reconcilia o badge.
        state.refreshTimer = window.setInterval(fetchResumo, 30000);
        conectarSocket();
    }

    document.addEventListener('DOMContentLoaded', iniciar);

    window.NotificationCenterUI = {
        renderBadge: renderBadge,
        fetchResumo: fetchResumo,
        notificar: notificar,
        mostrarToast: mostrarToast,
        marcarComoLida: marcarComoLida,
        marcarTodasComoLidas: marcarTodasComoLidas,
        handleRealtimeNotification: notificar,
        conectarSocket: conectarSocket,
    };
})();