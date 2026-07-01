(function () {
    if (window.NotificationCenterUI) {
        return;
    }

    const state = {
        count: null,
        refreshTimer: null,
    };

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

    function iniciar() {
        if (!badgeElements().length) {
            return;
        }

        fetchResumo();
        if (state.refreshTimer) {
            clearInterval(state.refreshTimer);
        }
        state.refreshTimer = window.setInterval(fetchResumo, 30000);
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
    };
})();