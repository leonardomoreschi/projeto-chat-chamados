<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agendamentos - Chat Interno</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/light-mode.css">
    <script src="/assets/js/utils.js"></script>
    <script src="/assets/js/config.js"></script>
</head>
<body class="page-agendamentos bg-gray-950 text-white min-h-screen">
<?php
$agendamentosBootstrap = [
    'currentUserId' => (int) ($userId ?? 0),
    'currentUserName' => (string) ($userName ?? ''),
    'userPapel' => (string) ($userPapel ?? 'usuario'),
    'mode' => 'user',
];
?>
<header class="bg-gray-900 border-b border-gray-800 px-4 md:px-6 py-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex items-center gap-3">
        <a href="/chat" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center text-gray-300 transition" title="Voltar ao chat">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Agenda</p>
            <h1 class="text-xl md:text-2xl font-black text-white">Sistema de Agendamento de Serviços</h1>
        </div>
    </div>
    <div class="flex items-center gap-3 flex-wrap">
        <span class="text-sm text-gray-400">Olá, <span class="text-white font-medium"><?= htmlspecialchars($userName) ?></span></span>
        <button data-theme-toggle class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Alternar tema">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
        </button>
        <?php if (in_array($userPapel, ['admin', 'ti'], true)): ?>
        <a href="/painel-agendamentos" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-xl px-4 py-2 transition">Painel Admin/TI</a>
        <?php endif; ?>
        <button onclick="abrirModalSolicitacao()" class="bg-green-600 hover:bg-green-500 text-white text-sm font-semibold rounded-xl px-4 py-2 transition">Solicitar Serviço</button>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 md:px-6 py-6 grid grid-cols-1 xl:grid-cols-[minmax(0,1.5fr)_380px] gap-6">
    <section class="space-y-6">
        <div class="agenda-card bg-gray-900 border border-gray-800 rounded-3xl p-5 md:p-6">
            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Calendário</p>
                    <h2 id="agenda-mes-rotulo" class="text-xl font-black text-white">Carregando...</h2>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="flex items-center bg-gray-800 border border-gray-700 rounded-xl p-1 gap-0.5">
                        <button id="btn-view-month" class="px-3 h-8 rounded-lg text-xs font-semibold transition bg-indigo-600 text-white">Mês</button>
                        <button id="btn-view-week"  class="px-3 h-8 rounded-lg text-xs font-semibold transition bg-gray-800 text-gray-300 border border-gray-700">Semana</button>
                        <button id="btn-view-day"   class="px-3 h-8 rounded-lg text-xs font-semibold transition bg-gray-800 text-gray-300 border border-gray-700">Dia</button>
                    </div>
                    <button id="btn-mes-anterior" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 transition">‹</button>
                    <button id="btn-mes-hoje" class="px-4 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-sm text-gray-300 transition">Hoje</button>
                    <button id="btn-mes-proximo" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 transition">›</button>
                </div>
            </div>
            <div id="calendario-agendamentos" class="overflow-x-auto" style="position:relative;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:0.5rem;"></div>
        </div>

        <div class="agenda-card bg-gray-900 border border-gray-800 rounded-3xl p-5 md:p-6">
            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Dia selecionado</p>
                    <h3 id="dia-selecionado-rotulo" class="text-lg font-bold text-white">Nenhum dia selecionado</h3>
                </div>
                <button id="btn-abrir-solicitacao-dia" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-sm font-semibold text-white transition">Solicitar neste dia</button>
            </div>
            <div id="lista-agendamentos-dia" class="space-y-3 text-sm text-gray-400">
                Selecione um dia no calendário para ver os serviços agendados.
            </div>
        </div>
    </section>

    <aside class="space-y-6">
        <div class="agenda-card bg-gray-900 border border-gray-800 rounded-3xl p-5 md:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm uppercase tracking-[0.2em] text-gray-500 font-black">Meus serviços</h3>
                <span class="text-xs text-gray-400">por status</span>
            </div>
            <div class="grid grid-cols-2 gap-3 mb-5">
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3">
                    <p class="text-[10px] uppercase tracking-[0.2em] text-gray-500 font-bold">Solicitados</p>
                    <p id="count-status-solicitado" class="text-2xl font-black mt-1 text-white">0</p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3">
                    <p class="text-[10px] uppercase tracking-[0.2em] text-gray-500 font-bold">Agendados</p>
                    <p id="count-status-agendado" class="text-2xl font-black mt-1 text-white">0</p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3">
                    <p class="text-[10px] uppercase tracking-[0.2em] text-gray-500 font-bold">Em avaliação</p>
                    <p id="count-status-em_avaliacao" class="text-2xl font-black mt-1 text-white">0</p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3">
                    <p class="text-[10px] uppercase tracking-[0.2em] text-gray-500 font-bold">Cancelados</p>
                    <p id="count-status-cancelado" class="text-2xl font-black mt-1 text-white">0</p>
                </div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3">
                    <p class="text-[10px] uppercase tracking-[0.2em] text-gray-500 font-bold">Encerrados</p>
                    <p id="count-status-encerrado" class="text-2xl font-black mt-1 text-white">0</p>
                </div>
            </div>
            <div class="space-y-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Solicitados</p>
                    <div id="lista-status-solicitado" class="space-y-2"></div>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Agendados</p>
                    <div id="lista-status-agendado" class="space-y-2"></div>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Em avaliação</p>
                    <div id="lista-status-em_avaliacao" class="space-y-2"></div>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Cancelados</p>
                    <div id="lista-status-cancelado" class="space-y-2"></div>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Encerrados</p>
                    <div id="lista-status-encerrado" class="space-y-2"></div>
                </div>
            </div>
        </div>
    </aside>
</main>

<div id="modal-solicitacao" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="agenda-card bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-xl shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Solicitação</p>
                <h3 class="text-xl font-black text-white">Novo agendamento</h3>
            </div>
            <button onclick="fecharModal('modal-solicitacao')" class="text-gray-500 hover:text-white text-2xl leading-none">×</button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Serviço</label>
                <select id="solicitacao-servico" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white"></select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Data e horário</label>
                <input id="solicitacao-data-inicio" type="datetime-local" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Data e horário de término (opcional)</label>
                <input id="solicitacao-data-fim" type="datetime-local" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Observações</label>
                <textarea id="solicitacao-observacoes" rows="4" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white resize-none" placeholder="Descreva a necessidade, local, preferência de horário..."></textarea>
            </div>
        </div>
        <div class="flex gap-3 px-6 pb-6">
            <button onclick="fecharModal('modal-solicitacao')" class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-3 text-sm font-semibold transition">Cancelar</button>
            <button onclick="enviarSolicitacao()" class="flex-1 bg-green-600 hover:bg-green-500 text-white rounded-xl py-3 text-sm font-semibold transition">Enviar solicitação</button>
        </div>
    </div>
</div>

<div id="modal-dia" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="agenda-card bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-4xl shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Detalhe do dia</p>
                <h3 id="modal-dia-titulo" class="text-xl font-black text-white">Agendamentos</h3>
            </div>
            <button onclick="fecharModal('modal-dia')" class="text-gray-500 hover:text-white text-2xl leading-none">×</button>
        </div>
        <div id="modal-dia-conteudo" class="p-6 space-y-3"></div>
    </div>
</div>

<div id="modal-detalhe" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="agenda-card bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Agendamento</p>
                <h3 id="detalhe-servico-nome" class="text-xl font-black text-white">Detalhe</h3>
            </div>
            <button onclick="fecharModal('modal-detalhe')" class="text-gray-500 hover:text-white text-2xl leading-none">×</button>
        </div>
        <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-4"><p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Solicitante</p><p id="detalhe-solicitante" class="text-white mt-1"></p></div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-4"><p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Status</p><p id="detalhe-status" class="text-white mt-1"></p></div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-4"><p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Início</p><p id="detalhe-inicio" class="text-white mt-1"></p></div>
                <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-4"><p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold">Fim</p><p id="detalhe-fim" class="text-white mt-1"></p></div>
            </div>
            <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Observações</p>
                <p id="detalhe-observacoes" class="text-sm text-gray-300 whitespace-pre-wrap"></p>
            </div>
            <div id="bloco-fechamento-info" class="hidden bg-gray-800/70 border border-gray-700 rounded-2xl p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-gray-500 font-bold mb-2">Fechamento</p>
                <p class="text-sm text-gray-300">Serviço realizado: <span id="detalhe-realizado" class="text-white font-semibold"></span></p>
                <p id="detalhe-observacao-fechamento" class="text-sm text-gray-300 whitespace-pre-wrap mt-1"></p>
            </div>
            <div id="bloco-fechamento-form" class="hidden bg-gray-800/70 border border-indigo-700/60 rounded-2xl p-4 space-y-3">
                <p class="text-xs uppercase tracking-[0.2em] text-indigo-300 font-bold">Fechar agendamento</p>
                <label class="flex items-center gap-2 text-sm text-gray-300">
                    <input id="fechamento-realizado" type="checkbox" checked>
                    Serviço foi realizado
                </label>
                <textarea id="fechamento-observacao" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white resize-none" placeholder="Observações do fechamento (opcional)"></textarea>
            </div>
            <div class="flex gap-3 flex-wrap">
                <button id="btn-detalhe-cancelar" class="hidden bg-red-600 hover:bg-red-500 text-white rounded-xl px-4 py-3 text-sm font-semibold transition">Cancelar</button>
                <button id="btn-detalhe-aprovar" class="hidden bg-green-600 hover:bg-green-500 text-white rounded-xl px-4 py-3 text-sm font-semibold transition">Aprovar</button>
                <button id="btn-detalhe-recusar" class="hidden bg-amber-600 hover:bg-amber-500 text-white rounded-xl px-4 py-3 text-sm font-semibold transition">Recusar</button>
                <button id="btn-detalhe-encerrar" class="hidden bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl px-4 py-3 text-sm font-semibold transition">Encerrar</button>
            </div>
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