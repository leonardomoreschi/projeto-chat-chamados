(function () {
    if (window.escapeHtml && window.formatarDataHora && window.normalizarTexto && window.formatarDuracaoMinutos) {
        return;
    }

    /**
     * Sessão derrubada pelo servidor → volta para o login.
     *
     * O AuthMiddleware responde 401 nas rotas /api quando a conta teve e-mail,
     * senha ou papel alterados (ou foi desativada). Sem isto o fetch receberia
     * o 401 calado e a tela continuaria aberta, aparentando funcionar.
     */
    const fetchOriginal = window.fetch.bind(window);

    window.fetch = function (...argumentos) {
        return fetchOriginal(...argumentos).then(function (resposta) {
            if (resposta.status === 401 && !window.redirecionandoParaLogin) {
                window.redirecionandoParaLogin = true;
                window.location.href = '/login';
            }

            return resposta;
        });
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