<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard TI - Gestão de Chamados</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('/assets/css/light-mode.css') ?>">
    <script src="<?= asset('/assets/js/utils.js') ?>"></script>
    <script src="<?= asset('/assets/js/config.js') ?>"></script>
    <script>
        window.APP_USER = <?= json_encode([
            'id' => (int) ($userId ?? 0),
            'nome' => (string) ($userName ?? ''),
            'papel' => (string) ($userPapel ?? 'usuario'),
            // O menu lateral abre o próprio socket e repassa as notificações.
            'socketProprio' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= asset('/assets/js/som-notificacoes.js') ?>"></script>
    <script src="<?= asset('/assets/js/notificacoes.js') ?>"></script>
</head>
<body class="page-dashboard-ti bg-gray-950 text-white h-screen flex overflow-hidden">
<?php $chamadosBootstrap = $chamadosBootstrap ?? []; ?>
<?php $paginaAtual = 'dashboard-ti'; include __DIR__ . '/partials/menu-lateral.php'; ?>

<div class="flex-1 min-w-0 flex flex-col overflow-hidden">

    <!-- Altura fixa h-16: mesma barra superior do /agendamentos e do /painel-agendamentos. -->
    <header class="h-16 shrink-0 bg-gray-900 border-b border-gray-800 px-4 md:px-6 flex items-center justify-between gap-3">
        <div class="flex items-center gap-4 min-w-0">
            <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/20 shrink-0">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2" /></svg>
            </div>
            <div class="min-w-0">
                <h1 class="text-lg font-bold leading-none truncate">Painel de Chamados</h1>
            </div>
        </div>

        <!-- Ordem padrão de todas as telas: ações da página, tema, notificações e
             o "Olá, fulano" sempre encostado na direita. O ml-auto garante o
             encosto mesmo quando o título é curto e o menu lateral está aberto;
             gap-2 é o mesmo espaçamento das barras de /agendamentos. -->
        <div class="flex items-center gap-2 ml-auto flex-shrink-0">
            <button onclick="abrirModalTaxonomias()" class="bg-gray-800 border border-gray-700 text-xs font-bold text-indigo-300 rounded-xl px-3 py-2 hover:bg-gray-700 transition">
                Gerenciar Categorias
            </button>
            <a href="/dashboard-ti/relatorio" class="bg-indigo-600 hover:bg-indigo-500 border border-indigo-500 text-xs font-bold text-white rounded-xl px-3 py-2 transition">
                Resultados
            </a>
            <button data-theme-toggle class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Alternar tema">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                </svg>
            </button>
            <a href="/notificacoes" class="relative w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Central de notificações">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span data-notification-badge class="<?= (($notificationCount ?? 0) > 0) ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"><?= (int) ($notificationCount ?? 0) ?></span>
            </a>
            <span class="hidden sm:block text-sm text-gray-500">Olá, <span class="text-white font-medium"><?= htmlspecialchars($userName) ?></span></span>
        </div>
    </header>

    <?php
        // Espelha CONFIG.prioridades de public/assets/js/config.js — o primeiro
        // paint vem daqui e é substituído por carregarDados() logo em seguida.
        // 'borda_esquerda' é usada no card de triagem, que mantém a borda cinza
        // nos outros lados e só colore a lateral esquerda.
        $coresPrioridade = [
            'critica' => ['label' => 'Crítica', 'badge' => 'bg-red-500', 'borda' => 'border-red-500', 'borda_esquerda' => 'border-l-red-500'],
            'alta'    => ['label' => 'Alta',    'badge' => 'bg-orange-500', 'borda' => 'border-orange-500', 'borda_esquerda' => 'border-l-orange-500'],
            'media'   => ['label' => 'Média',   'badge' => 'bg-yellow-500', 'borda' => 'border-yellow-500', 'borda_esquerda' => 'border-l-yellow-500'],
            'baixa'   => ['label' => 'Baixa',   'badge' => 'bg-blue-500', 'borda' => 'border-blue-500', 'borda_esquerda' => 'border-l-blue-500'],
        ];
    ?>
    <main class="flex-1 flex flex-col lg:flex-row overflow-auto p-3 md:p-6 gap-4 md:gap-6">

        <section class="w-full lg:w-96 flex flex-col shrink-0 min-h-[260px] lg:min-h-0">
            <div class="flex items-center justify-between mb-4 px-2">
                <h3 class="text-sm font-black text-gray-500 uppercase tracking-widest flex items-center gap-2">
                    <span class="w-2 h-2 bg-orange-500 rounded-full animate-pulse"></span>
                    Aguardando Triagem
                </h3>
                <span id="count-triagem" class="bg-orange-500/10 text-orange-500 text-xs font-bold px-2.5 py-0.5 rounded-full border border-orange-500/20">0</span>
            </div>
            
            <div id="container-triagem" class="flex-1 overflow-y-auto space-y-4 pr-2">
                <?php foreach (($triagemBootstrap ?? []) as $chamado): ?>
                    <?php $prio = $coresPrioridade[$chamado['prioridade'] ?? 'media'] ?? $coresPrioridade['media']; ?>
                    <div class="bg-gray-900 border border-gray-800 border-l-4 <?= $prio['borda_esquerda'] ?> p-5 rounded-2xl card-anim group">
                        <div class="flex justify-between items-center mb-3">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 bg-gray-800 rounded-lg flex items-center justify-center text-[10px] font-bold text-indigo-500 border border-gray-700"><?= htmlspecialchars(strtoupper(substr((string)($chamado['usuario_nome'] ?? 'U'), 0, 1))) ?></div>
                                <span class="text-[10px] text-gray-400 font-bold"><?= htmlspecialchars((string)($chamado['usuario_nome'] ?? 'Usuário')) ?></span>
                            </div>
                            <span class="text-[10px] text-gray-600 font-bold"><?= htmlspecialchars(date('d/m/Y', strtotime((string)($chamado['criado_em'] ?? 'now')))) ?></span>
                        </div>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="<?= $prio['badge'] ?> text-[9px] font-black text-black px-2 py-0.5 rounded uppercase"><?= $prio['label'] ?></span>
                            <span class="text-[9px] text-gray-600 font-bold uppercase tracking-wide">indicada pelo solicitante</span>
                        </div>
                        <h4 class="text-white font-bold text-sm mb-2 group-hover:text-indigo-400 transition-colors">#<?= (int)($chamado['id'] ?? 0) ?> - <?= htmlspecialchars((string)($chamado['titulo'] ?? '')) ?></h4>
                        <p class="text-gray-500 text-xs line-clamp-2 mb-4 leading-relaxed"><?= htmlspecialchars((string)($chamado['descricao_rich'] ?? '')) ?></p>
                        <p class="w-full py-2.5 bg-gray-800 text-gray-300 text-xs font-black rounded-xl text-center">AGUARDANDO TRIAGEM</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="flex-1 flex flex-col bg-gray-900/40 rounded-3xl border border-gray-800/50 p-4 md:p-6 min-h-[340px]">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4 mb-6">
                <!-- min-h espelha a altura do select (text-xs + py-2 + borda) para o
                     título ficar na mesma linha da fileira de filtros. -->
                <div class="flex items-center sm:min-h-[34px]">
                    <h3 class="text-sm font-black text-gray-500 uppercase tracking-widest">Chamados Documentados</h3>
                </div>
                
                <!-- Fileira de filtros sempre encostada à direita (justify-end
                     no wrap e items-end na coluna). Com o menu lateral aberto a
                     seção estreita e a fileira quebra em duas linhas — sem o
                     justify-end a segunda linha voltava para a esquerda e o
                     bloco ficava jogado no meio do espaço vazio. -->
                <div class="flex flex-col items-end gap-1.5 min-w-0">
                    <div class="flex flex-wrap justify-end gap-2">
                        <!-- Busca livre por quem abriu o chamado: nome ou cargo
                             (setor do usuário, ex. Engenharia/Financeiro). -->
                        <div class="relative w-56">
                            <svg class="w-3.5 h-3.5 text-gray-500 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                            </svg>
                            <input id="filtro-solicitante" type="search" autocomplete="off"
                                   oninput="renderizarTudo()" onsearch="renderizarTudo()"
                                   placeholder="Buscar por nome ou cargo"
                                   class="w-full bg-gray-800 border border-gray-700 text-xs font-bold text-gray-300 placeholder:font-medium placeholder:text-gray-500 rounded-lg pl-8 pr-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
                        </div>
                        <select id="filtro-setor" onchange="popularFiltroSubcategorias()" class="bg-gray-800 border border-gray-700 text-xs font-bold text-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">TODOS OS SETORES</option>
                            <option value="ERP">ERP</option>
                            <option value="Infraestrutura">INFRAESTRUTURA</option>
                            <option value="Engenharia">ENGENHARIA</option>
                            <option value="Redes">REDES</option>
                            <option value="Segurança">SEGURANÇA</option>
                            <option value="Hardware">HARDWARE</option>
                            <option value="Acessos">ACESSOS</option>
                        </select>
                        <select id="filtro-subcategoria" onchange="renderizarTudo()" class="bg-gray-800 border border-gray-700 text-xs font-bold text-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">TODAS AS SUBCATEGORIAS</option>
                        </select>
                        <select id="filtro-ordenacao" onchange="renderizarTudo()" class="bg-gray-800 border border-gray-700 text-xs font-bold text-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">MAIS RECENTES</option>
                            <option value="antigos">MAIS ANTIGOS</option>
                        </select>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" onclick="limparFiltrosDocumentados()" title="Limpar filtros" class="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 hover:text-indigo-400 uppercase tracking-wide transition py-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Limpar filtros
                        </button>
                    </div>
                </div>
            </div>

            <div id="container-documentados" class="flex-1 overflow-y-auto grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 content-start pr-2">
                <?php foreach ($chamadosBootstrap as $chamado): ?>
                    <?php if (($chamado['status'] ?? '') !== 'classificado') continue; ?>
                    <?php $prio = $coresPrioridade[$chamado['prioridade'] ?? 'media'] ?? $coresPrioridade['media']; ?>
                    <div class="bg-gray-900 border-l-4 <?= $prio['borda'] ?> p-5 rounded-r-2xl shadow-xl card-anim flex flex-col h-full relative group">
                        <div class="flex justify-between items-start mb-3">
                            <span class="<?= $prio['badge'] ?> text-[9px] font-black text-black px-2 py-0.5 rounded uppercase"><?= $prio['label'] ?></span>
                            <span class="text-[10px] text-indigo-400 font-bold uppercase"><?= htmlspecialchars((string)($chamado['categoria'] ?? '')) ?></span>
                        </div>
                        <h4 class="text-white font-bold text-sm mb-1">#<?= (int)($chamado['id'] ?? 0) ?> - <?= htmlspecialchars((string)($chamado['titulo'] ?? '')) ?></h4>
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <p class="text-[10px] text-gray-500 font-medium"><?= htmlspecialchars((string)($chamado['subcategoria'] ?? 'Sem subcategoria')) ?></p>
                            <span class="text-[10px] text-gray-500 font-bold"><?= htmlspecialchars(date('d/m/Y', strtotime((string)($chamado['criado_em'] ?? 'now')))) ?></span>
                        </div>
                        <div class="flex items-center gap-2 pt-3 border-t border-gray-800 mt-auto mb-4">
                            <div class="w-6 h-6 bg-gray-800 rounded-lg flex items-center justify-center text-[10px] font-bold text-indigo-500 border border-gray-700 shrink-0"><?= htmlspecialchars(strtoupper(substr((string)($chamado['usuario_nome'] ?? 'U'), 0, 1))) ?></div>
                            <span class="text-[10px] text-gray-400 truncate min-w-0"><?= htmlspecialchars((string)($chamado['usuario_nome'] ?? 'Usuário')) ?></span>
                            <?php if (!empty($chamado['usuario_setor'])): ?>
                            <span class="text-[9px] font-bold uppercase tracking-wide text-gray-500 bg-gray-800 border border-gray-700 rounded px-1.5 py-0.5 shrink-0"><?= htmlspecialchars((string) $chamado['usuario_setor']) ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="w-full bg-indigo-600 text-white text-[10px] font-bold py-2 rounded-lg text-center">DOCUMENTADO</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <aside id="painel-historico" class="w-full lg:w-80 flex flex-col shrink-0 bg-gray-900/40 rounded-3xl border border-gray-800/50 transition-all duration-300 min-h-[260px] lg:min-h-0">
            <div class="p-4 border-b border-gray-800/70 flex items-center gap-3">
                <div id="historico-header-info" class="flex-1 min-w-0 flex items-center justify-between">
                    <h3 class="text-sm font-black text-gray-500 uppercase tracking-widest">Histórico</h3>
                    <span id="count-finalizados" class="bg-green-500/10 text-green-500 text-xs font-bold px-2.5 py-0.5 rounded-full border border-green-500/20">0</span>
                </div>
                <button id="btn-toggle-historico" onclick="togglePainelHistorico()" class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Minimizar histórico">
                    <svg id="icone-historico" class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
            </div>

            <div id="filtros-historico" class="px-4 pt-3 pb-2 border-b border-gray-800/70 space-y-2">
                <select id="filtro-historico-categoria" onchange="popularFiltroHistoricoSubcategorias()" class="w-full bg-gray-800 border border-gray-700 text-[11px] font-bold text-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">CATEGORIA (TODAS)</option>
                </select>
                <select id="filtro-historico-subcategoria" onchange="renderizarTudo()" class="w-full bg-gray-800 border border-gray-700 text-[11px] font-bold text-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">SUBCATEGORIA (TODAS)</option>
                </select>
                <input id="filtro-historico-data" type="date" onchange="renderizarTudo()" class="w-full bg-gray-800 border border-gray-700 text-[11px] font-bold text-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
                <div class="flex justify-end">
                    <button type="button" onclick="limparFiltrosHistorico()" title="Limpar filtros do histórico" class="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 hover:text-indigo-400 uppercase tracking-wide transition py-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Limpar filtros
                    </button>
                </div>
            </div>

            <div id="conteudo-historico" class="flex-1 overflow-y-auto p-4 space-y-3">
                <?php foreach ($chamadosBootstrap as $chamado): ?>
                    <?php if (($chamado['status'] ?? '') !== 'resolvido') continue; ?>
                    <button class="w-full text-left bg-gray-900 border border-gray-800 hover:border-green-500/30 p-3 rounded-xl transition">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <span class="text-[10px] font-black uppercase text-green-500">Finalizado</span>
                            <span class="text-[10px] text-gray-500"><?= htmlspecialchars(date('d/m/Y', strtotime((string)($chamado['atualizado_em'] ?? $chamado['criado_em'] ?? 'now')))) ?></span>
                        </div>
                        <p class="text-xs font-bold text-white truncate">#<?= (int)($chamado['id'] ?? 0) ?> - <?= htmlspecialchars((string)($chamado['titulo'] ?? '')) ?></p>
                        <p class="text-[10px] text-gray-500 mt-1 truncate">Solicitante: <?= htmlspecialchars((string)($chamado['usuario_nome'] ?? 'Usuário')) ?></p>
                        <p class="text-[10px] text-gray-600 mt-1 truncate">Resolvido por: <?= htmlspecialchars((string)($chamado['resolvido_por_nome'] ?? 'Nao informado')) ?></p>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>
    </main>

    <div id="modal-classificar" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-3xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            
            <div class="p-6 bg-gray-800/50 border-b border-gray-800 shrink-0">
                <div class="flex items-center gap-3 mb-1">
                    <span id="classificar-id-badge" class="bg-indigo-500/20 text-indigo-400 px-2 py-0.5 rounded text-xs font-mono font-bold"></span>
                    <span class="text-xs text-gray-500 font-bold uppercase tracking-widest">Aguardando Triagem</span>
                </div>
                <h3 id="classificar-titulo" class="text-xl font-bold text-white"></h3>
            </div>
            
            <div class="p-6 overflow-y-auto">
                
                <div class="mb-6">
                    <label class="block text-[10px] font-black text-gray-500 uppercase mb-2 tracking-widest">Descrição do Problema</label>
                    <div id="classificar-descricao" class="text-sm text-gray-300 bg-black/30 p-4 rounded-xl border border-gray-800/50 max-h-56 overflow-y-auto"></div>
                </div>

                <div id="classificar-anexo-container" class="mb-6 hidden">
                    <label class="block text-[10px] font-black text-gray-500 uppercase mb-2 tracking-widest">Arquivos Anexados</label>
                    <div id="classificar-anexos-lista" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-72 overflow-y-auto pr-1"></div>
                </div>

                <!-- Os três campos da triagem numa linha só: com o modal em 3xl
                     cabem lado a lado e o formulário deixa de ser uma coluna
                     estreita e alta. -->
                <form id="form-classificar" class="space-y-5 border-t border-gray-800 pt-6">
                    <input type="hidden" id="classificar-id-input">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase mb-2 tracking-widest">Confirmar Prioridade</label>
                            <select id="sel-prioridade" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="baixa">Baixa</option>
                                <option value="media" selected>Média</option>
                                <option value="alta">Alta</option>
                                <option value="critica">Crítica</option>
                            </select>
                            <!-- Abaixo do select (e não ao lado do label) para os
                                 três campos da linha ficarem alinhados. -->
                            <span id="classificar-prioridade-solicitada" class="block text-[10px] font-bold text-gray-500 mt-1.5"></span>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase mb-2 tracking-widest">Categoria</label>
                            <select id="sel-categoria" onchange="atualizarSubcategorias()" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase mb-2 tracking-widest">Subcategoria</label>
                            <select id="sel-subcategoria" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Aguardando...</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="button" onclick="fecharModal('modal-classificar')" class="flex-1 px-4 py-3 bg-gray-800 text-gray-400 font-bold rounded-xl hover:bg-gray-700 transition">Cancelar</button>
                        <button type="submit" class="flex-1 px-4 py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-500 transition">Confirmar Triagem</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cabeçalho e rodapé fixos, corpo rolável: com muitos anexos os botões de
         ação continuam à vista. A coluna de anexos não some mais quando o
         chamado não tem arquivo — mostra "Nenhum anexo enviado". -->
    <div id="modal-detalhes" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-6xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">
            <div class="shrink-0 flex items-start justify-between gap-4 p-6 border-b border-gray-800">
                <div class="min-w-0">
                    <div class="flex items-center gap-3 mb-2">
                        <span id="detalhes-id-badge" class="bg-gray-800 text-gray-400 px-2 py-0.5 rounded text-xs font-mono font-bold"></span>
                        <span id="detalhes-prioridade" class="text-[10px] font-black px-2 py-0.5 rounded uppercase"></span>
                    </div>
                    <h3 id="detalhes-titulo" class="text-xl font-bold text-white"></h3>
                </div>
                <button onclick="fecharModal('modal-detalhes')" class="shrink-0 rounded-full p-1 text-gray-500 hover:text-white transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto p-6 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase mb-2 tracking-widest">Descrição</label>
                        <div id="detalhes-descricao" class="text-sm text-gray-300 bg-black/30 p-4 rounded-xl border border-gray-800/50 max-h-56 overflow-y-auto"></div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <p id="detalhes-meta-categoria" class="text-xs text-gray-400"></p>
                        <p id="detalhes-meta-subcategoria" class="text-xs text-gray-400"></p>
                        <p id="detalhes-meta-data-abertura" class="text-xs text-gray-400"></p>
                        <p id="detalhes-meta-data-fechamento" class="text-xs text-gray-400"></p>
                        <p id="detalhes-meta-solicitante" class="text-xs text-gray-400 sm:col-span-2"></p>
                    </div>

                    <p id="detalhes-resolvido-por" class="text-xs text-gray-500"></p>
                </div>

                <div id="detalhes-anexo-container">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest">Anexos</label>
                        <span id="detalhes-anexos-contador" class="text-[11px] text-gray-500"></span>
                    </div>
                    <div id="detalhes-anexos-lista" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[55vh] overflow-y-auto pr-1"></div>
                </div>
            </div>

            <div class="shrink-0 px-6 py-4 border-t border-gray-800 flex flex-wrap gap-3">
                <button id="detalhes-btn-comentarios" class="flex-1 min-w-[9rem] bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white text-xs font-bold py-2.5 rounded-lg transition">COMENTÁRIOS</button>
                <button id="detalhes-btn-editar" class="flex-1 min-w-[9rem] bg-gray-800 hover:bg-amber-600 text-gray-300 hover:text-white text-xs font-bold py-2.5 rounded-lg transition">EDITAR CLASSIFICAÇÃO</button>
                <button id="detalhes-btn-chamar" class="flex-1 min-w-[9rem] bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white text-xs font-bold py-2.5 rounded-lg transition">CHAMAR SETOR</button>
                <button id="detalhes-btn-finalizar" class="flex-1 min-w-[9rem] bg-gray-800 hover:bg-green-600 text-gray-300 hover:text-white text-xs font-bold py-2.5 rounded-lg transition">FINALIZAR</button>
            </div>
        </div>
    </div>

    <div id="modal-comentarios" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-4xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="p-5 border-b border-gray-800 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-white">Comentários do Chamado</h3>
                    <p id="comentarios-subtitulo" class="text-xs text-gray-500 mt-1"></p>
                    <p id="comentarios-helper" class="text-[11px] text-gray-400 mt-2"></p>
                </div>
                <button onclick="fecharModal('modal-comentarios')" class="text-gray-500 hover:text-white">✕</button>
            </div>

            <div id="comentarios-lista" class="flex-1 overflow-y-auto p-5 space-y-3 bg-black/20"></div>

            <form id="form-comentario" class="p-5 border-t border-gray-800 space-y-3">
                <input type="hidden" id="comentario-chamado-id">
                <textarea id="comentario-texto" rows="3" placeholder="Adicionar comentário técnico..." class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-sm text-gray-100 placeholder-gray-500 outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <label class="flex flex-1 items-center gap-3 bg-gray-800 border border-dashed border-gray-600 rounded-xl px-4 py-2.5 cursor-pointer hover:border-gray-500 transition">
                        <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <span id="label-comentario-anexos" class="text-sm text-gray-400">Clique para selecionar arquivos</span>
                        <input id="comentario-anexos" type="file" name="anexos[]" multiple class="hidden" accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.heif,.pdf,.doc,.docx,.txt,.step,.stp,.exe" />
                    </label>
                    <button type="submit" class="w-full sm:w-auto px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 rounded-xl text-sm font-bold text-white">Salvar comentário</button>
                </div>
                <div id="comentario-anexos-lista" class="hidden space-y-2 max-h-44 overflow-y-auto"></div>
            </form>
        </div>
    </div>

    <div id="modal-finalizar" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-xl shadow-2xl overflow-hidden">
            <div class="p-5 border-b border-gray-800 flex items-center justify-between">
                <h3 class="text-lg font-bold text-white">Finalizar Chamado</h3>
                <button onclick="fecharModal('modal-finalizar')" class="text-gray-500 hover:text-white">✕</button>
            </div>

            <form id="form-finalizar" class="p-5 space-y-4">
                <input type="hidden" id="finalizar-chamado-id">
                <p id="finalizar-chamado-titulo" class="text-sm text-gray-300"></p>
                <div class="flex gap-3">
                    <button type="button" onclick="fecharModal('modal-finalizar')" class="flex-1 px-4 py-2.5 bg-gray-800 hover:bg-gray-700 rounded-xl text-sm font-bold text-gray-300">Cancelar</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-green-600 hover:bg-green-500 rounded-xl text-sm font-bold text-white">Confirmar finalização</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-taxonomias" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-3xl w-full max-w-2xl shadow-2xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-white">Categorias e Subcategorias</h3>
                <button onclick="fecharModal('modal-taxonomias')" class="text-gray-500 hover:text-white">✕</button>
            </div>

            <form id="form-taxonomia" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                <input id="taxonomia-categoria" placeholder="Categoria" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm">
                <input id="taxonomia-subcategoria" placeholder="Subcategoria" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm font-bold">Adicionar</button>
            </form>

            <div id="lista-taxonomias" class="max-h-80 overflow-y-auto space-y-2"></div>
        </div>
    </div>
</div>

    <script src="<?= asset('/assets/js/theme.js') ?>"></script>
    <script src="<?= asset('/assets/js/anexos.js') ?>"></script>
    <script>
        window.DASHBOARD_TI_BOOTSTRAP = <?= json_encode($chamadosBootstrap ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= asset('/assets/js/dashboard-ti.js') ?>"></script>
    <script src="<?= asset('/assets/js/menu-lateral.js') ?>"></script>
</body>
</html>