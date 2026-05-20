const CHAT_BOOTSTRAP = window.CHAT_BOOTSTRAP || {};
const CURRENT_USER_ID = Number(CHAT_BOOTSTRAP.currentUserId || 0);
const CURRENT_USER_NAME = String(CHAT_BOOTSTRAP.currentUserName || '');
const CURRENT_USER_PAPEL = String(CHAT_BOOTSTRAP.userPapel || (CHAT_BOOTSTRAP.isAdmin ? 'admin' : 'usuario'));
const IS_ADMIN = Boolean(CHAT_BOOTSTRAP.isAdmin);
const CONFIG_CHAMADOS = window.APP_CONFIG || { categorias: {}, prioridades: {}, status: {} };
let conversaAtualId = null;
let conversaAtualNome = null;
let ws = null;
let typingTimer = null;
let grupoEditandoId = null;
let grupoEditandoNome = null;
let ultimoTotalNaoLidas = 0;
let notificacoesInicializadas = false;
let conversaAtualTipo = null;
let paginaMensagensAtual = 1;
let podeCarregarMaisMensagens = false;
let sincronizacaoIntervalId = null;
let conversasConhecidas = new Set();
let sincronizacaoInicialConversasFeita = false;
let wsSincronizacaoInicialConcluida = false;
let emojiPicker = null;
let emojiPickerVisivel = false;
let emojiFallbackVisivel = false;
// ── WebSocket ─────────────────────────────────
function conectarWS() {
    const host = window.location.hostname;
    ws = new WebSocket('ws://' + host + ':8080');

    ws.onopen = function () {
        console.log('WebSocket conectado!');
        ws.send(JSON.stringify({
            type: 'auth',
            user_id: CURRENT_USER_ID,
            user_nome: CURRENT_USER_NAME,
            user_papel: CURRENT_USER_PAPEL,
            conversa_id: conversaAtualId || 0,
        }));
    };

    ws.onmessage = function (event) {
        const data = JSON.parse(event.data);
        switch (data.type) {
            case 'auth_ok':
                console.log('Autenticado no WS como userId:', data.userId);
                wsSincronizacaoInicialConcluida = true;
                break;
            case 'new_conversation':
                carregarConversas().catch(function () { });
                if (wsSincronizacaoInicialConcluida && data.conversa && data.conversa.nome) {
                    notificarMensagem('Novo chat iniciado', 'Você foi adicionado(a) em "' + data.conversa.nome + '".');
                }
                break;
            case 'new_message':
                if (!data.message || !data.message.id) break;
                if (document.querySelector('[data-msg-id="' + data.message.id + '"]')) break;

                // Se a mensagem é de uma conversa desconhecida, recarrega a lista
                if (!document.querySelector('[data-conversa-id="' + data.message.conversa_id + '"]')) {
                    carregarConversas().catch(function () { });
                }

                if (data.message.conversa_id == conversaAtualId) {
                    renderizarMensagem(data.message);
                    document.getElementById('messages').scrollTop = 99999;
                    fetch('/api/conversas/' + conversaAtualId + '/lida', { method: 'POST' });
                } else {
                    if (wsSincronizacaoInicialConcluida) {
                        notificarMensagem('Nova mensagem de ' + (data.message.usuario_nome || 'Contato'), (data.message.conteudo || 'Nova mensagem').substring(0, 100));
                    }
                }
                atualizarPreviewSidebar(data.message);
                break;
            case 'message_deleted':
                if (data.message_id) {
                    aplicarMensagemApagadaNoDom(data.message_id);
                }
                if (data.conversa_id) {
                    atualizarPreviewSidebar({ conversa_id: data.conversa_id, conteudo: 'Mensagem apagada' });
                }
                break;
            case 'typing':
                if (data.conversa_id == conversaAtualId) {
                    mostrarTyping(data.user_nome);
                }
                break;
            case 'action_error':
                if (data.action === 'delete_message') {
                    alert(data.message || 'Erro ao apagar mensagem');
                }
                break;
        }
    };

    ws.onclose = function () {
        console.log('WS desconectado. Reconectando em 3s...');
        wsSincronizacaoInicialConcluida = false;
        setTimeout(conectarWS, 3000);
    };

    ws.onerror = function (err) {
        console.error('WS erro:', err);
    };
}

// ── Inicialização ─────────────────────────────
document.addEventListener('DOMContentLoaded', async function () {
    try {
        configurarEmojiPicker();
        conectarWS();
        await carregarConversas();
        await abrirConversaViaUrl();
        carregarUsuarios();
        configurarBusca();
        configurarNotificacoes();
        configurarAnexoChamado();
        atualizarBadgePainelChamados();
        setInterval(atualizarBadgePainelChamados, 5000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                atualizarBadgePainelChamados();
            }
        });
        document.getElementById('modal-emergencia').addEventListener('click', function (e) {
            if (e.target === this) fecharEmergencia();
        });
        document.getElementById('modal-nova-conversa').addEventListener('click', function (e) {
            if (e.target === this) fecharModalNovaConversa();
        });
        document.getElementById('modal-editar-grupo').addEventListener('click', function (e) {
            if (e.target === this) fecharModalEditarGrupo();
        });
        const inputWrapper = document.getElementById('msg-input-wrapper');
        if (inputWrapper) {
            inputWrapper.addEventListener('click', function (e) {
                if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input[type="file"]')) {
                    return;
                }
                const input = document.getElementById('msg-input');
                if (input) input.focus();
            });
        }

        // Capturar Enter no file input para evitar rearir seletor de arquivo
        const fileInput = document.getElementById('msg-file-input');
        if (fileInput) {
            fileInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    // Focar no textarea depois de selecionar arquivo
                    const msgInput = document.getElementById('msg-input');
                    if (msgInput) {
                        msgInput.focus();
                    }
                }
            });
        }

        configurarEmojiFallback();
        document.addEventListener('click', function (e) {
            const panel = document.getElementById('emoji-fallback-panel');
            const btn = document.getElementById('btn-emoji');
            if (!panel || !emojiFallbackVisivel) return;
            if (panel.contains(e.target) || (btn && btn.contains(e.target))) return;
            ocultarEmojiFallback();
        });

        verificarNovasMensagensNotificacao();
        sincronizacaoIntervalId = window.setInterval(function () {
            sincronizacaoLeve();
        }, 4000);
    } finally {
        document.documentElement.classList.remove('chat-loading');
    }
});

// ── Conversas (sidebar) ───────────────────────
async function carregarConversas() {
    const res = await fetch('/api/conversas');
    const lista = await res.json();
    const nav = document.getElementById('lista-conversas');
    const conversaSelecionadaAntes = conversaAtualId;

    conversaAtualId = null;
    conversaAtualNome = null;
    conversaAtualTipo = null;

    nav.innerHTML = '';

    let itemSelecionado = null;
    const idsAtuais = new Set();

    lista.forEach(function (c) {
        idsAtuais.add(Number(c.id));
        const isGrupo = c.tipo === 'grupo' || c.tipo === 'setor';
        const icone = isGrupo ? '#' : c.nome.charAt(0).toUpperCase();
        const cor = isGrupo ? 'bg-indigo-700' : 'bg-emerald-700';
        const badge = c.nao_lidas > 0 ? c.nao_lidas : '';
        const badgeHidden = c.nao_lidas > 0 ? '' : 'hidden';

        const wrapper = document.createElement('div');
        wrapper.className = 'group relative';

        let editBtn = '';
        if (isGrupo && IS_ADMIN) {
            editBtn = '<button onclick="abrirModalEditarGrupo(' + c.id + ', \'' + c.nome.replace(/'/g, "\\'") + '\')" '
                + 'class="absolute right-2 top-1/2 -translate-y-1/2 hidden group-hover:flex w-6 h-6 items-center justify-center text-gray-500 hover:text-indigo-400 transition rounded-lg hover:bg-indigo-500/10">'
                + '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>'
                + '</svg></button>';
        }

        wrapper.innerHTML = '<button class="conversa-item w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left" data-id="' + c.id + '" data-nome="' + c.nome + '" data-tipo="' + c.tipo + '">'
            + '<div class="w-9 h-9 ' + cor + ' rounded-xl flex items-center justify-center shrink-0 text-sm font-bold">' + icone + '</div>'
            + '<div class="flex-1 min-w-0">'
            + '<p class="text-sm font-medium text-white truncate">' + c.nome + '</p>'
            + '<p class="preview-msg text-xs text-gray-400 truncate">' + (c.ultima_mensagem || 'Sem mensagens') + '</p>'
            + '</div>'
            + '<span class="badge-nao-lidas ' + badgeHidden + ' bg-indigo-600 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 shrink-0">' + badge + '</span>'
            + '</button>'
            + editBtn;

        const btn = wrapper.querySelector('.conversa-item');
        if (conversaSelecionadaAntes && c.id === conversaSelecionadaAntes) {
            itemSelecionado = btn;
        }
        btn.addEventListener('click', function () { selecionarConversa(c.id, c.nome, btn); });
        nav.appendChild(wrapper);
    });

    if (sincronizacaoInicialConversasFeita) {
        lista.forEach(function (c) {
            const id = Number(c.id);
            if (!conversasConhecidas.has(id)) {
                notificarMensagem('Nova conversa disponível', 'Você foi adicionado(a) em "' + c.nome + '".');
            }
        });
    }

    conversasConhecidas = idsAtuais;
    sincronizacaoInicialConversasFeita = true;

    if (itemSelecionado) {
        const id = parseInt(itemSelecionado.dataset.id || '0', 10);
        if (id) {
            conversaAtualId = id;
            conversaAtualNome = itemSelecionado.dataset.nome || conversaAtualNome;
            conversaAtualTipo = itemSelecionado.dataset.tipo || conversaAtualTipo;
            itemSelecionado.classList.add('bg-gray-800');
        }
    }
}

function toggleSidebarMobile(forceOpen) {
    const shouldOpen = typeof forceOpen === 'boolean'
        ? forceOpen
        : !document.body.classList.contains('sidebar-open');
    document.body.classList.toggle('sidebar-open', shouldOpen);
    const overlay = document.getElementById('sidebar-overlay');
    if (overlay) {
        overlay.classList.toggle('hidden', !shouldOpen);
    }
}

function atualizarPreviewSidebar(msg) {
    const cId = msg.conversa_id || conversaAtualId;
    const btn = document.querySelector('.conversa-item[data-id="' + cId + '"]');
    if (!btn) {
        carregarConversas().catch(function () { });
        return;
    }

    const preview = btn.querySelector('.preview-msg');
    if (preview) preview.textContent = (msg.conteudo || 'Mensagem apagada').substring(0, 40);

    if (cId != conversaAtualId) {
        const badge = btn.querySelector('.badge-nao-lidas');
        if (badge) {
            const atual = parseInt(badge.textContent) || 0;
            badge.textContent = atual + 1;
            badge.classList.remove('hidden');
        }
    }
}

function limparBadge(conversaId) {
    const btn = document.querySelector('.conversa-item[data-id="' + conversaId + '"]');
    if (!btn) return;
    const badge = btn.querySelector('.badge-nao-lidas');
    if (badge) { badge.textContent = ''; badge.classList.add('hidden'); }
}

function atualizarUrlConversa(conversaId) {
    const url = new URL(window.location.href);
    if (conversaId) {
        url.searchParams.set('conversa', String(conversaId));
    } else {
        url.searchParams.delete('conversa');
    }
    url.searchParams.delete('conversa_com');
    window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
}

function mostrarEstadoVazioChat() {
    conversaAtualId = null;
    conversaAtualNome = null;
    conversaAtualTipo = null;

    const header = document.getElementById('chat-header');
    const loadMoreWrap = document.getElementById('chat-load-more-wrap');
    const composer = document.getElementById('chat-composer');
    const messages = document.getElementById('messages');
    const chatNomeEl = document.getElementById('chat-nome');
    const btnInfo = document.getElementById('btn-info-grupo');

    if (header) header.classList.add('hidden');
    if (loadMoreWrap) loadMoreWrap.classList.add('hidden');
    if (composer) composer.classList.add('hidden');
    if (btnInfo) btnInfo.classList.add('hidden');
    if (chatNomeEl) chatNomeEl.textContent = 'Chat Interno';
    atualizarUrlConversa(null);

    if (messages) {
        messages.className = 'flex-1 overflow-y-auto p-6 flex items-center justify-center';
        messages.innerHTML = '<div id="chat-empty-state" class="text-center select-none px-6"><p class="text-3xl md:text-4xl font-semibold tracking-tight text-gray-300">Bem-vindo ao Chat Interno!</p></div>';
    }
}

function mostrarEstadoConversa() {
    const header = document.getElementById('chat-header');
    const loadMoreWrap = document.getElementById('chat-load-more-wrap');
    const composer = document.getElementById('chat-composer');
    const messages = document.getElementById('messages');

    if (header) header.classList.remove('hidden');
    if (loadMoreWrap) loadMoreWrap.classList.remove('hidden');
    if (composer) composer.classList.remove('hidden');
    if (messages) {
        messages.className = 'flex-1 overflow-y-auto p-6 space-y-4';
        const empty = messages.querySelector('#chat-empty-state');
        if (empty) empty.remove();
    }
}

// ── Usuários (sidebar) ────────────────────────
async function carregarUsuarios() {
    const res = await fetch('/api/usuarios/online');
    const lista = await res.json();
    const nav = document.getElementById('lista-usuarios');
    nav.innerHTML = '';

    if (!lista.length) {
        nav.innerHTML = '<p class="text-xs text-gray-600 px-3 py-2">Nenhum outro usuário cadastrado</p>';
        return;
    }

    const cores = ['bg-pink-700', 'bg-emerald-700', 'bg-amber-700', 'bg-purple-700'];
    lista.forEach(function (u) {
        const cor = cores[u.id % cores.length];
        const isOnline = !!parseInt(u.online || 0, 10);
        const statusTexto = isOnline ? 'Online' : 'Offline';
        const statusCor = isOnline ? 'text-green-400' : 'text-gray-500';
        const dotCor = isOnline ? 'bg-green-400' : 'bg-gray-500';

        const btn = document.createElement('button');
        btn.className = 'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left';
        btn.innerHTML = '<div class="w-9 h-9 ' + cor + ' rounded-xl flex items-center justify-center text-sm font-bold shrink-0">' + u.nome.charAt(0).toUpperCase() + '</div>'
            + '<div class="flex-1 min-w-0"><p class="text-sm font-medium text-white truncate">' + u.nome + '</p>'
            + '<p class="text-xs text-gray-400 truncate">' + (u.setor || u.papel) + '</p>'
            + '<p class="text-xs ' + statusCor + ' truncate flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full ' + dotCor + '"></span>' + statusTexto + '</p></div>';
        nav.appendChild(btn);
    });
}

// ── Selecionar conversa ───────────────────────
function selecionarConversa(id, nome, el = null) {
    mostrarEstadoConversa();
    conversaAtualId = id;
    conversaAtualNome = nome;
    conversaAtualTipo = el ? el.dataset.tipo : (document.querySelector('.conversa-item[data-id="' + id + '"]')?.dataset.tipo || null);
    atualizarUrlConversa(id);

    document.querySelectorAll('.conversa-item').forEach(function (b) { b.classList.remove('bg-gray-800'); });

    // Agora ele só tenta pintar o elemento se ele existir
    if (el) {
        el.classList.add('bg-gray-800');
    } else {
        // Se viemos pela URL, tenta achar o elemento pelo ID na lista de chats
        const itemLista = document.querySelector(`.conversa-item[data-id="${id}"]`);
        if (itemLista) itemLista.classList.add('bg-gray-800');
    }

    limparBadge(id);

    const chatNomeEl = document.getElementById('chat-nome');
    if (chatNomeEl) chatNomeEl.textContent = '#' + nome;

    const msgInputEl = document.getElementById('msg-input');
    if (msgInputEl) msgInputEl.placeholder = 'Mensagem para #' + nome + '...';

    const typingEl = document.getElementById('typing-indicator');
    if (typingEl) typingEl.classList.add('hidden');

    if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'join', conversa_id: id }));
    }

    fetch('/api/conversas/' + id + '/lida', { method: 'POST' }).catch(e => console.log(e));
    carregarMensagens(id);

    if (window.innerWidth < 768) {
        toggleSidebarMobile(false);
    }

    const btnInfo = document.getElementById('btn-info-grupo');
    if (btnInfo) {
        const mostrar = conversaAtualTipo === 'grupo' || conversaAtualTipo === 'setor';
        btnInfo.classList.toggle('hidden', !mostrar);
    }
}

// ── Histórico ─────────────────────────────────
async function carregarMensagens(conversaId) {
    mostrarEstadoConversa();
    paginaMensagensAtual = 1;
    const box = document.getElementById('messages');
    box.innerHTML = '<p class="text-center text-gray-600 text-xs py-4">Carregando...</p>';
    const res = await fetch('/api/mensagens?conversa_id=' + conversaId + '&pagina=1&_ts=' + Date.now());
    const msgs = await res.json();
    podeCarregarMaisMensagens = Array.isArray(msgs) && msgs.length >= 50;
    atualizarBotaoCarregarMais();
    box.innerHTML = '';
    if (!msgs.length) {
        box.innerHTML = '<p class="text-center text-gray-600 text-xs py-8">Nenhuma mensagem ainda. Diga olá! 👋</p>';
        return;
    }
    msgs.forEach(function (m) { renderizarMensagem(m); });

    // Desabilitar scroll suave durante carregamento para evitar animação gigante
    const scrollBehavior = box.style.scrollBehavior;
    box.style.scrollBehavior = 'auto';

    // Usar requestAnimationFrame duplo para garantir que o DOM foi pintado
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            box.scrollTop = box.scrollHeight;
            // Restaurar scroll suave após scroll finalizar
            box.style.scrollBehavior = scrollBehavior || '';
        });
    });

    configurarScrollParaBotaoCarregar();
}

async function carregarMaisMensagens() {
    if (!conversaAtualId || !podeCarregarMaisMensagens) return;

    paginaMensagensAtual += 1;
    const box = document.getElementById('messages');
    const scrollAntes = box.scrollHeight;

    const res = await fetch('/api/mensagens?conversa_id=' + conversaAtualId + '&pagina=' + paginaMensagensAtual + '&_ts=' + Date.now());
    const msgs = await res.json();

    if (!Array.isArray(msgs) || msgs.length === 0) {
        podeCarregarMaisMensagens = false;
        atualizarBotaoCarregarMais();
        return;
    }

    podeCarregarMaisMensagens = msgs.length >= 50;
    atualizarBotaoCarregarMais();

    const fragmento = document.createDocumentFragment();
    msgs.forEach(function (m) {
        fragmento.appendChild(criarElementoMensagem(m));
    });
    box.prepend(fragmento);

    box.scrollTop = box.scrollHeight - scrollAntes;
    configurarScrollParaBotaoCarregar();
}

function atualizarBotaoCarregarMais() {
    const btn = document.getElementById('btn-carregar-mais');
    if (!btn) return;
    // Só mostra o botão se houver mais mensagens E o usuário estiver no topo
    const box = document.getElementById('messages');
    const estaNoTopo = box && box.scrollTop <= 10;
    btn.classList.toggle('hidden', !podeCarregarMaisMensagens || !estaNoTopo);
}

function configurarScrollParaBotaoCarregar() {
    const box = document.getElementById('messages');
    if (!box) return;

    // Remove listener anterior se existir
    if (box._carregarMaisScrollListener) {
        box.removeEventListener('scroll', box._carregarMaisScrollListener);
    }

    // Adiciona novo listener
    box._carregarMaisScrollListener = function () {
        const estaNoTopo = box.scrollTop <= 10;
        const btn = document.getElementById('btn-carregar-mais');
        if (btn) {
            btn.classList.toggle('hidden', !podeCarregarMaisMensagens || !estaNoTopo);
        }
    };

    box.addEventListener('scroll', box._carregarMaisScrollListener);
}

// ── Renderizar mensagem ───────────────────────
function renderizarMensagem(m) {
    const box = document.getElementById('messages');
    const div = criarElementoMensagem(m);

    const vazio = box.querySelector('#chat-empty-state') || box.querySelector('p.text-center');
    if (vazio) vazio.remove();
    box.appendChild(div);
}

function criarElementoMensagem(m) {
    const proprio = m.usuario_id === CURRENT_USER_ID;
    const inicial = m.usuario_nome.charAt(0).toUpperCase();
    const cores = ['bg-emerald-700', 'bg-pink-700', 'bg-amber-700', 'bg-purple-700'];
    const cor = proprio ? 'bg-indigo-600' : cores[m.usuario_id % cores.length];
    const hora = formatarHoraBrasilia(m.criado_em);
    const foiApagada = !!m.excluida_em || m.conteudo === '[mensagem apagada]';
    const conteudoHtml = foiApagada ? '<em class="text-gray-400">Mensagem apagada</em>' : formatarConteudoMensagem(m.conteudo || '');
    const temArquivo = !!m.arquivo_path;
    const arquivoUrl = temArquivo ? ('/uploads/' + m.arquivo_path) : '';
    const isImagem = temArquivo && /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(m.arquivo_nome || m.arquivo_path || '');

    const anexoHtml = temArquivo && !foiApagada
        ? (isImagem
            ? '<a href="' + arquivoUrl + '" target="_blank" class="block mt-2"><img src="' + arquivoUrl + '" alt="Anexo" class="max-w-xs rounded-lg border border-gray-700"></a>'
            : '<a href="' + arquivoUrl + '" target="_blank" download class="inline-flex mt-2 text-xs text-indigo-300 underline">Anexo: ' + (m.arquivo_nome || 'arquivo') + '</a>')
        : '';

    const podeApagar = proprio && !foiApagada;
    const btnApagar = podeApagar
        ? '<button onclick="apagarMensagem(' + m.id + ')" class="opacity-0 group-hover:opacity-100 text-[10px] text-red-300 hover:text-red-200 transition">Apagar</button>'
        : '';

    const div = document.createElement('div');
    div.className = 'flex items-start gap-3 msg-enter group' + (proprio ? ' flex-row-reverse' : '');
    div.setAttribute('data-msg-id', String(m.id || ''));
    div.innerHTML = '<div class="w-8 h-8 ' + cor + ' rounded-lg flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">' + inicial + '</div>'
        + '<div class="max-w-lg">'
        + '<div class="flex items-baseline gap-2 mb-1' + (proprio ? ' flex-row-reverse' : '') + '">'
        + '<span class="text-sm font-semibold ' + (proprio ? 'text-indigo-400' : 'text-white') + '">' + (proprio ? 'Você' : m.usuario_nome) + '</span>'
        + '<span class="text-xs text-gray-500">' + hora + '</span>'
        + btnApagar
        + '</div>'
        + '<div class="' + (proprio ? 'bg-indigo-600' : 'bg-gray-800') + ' rounded-2xl ' + (proprio ? 'rounded-tr-sm' : 'rounded-tl-sm') + ' px-4 py-2.5 text-sm ' + (proprio ? 'text-white' : 'text-gray-200') + '">' + conteudoHtml + anexoHtml + '</div>'
        + '</div>';

    return div;
}

function formatarConteudoMensagem(texto) {
    const escapado = escapeHtml(texto || '');
    return escapado
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`([^`]+)`/g, '<code class="px-1 py-0.5 rounded bg-black/30 text-amber-300">$1</code>')
        .replace(/\n/g, '<br>');
}

function formatarHoraBrasilia(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    if (!data) return '--:--';

    return data.toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'America/Sao_Paulo'
    });
}

function parseDataServidorBrasilia(valorData) {
    if (!valorData) return null;

    if (typeof valorData === 'string') {
        const base = valorData.includes('T') ? valorData : valorData.replace(' ', 'T');
        const possuiTimezone = /Z|[+-]\d{2}:?\d{2}$/.test(base);
        const normalizada = possuiTimezone ? base : (base + '-03:00');
        const data = new Date(normalizada);
        if (!isNaN(data.getTime())) return data;
    }

    const fallback = new Date(valorData);
    return isNaN(fallback.getTime()) ? null : fallback;
}

// ── Enviar mensagem ───────────────────────────
function enviarMensagem() {
    if (!conversaAtualId) return;
    const input = document.getElementById('msg-input');
    const fileInput = document.getElementById('msg-file-input');
    const preview = document.getElementById('msg-file-preview');
    const texto = input.value.trim();
    const arquivos = fileInput && fileInput.files ? Array.from(fileInput.files) : [];
    if (!texto && arquivos.length === 0) return;

    if (arquivos.length > 0) {
        const formData = new FormData();
        formData.append('conversa_id', String(conversaAtualId));
        formData.append('conteudo', texto);
        arquivos.forEach(function (arquivo) {
            formData.append('arquivos[]', arquivo);
        });

        fetch('/api/mensagens', {
            method: 'POST',
            body: formData,
        }).then(function (r) { return r.json(); }).then(function (m) {
            const mensagens = (m && Array.isArray(m.mensagens))
                ? m.mensagens
                : (m && m.id ? [m] : []);

            if (mensagens.length > 0) {
                mensagens.forEach(function (msg) { renderizarMensagem(msg); });
                document.getElementById('messages').scrollTop = 99999;
                input.value = '';
                input.style.height = 'auto';
                fileInput.value = '';
                if (preview) {
                    preview.textContent = '';
                    preview.classList.add('hidden');
                }
            } else {
                alert(m.erro || 'Erro ao enviar arquivo');
            }
        }).catch(function (e) {
            alert('Erro ao enviar arquivo: ' + e.message);
        });
        return;
    }

    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'send_message', conversa_id: conversaAtualId, conteudo: texto }));
    } else {
        fetch('/api/mensagens', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'conversa_id=' + conversaAtualId + '&conteudo=' + encodeURIComponent(texto)
        }).then(function (r) { return r.json(); }).then(function (m) {
            renderizarMensagem(m);
            document.getElementById('messages').scrollTop = 99999;
        });
    }
    input.value = '';
    input.style.height = 'auto';
}

function atualizarPreviewAnexoMensagem() {
    const fileInput = document.getElementById('msg-file-input');
    const preview = document.getElementById('msg-file-preview');
    const msgInput = document.getElementById('msg-input');

    if (!fileInput || !preview) return;

    const arquivos = fileInput.files ? Array.from(fileInput.files) : [];
    if (arquivos.length === 0) {
        preview.textContent = '';
        preview.classList.add('hidden');
        return;
    }

    if (arquivos.length === 1) {
        preview.textContent = 'Anexo selecionado: ' + arquivos[0].name;
    } else {
        const nomes = arquivos.slice(0, 3).map(function (arquivo) { return arquivo.name; }).join(', ');
        const restante = arquivos.length > 3 ? ' e mais ' + (arquivos.length - 3) : '';
        preview.textContent = arquivos.length + ' anexos: ' + nomes + restante;
    }
    preview.classList.remove('hidden');

    // Garantir que o foco retorna para o textarea após selecionar arquivos
    if (msgInput) {
        setTimeout(function () {
            msgInput.focus();
        }, 50);
    }
}

async function apagarMensagem(id) {
    if (!id) return;
    if (!confirm('Deseja apagar esta mensagem?')) return;

    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'delete_message', message_id: id }));
        return;
    }

    const res = await fetch('/api/mensagens/' + id, { method: 'DELETE' });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        alert(data.erro || 'Erro ao apagar mensagem');
        return;
    }

    aplicarMensagemApagadaNoDom(id);
}

function aplicarMensagemApagadaNoDom(id) {
    const item = document.querySelector('[data-msg-id="' + id + '"]');
    if (!item) return;

    const bolha = item.querySelector('.rounded-2xl');
    if (bolha) {
        bolha.innerHTML = '<em class="text-gray-400">Mensagem apagada</em>';
    }

    const botao = item.querySelector('button[onclick^="apagarMensagem("]');
    if (botao) botao.remove();
}

function mostrarTyping(nome) {
    const el = document.getElementById('typing-indicator');
    el.textContent = nome + ' está digitando...';
    el.classList.remove('hidden');
    clearTimeout(typingTimer);
    typingTimer = setTimeout(function () { el.classList.add('hidden'); }, 2000);
}

function handleEnter(e) {
    // Se o arquivo está selecionado, não deixar que arquivo seja reenviado
    const fileInput = document.getElementById('msg-file-input');

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        // Garantir que o foco está no textarea
        const msgInput = document.getElementById('msg-input');
        if (msgInput) {
            msgInput.focus();
        }
        enviarMensagem();
    }
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 128) + 'px';
    if (ws && ws.readyState === WebSocket.OPEN && conversaAtualId) {
        ws.send(JSON.stringify({ type: 'typing', conversa_id: conversaAtualId }));
    }
}

function toggleEmojiPicker() {
    const trigger = document.getElementById('btn-emoji');
    if (!trigger) return;

    if (!emojiPicker) {
        configurarEmojiPicker();
        if (!emojiPicker) {
            toggleEmojiFallback(trigger);
            return;
        }
    }

    try {
        if (typeof emojiPicker.togglePicker === 'function') {
            emojiPicker.togglePicker(trigger);
            ocultarEmojiFallback();
            window.setTimeout(function () {
                const abriuPicker = Boolean(
                    emojiPickerVisivel
                    || (emojiPicker && (emojiPicker.pickerVisible || emojiPicker.isPickerVisible))
                );
                if (!abriuPicker) {
                    toggleEmojiFallback(trigger);
                }
            }, 0);
            return;
        }

        if (emojiPickerVisivel && typeof emojiPicker.hidePicker === 'function') {
            emojiPicker.hidePicker();
            emojiPickerVisivel = false;
            return;
        }

        if (typeof emojiPicker.showPicker === 'function') {
            emojiPicker.showPicker(trigger);
            emojiPickerVisivel = true;
            ocultarEmojiFallback();
            return;
        }

        toggleEmojiFallback(trigger);
    } catch (e) {
        console.error('Falha ao abrir seletor de emoji:', e);
        toggleEmojiFallback(trigger);
    }
}

function inserirEmoji(emoji) {
    const input = document.getElementById('msg-input');
    if (!input) return;

    const inicio = input.selectionStart || input.value.length;
    const fim = input.selectionEnd || input.value.length;
    const antes = input.value.slice(0, inicio);
    const depois = input.value.slice(fim);

    input.value = antes + emoji + depois;
    const novaPos = inicio + emoji.length;
    input.selectionStart = novaPos;
    input.selectionEnd = novaPos;
    input.focus();
    autoResize(input);
}

function configurarEmojiPicker() {
    const EmojiCtor = typeof window.EmojiButton === 'function'
        ? window.EmojiButton
        : (window.EmojiButton && typeof window.EmojiButton.EmojiButton === 'function'
            ? window.EmojiButton.EmojiButton
            : null);

    if (!EmojiCtor) {
        emojiPicker = null;
        return;
    }

    const temaAtual = document.documentElement.getAttribute('data-theme') || 'dark';

    emojiPicker = new EmojiCtor({
        position: 'top-end',
        theme: temaAtual === 'light' ? 'light' : 'dark',
        autoHide: true,
        rootElement: document.body,
        zIndex: 99999,
        showSearch: true,
        showRecents: true
    });

    emojiPicker.on('emoji', function (emoji) {
        const unicode = emoji && (emoji.emoji || emoji.character || emoji.unicode || '');
        if (unicode) inserirEmoji(unicode);
    });

    emojiPicker.on('hidden', function () {
        emojiPickerVisivel = false;
    });

    emojiPicker.on('shown', function () {
        emojiPickerVisivel = true;
    });
}

function configurarEmojiFallback() {
    const panel = document.getElementById('emoji-fallback-panel');
    if (!panel) return;
    panel.querySelectorAll('.emoji-fallback-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const emoji = btn.getAttribute('data-emoji') || '';
            if (emoji) inserirEmoji(emoji);
            ocultarEmojiFallback();
        });
    });
}

function toggleEmojiFallback(trigger) {
    const panel = document.getElementById('emoji-fallback-panel');
    if (!panel || !trigger) return;

    if (emojiFallbackVisivel) {
        ocultarEmojiFallback();
        return;
    }

    const rect = trigger.getBoundingClientRect();
    panel.style.left = Math.max(8, rect.right - 256) + 'px';
    panel.style.top = Math.max(8, rect.top - 172) + 'px';
    panel.classList.remove('hidden');
    emojiFallbackVisivel = true;
}

function ocultarEmojiFallback() {
    const panel = document.getElementById('emoji-fallback-panel');
    if (!panel) return;
    panel.classList.add('hidden');
    emojiFallbackVisivel = false;
}

function aplicarFormatacaoTexto(tipo) {
    const input = document.getElementById('msg-input');
    if (!input) return;

    const inicio = input.selectionStart || 0;
    const fim = input.selectionEnd || 0;
    const selecionado = input.value.slice(inicio, fim);

    if (tipo === 'bold') {
        input.setRangeText('**' + selecionado + '**', inicio, fim, 'end');
    } else if (tipo === 'italic') {
        input.setRangeText('*' + selecionado + '*', inicio, fim, 'end');
    }

    input.focus();
    autoResize(input);
}

// ── Emergência ────────────────────────────────
function abrirEmergencia() { document.getElementById('modal-emergencia').classList.remove('hidden'); }
function fecharEmergencia() { document.getElementById('modal-emergencia').classList.add('hidden'); }

async function enviarChamado() {
    const titulo = document.getElementById('chamado-titulo').value.trim();
    const descricao = document.getElementById('chamado-descricao').value.trim();
    const fileInput = document.querySelector('#modal-emergencia input[type=file]');
    if (!titulo) { alert('Informe o título do problema.'); return; }

    const btnEnviar = document.getElementById('btn-enviar-chamado');
    btnEnviar.disabled = true;
    btnEnviar.textContent = 'Enviando...';

    try {
        const formData = new FormData();
        formData.append('titulo', titulo);
        formData.append('descricao', descricao);
        if (fileInput && fileInput.files.length > 0) {
            Array.from(fileInput.files).forEach(function (f) { formData.append('anexos[]', f); });
        }

        const res = await fetch('/api/chamados', { method: 'POST', body: formData });
        const data = await res.json();
        if (!res.ok) throw new Error(data.erro || 'Erro ao abrir chamado');

        document.getElementById('chamado-titulo').value = '';
        document.getElementById('chamado-descricao').value = '';
        if (fileInput) fileInput.value = '';
        fecharEmergencia();

        const toast = document.createElement('div');
        toast.className = 'fixed bottom-6 right-6 bg-gray-800 border border-green-500/30 text-white rounded-2xl p-4 shadow-2xl z-50 max-w-sm';
        toast.innerHTML = '<div class="flex items-start gap-3">'
            + '<div class="w-8 h-8 bg-green-600 rounded-lg flex items-center justify-center shrink-0 text-sm">✓</div>'
            + '<div><p class="font-semibold text-green-400 text-sm">Chamado aberto!</p>'
            + '<p class="text-gray-300 text-xs mt-0.5">' + data.chamado.titulo + '</p>'
            + '<p class="text-gray-500 text-xs mt-1">A equipe de TI foi notificada. #' + data.chamado.id + '</p></div></div>';
        document.body.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 8000);

        if (Array.isArray(data.anexo_erros) && data.anexo_erros.length > 0) {
            const detalhes = data.anexo_erros.map(function (item) {
                return '- ' + (item.arquivo || 'arquivo') + ': ' + (item.erro || 'erro desconhecido');
            }).join('\n');
            alert('Chamado aberto, mas alguns anexos falharam:\n' + detalhes);
        }

        atualizarBadgePainelChamados();

    } catch (err) {
        alert('Erro: ' + err.message);
    } finally {
        btnEnviar.disabled = false;
        btnEnviar.textContent = 'Abrir Chamado de Emergência';
    }
}

// ── Nova Conversa ─────────────────────────────
let tipoConversa = 'privada';
let usuariosSelecionados = new Set();

function abrirModalNovaConversa() {
    tipoConversa = 'privada';
    usuariosSelecionados.clear();
    const tabPrivada = document.getElementById('tab-privada');
    const tabGrupo = document.getElementById('tab-grupo');
    if (tabPrivada) {
        tabPrivada.className = 'flex-1 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white transition';
    }
    if (tabGrupo) {
        tabGrupo.className = 'flex-1 py-2 text-sm font-medium rounded-lg text-gray-400 hover:text-white transition';
    }
    const grupoNomeInput = document.getElementById('grupo-nome');
    if (grupoNomeInput) grupoNomeInput.value = '';
    document.getElementById('form-privada').classList.remove('hidden');
    document.getElementById('form-grupo').classList.add('hidden');
    document.getElementById('modal-nova-conversa').classList.remove('hidden');
    carregarUsuariosModal();
}

function fecharModalNovaConversa() {
    document.getElementById('modal-nova-conversa').classList.add('hidden');
    tipoConversa = 'privada';
    usuariosSelecionados.clear();
}

function trocarTipoConversa(tipo) {
    tipoConversa = tipo;
    document.getElementById('form-privada').classList.toggle('hidden', tipo !== 'privada');
    document.getElementById('form-grupo').classList.toggle('hidden', tipo !== 'grupo');
    document.getElementById('tab-privada').className = 'flex-1 py-2 text-sm font-medium rounded-lg transition ' + (tipo === 'privada' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white');
    const tabGrupo = document.getElementById('tab-grupo');
    if (tabGrupo) tabGrupo.className = 'flex-1 py-2 text-sm font-medium rounded-lg transition ' + (tipo === 'grupo' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white');
    if (tipo === 'grupo') carregarUsuariosGrupo();
}

async function carregarUsuariosModal() {
    const res = await fetch('/api/usuarios/online');
    const lista = await res.json();
    const div = document.getElementById('lista-usuarios-conversa');
    if (!lista.length) {
        div.innerHTML = '<p class="text-xs text-gray-500 py-2">Nenhum outro usuário cadastrado.</p>';
        return;
    }
    const cores = ['bg-pink-700', 'bg-emerald-700', 'bg-amber-700', 'bg-purple-700', 'bg-blue-700'];
    div.innerHTML = lista.map(function (u) {
        return '<button onclick="iniciarConversaPrivada(' + u.id + ', \'' + u.nome.replace(/'/g, "\\'") + '\')" '
            + 'class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left">'
            + '<div class="w-8 h-8 ' + cores[u.id % cores.length] + ' rounded-lg flex items-center justify-center text-xs font-bold shrink-0">' + u.nome.charAt(0).toUpperCase() + '</div>'
            + '<div><p class="text-sm font-medium text-white">' + u.nome + '</p>'
            + '<p class="text-xs text-gray-400">' + (u.setor || u.papel) + '</p></div>'
            + '</button>';
    }).join('');
}

async function carregarUsuariosGrupo() {
    const res = await fetch('/api/usuarios/online');
    const lista = await res.json();
    const div = document.getElementById('lista-usuarios-grupo');
    const cores = ['bg-pink-700', 'bg-emerald-700', 'bg-amber-700', 'bg-purple-700', 'bg-blue-700'];
    div.innerHTML = lista.map(function (u) {
        return '<label class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-800 transition cursor-pointer">'
            + '<input type="checkbox" value="' + u.id + '" class="rounded" onchange="toggleParticipante(' + u.id + ')">'
            + '<div class="w-7 h-7 ' + cores[u.id % cores.length] + ' rounded-lg flex items-center justify-center text-xs font-bold shrink-0">' + u.nome.charAt(0).toUpperCase() + '</div>'
            + '<span class="text-sm text-white">' + u.nome + '</span></label>';
    }).join('');
}

function toggleParticipante(id) {
    if (usuariosSelecionados.has(id)) usuariosSelecionados.delete(id);
    else usuariosSelecionados.add(id);
}

async function iniciarConversaPrivada(usuarioId, usuarioNome) {
    const res = await fetch('/api/conversas', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tipo=privada&usuario_id=' + usuarioId,
    });
    const data = await res.json();
    if (!res.ok) { alert(data.erro); return; }
    fecharModalNovaConversa();
    await carregarConversas();
    const btn = document.querySelector('.conversa-item[data-id="' + data.id + '"]');
    if (btn) selecionarConversa(data.id, usuarioNome, btn);
}

async function abrirConversaViaUrl() {
    const urlParams = new URLSearchParams(window.location.search);
    const conversaId = parseInt(urlParams.get('conversa') || '0', 10);
    const usuarioAlvoId = parseInt(urlParams.get('conversa_com') || '0', 10);

    if (conversaId) {
        const btn = document.querySelector('.conversa-item[data-id="' + conversaId + '"]');
        if (btn) {
            selecionarConversa(conversaId, btn.dataset.nome || 'Conversa', btn);
        } else {
            mostrarEstadoVazioChat();
        }
        return;
    }

    if (!usuarioAlvoId) return;

    try {
        const res = await fetch('/api/conversas', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'tipo=privada&usuario_id=' + encodeURIComponent(usuarioAlvoId)
        });

        const data = await res.json();
        if (!res.ok) {
            console.error('Nao foi possivel iniciar conversa privada:', data.erro || data);
            return;
        }

        await carregarConversas();

        const btn = document.querySelector('.conversa-item[data-id="' + data.id + '"]');
        const nomeConversa = data.nome || (btn ? btn.dataset.nome : 'Conversa');
        if (btn) {
            selecionarConversa(data.id, nomeConversa, btn);
        } else {
            selecionarConversa(data.id, nomeConversa);
        }
    } catch (err) {
        console.error('Erro ao abrir conversa via URL:', err);
    }
}

async function criarGrupo() {
    const nome = document.getElementById('grupo-nome').value.trim();
    if (!nome) { alert('Informe o nome do grupo.'); return; }
    const participantes = Array.from(usuariosSelecionados).join(',');
    const res = await fetch('/api/conversas', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tipo=grupo&nome=' + encodeURIComponent(nome) + '&participantes=' + participantes,
    });
    const data = await res.json();
    if (!res.ok) { alert(data.erro); return; }
    fecharModalNovaConversa();
    await carregarConversas();
    const btn = document.querySelector('.conversa-item[data-id="' + data.id + '"]');
    if (btn) selecionarConversa(data.id, nome, btn);
}

// ── Editar Grupo ──────────────────────────────
function abrirModalEditarGrupo(id, nome) {
    grupoEditandoId = id;
    grupoEditandoNome = nome;
    document.getElementById('editar-grupo-nome').value = nome;
    document.getElementById('editar-grupo-subtitulo').textContent = nome;
    document.getElementById('modal-editar-grupo').classList.remove('hidden');
    carregarMembrosGrupo();
}

function fecharModalEditarGrupo() {
    document.getElementById('modal-editar-grupo').classList.add('hidden');
    grupoEditandoId = null;
    grupoEditandoNome = null;
}

async function abrirModalInfoGrupo() {
    if (!conversaAtualId) return;

    const [resConv, resParts] = await Promise.all([
        fetch('/api/conversas/' + conversaAtualId),
        fetch('/api/conversas/' + conversaAtualId + '/participantes')
    ]);

    if (!resConv.ok) {
        alert('Não foi possível carregar informações do grupo.');
        return;
    }

    const conversa = await resConv.json();
    const participantes = resParts.ok ? await resParts.json() : [];

    document.getElementById('info-grupo-nome').textContent = conversa.nome || 'Grupo';
    document.getElementById('info-grupo-meta').textContent = (conversa.participantes_count || participantes.length || 0) + ' participante(s)';
    document.getElementById('info-grupo-descricao').textContent = conversa.descricao || 'Sem descrição cadastrada.';

    const descricaoInput = document.getElementById('info-grupo-descricao-input');
    if (descricaoInput) {
        descricaoInput.value = conversa.descricao || '';
    }

    const div = document.getElementById('info-grupo-participantes');
    div.innerHTML = (participantes || []).map(function (u) {
        return '<div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-gray-800 border border-gray-700">'
            + '<span class="w-2 h-2 rounded-full bg-green-400"></span>'
            + '<span class="text-sm text-gray-200">' + u.nome + '</span>'
            + '<span class="text-xs text-gray-500 ml-auto">' + (u.setor || u.papel || '') + '</span>'
            + '</div>';
    }).join('') || '<p class="text-xs text-gray-500">Sem participantes.</p>';

    document.getElementById('modal-info-grupo').classList.remove('hidden');
}

function fecharModalInfoGrupo() {
    document.getElementById('modal-info-grupo').classList.add('hidden');
}

async function salvarDescricaoGrupo() {
    const input = document.getElementById('info-grupo-descricao-input');
    if (!input || !conversaAtualId) return;

    const res = await fetch('/api/conversas/' + conversaAtualId + '/descricao', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ descricao: input.value.trim() })
    });

    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        alert(data.erro || 'Erro ao salvar descrição.');
        return;
    }

    document.getElementById('info-grupo-descricao').textContent = input.value.trim() || 'Sem descrição cadastrada.';
}

async function carregarMembrosGrupo() {
    const resMembros = await fetch('/api/conversas/' + grupoEditandoId + '/participantes');
    const membros = await resMembros.json();
    const resAll = await fetch('/api/usuarios/online');
    const todos = await resAll.json();

    const divMembros = document.getElementById('editar-grupo-membros');
    const divDisp = document.getElementById('editar-grupo-disponiveis');
    const cores = ['bg-indigo-700', 'bg-emerald-700', 'bg-pink-700', 'bg-amber-700', 'bg-purple-700'];
    const ids = membros.map(function (u) { return u.id; });

    divMembros.innerHTML = membros.length ? membros.map(function (u) {
        const ehEu = u.id === CURRENT_USER_ID;
        const removeBtn = !ehEu
            ? '<button onclick="removerMembroGrupo(' + u.id + ')" class="text-gray-600 hover:text-red-400 transition p-1 rounded-lg hover:bg-red-500/10" title="Remover">'
            + '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>'
            + '</button>'
            : '<span class="text-xs text-gray-600">você</span>';
        return '<div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-gray-800">'
            + '<div class="w-8 h-8 ' + cores[u.id % cores.length] + ' rounded-lg flex items-center justify-center text-xs font-bold shrink-0">' + u.nome.charAt(0).toUpperCase() + '</div>'
            + '<div class="flex-1 min-w-0"><p class="text-sm font-medium text-white truncate">' + u.nome + '</p>'
            + '<p class="text-xs text-gray-400">' + (u.setor || u.papel) + '</p></div>'
            + removeBtn + '</div>';
    }).join('') : '<p class="text-xs text-gray-500 px-2">Nenhum membro.</p>';

    const disponiveis = todos.filter(function (u) { return !ids.includes(u.id); });
    divDisp.innerHTML = disponiveis.length ? disponiveis.map(function (u) {
        return '<button onclick="adicionarMembroGrupo(' + u.id + ')" class="w-full flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-800 transition text-left">'
            + '<div class="w-8 h-8 ' + cores[u.id % cores.length] + ' rounded-lg flex items-center justify-center text-xs font-bold shrink-0">' + u.nome.charAt(0).toUpperCase() + '</div>'
            + '<div class="flex-1 min-w-0"><p class="text-sm font-medium text-white truncate">' + u.nome + '</p>'
            + '<p class="text-xs text-gray-400">' + (u.setor || u.papel) + '</p></div>'
            + '<span class="text-xs text-indigo-400 shrink-0">+ Adicionar</span>'
            + '</button>';
    }).join('') : '<p class="text-xs text-gray-500 px-2">Todos os usuários já estão no grupo.</p>';
}

async function salvarNomeGrupo() {
    const nome = document.getElementById('editar-grupo-nome').value.trim();
    if (!nome) { alert('Informe o nome do grupo.'); return; }
    const res = await fetch('/api/conversas/' + grupoEditandoId, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'nome=' + encodeURIComponent(nome),
    });
    if (res.ok) {
        grupoEditandoNome = nome;
        document.getElementById('editar-grupo-subtitulo').textContent = nome;
        await carregarConversas();
    }
}

async function adicionarMembroGrupo(usuarioId) {
    const res = await fetch('/api/conversas/' + grupoEditandoId + '/participantes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'usuario_id=' + usuarioId,
    });
    if (res.ok) await carregarMembrosGrupo();
}

async function removerMembroGrupo(usuarioId) {
    if (!confirm('Remover este membro do grupo?')) return;
    const res = await fetch('/api/conversas/' + grupoEditandoId + '/participantes/' + usuarioId, { method: 'DELETE' });
    const data = await res.json();
    if (!res.ok) { alert(data.erro); return; }
    await carregarMembrosGrupo();
}

async function confirmarExcluirGrupo() {
    if (!confirm('Excluir o grupo "' + grupoEditandoNome + '" permanentemente? Todas as mensagens serão perdidas.')) return;
    const res = await fetch('/api/conversas/' + grupoEditandoId, { method: 'DELETE' });
    if (res.ok) {
        fecharModalEditarGrupo();
        if (conversaAtualId == grupoEditandoId) {
            mostrarEstadoVazioChat();
        }
        carregarConversas();
    }
}

function configurarBusca() {
    const input = document.getElementById('search-input');
    if (!input) return;

    input.addEventListener('input', function () {
        const termo = input.value.trim().toLowerCase();

        document.querySelectorAll('.conversa-item').forEach(function (item) {
            const nome = (item.dataset.nome || '').toLowerCase();
            const preview = (item.querySelector('.preview-msg')?.textContent || '').toLowerCase();
            const visivel = !termo || nome.includes(termo) || preview.includes(termo);
            item.parentElement.style.display = visivel ? '' : 'none';
        });

        document.querySelectorAll('#messages .msg-enter').forEach(function (msg) {
            const texto = (msg.textContent || '').toLowerCase();
            msg.style.display = (!termo || texto.includes(termo)) ? '' : 'none';
        });
    });
}

function configurarAnexoChamado() {
    const input = document.getElementById('input-anexo-chamado');
    const label = document.getElementById('label-anexo-chamado');
    if (!input || !label) return;

    input.addEventListener('change', function () {
        if (!input.files || input.files.length === 0) {
            label.textContent = 'Clique para selecionar arquivos';
            label.classList.remove('text-indigo-300', 'font-semibold');
            label.classList.add('text-gray-400');
            return;
        }

        if (input.files.length === 1) {
            label.textContent = 'Arquivo: ' + input.files[0].name;
        } else {
            label.textContent = input.files.length + ' arquivos selecionados';
        }

        label.classList.remove('text-gray-400');
        label.classList.add('text-indigo-300', 'font-semibold');
    });
}

function configurarNotificacoes() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'default') {
        Notification.requestPermission().catch(() => { });
    }
}

function notificarMensagem(titulo, corpo) {
    if (!('Notification' in window)) return;
    if (document.hasFocus()) {
        mostrarToastNotificacao(titulo, corpo);
        return;
    }
    if (Notification.permission !== 'granted') return;

    const n = new Notification(titulo, { body: corpo });
    setTimeout(function () { n.close(); }, 5000);
}

function mostrarToastNotificacao(titulo, corpo) {
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-6 right-6 bg-gray-800 border border-indigo-500/30 text-white rounded-2xl p-4 shadow-2xl z-50 max-w-sm';
    toast.innerHTML = '<p class="font-semibold text-indigo-300 text-sm">' + titulo + '</p>'
        + '<p class="text-gray-300 text-xs mt-1">' + corpo + '</p>';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 4500);
}

async function verificarNovasMensagensNotificacao() {
    try {
        const res = await fetch('/api/conversas');
        const lista = await res.json();
        const total = (lista || []).reduce(function (acc, c) {
            return acc + (parseInt(c.nao_lidas, 10) || 0);
        }, 0);

        if (notificacoesInicializadas && total > ultimoTotalNaoLidas) {
            const delta = total - ultimoTotalNaoLidas;
            notificarMensagem('Chat Interno', 'Voce recebeu ' + delta + ' nova(s) mensagem(ns).');
        }

        ultimoTotalNaoLidas = total;
        notificacoesInicializadas = true;
    } catch (e) {
        console.error('Erro ao verificar notificacoes:', e);
    }
}

async function sincronizarMensagensConversaAtual() {
    if (!conversaAtualId) return;

    try {
        const res = await fetch('/api/mensagens?conversa_id=' + conversaAtualId + '&pagina=1&_ts=' + Date.now());
        if (!res.ok) return;

        const msgs = await res.json();
        if (!Array.isArray(msgs)) return;

        let adicionou = false;
        msgs.forEach(function (m) {
            if (!m || !m.id) return;
            if (document.querySelector('[data-msg-id="' + m.id + '"]')) return;
            renderizarMensagem(m);
            adicionou = true;
        });

        if (adicionou) {
            const box = document.getElementById('messages');
            box.scrollTop = box.scrollHeight;
            fetch('/api/conversas/' + conversaAtualId + '/lida', { method: 'POST' }).catch(function () { });
        }
    } catch (_) {
        // Falha silenciosa: fallback não deve interromper o chat.
    }
}

function sincronizacaoLeve() {
    carregarConversas().catch(function () { });
    verificarNovasMensagensNotificacao();
    sincronizarMensagensConversaAtual();
}

async function atualizarBadgePainelChamados() {
    const badge = document.getElementById('badge-novos-chamados');
    if (!badge) return;

    try {
        const res = await fetch('/api/chamados?status=aberto');
        if (!res.ok) {
            throw new Error('Falha ao buscar chamados: HTTP ' + res.status);
        }
        const chamados = await res.json();
        let lastSeen = parseInt(localStorage.getItem('dashboard_last_seen_aberto') || '0', 10);
        const agora = Date.now();

        if (!Number.isFinite(lastSeen) || lastSeen < 0 || lastSeen > (agora + 60000)) {
            lastSeen = 0;
            localStorage.setItem('dashboard_last_seen_aberto', '0');
        }

        const temNovo = (chamados || []).some(function (c) {
            const criado = normalizarDataServidor(c.criado_em);
            return criado > lastSeen;
        });

        badge.classList.toggle('hidden', !temNovo);
    } catch (e) {
        console.error('Erro ao atualizar badge de chamados:', e);
        badge.classList.add('hidden');
    }
}

function normalizarDataServidor(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    return data ? data.getTime() : 0;
}

// Preenche o select de categorias ao carregar a página
function popularCategorias() {
    const sel = document.getElementById('sel-categoria');
    Object.keys(CONFIG_CHAMADOS.categorias).forEach(cat => {
        sel.innerHTML += `<option value="${cat}">${cat}</option>`;
    });
}

// Muda as subcategorias baseado na categoria escolhida
function atualizarSubcategorias() {
    const categoria = document.getElementById('sel-categoria').value;
    const selSub = document.getElementById('sel-subcategoria');

    selSub.innerHTML = '<option value="">Selecione...</option>';

    if (categoria && CONFIG_CHAMADOS.categorias[categoria]) {
        CONFIG_CHAMADOS.categorias[categoria].forEach(sub => {
            selSub.innerHTML += `<option value="${sub}">${sub}</option>`;
        });
    }
}

// Envia a classificação para a API que criamos no Passo 3
document.getElementById('form-classificar').onsubmit = async (e) => {
    e.preventDefault();
    const id = document.getElementById('classificar-id').value;
    const payload = {
        prioridade: document.getElementById('sel-prioridade').value,
        categoria: document.getElementById('sel-categoria').value,
        subcategoria: document.getElementById('sel-subcategoria').value
    };

    try {
        const response = await fetch(`/api/chamados/${id}/classificar`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (response.ok) {
            alert("Chamado classificado e movido para documentação!");
            fecharModalClassificar();
            atualizarBadgePainelChamados();
        }
    } catch (error) {
        console.error("Erro ao classificar:", error);
    }
};

function fecharModalClassificar() {
    document.getElementById('modal-classificar').classList.add('hidden');
}

// Inicializa as categorias
popularCategorias();

