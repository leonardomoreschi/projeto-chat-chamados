/**
 * Menu lateral fixo das telas fora do chat.
 *
 * Mantém a lista de conversas viva sem sair da tela atual: carrega por HTTP e
 * atualiza pelo WebSocket (mesma conexão que traz as notificações). Clicar em
 * uma conversa leva para /chat?conversa=ID.
 *
 * O estado minimizado fica no localStorage, então acompanha o usuário de uma
 * tela para outra.
 */
(function () {
    if (window.MenuLateral) {
        return;
    }

    const CHAVE_RECOLHIDO = 'menu-lateral:recolhido';

    const estado = {
        conversas: [],
        socket: null,
        reconectarTimer: null,
        autenticado: false,
        sessaoEncerrada: false,
    };

    function usuario() {
        return window.APP_USER || {};
    }

    function elMenu() {
        return document.querySelector('[data-menu-lateral]');
    }

    // ── Minimizar ─────────────────────────────
    function estaRecolhido() {
        try {
            return window.localStorage.getItem(CHAVE_RECOLHIDO) === '1';
        } catch (_) {
            return false;
        }
    }

    function guardarRecolhido(valor) {
        try {
            if (valor) {
                window.localStorage.setItem(CHAVE_RECOLHIDO, '1');
            } else {
                window.localStorage.removeItem(CHAVE_RECOLHIDO);
            }
        } catch (_) {
            // localStorage bloqueado: o estado vale só para esta tela.
        }
    }

    function aplicarRecolhido(recolhido) {
        const menu = elMenu();
        if (!menu) return;

        menu.classList.toggle('w-72', !recolhido);
        menu.classList.toggle('w-16', recolhido);

        menu.querySelectorAll('[data-menu-conteudo]').forEach(function (el) {
            el.classList.toggle('hidden', recolhido);
        });
        menu.querySelectorAll('[data-menu-recolhido]').forEach(function (el) {
            el.classList.toggle('hidden', !recolhido);
            el.classList.toggle('flex', recolhido);
        });

        const botao = menu.querySelector('[data-menu-toggle]');
        if (botao) {
            botao.title = recolhido ? 'Expandir menu' : 'Minimizar menu';
            botao.classList.toggle('mx-auto', recolhido);
        }

        const icone = menu.querySelector('[data-menu-icone]');
        if (icone) {
            // Setas viradas para o lado em que o menu vai se mover.
            icone.innerHTML = recolhido
                ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>'
                : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>';
        }
    }

    function alternarRecolhido() {
        const novo = !estaRecolhido();
        guardarRecolhido(novo);
        aplicarRecolhido(novo);
    }

    // ── Conversas ─────────────────────────────
    function totalNaoLidas() {
        return estado.conversas.reduce(function (acc, c) {
            return acc + (parseInt(c.nao_lidas, 10) || 0);
        }, 0);
    }

    function renderizar() {
        const lista = document.getElementById('menu-lista-conversas');
        if (!lista) return;

        if (!estado.conversas.length) {
            lista.innerHTML = '<p class="px-3 py-2 text-xs text-gray-600">Nenhuma conversa ainda.</p>';
        } else {
            lista.innerHTML = estado.conversas.map(function (c) {
                const naoLidas = parseInt(c.nao_lidas, 10) || 0;
                const nome = c.display_nome || c.nome || 'Conversa';

                return '<a href="/chat?conversa=' + Number(c.id) + '" '
                    + 'class="w-full flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-800 transition text-left">'
                    + '<div class="w-8 h-8 bg-gray-800 border border-gray-700 rounded-lg flex items-center justify-center text-xs font-bold text-indigo-300 shrink-0">'
                    + escapeHtml(nome.charAt(0).toUpperCase()) + '</div>'
                    + '<div class="flex-1 min-w-0">'
                    + '<p class="text-sm text-white truncate">' + escapeHtml(nome) + '</p>'
                    + '<p class="text-xs text-gray-500 truncate">' + escapeHtml(c.ultima_mensagem || 'Sem mensagens') + '</p>'
                    + '</div>'
                    + (naoLidas > 0
                        ? '<span class="bg-indigo-600 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 shrink-0">' + naoLidas + '</span>'
                        : '')
                    + '</a>';
            }).join('');
        }

        // Badge do modo minimizado: soma tudo que não foi lido.
        const badge = document.getElementById('menu-badge-conversas');
        if (badge) {
            const total = totalNaoLidas();
            badge.textContent = total > 0 ? String(total) : '';
            badge.classList.toggle('hidden', total === 0);
        }
    }

    async function carregarConversas() {
        try {
            const res = await fetch('/api/conversas');
            if (!res.ok) return;

            const lista = await res.json();
            estado.conversas = Array.isArray(lista) ? lista : [];
            renderizar();
        } catch (_) {
            // Mantém o que já estava na tela.
        }
    }

    /**
     * Mensagem nova chegando: atualiza prévia e contador sem recarregar a lista
     * inteira. Se a conversa ainda não é conhecida, aí sim busca do servidor.
     */
    function aplicarMensagem(mensagem) {
        if (!mensagem || !mensagem.conversa_id) return;

        const conversa = estado.conversas.find(function (c) {
            return Number(c.id) === Number(mensagem.conversa_id);
        });

        if (!conversa) {
            carregarConversas();
            return;
        }

        conversa.ultima_mensagem = mensagem.conteudo || 'Anexo';
        if (Number(mensagem.usuario_id) !== Number(usuario().id)) {
            conversa.nao_lidas = (parseInt(conversa.nao_lidas, 10) || 0) + 1;
        }

        // Conversa com novidade sobe para o topo, como no chat.
        estado.conversas = [conversa].concat(estado.conversas.filter(function (c) {
            return Number(c.id) !== Number(conversa.id);
        }));

        renderizar();
    }

    // ── WebSocket ─────────────────────────────
    function conectar() {
        const eu = usuario();
        if (!eu.id || !('WebSocket' in window)) return;
        if (estado.socket && (estado.socket.readyState === WebSocket.OPEN || estado.socket.readyState === WebSocket.CONNECTING)) return;

        try {
            estado.socket = new WebSocket('ws://' + window.location.hostname + ':8080');
        } catch (_) {
            return;
        }

        estado.socket.onopen = function () {
            estado.autenticado = false;
            estado.socket.send(JSON.stringify({
                type: 'auth',
                user_id: eu.id,
                user_nome: eu.nome || '',
                user_papel: eu.papel || 'usuario',
                conversa_id: 0,
            }));
        };

        estado.socket.onmessage = function (evento) {
            let msg;
            try {
                msg = JSON.parse(evento.data);
            } catch (_) {
                return;
            }

            switch (msg.type) {
                case 'auth_ok':
                    // Antes disto vem o replay das últimas 100 mensagens, que
                    // não pode inflar os contadores.
                    estado.autenticado = true;
                    carregarConversas();
                    break;
                case 'new_message':
                    if (estado.autenticado) aplicarMensagem(msg.message);
                    break;
                case 'new_conversation':
                    if (estado.autenticado) carregarConversas();
                    break;
                case 'notification_created':
                    // notificar() deduplica por id, então não há risco de dobrar
                    // com o socket próprio de outra tela (agendamentos).
                    if (window.NotificationCenterUI) {
                        window.NotificationCenterUI.handleRealtimeNotification(msg.notification);
                    }
                    break;
                case 'sessao_encerrada':
                    estado.sessaoEncerrada = true;
                    window.location.href = '/login';
                    break;
            }
        };

        estado.socket.onclose = function () {
            estado.autenticado = false;
            if (estado.sessaoEncerrada) return;
            clearTimeout(estado.reconectarTimer);
            estado.reconectarTimer = setTimeout(conectar, 3000);
        };
    }

    function iniciar() {
        if (!elMenu()) return;

        aplicarRecolhido(estaRecolhido());

        const botao = elMenu().querySelector('[data-menu-toggle]');
        if (botao) botao.addEventListener('click', alternarRecolhido);

        carregarConversas();
        conectar();
        // Rede de segurança para o intervalo em que o socket estiver caído.
        setInterval(carregarConversas, 30000);
    }

    document.addEventListener('DOMContentLoaded', iniciar);

    window.MenuLateral = {
        recarregar: carregarConversas,
        alternar: alternarRecolhido,
    };
})();
