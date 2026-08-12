(function () {
    if (window.escapeHtml && window.formatarDataHora && window.normalizarTexto && window.formatarDuracaoMinutos) {
        return;
    }

    /**
     * URL do WebSocket derivada do protocolo da página.
     *
     * Hoje (HTTP na LAN) o resultado é o mesmo ws://<host>:8080 de sempre. Sob
     * HTTPS o navegador recusa ws:// por mixed content e a porta 8080 nem
     * existe atrás do proxy TLS, então o caminho passa a ser wss://<host>/ws —
     * ver docs/push-web-producao-tls.md.
     */
    window.urlWebSocket = function urlWebSocket() {
        if (window.location.protocol === 'https:') {
            return 'wss://' + window.location.host + '/ws';
        }

        return 'ws://' + window.location.hostname + ':8080';
    };

    /**
     * "O usuário está de fato olhando esta aba agora?"
     *
     * Critério único de todo o sistema de avisos: visível E com foco. Só
     * `document.hidden` não basta — uma janela atrás de outro aplicativo
     * continua 'visible' no Chrome, e um toast ali dentro ninguém vê.
     */
    window.appAtivo = function appAtivo() {
        return !document.hidden && document.hasFocus();
    };

    /**
     * Chama `callback(ativo)` sempre que o estado acima mudar (e uma vez já na
     * assinatura). Devolve uma função que reenvia o estado atual — útil para
     * reavisar o servidor depois que o WebSocket reconecta.
     */
    window.aoMudarAtividade = function aoMudarAtividade(callback) {
        let ultimo = null;

        function avaliar() {
            const atual = window.appAtivo();
            if (atual === ultimo) return;
            ultimo = atual;
            callback(atual);
        }

        document.addEventListener('visibilitychange', avaliar);
        window.addEventListener('focus', avaliar);
        window.addEventListener('blur', avaliar);
        avaliar();

        return function reenviar() {
            ultimo = null;
            avaliar();
        };
    };

    /**
     * Aviso para quem não está com a aba na frente.
     *
     * Retorna true quando o aviso já está garantido (popup do SO desta página,
     * ou push que o Service Worker vai mostrar) e false quando o chamador deve
     * mostrar o toast in-page. Nunca devolve true sem que algo apareça: era
     * exatamente esse o furo que deixava mensagem de chat só com som.
     */
    window.avisoDoSistema = function avisoDoSistema(titulo, corpo) {
        // Aba na frente: o toast in-page é o aviso certo.
        if (window.appAtivo()) return false;

        // Com push inscrito neste dispositivo o popup vem do Service Worker;
        // repetir aqui daria dois avisos para o mesmo fato.
        if (window.PushWeb && typeof window.PushWeb.inscrito === 'function' && window.PushWeb.inscrito()) {
            return true;
        }

        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return false;
        }

        try {
            const notificacao = new Notification(String(titulo || 'Chat Interno'), {
                body: String(corpo || ''),
                icon: '/assets/img/icone-192.png',
            });
            setTimeout(function () { notificacao.close(); }, 5000);
            return true;
        } catch (_) {
            // Chrome no Android proíbe o construtor (só ServiceWorker
            // .showNotification): cai no toast.
            return false;
        }
    };

    window.escapeHtml = function escapeHtml(valor) {
        return String(valor || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    window.formatarDataHora = function formatarDataHora(valorData) {
        if (!valorData) return 'Não informado';

        const base = typeof valorData === 'string' && !valorData.includes('T')
            ? valorData.replace(' ', 'T') + '-03:00'
            : valorData;

        const data = new Date(base);
        if (Number.isNaN(data.getTime())) return 'Não informado';

        return data.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
    };

    window.normalizarTexto = function normalizarTexto(valor) {
        return String(valor || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '');
    };

    window.formatarDuracaoMinutos = function formatarDuracaoMinutos(minutos) {
        const total = Number(minutos || 0);
        if (!total || total < 1) return '0h';

        const horas = total / 60;
        if (horas < 1) {
            return Math.round(total) + 'min';
        }

        return horas.toFixed(horas >= 10 ? 0 : 1) + 'h';
    };
})();