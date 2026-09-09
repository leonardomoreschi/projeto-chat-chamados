<?php
/**
 * Menu lateral fixo das telas que não são o chat.
 *
 * O /chat monta a própria `<aside>` (com o modal de nova conversa), mas
 * reaproveita `menu-lateral-cabecalho.php` e `menu-lateral-recolhido.php`
 * daqui — é o que mantém os mesmos botões nas mesmas posições nas duas telas.
 * Esta versão acrescenta a busca e as listas de conversas e de usuários ao
 * vivo — mesmo campo #search-input e mesmo conteúdo/visual de
 * #lista-conversas/#lista-usuarios no chat.js, só que clicar numa conversa
 * leva para /chat?conversa=ID em vez de abrir na hora, e o "+" leva para
 * /chat?nova_conversa=1 em vez de abrir o modal na hora (ele só existe
 * dentro do /chat).
 * Quem alimenta é o `public/assets/js/menu-lateral.js`.
 *
 * Espera as variáveis já extraídas pelo TemplateRenderer: $userName, $userPapel,
 * $notificationCount. `$paginaAtual` (opcional) destaca o item da tela atual.
 *
 * Uso na página:
 *   <body class="... h-screen flex overflow-hidden">
 *   <?php $paginaAtual = 'meus-chamados'; include __DIR__ . '/partials/menu-lateral.php'; ?>
 *   <div class="flex-1 min-w-0 overflow-y-auto"> ...conteúdo... </div>
 *   <script src="<?= asset('/assets/js/menu-lateral.js') ?>"></script>
 */
?>
<aside id="menu-lateral" data-menu-lateral
       class="bg-gray-900 border-r border-gray-800 flex flex-col shrink-0 h-screen">

    <?php include __DIR__ . '/menu-lateral-cabecalho.php'; ?>

    <!-- Busca — mesmo campo #search-input do /chat, filtra as duas listas
         abaixo. O "+" leva para /chat?nova_conversa=1, que abre o modal por lá
         — o mesmo truque que o botão de emergência já usa (?emergencia=1),
         já que o modal de nova conversa só existe dentro do /chat. -->
    <div class="p-3 pt-0 flex items-center gap-2" data-menu-conteudo>
        <div class="relative flex-1">
            <svg class="w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input id="search-input" type="text" placeholder="Buscar..."
                   class="w-full bg-gray-800 border border-gray-700 rounded-xl pl-9 pr-4 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <a href="/chat?nova_conversa=1" title="Nova conversa"
           class="w-9 h-9 bg-gray-800 border border-gray-700 hover:border-indigo-500 hover:text-indigo-400 text-gray-400 rounded-xl flex items-center justify-center transition shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </a>
    </div>

    <!-- Conversas e usuários ao vivo. "Conversas" é recolhível — com muitos
         chats abertos, dá pra encolher a lista sem perder o acesso a
         "Usuários" logo abaixo. Estado (aberto ou recolhido) fica em
         localStorage, então acompanha o usuário para as outras telas — ver
         CHAVE_CONVERSAS_RECOLHIDAS em menu-lateral.js/chat.js. -->
    <nav class="flex-1 overflow-y-auto px-2 pb-4 min-h-0" data-menu-conteudo>
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
                 * Pré-renderizado a partir de $conversasBootstrap (ver as rotas de página
                 * em public/index.php), com a mesma marcação de renderizar() em
                 * menu-lateral.js. Sem isto o menu nasce com "Carregando…" e pisca ao
                 * trocar de tela assim que o fetch de carregarConversas() volta — mesmo
                 * que o conteúdo seja idêntico.
                 */
                ?>
                <div id="menu-lista-conversas" class="space-y-0.5">
                    <?php if (empty($conversasBootstrap)): ?>
                    <p class="px-3 py-2 text-xs text-gray-600">Nenhuma conversa ainda.</p>
                    <?php else: foreach ($conversasBootstrap as $c):
                        $ehGrupo = in_array($c['tipo'] ?? '', ['grupo', 'setor'], true);
                        $nome = (string) ($c['nome'] ?? 'Conversa');
                        $avatarTexto = $ehGrupo ? '#' : ($nome !== '' ? mb_strtoupper(mb_substr($nome, 0, 1)) : '?');
                        $avatarCor = $ehGrupo ? 'bg-indigo-700' : 'bg-emerald-700';
                        $naoLidas = (int) ($c['nao_lidas'] ?? 0);
                        $preview = (string) ($c['ultima_mensagem'] ?? 'Sem mensagens');
                        $busca = mb_strtolower($nome . ' ' . $preview);
                    ?>
                    <a href="/chat?conversa=<?= (int) $c['id'] ?>" data-busca="<?= htmlspecialchars($busca) ?>"
                       class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left">
                        <div class="w-9 h-9 <?= $avatarCor ?> rounded-xl flex items-center justify-center text-sm font-bold shrink-0"><?= htmlspecialchars($avatarTexto) ?></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-white truncate"><?= htmlspecialchars($nome) ?></p>
                            <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($preview) ?></p>
                        </div>
                        <?php if ($naoLidas > 0): ?>
                        <span class="bg-indigo-600 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 shrink-0"><?= $naoLidas ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-4 pb-2">Usuários</p>
        <?php
        /** Pré-renderizado a partir de $usuariosBootstrap — mesma marcação de
         *  renderizarUsuarios() em menu-lateral.js. */
        $coresAvatarUsuario = ['bg-pink-700', 'bg-emerald-700', 'bg-amber-700', 'bg-purple-700'];
        ?>
        <div id="menu-lista-usuarios" class="space-y-0.5">
            <?php if (empty($usuariosBootstrap)): ?>
            <p class="px-3 py-2 text-xs text-gray-600">Nenhum outro usuário cadastrado</p>
            <?php else: foreach ($usuariosBootstrap as $u):
                $nome = (string) ($u['nome'] ?? '');
                $cor = $coresAvatarUsuario[((int) $u['id']) % count($coresAvatarUsuario)];
                $busca = mb_strtolower($nome . ' ' . ($u['setor'] ?? '') . ' ' . ($u['papel'] ?? ''));
            ?>
            <div data-busca="<?= htmlspecialchars($busca) ?>" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-800 transition text-left">
                <div class="w-9 h-9 <?= $cor ?> rounded-xl flex items-center justify-center text-sm font-bold shrink-0"><?= htmlspecialchars($nome !== '' ? mb_strtoupper(mb_substr($nome, 0, 1)) : '?') ?></div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($nome) ?></p>
                    <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars((string) ($u['setor'] ?? $u['papel'] ?? '')) ?></p>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </nav>

    <!-- Só aparece com o menu minimizado -->
    <?php include __DIR__ . '/menu-lateral-recolhido.php'; ?>
</aside>
