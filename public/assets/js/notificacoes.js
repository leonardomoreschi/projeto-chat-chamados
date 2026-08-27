/**
 * Central de notificações do front: sino, badge, toast e o disparo do aviso.
 *
 * Duas entradas, um único caminho de saída:
 *
 *   notificar()             ← linha da tabela `notificacoes` (chamado/agendamento)
 *   notificarMensagemChat() ← evento `new_message` do WebSocket
 *                                        │
 *                                        └─► window.avisoDoSistema() (utils.js)
 *                                              aba ativa  → toast in-page
 *                                              minimizada → pop-up do SO
 *
 * Mensagem de chat NÃO cria linha em `notificacoes` (fora do sino e da central,
 * de propósito), mas avisa igual: quem chama `notificarMensagemChat()` é o
 * `chat.js` na tela /chat e o `menu-lateral.js` em todas as demais.
 */
(function () {
    if (window.NotificationCenterUI) {
        return;
    }

    const CHAVE_BANNER_DISPENSADO = 'aviso-permissao-dispensado';
    const MAX_IDS_MEMORIZADOS = 500;

    const state = {
        count: null,
        refreshTimer: null,
        socket: null,
        reconectarTimer: null,
        pingTimer: null,
        autenticado: false,
        sessaoEncerrada: false,
        idsProcessados: new Set(),
        mensagensProcessadas: new Set(),
    };

    function usuarioAtual() {
        return window.APP_USER || {};
    }

    function papelAtual() {
        return String(usuarioAtual().papel || '');
    }

    /** Evita que os Sets de deduplicação cresçam para sempre numa aba antiga. */
    function memorizar(conjunto, chave) {
        if (conjunto.has(chave)) return false;

        conjunto.add(chave);
        if (conjunto.size > MAX_IDS_MEMORIZADOS) {
            const maisAntigo = conjunto.values().next().value;
            conjunto.delete(maisAntigo);
        }

        return true;
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
            return gestor ? 'agendamento' : 'atualizacao';
        }
        if (evento === 'novo_chamado') {
            return gestor ? 'chamado' : 'atualizacao';
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

    // ── Toast in-page ─────────────────────────────────────────────────────────

    function mostrarToast(notificacao) {
        if (!notificacao) return;

        const titulo = String(notificacao.titulo || 'Nova notificação');
        const mensagem = String(notificacao.mensagem || '');
        const url = String(notificacao.url || '');
        // Mesma distinção da central: aviso que espera resposta vem em âmbar.
        const exigeAcao = Boolean(notificacao.exige_acao);

        const toast = document.createElement('div');
        toast.className = 'fixed bottom-6 right-6 z-50 w-[min(100vw-2rem,24rem)] rounded-2xl border '
            + (exigeAcao ? 'border-amber-400/70' : 'border-indigo-500/30')
            + ' bg-gray-900 text-white shadow-2xl shadow-black/30 overflow-hidden';
        toast.innerHTML =
            '<div class="px-4 py-3 flex items-start gap-3">' +
            '<div class="mt-0.5 h-8 w-8 shrink-0 rounded-xl ' + (exigeAcao ? 'bg-amber-500' : 'bg-indigo-600') + ' flex items-center justify-center text-white text-sm font-black">!</div>' +
            '<div class="min-w-0 flex-1">' +
            (exigeAcao ? '<p class="text-[10px] font-black uppercase tracking-widest text-amber-300 mb-0.5">Requer ação</p>' : '') +
            '<p class="text-sm font-semibold ' + (exigeAcao ? 'text-amber-200' : 'text-indigo-300') + ' truncate">' + escapeHtml(titulo) + '</p>' +
            '<p class="mt-1 text-xs leading-relaxed text-gray-300">' + escapeHtml(mensagem) + '</p>' +
            '</div>' +
            (url ? '<a href="' + escapeHtml(url) + '" class="text-xs font-bold ' + (exigeAcao ? 'text-amber-300 hover:text-amber-200' : 'text-indigo-300 hover:text-indigo-200') + ' shrink-0">Abrir</a>' : '') +
            '</div>';
        document.body.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 5000);
    }

    // ── Disparo do aviso ──────────────────────────────────────────────────────

    /** Notificação da central (chamado, agendamento, aviso do sistema). */
    function notificar(notificacao) {
        if (!notificacao) return;

        // Uma notificação só é processada uma vez por aba, mesmo que chegue de
        // dois caminhos (socket da página + socket deste módulo) ou reentrega.
        const id = Number(notificacao.id || 0);
        if (id > 0 && !memorizar(state.idsProcessados, 'notif:' + id)) {
            return;
        }

        updateBadgeDelta(1);

        const chave = id > 0
            ? 'notif:' + id
            : 'notif:' + String(notificacao.chave_evento || notificacao.titulo || '');

        const exibiuPopup = window.avisoDoSistema({
            titulo: String(notificacao.titulo || 'Nova notificação'),
            corpo: String(notificacao.mensagem || ''),
            url: String(notificacao.url || '/notificacoes'),
            tag: chave,
            tipoSom: tipoSomDaNotificacao(notificacao),
            chaveSom: chave,
        });

        // "Nunca sai só o som": se o pop-up não pôde ser exibido — aba ativa,
        // permissão negada ou origem insegura — o toast é obrigatório.
        if (!exibiuPopup) {
            mostrarToast(notificacao);
        }
    }

    /**
     * Mensagem de chat recebida. Chamado pelo dono do socket em cada tela.
     *
     * @param {object} mensagem  payload de `new_message` do WebSocket
     * @param {{conversaAberta?: boolean}} [opcoes] a conversa está na tela?
     */
    function notificarMensagemChat(mensagem, opcoes) {
        if (!mensagem || !mensagem.id) return;

        const meuId = Number(usuarioAtual().id || 0);
        if (Number(mensagem.usuario_id) === meuId) return;   // eco da própria mensagem

        if (!memorizar(state.mensagensProcessadas, 'msg:' + Number(mensagem.id))) {
            return;
        }

        const conversaAberta = Boolean(opcoes && opcoes.conversaAberta);

        // Conversa aberta na tela e o usuário olhando: a mensagem já apareceu no
        // painel, o som basta. Minimizado/desfocado, porém, ele precisa do
        // pop-up mesmo que a conversa esteja "aberta" atrás da outra janela.
        if (conversaAberta && window.appAtivo()) {
            if (window.SomNotificacoes) {
                window.SomNotificacoes.tocar('mensagem', 'msg:' + mensagem.id);
            }
            return;
        }

        const autor = String(mensagem.usuario_nome || 'Contato');
        const corpo = String(mensagem.conteudo || '').trim() || 'Enviou um anexo';
        const conversaId = Number(mensagem.conversa_id || 0);

        const exibiuPopup = window.avisoDoSistema({
            titulo: 'Nova mensagem de ' + autor,
            corpo: corpo.substring(0, 140),
            url: conversaId > 0 ? '/chat?conversa=' + conversaId : '/chat',
            // Uma conversa colapsa num pop-up só: dez mensagens seguidas do
            // mesmo colega não viram dez balões empilhados.
            tag: 'conversa:' + conversaId,
            tipoSom: 'mensagem',
            chaveSom: 'msg:' + mensagem.id,
        });

        if (!exibiuPopup) {
            mostrarToast({
                titulo: 'Nova mensagem de ' + autor,
                mensagem: corpo.substring(0, 140),
                url: conversaId > 0 ? '/chat?conversa=' + conversaId : '/chat',
            });
        }
    }

    // ── Permissão do navegador ────────────────────────────────────────────────

    function bannerDispensado() {
        try {
            return window.localStorage.getItem(CHAVE_BANNER_DISPENSADO) === '1';
        } catch (_) {
            return false;
        }
    }

    function dispensarBanner() {
        try {
            window.localStorage.setItem(CHAVE_BANNER_DISPENSADO, '1');
        } catch (_) {
            // sem persistência: o banner volta na próxima tela
        }
    }

    /**
     * Banner de um clique. Existe por dois motivos que se resolvem juntos:
     * o Firefox só concede a permissão a partir de um gesto do usuário, e a
     * política de autoplay só libera o áudio depois de um gesto também.
     */
    function mostrarBannerPermissao() {
        if (document.getElementById('banner-permissao-aviso')) return;

        const banner = document.createElement('div');
        banner.id = 'banner-permissao-aviso';
        banner.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-50 w-[min(100vw-2rem,30rem)] '
            + 'rounded-2xl border border-indigo-500/40 bg-gray-900 text-white shadow-2xl shadow-black/40';
        banner.innerHTML =
            '<div class="px-4 py-3 flex items-center gap-3">' +
            '<div class="h-9 w-9 shrink-0 rounded-xl bg-indigo-600 flex items-center justify-center text-lg">🔔</div>' +
            '<div class="min-w-0 flex-1">' +
            '<p class="text-sm font-semibold text-indigo-200">Ativar avisos do sistema</p>' +
            '<p class="text-xs text-gray-400 mt-0.5">Receba mensagens e chamados mesmo com a janela minimizada.</p>' +
            '</div>' +
            '<button type="button" data-permitir class="shrink-0 text-xs font-bold bg-indigo-600 hover:bg-indigo-500 rounded-xl px-3 py-2 transition">Ativar</button>' +
            '<button type="button" data-dispensar class="shrink-0 text-gray-500 hover:text-gray-300 text-lg leading-none px-1" title="Agora não">×</button>' +
            '</div>';

        banner.querySelector('[data-permitir]').addEventListener('click', function () {
            // O mesmo clique libera o áudio (política de autoplay) e a permissão.
            if (window.SomNotificacoes) window.SomNotificacoes.desbloquear();
            window.pedirPermissaoDeAviso().then(function () { banner.remove(); });
        });

        banner.querySelector('[data-dispensar]').addEventListener('click', function () {
            dispensarBanner();
            banner.remove();
        });

        document.body.appendChild(banner);
    }

    function prepararPermissao() {
        const situacao = window.permissaoDeAviso();

        if (situacao === 'inseguro') {
            console.warn(
                '[avisos] Origem insegura (' + window.location.origin + '): o navegador bloqueia '
                + 'notificações e Service Worker. Só o toast in-page vai funcionar. '
                + 'Ver docs/notificacoes.md.'
            );
            return;
        }

        if (situacao === 'indisponivel' || situacao === 'granted') {
            return;
        }

        if (situacao === 'denied') {
            // Uma vez negada, o navegador não deixa perguntar de novo por JS —
            // só pelo cadeado da barra de endereço. Insistir aqui seria ruído.
            console.warn('[avisos] Permissão de notificação negada para esta origem.');
            return;
        }

        // situacao === 'default': o Chrome concede sem gesto; os demais não.
        window.pedirPermissaoDeAviso().then(function (permissao) {
            if (permissao === 'default' && !bannerDispensado()) {
                mostrarBannerPermissao();
            }
        });
    }

    // ── Leitura ───────────────────────────────────────────────────────────────

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

    // ── WebSocket ─────────────────────────────────────────────────────────────

    // Páginas com socket próprio (chat.js, menu-lateral.js, agendamentos.js)
    // marcam APP_USER.socketProprio e encaminham os eventos por
    // handleRealtimeNotification / notificarMensagemChat; as demais (admin,
    // relatório) ganham aqui uma conexão mínima.
    function conectarSocket() {
        const usuario = usuarioAtual();
        if (usuario.socketProprio || !usuario.id || !('WebSocket' in window)) {
            return;
        }
        if (state.socket && (state.socket.readyState === WebSocket.OPEN || state.socket.readyState === WebSocket.CONNECTING)) {
            return;
        }

        try {
            state.socket = new WebSocket(window.urlWebSocket());
        } catch (_) {
            return;
        }

        state.socket.onopen = function () {
            state.autenticado = false;
            state.socket.send(JSON.stringify({
                type: 'auth',
                user_id: usuario.id,
                user_nome: usuario.nome || '',
                user_papel: usuario.papel || 'usuario',
                conversa_id: 0,
            }));

            // Mantém a conexão comprovadamente ativa. Serve para dois fins: o
            // Chrome não congela aba que segura conexão viva (é o que faz o
            // aviso chegar com a janela minimizada) e o proxy não derruba o
            // socket por ociosidade.
            clearInterval(state.pingTimer);
            state.pingTimer = window.setInterval(function () {
                if (state.socket && state.socket.readyState === WebSocket.OPEN) {
                    state.socket.send(JSON.stringify({ type: 'ping' }));
                }
            }, 25000);
        };

        state.socket.onmessage = function (event) {
            let msg;
            try {
                msg = JSON.parse(event.data);
            } catch (_) {
                return;
            }

            switch (msg.type) {
                case 'auth_ok':
                    // Antes disto vem o replay das últimas 100 mensagens, que não
                    // pode virar uma saraivada de pop-ups a cada F5.
                    state.autenticado = true;
                    break;
                case 'notification_created':
                    notificar(msg.notification);
                    break;
                case 'new_message':
                    if (state.autenticado) notificarMensagemChat(msg.message);
                    break;
                case 'sessao_encerrada':
                    // Conta alterada pelo admin: o servidor fecha a conexão e a
                    // sessão HTTP já não vale mais.
                    state.sessaoEncerrada = true;
                    window.location.href = '/login';
                    break;
            }
        };

        state.socket.onclose = function () {
            state.autenticado = false;
            clearInterval(state.pingTimer);
            if (state.sessaoEncerrada) return;
            if (state.reconectarTimer) clearTimeout(state.reconectarTimer);
            state.reconectarTimer = window.setTimeout(conectarSocket, 3000);
        };
    }

    function iniciar() {
        prepararPermissao();

        if (!badgeElements().length && usuarioAtual().socketProprio) {
            // Tela sem sino e com socket de outro módulo: nada a fazer aqui além
            // da permissão, que já foi pedida acima.
            return;
        }

        fetchResumo();
        if (state.refreshTimer) {
            clearInterval(state.refreshTimer);
        }
        // Rede de segurança: o socket é a via principal, o poll reconcilia o badge.
        state.refreshTimer = window.setInterval(fetchResumo, 30000);
        conectarSocket();

        // Voltar para a aba depois de um tempo fora: reconcilia o que o socket
        // possa ter perdido enquanto o navegador segurava os timers.
        window.aoMudarAtividade(function (ativo) {
            if (!ativo) return;
            fetchResumo();
            conectarSocket();
        });
    }

    document.addEventListener('DOMContentLoaded', iniciar);

    window.NotificationCenterUI = {
        renderBadge: renderBadge,
        fetchResumo: fetchResumo,
        notificar: notificar,
        notificarMensagemChat: notificarMensagemChat,
        mostrarToast: mostrarToast,
        marcarComoLida: marcarComoLida,
        marcarTodasComoLidas: marcarTodasComoLidas,
        handleRealtimeNotification: notificar,
        conectarSocket: conectarSocket,
        pedirPermissao: function () { return window.pedirPermissaoDeAviso(); },
    };
})();
