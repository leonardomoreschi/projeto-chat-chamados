<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Chamados</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="<?= asset('/assets/css/light-mode.css') ?>">
    <script src="<?= asset('/assets/js/utils.js') ?>"></script>
    <script src="<?= asset('/assets/js/config.js') ?>"></script>
</head>
<body class="page-relatorio-chamados bg-gray-950 text-white min-h-screen">
    <header class="sticky top-0 z-30 bg-gray-900/70 backdrop-blur border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 md:px-6 py-4 flex items-center justify-between gap-4">
            <div class="flex flex-col items-start gap-1">
                <a href="/dashboard-ti" class="text-xs font-bold uppercase tracking-widest text-indigo-300 hover:text-indigo-200 transition">← Voltar aos chamados</a>
                <h1 class="text-xl md:text-2xl font-black">Relatório de Chamados</h1>
            </div>
            <div class="flex items-center gap-3">
                <button id="btn-exportar-csv" class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-xs font-bold text-white border border-emerald-500 transition">
                    Exportar CSV
                </button>
                <button id="btn-exportar-pdf" class="px-3 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-xs font-bold text-white border border-rose-500 transition">
                    Exportar PDF
                </button>
                <span class="text-xs text-gray-400"><?= htmlspecialchars($userName) ?></span>
                <button data-theme-toggle class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center transition" title="Alternar tema">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-10h-1M4.34 12h-1m15.02 6.36l-.7-.7M6.34 6.34l-.7-.7m12.02 0l-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 md:px-6 py-6 md:py-8 space-y-6">
        <section class="relative overflow-hidden rounded-3xl border border-gray-800 bg-gradient-to-br from-gray-900 via-gray-900 to-indigo-950 p-6 md:p-8 card-glow">
            <div class="absolute inset-0 opacity-40 hero-gradient-relatorio"></div>
            <div class="relative">
                <h2 class="text-lg md:text-2xl font-black">Visão Geral</h2>
                <p class="text-sm text-gray-300 mt-2 max-w-3xl">Métricas consolidadas de chamados abertos e encerrados, desempenho por categoria/subcategoria, solicitantes e resolutores com tempo médio de solução.</p>
                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div class="bg-black/30 border border-gray-800 rounded-2xl p-4">
                        <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Total</p>
                        <p id="kpi-total" class="text-3xl font-black mt-2">0</p>
                    </div>
                    <div class="bg-black/30 border border-gray-800 rounded-2xl p-4">
                        <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Abertos</p>
                        <p id="kpi-abertos" class="text-3xl font-black mt-2">0</p>
                    </div>
                    <div class="bg-black/30 border border-gray-800 rounded-2xl p-4">
                        <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Resolvidos</p>
                        <p id="kpi-resolvidos" class="text-3xl font-black mt-2">0</p>
                    </div>
                    <div class="bg-black/30 border border-gray-800 rounded-2xl p-4">
                        <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Cancelados</p>
                        <p id="kpi-cancelados" class="text-3xl font-black mt-2">0</p>
                    </div>
                    <div class="bg-black/30 border border-gray-800 rounded-2xl p-4">
                        <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Tempo Médio</p>
                        <p id="kpi-tempo" class="text-3xl font-black mt-2">0h</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="xl:col-span-2 bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-500">Abertos vs Resolvidos (30 dias)</h3>
                </div>
                <div class="h-72">
                    <canvas id="chart-serie"></canvas>
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-500">Distribuição por Categoria</h3>
                </div>
                <div class="h-72">
                    <canvas id="chart-categorias"></canvas>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-5">
                <h3 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Categorias</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left py-2">Categoria</th>
                                <th class="text-right py-2">Total</th>
                                <th class="text-right py-2">Abertos</th>
                                <th class="text-right py-2">Resolvidos</th>
                                <th class="text-right py-2">Médio</th>
                            </tr>
                        </thead>
                        <tbody id="tabela-categorias" class="divide-y divide-gray-800"></tbody>
                    </table>
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-5">
                <h3 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Subcategorias</h3>
                <div class="max-h-[24rem] overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left py-2">Categoria</th>
                                <th class="text-left py-2">Subcategoria</th>
                                <th class="text-right py-2">Total</th>
                                <th class="text-right py-2">Médio</th>
                            </tr>
                        </thead>
                        <tbody id="tabela-subcategorias" class="divide-y divide-gray-800"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-5">
                <h3 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Solicitantes</h3>
                <div class="max-h-[24rem] overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left py-2">Usuário</th>
                                <th class="text-right py-2">Total</th>
                                <th class="text-right py-2">Abertos</th>
                                <th class="text-right py-2">Resolvidos</th>
                            </tr>
                        </thead>
                        <tbody id="tabela-solicitantes" class="divide-y divide-gray-800"></tbody>
                    </table>
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-5">
                <h3 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Finalizadores</h3>
                <div class="max-h-[24rem] overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left py-2">Usuário</th>
                                <th class="text-right py-2">Resolvidos</th>
                                <th class="text-right py-2">Tempo Médio</th>
                            </tr>
                        </thead>
                        <tbody id="tabela-finalizadores" class="divide-y divide-gray-800"></tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <script src="<?= asset('/assets/js/theme.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
    <script src="<?= asset('/assets/js/relatorio-chamados.js') ?>"></script>
</body>
</html>
