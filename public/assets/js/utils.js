(function () {
    if (window.escapeHtml && window.formatarDataHora && window.normalizarTexto
        && window.formatarDuracaoMinutos && window.avisoDoSistema) {
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

    // ── WebSocket ─────────────────────────────────────────────────────────────

    /**
     * Endereço do canal de tempo real.
     *
     * Sob HTTPS o navegador bloqueia `ws://` por mixed content, então a página
     * segura fala `wss://<host>/ws` — proxiado pelo nginx (location /ws). Em
     * HTTP interno continua indo direto na 8080 publicada pelo compose.
     */
    window.urlWebSocket = function urlWebSocket() {
        if (window.location.protocol === 'https:') {
            return 'wss://' + window.location.host + '/ws';
        }

        return 'ws://' + window.location.hostname + ':8080';
    };

    // ── Atividade da aba ──────────────────────────────────────────────────────

    /**
     * "O usuário está olhando para o sistema agora?"
     *
     * Só `document.hidden` não basta: uma janela atrás de outro aplicativo — ou
     * de um monitor que o usuário não está olhando — continua `visible` no
     * Chrome, e um toast ali dentro ninguém vê. É por isso que a janela
     * minimizada/desfocada precisa do pop-up do sistema operacional.
     */
    window.appAtivo = function appAtivo() {
        return !document.hidden && document.hasFocus();
    };

    /** Chama `callback(ativo)` a cada mudança de foco ou de visibilidade. */
    window.aoMudarAtividade = function aoMudarAtividade(callback) {
        function avisar() {
            try {
                callback(window.appAtivo());
            } catch (_) {
                // um ouvinte quebrado não pode derrubar os outros
            }
        }

        document.addEventListener('visibilitychange', avisar);
        window.addEventListener('focus', avisar);
        window.addEventListener('blur', avisar);
    };

    // ── Pop-up do sistema operacional ─────────────────────────────────────────

    // Registro do Service Worker, quando disponível. É ele quem exibe o pop-up
    // no Chrome/Android e quem trata o clique (foca a aba em vez de abrir outra).
    let registroSW = null;

    /**
     * O navegador só expõe Notification e Service Worker em contexto seguro
     * (HTTPS ou localhost). Em `http://<ip>:8188` a permissão é negada de
     * saída — daí o TLS ser pré-requisito, não enfeite. Ver
     * docs/notificacoes.md.
     */
    window.contextoSeguroDeAviso = function contextoSeguroDeAviso() {
        return window.isSecureContext === true && ('Notification' in window);
    };

    window.permissaoDeAviso = function permissaoDeAviso() {
        // A origem insegura vem PRIMEIRO: o Firefox nem expõe `Notification`
        // fora de contexto seguro, e reportar "indisponível" ali mandaria quem
        // for diagnosticar caçar suporte do navegador em vez de olhar o TLS.
        if (!window.isSecureContext) return 'inseguro';
        if (!('Notification' in window)) return 'indisponivel';

        return Notification.permission; // default | granted | denied
    };

    function registrarServiceWorker() {
        if (!window.contextoSeguroDeAviso() || !('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }

        return navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(function (registro) {
                registroSW = registro;
                return registro;
            })
            .catch(function () {
                // Certificado não confiável, escopo errado, SW desabilitado…
                // O aviso cai para `new Notification` e, no limite, para o toast.
                registroSW = null;
                return null;
            });
    }

    /**
     * Pede a permissão de notificação. Firefox e Safari só aceitam a partir de
     * um gesto do usuário, então o caminho normal é o clique no banner de
     * `notificacoes.js` — mas o Chrome aceita no load e é isso que faz o aviso
     * funcionar já na primeira sessão.
     */
    window.pedirPermissaoDeAviso = function pedirPermissaoDeAviso() {
        if (!window.contextoSeguroDeAviso()) {
            return Promise.resolve(window.permissaoDeAviso());
        }
        if (Notification.permission !== 'default') {
            return Promise.resolve(Notification.permission);
        }

        try {
            const resultado = Notification.requestPermission();

            // Safari antigo devolve undefined e usa callback.
            if (!resultado || typeof resultado.then !== 'function') {
                return Promise.resolve(Notification.permission);
            }

            return resultado.then(function (permissao) {
                if (permissao === 'granted') registrarServiceWorker();
                return permissao;
            }).catch(function () {
                return Notification.permission;
            });
        } catch (_) {
            return Promise.resolve(Notification.permission);
        }
    };

    function abrirPelaNotificacao(url) {
        const destino = url || '/notificacoes';
        try {
            window.focus();
        } catch (_) {
            // sem foco programático: o clique ainda navega
        }
        window.location.href = destino;
    }

    /** Dispara o pop-up do SO. Devolve false se não foi possível exibir. */
    function popupDoSistema(aviso) {
        if (window.permissaoDeAviso() !== 'granted') return false;

        const titulo = String(aviso.titulo || 'Chat Interno');
        const opcoes = {
            body: String(aviso.corpo || ''),
            // A tag colapsa avisos do mesmo fato num pop-up só, em vez de
            // empilhar cinco balões da mesma conversa.
            tag: String(aviso.tag || 'chat-interno'),
            renotify: true,
            icon: '/assets/img/icone-192.png',
            badge: '/assets/img/badge-72.png',
            data: { url: String(aviso.url || '/notificacoes') },
        };

        if (registroSW) {
            registroSW.showNotification(titulo, opcoes).catch(function () { });
            return true;
        }

        // Sem Service Worker (registro falhou): o construtor da página ainda
        // resolve no Chrome/Firefox de desktop. No Android ele lança.
        try {
            const popup = new Notification(titulo, opcoes);
            popup.onclick = function () {
                abrirPelaNotificacao(opcoes.data.url);
                popup.close();
            };
            setTimeout(function () { popup.close(); }, 8000);
            return true;
        } catch (_) {
            return false;
        }
    }

    /**
     * PONTO ÚNICO de decisão de aviso — nenhum outro arquivo chama
     * `new Notification` direto.
     *
     * Regra: o som toca sempre; o canal visual depende de o usuário estar ou
     * não com o sistema na frente.
     *
     *   aba ativa (visível E com foco) → false, e o chamador mostra o toast
     *   aba minimizada/desfocada/outra → pop-up do SO, devolve true
     *   sem permissão ou origem insegura → false (toast como último recurso)
     *
     * @param {{titulo: string, corpo: string, url?: string, tag?: string,
     *          tipoSom?: string, chaveSom?: string, forcarPopup?: boolean}} aviso
     * @returns {boolean} true = pop-up exibido; false = cabe ao chamador o toast
     */
    window.avisoDoSistema = function avisoDoSistema(aviso) {
        if (!aviso) return false;

        if (window.SomNotificacoes) {
            window.SomNotificacoes.tocar(aviso.tipoSom || 'geral', aviso.chaveSom || '');
        }

        if (window.appAtivo() && !aviso.forcarPopup) {
            return false;
        }

        return popupDoSistema(aviso);
    };

    // Registra o SW já no load: sem ele o pop-up depende do construtor da
    // página, que não existe no Android e é o primeiro a ser cortado quando a
    // aba perde prioridade.
    if (window.contextoSeguroDeAviso()) {
        registrarServiceWorker();
    }

    window.AvisoSistema = {
        registrarServiceWorker: registrarServiceWorker,
        temServiceWorker: function () { return registroSW !== null; },
    };
})();
