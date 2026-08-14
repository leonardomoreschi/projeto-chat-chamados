<?php
/**
 * Faixa que substitui o menu quando ele está minimizado — só os atalhos que
 * cabem em 4rem. Fica escondida enquanto o menu está aberto; quem alterna é o
 * `public/assets/js/menu-lateral.js`.
 */
?>
<div class="hidden flex-1 flex-col items-center gap-3 pt-3" data-menu-recolhido>
    <a href="/chat" title="Chat" class="w-9 h-9 rounded-xl bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white flex items-center justify-center transition relative">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.2A7.6 7.6 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        <span id="menu-badge-conversas" class="hidden absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"></span>
    </a>
    <a href="/notificacoes" title="Notificações" class="w-9 h-9 rounded-xl bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white flex items-center justify-center transition relative">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <span data-notification-badge class="hidden absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"></span>
    </a>
</div>
