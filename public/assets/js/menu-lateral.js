/**
 * Menu lateral fixo.
 *
 * Fora do chat, mantém a lista de conversas viva sem sair da tela atual:
 * carrega por HTTP e atualiza pelo WebSocket (mesma conexão que traz as
 * notificações). Clicar em uma conversa leva para /chat?conversa=ID.
 *
 * No /chat a sidebar é a do próprio chat.js (com busca e lista de usuários),
 * então aqui só entra a parte de minimizar — a lista e o socket ficam de fora,
 * detectados pela ausência de `#menu-lista-conversas`.
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
        pingTimer: null,
        timerAnimacao: null,
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

    // Tempo que o conteúdo leva para sumir. Espelha o `110ms` do fade em
    // public/assets/css/menu-lateral.css — mudar lá exige mudar aqui, senão o
    // layout troca com o conteúdo ainda visível (o piscar que isto corrige).
    const DURACAO_FADE_MS = 110;

    function mostrarBlocos(blocos, ehFaixaRecolhida) {
        blocos.forEach(function (el) {
            el.classList.remove('hidden');
            if (ehFaixaRecolhida) el.classList.add('flex');
        });
    }

    function ocultarBlocos(blocos, ehFaixaRecolhida) {
        blocos.forEach(function (el) {
            el.classList.add('hidden');
            if (ehFaixaRecolhida) el.classList.remove('flex');
        });
    }

    /**
     * Aplica o estado do menu, animando a troca.
     *
     * `display:none` não anima: trocar a visibilidade num quadro só, enquanto a
     * largura leva 260ms, era o que fazia o conteúdo piscar e a barra encolher
     * vazia. A ordem aqui é o que dá a suavidade:
     *
     *   1. o que ENTRA volta ao fluxo já (transparente, pelo CSS);
     *   2. a classe `menu-recolhido` dispara os fades e a largura;
     *   3. o que SAI só deixa o fluxo depois do fade — e o salto de layout
     *      acontece num instante em que ninguém está olhando para ele.
     *
     * @param {boolean} recolhido estado desejado
     * @param {boolean} [animar]  false no primeiro paint, para o menu não abrir
     *                            e fechar sozinho a cada troca de tela
     */
    function aplicarRecolhido(recolhido, animar) {
        const menu = elMenu();
        if (!menu) return;

        const comAnimacao = animar !== false;
        clearTimeout(estado.timerAnimacao);

        const conteudos = Array.from(menu.querySelectorAll('[data-menu-conteudo]'));
        const faixa = Array.from(menu.querySelectorAll('[data-menu-recolhido]'));
        const entrando = recolhido ? faixa : conteudos;
        const saindo = recolhido ? conteudos : faixa;

        const botao = menu.querySelector('[data-menu-toggle]');
        if (botao) {
            botao.title = recolhido ? 'Expandir menu' : 'Minimizar menu';
        }

        menu.classList.toggle('menu-sem-animacao', !comAnimacao);

        // A largura vem de `menu-recolhido` em menu-lateral.css, não de
        // `w-72`/`w-16`: o Tailwind só geraria a regra de `w-16` no primeiro
        // clique, tarde demais para a transição pegar. Ver o CSS.
        mostrarBlocos(entrando, recolhido);

        function concluir() {
            ocultarBlocos(saindo, !recolhido);
        }

        if (comAnimacao) {
            // Sem ler o layout aqui, o navegador agrupa "voltou ao fluxo" e
            // "ficou opaco" no mesmo quadro e não há transição para animar.
            void menu.offsetWidth;
            menu.classList.toggle('menu-recolhido', recolhido);
            estado.timerAnimacao = setTimeout(concluir, DURACAO_FADE_MS);
            return;
        }

        // Primeiro paint: tudo de uma vez, com a trava ativa.
        menu.classList.toggle('menu-recolhido', recolhido);
        concluir();

        // Força o recálculo AINDA travado e só então devolve a animação. Sem
        // este passo o navegador só veria os dois estados juntos, com a
        // transição já valendo — e a remoção da trava dispararia exatamente a
        // animação que ela existe para evitar (o menu "abrindo e fechando
        // sozinho" ao entrar numa tela com ele minimizado).
        void menu.offsetWidth;
        menu.classList.remove('menu-sem-animacao');
    }

    function alternarRecolhido() {
        const novo = !estaRecolhido();
        guardarRecolhido(novo);
        aplicarRecolhido(novo, true);
    }

    // ── Conversas ─────────────────────────────
    function totalNaoLidas() {
        return estado.conversas.reduce(function (acc, c) {
            return acc + (parseInt(c.nao_lidas, 10) || 0);
        }, 0);
    }

    // Mesmo critério visual de atualizarItemConversa() em chat.js: grupo/setor
    // vira "#", conversa privada vira a inicial do nome. Divergir daqui é
    // exatamente o que fazia o ícone da conversa mudar ao sair do /chat.
    function renderizar() {
        const lista = document.getElementById('menu-lista-conversas');
        if (!lista) return;

        if (!estado.conversas.length) {
            lista.innerHTML = '<p class="px-3 py-2 text-xs text-gray-600">Nenhuma conversa ainda.</p>';
        } else {
            lista.innerHTML = estado.conversas.map(function (c) {
                const naoLidas = parseInt(c.nao_lidas, 10) || 0;
                const nome = c.display_nome || c.nome || 'Conversa';
                const ehGrupo = c.tipo === 'grupo' || c.tipo === 'setor';
                const avatarTexto = ehGrupo ? '#' : (nome ? nome.charAt(0).toUpperCase() : '?');
                const avatarCor = ehGrupo ? 'bg-indigo-700' : 'bg-emerald-700';

                const busca = (nome + ' ' + (c.ultima_mensagem || '')).toLowerCase();

                return '<a href="/chat?conversa=' + Number(c.id) + '" data-busca="' + escapeHtml(busca) + '" '
                    + 'class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left">'
                    + '<div class="w-9 h-9 ' + avatarCor + ' rounded-xl flex items-center justify-center text-sm font-bold shrink-0">'
                    + escapeHtml(avatarTexto) + '</div>'
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

        // A sincronização de 30s (ou o socket) recria a lista inteira: sem isto
        // o filtro digitado sumiria a cada atualização.
        aplicarFiltroBusca();
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

    // ── Usuários ──────────────────────────────
    // Mesma lista de carregarUsuarios() em chat.js — sem indicador de presença
    // (informação restrita ao painel admin) e sem ação de clique, também igual
    // ao chat: é só "quem mais existe no sistema", útil para abrir uma
    // conversa nova a partir do botão "+" (só disponível dentro do /chat).
    const CORES_AVATAR_USUARIO = ['bg-pink-700', 'bg-emerald-700', 'bg-amber-700', 'bg-purple-700'];

    function renderizarUsuarios(lista) {
        const nav = document.getElementById('menu-lista-usuarios');
        if (!nav) return;

        if (!lista.length) {
            nav.innerHTML = '<p class="px-3 py-2 text-xs text-gray-600">Nenhum outro usuário cadastrado</p>';
            return;
        }

        nav.innerHTML = lista.map(function (u) {
            const cor = CORES_AVATAR_USUARIO[u.id % CORES_AVATAR_USUARIO.length];
            const nome = u.nome || '';
            const busca = (nome + ' ' + (u.setor || '') + ' ' + (u.papel || '')).toLowerCase();

            return '<div data-busca="' + escapeHtml(busca) + '" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left">'
                + '<div class="w-9 h-9 ' + cor + ' rounded-xl flex items-center justify-center text-sm font-bold shrink-0">'
                + escapeHtml(nome.charAt(0).toUpperCase()) + '</div>'
                + '<div class="flex-1 min-w-0">'
                + '<p class="text-sm font-medium text-white truncate">' + escapeHtml(nome) + '</p>'
                + '<p class="text-xs text-gray-400 truncate">' + escapeHtml(u.setor || u.papel || '') + '</p>'
                + '</div>'
                + '</div>';
        }).join('');

        aplicarFiltroBusca();
    }

    async function carregarUsuarios() {
        try {
            const res = await fetch('/api/usuarios');
            if (!res.ok) return;

            const lista = await res.json();
            renderizarUsuarios(Array.isArray(lista) ? lista : []);
        } catch (_) {
            // Mantém o que já estava na tela.
        }
    }

    // ── Busca ─────────────────────────────────
    // Mesmo campo #search-input do chat.js, mas filtrando as duas listas desta
    // barra (aqui não há mensagens abertas para filtrar junto).
    function aplicarFiltroBusca() {
        const input = document.getElementById('search-input');
        const termo = (input ? input.value : '').trim().toLowerCase();

        document.querySelectorAll('#menu-lista-conversas [data-busca], #menu-lista-usuarios [data-busca]').forEach(function (item) {
            item.style.display = (!termo || item.dataset.busca.includes(termo)) ? '' : 'none';
        });
    }

    function configurarBusca() {
        const input = document.getElementById('search-input');
        if (!input) return;

        input.addEventListener('input', aplicarFiltroBusca);
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
            estado.socket = new WebSocket(window.urlWebSocket());
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

            // Mantém a conexão comprovadamente ativa: o Chrome não congela aba
            // que segura conexão viva, e é isso que faz o aviso continuar
            // chegando com a janela minimizada por muito tempo.
            clearInterval(estado.pingTimer);
            estado.pingTimer = setInterval(function () {
                if (estado.socket && estado.socket.readyState === WebSocket.OPEN) {
                    estado.socket.send(JSON.stringify({ type: 'ping' }));
                }
            }, 25000);
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
                    if (!estado.autenticado) break;   // replay das últimas 100 no auth
                    aplicarMensagem(msg.message);
                    // Fora do /chat nenhuma conversa está aberta na tela: toda
                    // mensagem recebida vira aviso (som + toast ou pop-up do SO).
                    if (window.NotificationCenterUI) {
                        window.NotificationCenterUI.notificarMensagemChat(msg.message);
                    }
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
            clearInterval(estado.pingTimer);
            if (estado.sessaoEncerrada) return;
            clearTimeout(estado.reconectarTimer);
            estado.reconectarTimer = setTimeout(conectar, 3000);
        };
    }

    function iniciar() {
        if (!elMenu()) return;

        // Primeiro paint: aplica o estado salvo sem animar.
        aplicarRecolhido(estaRecolhido(), false);

        const botao = elMenu().querySelector('[data-menu-toggle]');
        if (botao) botao.addEventListener('click', alternarRecolhido);

        // No /chat quem cuida das conversas (e do socket) é o chat.js.
        if (!document.getElementById('menu-lista-conversas')) return;

        configurarBusca();
        carregarConversas();
        carregarUsuarios();
        conectar();
        // Rede de segurança para o intervalo em que o socket estiver caído.
        setInterval(carregarConversas, 30000);

        // Voltando à aba depois de um tempo fora: o navegador pode ter derrubado
        // o socket em segundo plano sem disparar o timer de reconexão.
        window.aoMudarAtividade(function (ativo) {
            if (ativo) conectar();
        });
    }

    document.addEventListener('DOMContentLoaded', iniciar);

    window.MenuLateral = {
        recarregar: carregarConversas,
        alternar: alternarRecolhido,
    };
})();
