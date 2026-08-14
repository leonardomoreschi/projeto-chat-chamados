<?php
/**
 * Topo do menu lateral — igual no /chat e nas demais telas.
 *
 * Ordem fixa (é o que mantém a navegação previsível ao trocar de página):
 *   1. usuário + botão de minimizar;
 *   2. linha de ícones: admin, notificações, tema, sair;
 *   3. navegação: Chat, Emergência, Meus Chamados, Agendamentos, Painel.
 *
 * Quem inclui abre a própria `<aside data-menu-lateral>` e fecha depois — o
 * /chat acrescenta busca e listas de conversas/usuários; as outras telas usam
 * `partials/menu-lateral.php`, que é só este cabeçalho + lista de conversas.
 *
 * Espera $userName, $userPapel, $notificationCount e $paginaAtual (opcional).
 */

$paginaAtual = $paginaAtual ?? '';
$rotulosPapel = ['admin' => 'Admin', 'ti' => 'TI', 'usuario' => 'Usuário'];
$ehGestor = in_array($userPapel ?? 'usuario', ['ti', 'admin'], true);
$ehChat = $paginaAtual === 'chat';

/** Classe do item de navegação; o da tela atual fica destacado. */
$classeItem = static function (string $pagina) use ($paginaAtual): string {
    $base = 'w-full text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center gap-2 transition-colors border';

    return $pagina === $paginaAtual
        ? $base . ' bg-indigo-600 border-indigo-500 text-white'
        : $base . ' bg-gray-800 border-gray-700 text-white hover:bg-indigo-600';
};

// No /chat o modal de emergência já está na página; fora dele, o link recarrega
// o chat com ?emergencia=1 e o chat.js abre o modal.
$aberturaEmergencia = $ehChat
    ? '<button type="button" onclick="abrirEmergencia()"'
    : '<a href="/chat?emergencia=1"';
$fechamentoEmergencia = $ehChat ? '</button>' : '</a>';
?>
<!-- Cabeçalho: usuário + atalhos -->
<div class="p-4 border-b border-gray-800 flex items-center justify-between gap-2">
    <div class="flex items-center gap-3 min-w-0" data-menu-conteudo>
        <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center text-sm font-bold shrink-0">
            <?= strtoupper(substr((string) $userName, 0, 1)) ?>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-white truncate"><?= htmlspecialchars((string) $userName) ?></p>
            <p class="text-xs text-gray-400"><?= htmlspecialchars($rotulosPapel[$userPapel] ?? 'Usuário') ?></p>
        </div>
    </div>
    <button type="button" data-menu-toggle title="Minimizar menu"
            class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center shrink-0 transition">
        <svg data-menu-icone class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
        </svg>
    </button>
</div>

<div class="px-4 py-3 border-b border-gray-800 flex items-center gap-1" data-menu-conteudo>
    <?php if (($userPapel ?? '') === 'admin'): ?>
    <a href="/admin" title="Painel Admin" class="text-gray-500 hover:text-indigo-400 transition p-1.5 rounded-lg hover:bg-gray-800">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
    </a>
    <?php endif; ?>
    <a href="/notificacoes" title="Central de notificações"
       class="relative transition p-1.5 rounded-lg hover:bg-gray-800 <?= $paginaAtual === 'notificacoes' ? 'text-indigo-400 bg-gray-800' : 'text-gray-500 hover:text-indigo-400' ?>">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <span data-notification-badge class="<?= (($notificationCount ?? 0) > 0) ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"><?= (int) ($notificationCount ?? 0) ?></span>
    </a>
    <button type="button" data-theme-toggle title="Alternar tema"
            class="text-gray-500 hover:text-indigo-400 transition p-1.5 rounded-lg hover:bg-gray-800">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/>
        </svg>
    </button>
    <a href="/logout" title="Sair" class="text-gray-500 hover:text-red-400 transition p-1.5 rounded-lg hover:bg-gray-800 ml-auto">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
    </a>
</div>

<!-- Navegação -->
<div class="p-3 space-y-2" data-menu-conteudo>
    <a href="/chat" class="<?= $classeItem('chat') ?>">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.2A7.6 7.6 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        Chat
    </a>
    <?= $aberturaEmergencia ?>
       class="w-full bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center gap-2 transition-colors">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        Chamar TI — Emergência
    <?= $fechamentoEmergencia ?>
    <a href="/meus-chamados" class="<?= $classeItem('meus-chamados') ?>">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2"/>
        </svg>
        Meus Chamados
    </a>
    <a href="/agendamentos" class="<?= $classeItem('agendamentos') ?>">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z"/>
        </svg>
        Agendamentos
    </a>
    <?php if ($ehGestor): ?>
    <a href="/dashboard-ti" class="<?= $classeItem('dashboard-ti') ?> relative">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2"/>
        </svg>
        Painel de Chamados
        <?php if ($ehChat): ?>
        <span id="badge-novos-chamados" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border border-gray-900"></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>
