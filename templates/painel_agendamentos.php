<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Agendamentos — Chat Interno</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('/assets/css/light-mode.css') ?>">
    <script src="<?= asset('/assets/js/utils.js') ?>"></script>
    <script src="<?= asset('/assets/js/config.js') ?>"></script>
    <script>
        window.APP_USER = <?= json_encode([
            'id' => (int) ($userId ?? 0),
            'nome' => (string) ($userName ?? ''),
            'papel' => (string) ($userPapel ?? 'usuario'),
            'socketProprio' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= asset('/assets/js/som-notificacoes.js') ?>"></script>
    <script src="<?= asset('/assets/js/notificacoes.js') ?>"></script>
    <style>
        html, body { height: 100%; overflow: hidden; }
        /* O body é linha (menu lateral + conteúdo) pelas classes do próprio
           <body>; a coluna interna é o wrapper com flex-col. Forçar
           flex-direction aqui empilhava o menu sobre a página. */
        .tab-btn-ativo   { background: #4f46e5; color: #fff; border-color: #4f46e5; }
        .tab-btn-inativo { background: transparent; color: #6b7280; border-color: transparent; }
        .tab-btn-inativo:hover { color: #e5e7eb; background: rgba(255,255,255,.05); }
        .kanban-col-body::-webkit-scrollbar { width: 4px; }
        .kanban-col-body::-webkit-scrollbar-track { background: transparent; }
        .kanban-col-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 4px; }
        #kanban-board::-webkit-scrollbar { height: 6px; }
        #kanban-board::-webkit-scrollbar-track { background: var(--ag-kanban-board); }
        #kanban-board::-webkit-scrollbar-thumb { background: var(--ag-linha-forte); border-radius: 4px; }
        #tab-servicos-content::-webkit-scrollbar { width: 4px; }
        #tab-servicos-content::-webkit-scrollbar-track { background: transparent; }
        #tab-servicos-content::-webkit-scrollbar-thumb { background: var(--ag-linha-forte); border-radius: 4px; }
    </style>
</head>
<?php
$agendamentosBootstrap = [
    'currentUserId'   => (int) ($userId ?? 0),
    'currentUserName' => (string) ($userName ?? ''),
    'userPapel'       => (string) ($userPapel ?? 'usuario'),
    'mode'            => 'admin',
];
?>
<body class="page-painel-agendamentos bg-gray-950 text-white h-screen flex overflow-hidden">
<?php $paginaAtual = 'painel-agendamentos'; include __DIR__ . '/partials/menu-lateral.php'; ?>

<div class="flex-1 min-w-0 flex flex-col overflow-hidden">

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<!-- Altura fixa h-16: mesma barra superior do /dashboard-ti e do /agendamentos. -->
<header class="h-16 flex-shrink-0 bg-gray-900 border-b border-gray-800 px-4 md:px-6 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
        <div class="min-w-0">
            <p class="text-[10px] uppercase tracking-[.18em] text-gray-500 font-bold leading-none mb-0.5">Administração</p>
            <h1 class="text-base md:text-lg font-black text-white leading-tight truncate">Painel de Agendamentos</h1>
        </div>
    </div>
    <!-- Ordem padrão de todas as telas: ações da página, tema, notificações e o
         "Olá, fulano" sempre encostado na direita. -->
    <div class="flex items-center gap-2 flex-shrink-0">
        <a href="/agendamentos" class="hidden sm:flex items-center gap-1.5 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 hover:text-white text-xs font-bold rounded-xl px-3 py-2 transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Visão do usuário
        </a>
        <button onclick="abrirModalSolicitacao()" class="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-500 border border-indigo-500 text-white text-xs font-bold rounded-xl px-3 py-2 transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span class="hidden sm:inline">Solicitar</span>
        </button>
        <button data-theme-toggle class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Alternar tema">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
        </button>
        <a href="/notificacoes" class="relative w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Central de notificações">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            <span data-notification-badge class="<?= (($notificationCount ?? 0) > 0) ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"><?= (int) ($notificationCount ?? 0) ?></span>
        </a>
        <span class="hidden sm:block text-sm text-gray-500">Olá, <span class="text-white font-medium"><?= htmlspecialchars($userName) ?></span></span>
    </div>
</header>

<!-- ── Tabs ─────────────────────────────────────────────────────────────── -->
<nav class="flex-shrink-0 bg-gray-900 border-b border-gray-800 px-4 md:px-6 flex items-center gap-1 h-11 overflow-x-auto whitespace-nowrap">
    <div class="flex items-center gap-1 min-w-0">
    <button data-tab="kanban"
        class="tab-btn-ativo h-8 px-4 rounded-lg text-xs font-semibold border transition flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/></svg>
        Agendamentos
    </button>
    <button data-tab="calendario"
        class="tab-btn-inativo h-8 px-4 rounded-lg text-xs font-semibold border transition flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        Calendário
    </button>
    <button data-tab="servicos"
        class="tab-btn-inativo h-8 px-4 rounded-lg text-xs font-semibold border transition flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Serviços
    </button>
    </div>
</nav>

<!-- ── Conteúdo das tabs ─────────────────────────────────────────────────── -->
<main class="flex-1 relative overflow-hidden">

    <!-- Tab: Kanban (padrão admin) -->
    <div data-tab-content="kanban" style="position:absolute;inset:0;flex-direction:column;min-height:0;overflow:hidden;">
        <!-- Barra de busca por quem solicitou: nome ou cargo (setor do usuário,
             ex. Engenharia/Financeiro). Filtra os cards de todas as colunas e
             as contagens do cabeçalho. Usa as classes da nav de abas logo acima
             (bg-gray-900 + border-gray-800) para ler como uma faixa só; o board
             abaixo é bem mais escuro, então um campo em --ag-superficie
             desapareceria. -->
        <div class="flex-shrink-0 bg-gray-900 border-b border-gray-800 px-4 md:px-6 py-2.5 flex items-center gap-3">
            <div class="relative">
                <svg class="w-3.5 h-3.5 text-gray-500 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input id="filtro-kanban-solicitante" type="search" autocomplete="off"
                       oninput="renderizarKanban()" onsearch="renderizarKanban()"
                       placeholder="Buscar por nome ou cargo"
                       class="w-56 bg-gray-800 border border-gray-700 text-xs font-bold text-gray-300 placeholder:font-medium placeholder:text-gray-500 rounded-lg pl-8 pr-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
            </div>
            <span id="kanban-busca-resumo" class="hidden text-[11px] font-bold text-gray-500"></span>
            <button type="button" id="kanban-busca-limpar" onclick="limparBuscaKanban()"
                    class="hidden items-center gap-1.5 text-[10px] font-bold text-gray-500 hover:text-indigo-400 uppercase tracking-wide transition py-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Limpar busca
            </button>
        </div>
        <div id="kanban-board"
             style="flex:1;overflow-x:auto;overflow-y:hidden;display:flex;align-items:stretch;gap:10px;padding:14px 16px;background:var(--ag-kanban-board);min-height:0;">
        </div>
    </div>

    <!-- Tab: Calendário -->
    <div data-tab-content="calendario" style="position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;">
        <div class="p-4 md:p-6 space-y-4">

            <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-800 flex-wrap gap-y-3">
                    <div>
                        <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Calendário</p>
                        <h2 id="agenda-mes-rotulo" class="text-lg font-black text-white">Carregando…</h2>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
<div class="flex items-center bg-gray-800 border border-gray-700 rounded-xl p-2 gap-1">
    <button id="btn-view-month" class="px-4 h-10 rounded-lg text-sm font-semibold transition flex-1">Mês</button>
    <button id="btn-view-week"  class="px-4 h-10 rounded-lg text-sm font-semibold transition flex-1">Semana</button>
    <button id="btn-view-day"   class="px-4 h-10 rounded-lg text-sm font-semibold transition flex-1">Dia</button>
</div>
                        <button id="btn-mes-anterior" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 transition text-lg leading-none">‹</button>
                        <button id="btn-mes-hoje" class="px-4 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-sm font-semibold text-gray-300 transition">Hoje</button>
                        <button id="btn-mes-proximo" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 transition text-lg leading-none">›</button>
                    </div>
                </div>
                <div id="calendario-agendamentos" class="p-4 overflow-x-auto"></div>
            </div>

            <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-800">
                    <div>
                        <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Dia selecionado</p>
                        <h3 id="dia-selecionado-rotulo" class="text-base font-bold text-white">Nenhum dia selecionado</h3>
                    </div>
                    <button id="btn-abrir-solicitacao-dia" class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-semibold text-white transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Solicitar neste dia
                    </button>
                </div>
                <div id="lista-agendamentos-dia" class="p-4 space-y-3 text-sm text-gray-500">
                    Selecione um dia no calendário para ver os serviços agendados.
                </div>
            </div>

        </div>
    </div>

    <!-- Tab: Serviços -->
    <div data-tab-content="servicos" style="position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;" id="tab-servicos-content">
        <div class="max-w-4xl mx-auto p-4 md:p-6 space-y-6">

            <!-- Lista de serviços -->
            <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-800">
                    <div>
                        <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Catálogo</p>
                        <h2 class="text-base font-black text-white">
                            Serviços cadastrados
                            <span id="count-servicos" class="ml-2 text-xs font-bold text-gray-500 bg-gray-800 px-2 py-0.5 rounded-full">0</span>
                        </h2>
                    </div>
                    <button onclick="limparFormularioServico();document.getElementById('servico-nome').focus();" class="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-xl px-3 py-2 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Novo serviço
                    </button>
                </div>
                <div id="lista-servicos" class="p-4 space-y-3"></div>
            </div>

            <!-- Formulário -->
            <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-800">
                    <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Editor</p>
                    <h2 class="text-base font-black text-white">Criar / editar serviço</h2>
                </div>
                <form id="form-servico" class="p-5 space-y-4">
                    <input type="hidden" id="servico-id">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Nome</label>
                            <input id="servico-nome" type="text" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 transition" placeholder="Ex: Suporte presencial">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Cor (hex)</label>
                            <div class="flex gap-2">
                                <input id="servico-cor" type="text" value="#4f46e5" class="flex-1 bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 transition" placeholder="#4f46e5">
                                <input type="color" value="#4f46e5" oninput="document.getElementById('servico-cor').value=this.value" class="w-12 h-12 rounded-xl border border-gray-700 bg-gray-800 cursor-pointer p-1 flex-shrink-0">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Descrição</label>
                        <textarea id="servico-descricao" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-indigo-500 transition" placeholder="Descreva brevemente o serviço"></textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer select-none">
                        <input id="servico-ativo" type="checkbox" checked class="accent-indigo-500 w-4 h-4">
                        Serviço ativo (visível para usuários)
                    </label>
                    <div class="flex gap-3 pt-1">
                        <button type="button" onclick="limparFormularioServico()" class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-3 text-sm font-semibold transition">Limpar</button>
                        <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3 text-sm font-semibold transition">Salvar serviço</button>
                    </div>
                </form>
            </div>

        </div>
    </div>

</main>

<!-- ── Modal: Nova solicitação ───────────────────────────────────────────── -->
<div id="modal-solicitacao" class="hidden fixed inset-0 bg-black/75 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-xl shadow-2xl">
        <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-800">
            <div>
                <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Nova solicitação</p>
                <h3 class="text-lg font-black text-white">Solicitar serviço</h3>
            </div>
            <button data-close-modal="modal-solicitacao" class="text-gray-500 hover:text-white text-2xl leading-none w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 transition">×</button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Serviço</label>
                <select id="solicitacao-servico" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 transition"></select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Início</label>
                    <input id="solicitacao-data-inicio" type="datetime-local" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Término <span class="text-gray-600 normal-case font-normal">(opcional)</span></label>
                    <input id="solicitacao-data-fim" type="datetime-local" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-indigo-500 transition">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Observações</label>
                <textarea id="solicitacao-observacoes" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-indigo-500 transition" placeholder="Descreva a necessidade, local, preferência de horário…"></textarea>
            </div>
        </div>
<div class="flex gap-4 px-6 pb-6">
    <button data-close-modal="modal-solicitacao" class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-3 text-base font-semibold transition">Cancelar</button>
    <button onclick="enviarSolicitacao()" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3 text-base font-semibold transition">Enviar solicitação</button>
</div>
    </div>
</div>

<!-- ── Modal: Agendamentos do dia ────────────────────────────────────────── -->
<div id="modal-dia" class="hidden fixed inset-0 bg-black/75 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-3xl shadow-2xl max-h-[88vh] flex flex-col">
        <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-800 flex-shrink-0">
            <div>
                <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Detalhe do dia</p>
                <h3 id="modal-dia-titulo" class="text-lg font-black text-white">Agendamentos</h3>
            </div>
            <button data-close-modal="modal-dia" class="text-gray-500 hover:text-white text-2xl leading-none w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 transition">×</button>
        </div>
        <div id="modal-dia-conteudo" class="p-5 space-y-3 overflow-y-auto flex-1"></div>
    </div>
</div>

<!-- ── Modal: Detalhe do agendamento ────────────────────────────────────── -->
<div id="modal-detalhe" class="hidden fixed inset-0 bg-black/75 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-4xl shadow-2xl max-h-[88vh] flex flex-col">
        <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-800 flex-shrink-0">
            <div class="min-w-0 flex-1 pr-4">
                <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Agendamento</p>
                <h3 id="detalhe-servico-nome" class="text-lg font-black text-white truncate">Detalhe</h3>
            </div>
            <button data-close-modal="modal-detalhe" class="text-gray-500 hover:text-white text-2xl leading-none w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 transition flex-shrink-0">×</button>
        </div>
        <div class="p-6 space-y-4 overflow-y-auto flex-1">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3 col-span-2 lg:col-span-1">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Solicitante</p>
                    <p id="detalhe-solicitante" class="text-white text-sm"></p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3 col-span-2 lg:col-span-1">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Status</p>
                    <p id="detalhe-status" class="text-white mt-0.5"></p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Início</p>
                    <p id="detalhe-inicio" class="text-white text-sm"></p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Término</p>
                    <p id="detalhe-fim" class="text-white text-sm"></p>
                </div>
            </div>
            <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-2">Observações</p>
                <p id="detalhe-observacoes" class="text-sm text-gray-300 whitespace-pre-wrap leading-relaxed"></p>
            </div>
            <!-- Sugestão de novo horário pendente: quem vê o bloco de resposta é
                 o solicitante; a equipe vê apenas o aviso de que está aguardando. -->
            <div id="bloco-reagendamento-pendente" class="hidden bg-amber-500/10 border border-amber-500/40 rounded-xl p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-400 font-bold">Sugestão de novo horário</p>
                <p id="reagendamento-periodo" class="text-sm text-white font-semibold"></p>
                <p id="reagendamento-detalhe" class="text-xs text-gray-400"></p>
                <p data-erro-formulario class="hidden text-[11px] font-semibold text-red-400"></p>
                <div id="reagendamento-acoes" class="hidden flex flex-wrap gap-2 pt-1">
                    <button id="btn-reagendamento-aceitar" class="bg-green-600/90 hover:bg-green-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Aceitar novo horário</button>
                    <button id="btn-reagendamento-recusar" class="bg-gray-700 hover:bg-gray-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Recusar e combinar no chat</button>
                </div>
            </div>
            <div id="bloco-reagendamento-form" class="hidden bg-gray-800/70 border border-amber-700/50 rounded-xl p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-400 font-bold">Sugerir outro horário</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Novo início</span>
                        <input id="reagendamento-inicio" type="datetime-local" class="w-full mt-1 bg-gray-800 border border-gray-700 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    </label>
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Novo término</span>
                        <input id="reagendamento-fim" type="datetime-local" class="w-full mt-1 bg-gray-800 border border-gray-700 rounded-xl px-3 py-2.5 text-white text-sm focus:outline-none focus:border-amber-500 transition">
                    </label>
                </div>
                <textarea id="reagendamento-motivo" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-amber-500 transition" placeholder="Motivo da sugestão (opcional) — vai na notificação do solicitante"></textarea>
                <p class="text-[11px] text-gray-500">O solicitante recebe a sugestão e precisa aceitar antes de o período ser trocado.</p>
                <p data-erro-formulario class="hidden text-[11px] font-semibold text-red-400"></p>
                <div class="flex flex-wrap justify-end gap-2 pt-1">
                    <button id="btn-reagendamento-fechar" class="bg-gray-700 hover:bg-gray-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Voltar</button>
                    <button id="btn-reagendamento-enviar" class="bg-amber-500/90 hover:bg-amber-500 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Enviar sugestão</button>
                </div>
            </div>
            <div id="bloco-cancelamento-form" class="hidden bg-gray-800/70 border border-red-700/50 rounded-xl p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-wide text-red-400 font-bold">Cancelar agendamento</p>
                <textarea id="cancelamento-motivo" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-red-500 transition" placeholder="Motivo do cancelamento (obrigatório) — este texto vai na notificação do solicitante"></textarea>
                <p class="text-[11px] text-gray-500">O motivo é obrigatório e fica registrado no histórico do agendamento.</p>
                <p data-erro-formulario class="hidden text-[11px] font-semibold text-red-400"></p>
                <div class="flex flex-wrap justify-end gap-2 pt-1">
                    <button id="btn-cancelamento-fechar" class="bg-gray-700 hover:bg-gray-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Voltar</button>
                    <button id="btn-cancelamento-confirmar" class="bg-red-600/90 hover:bg-red-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Confirmar cancelamento</button>
                </div>
            </div>
            <div id="bloco-recusa-form" class="hidden bg-gray-800/70 border border-amber-700/50 rounded-xl p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-400 font-bold">Recusar solicitação</p>
                <textarea id="recusa-motivo" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-amber-500 transition" placeholder="Motivo da recusa (obrigatório) — este texto vai na notificação do solicitante"></textarea>
                <p class="text-[11px] text-gray-500">O motivo é obrigatório e fica registrado no histórico do agendamento.</p>
                <p data-erro-formulario class="hidden text-[11px] font-semibold text-red-400"></p>
                <div class="flex flex-wrap justify-end gap-2 pt-1">
                    <button id="btn-recusa-fechar" class="bg-gray-700 hover:bg-gray-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Voltar</button>
                    <button id="btn-recusa-confirmar" class="bg-amber-600/90 hover:bg-amber-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Confirmar recusa</button>
                </div>
            </div>
            <div id="bloco-registro" class="hidden bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-2">Registro do agendamento</p>
                <div id="detalhe-registro" class="space-y-2"></div>
            </div>
            <div id="bloco-fechamento-form" class="hidden bg-gray-800/70 border border-indigo-700/50 rounded-xl p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-wide text-indigo-400 font-bold">Fechar agendamento</p>
                <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                    <input id="fechamento-realizado" type="checkbox" checked class="accent-indigo-500 w-4 h-4">
                    Serviço foi realizado
                </label>
                <textarea id="fechamento-observacao" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-indigo-500 transition" placeholder="Parecer do encerramento (obrigatório) — este texto vai na notificação do solicitante" required></textarea>
                <p class="text-[11px] text-gray-500">O parecer é obrigatório e será enviado ao solicitante junto com o aviso de encerramento.</p>
                <p data-erro-formulario class="hidden text-[11px] font-semibold text-red-400"></p>
                <div class="flex flex-wrap justify-end gap-2 pt-1">
                    <button id="btn-fechamento-fechar" class="bg-gray-700 hover:bg-gray-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Voltar</button>
                    <button id="btn-fechamento-confirmar" class="bg-indigo-600/90 hover:bg-indigo-600 text-white rounded-lg px-3.5 py-2 text-xs font-bold transition">Confirmar encerramento</button>
                </div>
            </div>
        </div>
<div class="flex flex-wrap items-center justify-end gap-2 px-6 pb-6 pt-4 border-t border-gray-800 flex-shrink-0">
    <button id="btn-detalhe-cancelar" class="hidden bg-red-600/90 hover:bg-red-600 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">Cancelar agendamento</button>
    <button id="btn-detalhe-recusar"  class="hidden bg-amber-600/90 hover:bg-amber-600 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">Recusar</button>
    <button id="btn-detalhe-reagendar" class="hidden bg-amber-500/90 hover:bg-amber-500 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">Reagendar horário</button>
    <button id="btn-detalhe-aprovar"  class="hidden bg-green-600/90 hover:bg-green-600 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">Aprovar</button>
    <button id="btn-detalhe-encerrar" class="hidden bg-indigo-600/90 hover:bg-indigo-600 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">Encerrar</button>
</div>
    </div>
</div>

</div>

<script>
    window.AGENDAMENTO_BOOTSTRAP = <?= json_encode($agendamentosBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= asset('/assets/js/theme.js') ?>"></script>
<script src="<?= asset('/assets/js/agendamentos.js') ?>"></script>
<script src="<?= asset('/assets/js/menu-lateral.js') ?>"></script>
</body>
</html>
