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
 * leva para /chat?conversa=ID em vez de abrir na hora, e não há botão de
 * nova conversa (o modal só existe dentro do /chat).
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
         abaixo. Sem botão de nova conversa: o modal só existe no /chat. -->
    <div class="p-3 pt-0" data-menu-conteudo>
        <div class="relative">
            <svg class="w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input id="search-input" type="text" placeholder="Buscar..."
                   class="w-full bg-gray-800 border border-gray-700 rounded-xl pl-9 pr-4 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
    </div>

    <!-- Conversas e usuários ao vivo -->
    <nav class="flex-1 overflow-y-auto px-2 pb-4 min-h-0" data-menu-conteudo>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-3 pb-2">Conversas</p>
        <div id="menu-lista-conversas" class="space-y-0.5">
            <p class="px-3 py-2 text-xs text-gray-600">Carregando…</p>
        </div>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-4 pb-2">Usuários</p>
        <div id="menu-lista-usuarios" class="space-y-0.5">
            <p class="px-3 py-2 text-xs text-gray-600">Carregando…</p>
        </div>
    </nav>

    <!-- Só aparece com o menu minimizado -->
    <?php include __DIR__ . '/menu-lateral-recolhido.php'; ?>
</aside>
