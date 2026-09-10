<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Interno</title>
    <!-- Cópia local do CDN do Tailwind: como script de terceiro no <head>, ele
         bloqueava a primeira pintura de toda tela. Ver o topo do arquivo. -->
    <script src="<?= asset('/assets/js/tailwind.js') ?>"></script>
    <script type="module" src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@4.6.4/dist/index.min.js"></script>
    <link rel="stylesheet" href="<?= asset('/assets/css/light-mode.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/transicao-pagina.css') ?>">
    <script src="<?= asset('/assets/js/transicao-pagina.js') ?>"></script>
    <link rel="stylesheet" href="<?= asset('/assets/css/menu-lateral.css') ?>">
    <link rel="manifest" href="/manifest.json">
    <script src="<?= asset('/assets/js/utils.js') ?>"></script>
    <script src="<?= asset('/assets/js/config.js') ?>"></script>
    <script>
        // Segura a entrada da tela até a lista de conversas estar montada.
        // Quem solta é o chat.js; a regra está em transicao-pagina.css.
        document.documentElement.classList.add('pagina-aguardando');
        // Rede de segurança: se o chat.js não carregar (404, rede, erro de
        // sintaxe), ninguém remove a classe e a tela ficaria invisível para
        // sempre. Passado o prazo, a tela aparece mesmo incompleta.
        setTimeout(function () {
            document.documentElement.classList.remove('pagina-aguardando');
        }, 3000);
    </script>
    <style>
        #messages { scroll-behavior: smooth; }

        /* Botão de editar grupo (admin): em vez de flutuar por cima da data da
           última mensagem e do badge de não lidas, ele abre uma calha à direita
           — no hover o conteúdo do item desliza para a esquerda e nada fica
           encoberto. As transições moram aqui, em CSS de verdade: classe do
           Tailwind alternada por JS não animaria na primeira vez. E a
           especificidade é de dois seletores de propósito — o `.transition` do
           Tailwind entra no <head> depois deste bloco e venceria um empate,
           levando junto o padding-right. */
        .tem-editar > .conversa-item {
            transition: padding-right .12s ease, background-color .15s ease;
        }
        .tem-editar:hover > .conversa-item,
        .tem-editar:has(.conversa-editar:focus-visible) > .conversa-item {
            padding-right: 2.25rem;
        }

        .tem-editar .conversa-editar {
            opacity: 0;
            pointer-events: none;
            transition: opacity .12s ease, color .12s ease, background-color .12s ease;
        }
        .tem-editar:hover .conversa-editar,
        .conversa-editar:focus-visible {
            opacity: 1;
            pointer-events: auto;
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

        /* Painel de usuários (direita) — um item flex normal (igual ao
           #chat-sidebar), não `position: fixed`: assim ele reserva o próprio
           espaço e o limite real da área de mensagens é a borda dele, tanto
           aberto quanto minimizado (`<main>` tem `flex-1`, então acompanha a
           largura dele a cada quadro da transição). Mesma coreografia de
           fade do menu esquerdo (public/assets/css/menu-lateral.css), só que
           autocontida aqui por ser exclusiva desta tela. Larguras iguais às
           do menu esquerdo (18rem/4rem) de propósito: 4rem = padding (p-4,
           2rem) + botão (w-8, 2rem), o mesmo cálculo que faz o botão do
           cabeçalho caber exatamente na faixa recolhida. */
        #painel-usuarios {
            width: 18rem;
            transition: width 220ms cubic-bezier(.4, 0, .2, 1);
            overflow: hidden;
        }

        #painel-usuarios.painel-recolhido {
            width: 4rem;
        }

        #painel-usuarios.painel-sem-animacao,
        #painel-usuarios.painel-sem-animacao * {
            transition: none !important;
        }

        /* Só os blocos de largura inteira (a lista) travam a largura aberta —
           o bloco do título, dentro do cabeçalho, encolhe junto com a faixa
           (mesmo motivo do comentário em menu-lateral-cabecalho.php). */
        #painel-usuarios > [data-painel-conteudo] {
            min-width: 18rem;
        }

        #painel-usuarios [data-painel-conteudo] {
            opacity: 1;
            transition: opacity 150ms ease-out 110ms;
        }

        #painel-usuarios.painel-recolhido [data-painel-conteudo] {
            opacity: 0;
            pointer-events: none;
            transition: opacity 110ms ease-in;
        }

        #painel-usuarios [data-painel-recolhido] {
            opacity: 0;
            transition: opacity 110ms ease-in;
        }

        #painel-usuarios.painel-recolhido [data-painel-recolhido] {
            opacity: 1;
            transition: opacity 170ms ease-out 110ms;
        }

        /* A seta é a mesma nos dois estados: girar 180° amarra a animação num
           gesto só, igual ao botão do menu esquerdo ([data-menu-icone] em
           menu-lateral.css). */
        #painel-usuarios [data-painel-icone] {
            transition: transform 220ms ease;
        }

        #painel-usuarios.painel-recolhido [data-painel-icone] {
            transform: rotate(180deg);
        }

        @media (prefers-reduced-motion: reduce) {
            #painel-usuarios,
            #painel-usuarios * {
                transition-duration: 1ms !important;
                transition-delay: 0ms !important;
            }
        }
    </style>
</head>
<body class="page-chat bg-gray-950 text-white h-screen flex overflow-hidden">

<!-- ═══ SIDEBAR ═══ -->
<aside id="chat-sidebar" data-menu-lateral
       class="bg-gray-900 border-r border-gray-800 flex flex-col shrink-0">

    <div class="md:hidden p-3 border-b border-gray-800 flex justify-end" data-menu-conteudo>
        <button onclick="toggleSidebarMobile(false)" class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center" title="Fechar menu">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <?php $paginaAtual = 'chat'; include __DIR__ . '/partials/menu-lateral-cabecalho.php'; ?>

    <div class="p-3 pt-0 flex items-center gap-2" data-menu-conteudo>
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

    <!-- "Usuários" virou o painel próprio à direita (ver #painel-usuarios
         abaixo); "Conversas" continua recolhível como antes — útil por si só
         com muitos chats abertos, não só para abrir espaço pra outra seção. -->
    <nav class="flex-1 overflow-y-auto px-2 pb-4 space-y-0.5 min-h-0" data-menu-conteudo>
        <div data-secao="conversas">
            <button type="button" data-secao-toggle title="Recolher conversas"
                    class="w-full flex items-center justify-between px-3 pt-3 pb-2 text-xs font-semibold text-gray-500 uppercase tracking-wider hover:text-gray-300 transition">
                <span>Conversas</span>
                <svg data-secao-icone class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div data-secao-corpo>
                <?php
                /**
                 * Pré-renderizado a partir de $conversasBootstrap (ver rota /chat em
                 * public/index.php) com a MESMA marcação que obterItemConversa()/
                 * atualizarItemConversa() esperam em chat.js — data-conversa-id,
                 * data-nome e data-tipo já corretos. Assim, quando carregarConversas()
                 * roda no load, ela RESSUSA estes nós em vez de recriá-los (é a checagem
                 * `if (wrapper.dataset.nome !== nome)`), e a lista não pisca ao entrar
                 * na tela — mesmo motivo que already evita recriar tudo a cada
                 * sincronização de 4s.
                 */
                $conversaAtualId = (int) ($_GET['conversa'] ?? 0);
                ?>
                <div id="lista-conversas" class="space-y-0.5">
                    <?php foreach (($conversasBootstrap ?? []) as $c):
                        $id = (int) $c['id'];
                        $tipo = (string) ($c['tipo'] ?? 'privada');
                        $ehGrupo = in_array($tipo, ['grupo', 'setor'], true);
                        $nome = (string) ($c['nome'] ?? '');
                        $avatarTexto = $ehGrupo ? '#' : ($nome !== '' ? mb_strtoupper(mb_substr($nome, 0, 1)) : '?');
                        $avatarCor = $ehGrupo ? 'bg-indigo-700' : 'bg-emerald-700';
                        $naoLidas = $id === $conversaAtualId ? 0 : (int) ($c['nao_lidas'] ?? 0);
                        $preview = (string) ($c['ultima_mensagem'] ?? 'Sem mensagens');
                        $precisaEditar = $ehGrupo && $userPapel === 'admin';
                    ?>
                    <div class="group relative<?= $precisaEditar ? ' tem-editar' : '' ?>"
                         data-conversa-id="<?= $id ?>" data-nome="<?= htmlspecialchars($nome) ?>">
                        <button class="conversa-item w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left"
                                data-id="<?= $id ?>" data-nome="<?= htmlspecialchars($nome) ?>" data-tipo="<?= htmlspecialchars($tipo) ?>">
                            <div class="conversa-avatar w-9 h-9 <?= $avatarCor ?> rounded-xl flex items-center justify-center shrink-0 text-sm font-bold"><?= htmlspecialchars($avatarTexto) ?></div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <p class="conversa-nome text-sm font-medium text-white truncate flex-1 min-w-0"><?= htmlspecialchars($nome) ?></p>
                                    <span class="conversa-quando text-[11px] text-gray-500 shrink-0"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <p class="preview-msg text-xs text-gray-400 truncate flex-1 min-w-0"><?= htmlspecialchars($preview) ?></p>
                                    <span class="badge-nao-lidas <?= $naoLidas > 0 ? '' : 'hidden' ?> bg-indigo-600 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 shrink-0"><?= $naoLidas > 0 ? $naoLidas : '' ?></span>
                                </div>
                            </div>
                        </button>
                        <?php if ($precisaEditar): ?>
                        <button type="button" title="Editar grupo" aria-label="Editar grupo" class="conversa-editar absolute right-1.5 top-1/2 -translate-y-1/2 flex w-7 h-7 items-center justify-center text-gray-500 hover:text-indigo-400 rounded-lg hover:bg-indigo-500/10">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Só aparece com o menu minimizado -->
    <?php include __DIR__ . '/partials/menu-lateral-recolhido.php'; ?>
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

    <!-- Sem conversa aberta, o chat.js troca este bloco pelo painel de
         notificações (mostrarEstadoVazioChat). O placeholder existe só para não
         piscar conteúdo antigo antes do script rodar. -->
    <div id="messages" class="flex-1 overflow-y-auto p-6">
        <div id="chat-empty-state" class="max-w-2xl mx-auto pt-10 md:pt-16 text-sm text-gray-600">Carregando…</div>
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
            <input id="msg-file-input" type="file" multiple class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.m4a,.mp4,.mov,.webm">
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
        <div id="msg-anexos-lista" class="hidden mt-2 ml-1 space-y-2"></div>
        <p class="text-xs text-gray-600 mt-2 ml-1">Enter para enviar · Shift+Enter para nova linha</p>
    </div>
</main>

<!-- ═══ PAINEL DE USUÁRIOS (direita, só no /chat) ═══
     Item flex normal (ver <style> no <head>) — reserva o próprio espaço, então
     o limite real da área de mensagens é a borda dele, minimizado ou não
     (`<main>`, logo acima, tem `flex-1` e acompanha a largura dele a cada
     quadro da transição). Precisa vir DEPOIS de `<main>` no HTML: numa
     `<body class="flex">`, a ordem dos irmãos é a ordem visual — antes de
     `<main>` este painel apareceria no meio da tela, não na direita. Some
     abaixo de 768px (classes `hidden md:flex` — sem espaço pra abrir uma
     terceira coluna sem tampar o composer). Quem alimenta é
     carregarPainelUsuarios() em chat.js; o estado minimizado é independente
     do menu esquerdo (chave própria no localStorage) porque são painéis sem
     relação um com o outro. -->
<aside id="painel-usuarios" data-painel-usuarios
       class="hidden md:flex flex-col bg-gray-900 border-l border-gray-800 shrink-0">

    <!-- Botão sempre no mesmo lugar, dentro do cabeçalho — nunca some, nos
         dois estados. O botão vem ANTES do bloco do título (e não depois,
         como no menu esquerdo): aqui o painel fica ancorado à direita da
         tela, então é a borda ESQUERDA desta faixa que se move ao recolher;
         o botão precisa ficar colado nela para não sair da faixa visível de
         4rem — mesmo raciocínio do `justify-end` em
         menu-lateral-cabecalho.php, só que espelhado. O ícone é o mesmo nos
         dois estados, só gira 180° ao recolher (ver a regra de
         [data-painel-icone] no <style> acima) — mesma linguagem do botão do
         menu esquerdo. -->
    <div class="p-4 border-b border-gray-800 flex items-center gap-2">
        <button type="button" data-painel-usuarios-toggle title="Minimizar lista de usuários"
                class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center shrink-0 transition">
            <svg data-painel-icone class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
            </svg>
        </button>
        <div class="min-w-0 flex-1" data-painel-conteudo>
            <p class="text-sm font-semibold text-white truncate">Usuários</p>
            <p class="text-xs text-gray-500"><span id="painel-usuarios-total-online">0</span> online</p>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-2 min-h-0" data-painel-conteudo>
        <?php
        /** Pré-renderizado a partir de $usuariosBootstrap — mesma marcação de
         *  renderizarPainelUsuarios() em chat.js. Como esta lista é sempre
         *  recriada por inteiro (sem reaproveitar nós), o ganho aqui é só não
         *  nascer vazia. */
        $coresAvatarUsuario = ['bg-pink-700', 'bg-emerald-700', 'bg-amber-700', 'bg-purple-700'];
        ?>
        <div id="painel-lista-usuarios" class="space-y-0.5">
            <?php if (empty($usuariosBootstrap)): ?>
            <p class="text-xs text-gray-600 px-3 py-2">Nenhum outro usuário cadastrado</p>
            <?php else: foreach ($usuariosBootstrap as $u):
                $nome = (string) ($u['nome'] ?? '');
                $cor = $coresAvatarUsuario[((int) $u['id']) % count($coresAvatarUsuario)];
                $online = !empty($u['online']);
            ?>
            <button type="button" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left"
                    data-usuario-id="<?= (int) $u['id'] ?>" data-usuario-nome="<?= htmlspecialchars($nome) ?>" title="Conversar com <?= htmlspecialchars($nome) ?>">
                <div class="relative shrink-0">
                    <div class="w-9 h-9 <?= $cor ?> rounded-xl flex items-center justify-center text-sm font-bold"><?= htmlspecialchars($nome !== '' ? mb_strtoupper(mb_substr($nome, 0, 1)) : '?') ?></div>
                    <span data-painel-dot-usuario="<?= (int) $u['id'] ?>" class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-gray-900 <?= $online ? 'bg-green-400' : 'bg-gray-500' ?>"></span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($nome) ?></p>
                    <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars((string) ($u['setor'] ?? $u['papel'] ?? '')) ?></p>
                </div>
                <span data-painel-presenca-usuario="<?= (int) $u['id'] ?>" class="text-[11px] font-medium shrink-0 <?= $online ? 'text-green-400' : 'text-gray-500' ?>"><?= $online ? 'Online' : 'Offline' ?></span>
            </button>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Só aparece com o painel minimizado: só os avatares, com a bolinha de
         status — dá pra ver quem está online sem abrir o painel inteiro. -->
    <div class="hidden flex-1 flex-col items-center gap-2 pt-3 overflow-y-auto min-h-0" data-painel-recolhido>
        <div id="painel-avatares-recolhido" class="flex flex-col items-center gap-2"></div>
    </div>
</aside>

<div id="sidebar-overlay" class="hidden md:hidden fixed inset-0 bg-black/50 z-40" onclick="toggleSidebarMobile(false)"></div>

<!-- ═══ MODAL EMERGÊNCIA ═══ -->
<!-- max-h-[90vh] + flex-col: o corpo rola por dentro e o cabeçalho (com o X) e
     o rodapé (com o botão de abrir) ficam sempre visíveis, por mais anexos que
     a pessoa adicione. -->
<div id="modal-emergencia" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-red-500/30 rounded-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
        <div class="shrink-0 flex items-center justify-between p-6 border-b border-gray-800">
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
        <div class="flex-1 min-h-0 overflow-y-auto p-6 space-y-4">
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
            <!-- Gravidade e anexo dividem a linha: aproveita a largura do modal e
                 encurta o formulário, deixando a lista de mídias mais perto do
                 rodapé. -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                <div>
                    <label for="chamado-prioridade" class="block text-sm font-medium text-gray-300 mb-2">Gravidade do problema</label>
                    <select id="chamado-prioridade"
                            class="w-full bg-gray-800 border border-gray-700 text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 transition">
                        <option value="critica">Crítica — parou tudo, ninguém consegue trabalhar</option>
                        <option value="alta">Alta — impede o meu trabalho agora</option>
                        <option value="media" selected>Média — atrapalha, mas consigo continuar</option>
                        <option value="baixa">Baixa — pode ser resolvido com calma</option>
                    </select>
                    <p class="text-[11px] text-gray-500 mt-1.5">A TI confirma ou ajusta essa gravidade na triagem.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Anexar arquivos</label>
                    <label class="flex items-center gap-3 bg-gray-800 border border-dashed border-gray-600 rounded-xl px-4 py-2.5 cursor-pointer hover:border-gray-500 transition">
                        <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <span id="label-anexo-chamado" class="text-sm text-gray-400 truncate">Clique para selecionar arquivos</span>
                        <input id="input-anexo-chamado" type="file" multiple class="hidden" accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.heif,.pdf,.doc,.docx,.txt,.step,.stp,.exe">
                    </label>
                    <p class="text-[11px] text-gray-500 mt-1.5">Imagens, PDF e documentos — até 10 MB cada.</p>
                </div>
            </div>
            <!-- Lista em largura cheia e com rolagem própria: sem isso, cada mídia
                 acrescentava ~64px e empurrava o rodapé para fora da tela. -->
            <div id="chamado-anexos-lista" class="hidden space-y-2 max-h-56 overflow-y-auto pr-1"></div>
        </div>
        <div class="shrink-0 flex gap-3 px-6 py-4 border-t border-gray-800">
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

<!-- Mesma estrutura do modal de triagem do /dashboard-ti: 3xl, cabeçalho fixo,
     corpo rolável e os três campos numa linha só. -->
<div id="modal-classificar" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
        <div class="shrink-0 p-6 border-b border-gray-800">
            <h3 class="text-xl font-bold text-white">Classificar Chamado</h3>
            <p id="classificar-titulo-orig" class="text-sm text-gray-400 mt-1"></p>
        </div>

        <form id="form-classificar" class="flex-1 min-h-0 overflow-y-auto p-6 space-y-4">
            <input type="hidden" id="classificar-id">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-2">Prioridade</label>
                    <select id="sel-prioridade" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 text-white">
                        <option value="baixa">Baixa</option>
                        <option value="media" selected>Média</option>
                        <option value="alta">Alta</option>
                        <option value="critica">Crítica</option>
                    </select>
                </div>
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
window.APP_USER = <?= json_encode([
    'id' => (int) $userId,
    'nome' => (string) $userName,
    'papel' => (string) $userPapel,
    'socketProprio' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= asset('/assets/js/theme.js') ?>"></script>
<script src="<?= asset('/assets/js/anexos.js') ?>"></script>
<script src="<?= asset('/assets/js/som-notificacoes.js') ?>"></script>
<script src="<?= asset('/assets/js/notificacoes.js') ?>"></script>
<script src="<?= asset('/assets/js/chat.js') ?>"></script>
<script src="<?= asset('/assets/js/menu-lateral.js') ?>"></script>

</body>
</html>