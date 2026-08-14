<?php
/**
 * Menu lateral fixo das telas que não são o chat.
 *
 * O /chat monta a própria `<aside>` (com busca, listas de conversas e usuários),
 * mas reaproveita `menu-lateral-cabecalho.php` e `menu-lateral-recolhido.php`
 * daqui — é o que mantém os mesmos botões nas mesmas posições nas duas telas.
 * Esta versão acrescenta a lista de conversas ao vivo, cujo clique leva para
 * /chat?conversa=ID. Quem alimenta é o `public/assets/js/menu-lateral.js`.
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
       class="w-72 bg-gray-900 border-r border-gray-800 flex flex-col shrink-0 transition-all duration-200 h-screen">

    <?php include __DIR__ . '/menu-lateral-cabecalho.php'; ?>

    <!-- Conversas ao vivo -->
    <nav class="flex-1 overflow-y-auto px-2 pb-4 min-h-0" data-menu-conteudo>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-3 pb-2">Conversas</p>
        <div id="menu-lista-conversas" class="space-y-0.5">
            <p class="px-3 py-2 text-xs text-gray-600">Carregando…</p>
        </div>
    </nav>

    <!-- Só aparece com o menu minimizado -->
    <?php include __DIR__ . '/menu-lateral-recolhido.php'; ?>
</aside>
