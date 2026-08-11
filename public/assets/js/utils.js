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