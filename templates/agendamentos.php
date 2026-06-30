<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agendamentos — Chat Interno</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/light-mode.css">
    <script src="/assets/js/utils.js"></script>
    <script src="/assets/js/config.js"></script>
    <style>
        html, body { height: 100%; overflow: hidden; }
        body { display: flex; flex-direction: column; }
        .tab-btn-ativo   { background: #4f46e5; color: #fff; border-color: #4f46e5; }
        .tab-btn-inativo { background: transparent; color: #6b7280; border-color: transparent; }
        .tab-btn-inativo:hover { color: #e5e7eb; background: rgba(255,255,255,.05); }
        .kanban-col-body::-webkit-scrollbar { width: 4px; }
        .kanban-col-body::-webkit-scrollbar-track { background: transparent; }
        .kanban-col-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
        #kanban-board::-webkit-scrollbar { height: 6px; }
        #kanban-board::-webkit-scrollbar-track { background: #030712; }
        #kanban-board::-webkit-scrollbar-thumb { background: #374151; border-radius: 4px; }
    </style>
</head>
<?php
$agendamentosBootstrap = [
    'currentUserId'   => (int) ($userId ?? 0),
    'currentUserName' => (string) ($userName ?? ''),
    'userPapel'       => (string) ($userPapel ?? 'usuario'),
    'mode'            => 'user',
];
?>
<body class="page-agendamentos bg-gray-950 text-white">

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<header class="flex-shrink-0 bg-gray-900 border-b border-gray-800 px-4 md:px-6 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
        <a href="/chat" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center text-gray-300 transition flex-shrink-0" title="Voltar ao chat">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div class="min-w-0">
            <p class="text-[10px] uppercase tracking-[.18em] text-gray-500 font-bold leading-none mb-0.5">Agenda</p>
            <h1 class="text-base md:text-lg font-black text-white leading-tight truncate">Agendamento de Serviços</h1>
        </div>
    </div>
    <div class="flex items-center gap-2 flex-shrink-0">
        <span class="hidden sm:block text-sm text-gray-500">Olá, <span class="text-white font-medium"><?= htmlspecialchars($userName) ?></span></span>
        <button data-theme-toggle class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Alternar tema">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
        </button>
        <?php if (in_array($userPapel, ['admin', 'ti'], true)): ?>
        <a href="/painel-agendamentos" class="hidden sm:flex items-center gap-1.5 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 hover:text-white text-sm font-semibold rounded-xl px-3 py-2 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/></svg>
            Painel Admin/TI
        </a>
        <?php endif; ?>
        <button onclick="abrirModalSolicitacao()" class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-base font-semibold rounded-xl px-4 py-3 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span class="hidden sm:inline">Solicitar</span>
        </button>
    </div>
</header>

<!-- ── Tabs ─────────────────────────────────────────────────────────────── -->
<nav class="flex-shrink-0 bg-gray-900 border-b border-gray-800 px-4 md:px-6 flex items-center gap-1 h-11 overflow-x-auto whitespace-nowrap">
    <div class="flex items-center gap-1 min-w-0">
    <button data-tab="calendario"
        class="tab-btn-ativo h-8 px-4 rounded-lg text-xs font-semibold border transition flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        Calendário
    </button>
    <button data-tab="meus-agendamentos"
        class="tab-btn-inativo h-8 px-4 rounded-lg text-xs font-semibold border transition flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h7"/></svg>
        Meus Agendamentos
    </button>
    </div>
</nav>

<!-- ── Conteúdo das tabs ─────────────────────────────────────────────────── -->
<main class="flex-1 relative overflow-hidden">

    <!-- Tab: Calendário (default for user) -->
    <div data-tab-content="calendario" style="position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;">
        <div class="p-4 md:p-6 space-y-4">

            <!-- Calendário -->
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

            <!-- Painel do dia selecionado -->
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

    <!-- Tab: Meus Agendamentos (kanban) -->
    <div data-tab-content="meus-agendamentos" style="position:absolute;inset:0;flex-direction:column;">
        <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-gray-800 bg-gray-900 flex-shrink-0">
            <div>
                <h2 class="text-sm font-black text-white">Meus Agendamentos</h2>
            </div>
            <button onclick="abrirModalSolicitacao()" class="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-xl px-3 py-2 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Solicitar Serviço
            </button>
        </div>
        <div id="kanban-board"
             style="flex:1;overflow-x:auto;overflow-y:hidden;display:flex;align-items:stretch;gap:10px;padding:14px 16px;background:#030712;min-height:0;">
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
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Data e horário de início</label>
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
        <div class="px-6 pb-5 flex-shrink-0">
            <button onclick="abrirModalSolicitacao(diaSelecionado);fecharModal('modal-dia');" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Solicitar serviço neste dia
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: Detalhe do agendamento ────────────────────────────────────── -->
<div id="modal-detalhe" class="hidden fixed inset-0 bg-black/75 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-xl shadow-2xl max-h-[88vh] flex flex-col">
        <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-800 flex-shrink-0">
            <div class="min-w-0 flex-1 pr-4">
                <p class="text-[10px] uppercase tracking-[.2em] text-gray-500 font-bold leading-none mb-1">Agendamento</p>
                <h3 id="detalhe-servico-nome" class="text-lg font-black text-white truncate">Detalhe</h3>
            </div>
            <button data-close-modal="modal-detalhe" class="text-gray-500 hover:text-white text-2xl leading-none w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 transition flex-shrink-0">×</button>
        </div>
        <div class="p-6 space-y-4 overflow-y-auto flex-1">
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3 col-span-2">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Solicitante</p>
                    <p id="detalhe-solicitante" class="text-white text-sm"></p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Status</p>
                    <p id="detalhe-status" class="text-white mt-0.5"></p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Início</p>
                    <p id="detalhe-inicio" class="text-white text-sm"></p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3 col-span-2">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-1">Término</p>
                    <p id="detalhe-fim" class="text-white text-sm"></p>
                </div>
            </div>
            <div class="bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-2">Observações</p>
                <p id="detalhe-observacoes" class="text-sm text-gray-300 whitespace-pre-wrap leading-relaxed"></p>
            </div>
            <div id="bloco-fechamento-info" class="hidden bg-gray-800/70 border border-gray-700/60 rounded-xl p-3">
                <p class="text-[10px] uppercase tracking-wide text-gray-500 font-bold mb-2">Fechamento</p>
                <p class="text-sm text-gray-300">Serviço realizado: <span id="detalhe-realizado" class="text-white font-semibold"></span></p>
                <p id="detalhe-observacao-fechamento" class="text-sm text-gray-300 whitespace-pre-wrap mt-1"></p>
            </div>
            <div id="bloco-fechamento-form" class="hidden bg-gray-800/70 border border-indigo-700/50 rounded-xl p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-wide text-indigo-400 font-bold">Fechar agendamento</p>
                <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                    <input id="fechamento-realizado" type="checkbox" checked class="accent-indigo-500 w-4 h-4">
                    Serviço foi realizado
                </label>
                <textarea id="fechamento-observacao" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm resize-none focus:outline-none focus:border-indigo-500 transition" placeholder="Observações do fechamento (opcional)"></textarea>
            </div>
        </div>
<div class="flex gap-3 px-6 pb-6 flex-shrink-0 flex-wrap">
    <button id="btn-detalhe-cancelar" class="hidden bg-red-600/90 hover:bg-red-600 text-white rounded-xl px-5 py-3 text-base font-semibold transition">Cancelar agendamento</button>
    <button id="btn-detalhe-aprovar"  class="hidden bg-green-600/90 hover:bg-green-600 text-white rounded-xl px-5 py-3 text-base font-semibold transition">Aprovar</button>
    <button id="btn-detalhe-recusar"  class="hidden bg-amber-600/90 hover:bg-amber-600 text-white rounded-xl px-5 py-3 text-base font-semibold transition">Recusar</button>
    <button id="btn-detalhe-encerrar" class="hidden bg-indigo-600/90 hover:bg-indigo-600 text-white rounded-xl px-5 py-3 text-base font-semibold transition">Encerrar</button>
</div>
    </div>
</div>

<script>
    window.AGENDAMENTO_BOOTSTRAP = <?= json_encode($agendamentosBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/theme.js"></script>
<script src="/assets/js/agendamentos.js"></script>
</body>
</html>
