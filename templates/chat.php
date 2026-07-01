<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Interno</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@4.6.4/dist/index.min.js"></script>
    <link rel="stylesheet" href="/assets/css/light-mode.css">
    <script src="/assets/js/utils.js"></script>
    <script src="/assets/js/config.js"></script>
    <script>
        document.documentElement.classList.add('chat-loading');
    </script>
    <style>
        html.chat-loading body { visibility: hidden; }
        #messages { scroll-behavior: smooth; }
        .msg-enter { animation: fadeUp .2s ease; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 767px) {
            #chat-sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 50;
                transform: translateX(-100%);
                transition: transform .2s ease;
            }

            body.sidebar-open #chat-sidebar {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body class="page-chat bg-gray-950 text-white h-screen flex overflow-hidden">

<!-- ═══ SIDEBAR ═══ -->
<aside id="chat-sidebar" class="w-72 bg-gray-900 border-r border-gray-800 flex flex-col shrink-0">

    <div class="md:hidden p-3 border-b border-gray-800 flex justify-end">
        <button onclick="toggleSidebarMobile(false)" class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center" title="Fechar menu">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="p-4 border-b border-gray-800 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center text-sm font-bold">
                <?= strtoupper(substr($userName, 0, 1)) ?>
            </div>
            <div>
                <p class="text-sm font-semibold text-white"><?= htmlspecialchars($userName) ?></p>
                <p class="text-xs text-green-400 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 bg-green-400 rounded-full inline-block"></span>
                    Online
                </p>
            </div>
        </div>
        <div class="flex items-center gap-1">
            <?php if ($userPapel === 'admin'): ?>
            <a href="/admin" title="Painel Admin"
               class="text-gray-500 hover:text-indigo-400 transition p-1.5 rounded-lg hover:bg-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            <?php endif; ?>
            <a href="/notificacoes" title="Central de notificações"
               class="relative text-gray-500 hover:text-indigo-400 transition p-1.5 rounded-lg hover:bg-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <span data-notification-badge class="<?= (($notificationCount ?? 0) > 0) ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"><?= (int) ($notificationCount ?? 0) ?></span>
            </a>
            <a href="/logout" title="Sair"
               class="text-gray-500 hover:text-red-400 transition p-1.5 rounded-lg hover:bg-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </a>
        </div>
    </div>

    <div class="p-3">
        <button onclick="abrirEmergencia()"
                class="w-full bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            Chamar TI — Emergência
        </button>
    </div>

    <div class="p-3 pt-0">
        <a href="/meus-chamados"
           class="w-full bg-gray-800 hover:bg-indigo-600 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors border border-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2" />
            </svg>
            Meus Chamados
        </a>
    </div>

    <div class="p-3 pt-0">
        <a href="/agendamentos"
           class="w-full bg-gray-800 hover:bg-indigo-600 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors border border-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z" />
            </svg>
            Agendamentos
        </a>
    </div>

    <?php if (in_array($userPapel, ['ti', 'admin'])): ?>
    <div class="p-3 pt-0">
        <button id="btn-painel-chamados" onclick="window.location.href='/dashboard-ti'"
                class="relative w-full bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2" />
            </svg>
            Painel de Chamados
            <span id="badge-novos-chamados" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border border-gray-900"></span>
        </button>
    </div>
    <?php endif; ?>

    <div class="p-3 flex items-center gap-2">
        <div class="relative flex-1">
            <svg class="w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
                 <input id="search-input" type="text" placeholder="Buscar..."
                   class="w-full bg-gray-800 border border-gray-700 rounded-xl pl-9 pr-4 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <button onclick="abrirModalNovaConversa()" title="Nova conversa"
                class="w-9 h-9 bg-gray-800 border border-gray-700 hover:border-indigo-500 hover:text-indigo-400 text-gray-400 rounded-xl flex items-center justify-center transition shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-2 pb-4 space-y-0.5">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-3 pb-2">Conversas</p>
        <div id="lista-conversas" class="space-y-0.5"></div>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-4 pb-2">Usuários</p>
        <div id="lista-usuarios" class="space-y-0.5"></div>
    </nav>
</aside>

<!-- ═══ ÁREA PRINCIPAL ═══ -->
<main class="flex-1 flex flex-col min-w-0 w-full">

    <header id="chat-header" class="hidden h-16 bg-gray-900 border-b border-gray-800 flex items-center justify-between px-4 md:px-6 shrink-0">
        <div class="flex items-center gap-3">
            <button class="md:hidden w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center" onclick="toggleSidebarMobile(true)" title="Abrir menu">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="w-8 h-8 bg-indigo-700 rounded-lg flex items-center justify-center text-sm">#</div>
            <div>
                <p id="chat-nome" class="font-semibold text-white text-sm">Chat Interno</p>
                <p class="text-xs text-gray-400">Chat Interno</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button data-theme-toggle class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Alternar tema">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                </svg>
            </button>
            <button id="btn-info-grupo" onclick="abrirModalInfoGrupo()" class="hidden px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 transition">
                Informações do Grupo
            </button>
        </div>
    </header>

    <div id="chat-load-more-wrap" class="hidden px-6 pt-3">
        <button id="btn-carregar-mais" onclick="carregarMaisMensagens()" class="hidden w-full bg-gray-900 border border-gray-800 hover:border-indigo-500 text-xs text-gray-300 rounded-xl py-2 transition">
            Carregar mensagens anteriores
        </button>
    </div>

    <div id="messages" class="flex-1 overflow-y-auto p-6 flex items-center justify-center">
        <div id="chat-empty-state" class="text-center select-none px-6">
            <p class="text-3xl md:text-4xl font-semibold tracking-tight text-gray-300">Bem-vindo ao Chat Interno!</p>
        </div>
    </div>

    <div id="typing-indicator" class="hidden px-6 py-1 text-xs text-gray-500 italic"></div>

    <div id="chat-composer" class="hidden p-3 md:p-4 bg-gray-900 border-t border-gray-800 shrink-0">
        <div id="msg-input-wrapper" class="flex items-center gap-2 md:gap-3 bg-gray-800 border border-gray-700 rounded-2xl px-3 md:px-4 py-2.5 focus-within:ring-2 focus-within:ring-indigo-500 transition cursor-text">
            <button onclick="document.getElementById('msg-file-input').click()" class="text-gray-400 hover:text-indigo-400 transition shrink-0 p-1" title="Anexar arquivos">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                </svg>
            </button>
            <input id="msg-file-input" type="file" multiple class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.m4a,.mp4,.mov,.webm" onchange="atualizarPreviewAnexoMensagem()">
            <button onclick="aplicarFormatacaoTexto('bold')" class="text-gray-400 hover:text-indigo-400 transition shrink-0 p-1 text-xs font-bold" title="Negrito">B</button>
            <button onclick="aplicarFormatacaoTexto('italic')" class="text-gray-400 hover:text-indigo-400 transition shrink-0 p-1 text-xs italic font-semibold" title="Itálico">I</button>
            <textarea id="msg-input" rows="1"
                      placeholder="Selecione uma conversa..."
                      class="flex-1 bg-transparent text-sm text-white placeholder-gray-500 resize-none focus:outline-none max-h-32 leading-6 py-1"
                      onkeydown="handleEnter(event)"
                      oninput="autoResize(this)"></textarea>
            <button id="btn-emoji" type="button" onclick="toggleEmojiPicker()" class="text-gray-400 hover:text-amber-300 transition shrink-0 p-1" title="Emojis">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </button>
            <button onclick="enviarMensagem()"
                    class="bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl p-2 transition shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
            </button>
        </div>
        <div id="emoji-fallback-panel" class="hidden fixed z-[100000] bg-gray-900 border border-gray-700 rounded-xl shadow-2xl p-2 w-64">
            <div class="grid grid-cols-8 gap-1 text-lg">
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😀">😀</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😁">😁</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😂">😂</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😊">😊</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😍">😍</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😎">😎</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="🤔">🤔</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😭">😭</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="😡">😡</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="👍">👍</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="👏">👏</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="🙏">🙏</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="🔥">🔥</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="✅">✅</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="🎉">🎉</button>
                <button type="button" class="emoji-fallback-item hover:bg-gray-800 rounded p-1" data-emoji="❤️">❤️</button>
            </div>
        </div>
        <div id="msg-file-preview" class="hidden mt-2 ml-1 px-3 py-2 bg-gray-800 border border-gray-700 rounded-xl text-xs text-gray-300"></div>
        <p class="text-xs text-gray-600 mt-2 ml-1">Enter para enviar · Shift+Enter para nova linha</p>
    </div>
</main>

<div id="sidebar-overlay" class="hidden md:hidden fixed inset-0 bg-black/50 z-40" onclick="toggleSidebarMobile(false)"></div>

<!-- ═══ MODAL EMERGÊNCIA ═══ -->
<div id="modal-emergencia" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-red-500/30 rounded-2xl w-full max-w-2xl shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-red-600 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-bold text-white">Chamado de Emergência — TI</h2>
                    <p class="text-xs text-gray-400">A equipe será notificada imediatamente</p>
                </div>
            </div>
            <button onclick="fecharEmergencia()" class="text-gray-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Título do problema</label>
                <input type="text" id="chamado-titulo" placeholder="Ex: Impressora do 2º andar não funciona"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Descrição detalhada</label>
                <textarea id="chamado-descricao" rows="4"
                          placeholder="Descreva o problema: o que aconteceu, quando começou, quais equipamentos afetados..."
                          class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 transition resize-none"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Anexar arquivos</label>
                <label class="flex items-center gap-3 bg-gray-800 border border-dashed border-gray-600 rounded-xl px-4 py-3 cursor-pointer hover:border-gray-500 transition">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <span id="label-anexo-chamado" class="text-sm text-gray-400">Clique para selecionar arquivos</span>
                    <input id="input-anexo-chamado" type="file" multiple class="hidden" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.txt,.step,.stp,.exe">
                </label>
            </div>
        </div>
        <div class="flex gap-3 px-6 pb-6">
            <button onclick="fecharEmergencia()"
                    class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-2.5 text-sm font-medium transition">
                Cancelar
            </button>
            <button id="btn-enviar-chamado" onclick="enviarChamado()"
                    class="flex-1 bg-red-600 hover:bg-red-500 text-white rounded-xl py-2.5 text-sm font-bold transition disabled:opacity-50 disabled:cursor-not-allowed">
                Abrir Chamado de Emergência
            </button>
        </div>
    </div>
</div>

<!-- ═══ MODAL NOVA CONVERSA ═══ -->
<div id="modal-nova-conversa" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <h3 class="font-bold text-white">Nova Conversa</h3>
            <button onclick="fecharModalNovaConversa()" class="text-gray-500 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex bg-gray-800 rounded-xl p-1 gap-1">
                <button id="tab-privada" onclick="trocarTipoConversa('privada')"
                        class="flex-1 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white transition">
                    Conversa Privada
                </button>
                <?php if ($userPapel === 'admin'): ?>
                <button id="tab-grupo" onclick="trocarTipoConversa('grupo')"
                        class="flex-1 py-2 text-sm font-medium rounded-lg text-gray-400 hover:text-white transition">
                    Criar Grupo
                </button>
                <?php endif; ?>
            </div>
            <div id="form-privada">
                <label class="block text-sm font-medium text-gray-300 mb-2">Selecione o usuário</label>
                <div id="lista-usuarios-conversa" class="space-y-1 max-h-64 overflow-y-auto"></div>
            </div>
            <div id="form-grupo" class="hidden space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Nome do grupo</label>
                    <input type="text" id="grupo-nome" placeholder="Ex: Projeto X"
                           class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Participantes iniciais</label>
                    <div id="lista-usuarios-grupo" class="space-y-1 max-h-48 overflow-y-auto"></div>
                </div>
                <button onclick="criarGrupo()"
                        class="w-full bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-2.5 text-sm font-bold transition">
                    Criar Grupo
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MODAL EDITAR GRUPO ═══ -->
<div id="modal-editar-grupo" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <div>
                <h3 class="font-bold text-white">Editar Grupo</h3>
                <p id="editar-grupo-subtitulo" class="text-xs text-gray-400 mt-0.5"></p>
            </div>
            <button onclick="fecharModalEditarGrupo()" class="text-gray-500 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-5">
            <!-- Renomear -->
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Nome do grupo</label>
                <div class="flex gap-2">
                    <input type="text" id="editar-grupo-nome"
                           class="flex-1 bg-gray-800 border border-gray-700 text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <button onclick="salvarNomeGrupo()"
                            class="bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                        Salvar
                    </button>
                </div>
            </div>
            <!-- Membros atuais -->
            <div>
                <p class="text-sm font-medium text-gray-300 mb-3">Membros atuais</p>
                <div id="editar-grupo-membros" class="space-y-1 max-h-40 overflow-y-auto"></div>
            </div>
            <!-- Adicionar membros -->
            <div class="border-t border-gray-800 pt-4">
                <p class="text-sm font-medium text-gray-300 mb-3">Adicionar membros</p>
                <div id="editar-grupo-disponiveis" class="space-y-1 max-h-40 overflow-y-auto"></div>
            </div>
            <!-- Excluir grupo -->
            <div class="border-t border-gray-800 pt-4">
                <button onclick="confirmarExcluirGrupo()"
                        class="w-full bg-red-600/10 hover:bg-red-600/20 border border-red-500/30 text-red-400 rounded-xl py-2.5 text-sm font-medium transition">
                    Excluir grupo permanentemente
                </button>
            </div>
        </div>
    </div>
</div>

<div id="modal-info-grupo" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <h3 class="font-bold text-white">Informações do Grupo</h3>
            <button onclick="fecharModalInfoGrupo()" class="text-gray-500 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <p id="info-grupo-nome" class="text-sm font-semibold text-white"></p>
                <p id="info-grupo-meta" class="text-xs text-gray-400 mt-1"></p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-gray-500 mb-2">Descrição</p>
                <p id="info-grupo-descricao" class="text-sm text-gray-300 bg-gray-800 border border-gray-700 rounded-xl p-3"></p>
            </div>

            <?php if ($userPapel === 'admin'): ?>
            <div>
                <p class="text-xs font-semibold uppercase text-gray-500 mb-2">Editar Descrição</p>
                <textarea id="info-grupo-descricao-input" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white"></textarea>
                <button onclick="salvarDescricaoGrupo()" class="mt-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-xl px-4 py-2 transition">Salvar descrição</button>
            </div>
            <?php endif; ?>

            <div>
                <p class="text-xs font-semibold uppercase text-gray-500 mb-2">Participantes</p>
                <div id="info-grupo-participantes" class="space-y-2 max-h-56 overflow-y-auto"></div>
            </div>
        </div>
    </div>
</div>

<div id="modal-classificar" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg shadow-2xl">
        <div class="p-6 border-b border-gray-800">
            <h3 class="text-xl font-bold text-white">Classificar Chamado</h3>
            <p id="classificar-titulo-orig" class="text-sm text-gray-400 mt-1"></p>
        </div>
        
        <form id="form-classificar" class="p-6 space-y-4">
            <input type="hidden" id="classificar-id">
            
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-2">Prioridade</label>
                <select id="sel-prioridade" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 text-white">
                    <option value="baixa">Baixa</option>
                    <option value="media" selected>Média</option>
                    <option value="alta">Alta</option>
                    <option value="critica">Crítica</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-2">Categoria</label>
                    <select id="sel-categoria" onchange="atualizarSubcategorias()" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 text-white">
                        <option value="">Selecione...</option>
                        </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-2">Subcategoria</label>
                    <select id="sel-subcategoria" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 text-white">
                        <option value="">Selecione a categoria primeiro</option>
                    </select>
                </div>
            </div>

            <div class="flex gap-3 pt-4">
                <button type="button" onclick="fecharModalClassificar()" class="flex-1 px-4 py-2 bg-gray-800 text-white rounded-xl hover:bg-gray-700 transition">Cancelar</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-500 transition">Salvar Classificação</button>
            </div>
        </form>
    </div>
</div>

<script>
window.CHAT_BOOTSTRAP = <?= json_encode([
    'currentUserId' => (int) $userId,
    'currentUserName' => (string) $userName,
    'userPapel' => (string) $userPapel,
    'isAdmin' => $userPapel === 'admin',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/theme.js"></script>
<script src="/assets/js/notificacoes.js"></script>
<script src="/assets/js/chat.js"></script>

</body>
</html>