<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Chat Interno</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('/assets/css/light-mode.css') ?>">
    <script>
        // Identidade para o WebSocket que alimenta a coluna "Conexão".
        window.APP_USER = <?= json_encode([
            'id' => (int) ($userId ?? 0),
            'nome' => (string) ($userName ?? ''),
            'papel' => (string) ($userPapel ?? 'admin'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <!-- utils.js: redireciona para /login quando a API responde 401. -->
    <script src="<?= asset('/assets/js/utils.js') ?>"></script>
    <style>
    </style>
</head>
<body class="page-admin bg-gray-950 text-white min-h-screen">

<!-- Header -->
<header class="bg-gray-900 border-b border-gray-800 px-6 py-4 flex items-center justify-between">
    <div class="flex items-center gap-4">
        <a href="/chat" class="text-gray-400 hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <h1 class="text-lg font-bold">Painel Administrativo</h1>
    </div>
    <!-- Ordem padrão de todas as telas: ações da página, tema, notificações e o
         "Olá, fulano" sempre encostado na direita. -->
    <div class="flex items-center gap-3">
        <a href="/logout" title="Sair" class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-red-400 transition">
            Sair
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
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
        <span class="text-sm text-gray-400">Olá, <span class="text-white font-medium"><?= htmlspecialchars($userName) ?></span></span>
    </div>
</header>

<!-- Tabs -->
<div class="border-b border-gray-800 px-6">
    <div class="flex gap-0">
        <button onclick="trocarAba('usuarios')" id="tab-usuarios"
                class="tab-btn px-5 py-3 text-sm font-medium border-b-2 border-indigo-500 text-indigo-400 transition">
            Usuários
        </button>
        <button onclick="trocarAba('setores')" id="tab-setores"
                class="tab-btn px-5 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white transition">
            Setores
        </button>
    </div>
</div>

<main class="max-w-6xl mx-auto px-4 md:px-6 py-6 md:py-8">

    <!-- ABA USUÁRIOS -->
    <div id="aba-usuarios">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold">Usuários</h2>
            <button onclick="abrirModalUsuario()"
                    class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-xl px-4 py-2 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Novo Usuário
            </button>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
                <input id="filtro-usuarios-busca" type="text" placeholder="Buscar por nome, e-mail, setor ou papel"
                       class="xl:col-span-2 bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <select id="filtro-usuarios-papel"
                        class="bg-gray-800 border border-gray-700 text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">Todos os papéis</option>
                    <option value="admin">Admin</option>
                    <option value="ti">TI</option>
                    <option value="usuario">Usuário</option>
                </select>
                <select id="filtro-usuarios-setor"
                        class="bg-gray-800 border border-gray-700 text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">Todos os setores</option>
                </select>
                <div class="flex items-center gap-2">
                    <label for="filtro-usuarios-per-page" class="text-xs text-gray-400">Por página</label>
                    <select id="filtro-usuarios-per-page"
                            class="flex-1 bg-gray-800 border border-gray-700 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="7" selected>7</option>
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-800 text-xs text-gray-400 uppercase tracking-wider">
                        <th class="text-left px-6 py-3">Nome</th>
                        <th class="text-left px-6 py-3">E-mail</th>
                        <th class="text-left px-6 py-3">Setor</th>
                        <th class="text-left px-6 py-3">Papel</th>
                        <th class="text-left px-6 py-3">Status</th>
                        <th class="text-left px-6 py-3">
                            Conexão
                            <span id="presenca-indicador" class="ml-1 text-[10px] normal-case tracking-normal text-gray-600" title="Atualização em tempo real">•</span>
                        </th>
                        <th class="text-left px-6 py-3">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabela-usuarios" class="divide-y divide-gray-800">
                    <tr><td colspan="7" class="text-center py-8 text-gray-500 text-sm">Carregando...</td></tr>
                </tbody>
            </table>
            </div>
            <div class="border-t border-gray-800 px-4 md:px-6 py-3 flex items-center justify-between gap-3">
                <p id="usuarios-paginacao-info" class="text-xs text-gray-500">0 usuários</p>
                <div class="flex items-center gap-2">
                    <button id="usuarios-paginacao-prev" class="px-3 py-1.5 text-xs rounded-lg bg-gray-800 text-gray-300 border border-gray-700 hover:bg-gray-700 transition">Anterior</button>
                    <span id="usuarios-paginacao-page" class="text-xs text-gray-400">Página 1 de 1</span>
                    <button id="usuarios-paginacao-next" class="px-3 py-1.5 text-xs rounded-lg bg-gray-800 text-gray-300 border border-gray-700 hover:bg-gray-700 transition">Próxima</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ABA SETORES -->
    <div id="aba-setores" class="hidden">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold">Setores</h2>
            <button onclick="abrirModalSetor()"
                    class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-xl px-4 py-2 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Novo Setor
            </button>
        </div>

        <div id="grid-setores" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="text-center py-8 text-gray-500 text-sm">Carregando...</div>
        </div>
    </div>

</main>

<!-- MODAL USUÁRIO -->
<div id="modal-usuario" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <h3 id="modal-usuario-titulo" class="font-bold text-white">Novo Usuário</h3>
            <button onclick="fecharModalUsuario()" class="text-gray-500 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <input type="hidden" id="usuario-id">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Nome completo</label>
                <input type="text" id="usuario-nome" placeholder="Ex: Maria Silva"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">E-mail</label>
                <input type="email" id="usuario-email" placeholder="maria@empresa.com"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">
                    Senha <span id="senha-hint" class="text-gray-500 font-normal">(mínimo 6 caracteres)</span>
                </label>
                <input type="password" id="usuario-senha" placeholder="••••••••"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Papel</label>
                    <select id="usuario-papel"
                            class="w-full bg-gray-800 border border-gray-700 text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="usuario">Usuário</option>
                        <option value="ti">TI</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Setor</label>
                    <select id="usuario-setor"
                            class="w-full bg-gray-800 border border-gray-700 text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">Sem setor</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="flex gap-3 px-6 pb-6">
                <button onclick="fecharModalUsuario()"
                    class="btn-cancelar-modal flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-2.5 text-sm transition">
                Cancelar
            </button>
            <button id="btn-salvar-usuario" onclick="salvarUsuario()"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-2.5 text-sm font-bold transition">
                Salvar
            </button>
        </div>
    </div>
</div>

<!-- MODAL CONFIRMAÇÃO DO ADMIN -->
<!-- Pedido sempre que dados de um usuário forem alterados: a sessão sozinha não
     confirma que quem está no teclado é o admin. -->
<div id="modal-confirmar-admin" class="hidden fixed inset-0 bg-black/70 z-[60] flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-sm shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <h3 class="font-bold text-white">Confirmar identidade</h3>
            <button onclick="fecharConfirmacaoAdmin()" class="text-gray-500 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <p id="confirmar-admin-descricao" class="text-sm text-gray-400">
                Informe seu e-mail e senha de administrador para aplicar a alteração.
            </p>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">E-mail do administrador</label>
                <input type="email" id="confirmar-admin-email" autocomplete="username" placeholder="admin@empresa.com"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Senha</label>
                <input type="password" id="confirmar-admin-senha" autocomplete="current-password" placeholder="••••••••"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <p id="confirmar-admin-erro" class="hidden text-xs text-red-400"></p>
        </div>
        <div class="flex gap-3 px-6 pb-6">
            <button onclick="fecharConfirmacaoAdmin()"
                    class="btn-cancelar-modal flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-2.5 text-sm transition">
                Cancelar
            </button>
            <button id="btn-confirmar-admin"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-2.5 text-sm font-bold transition">
                Confirmar
            </button>
        </div>
    </div>
</div>

<!-- MODAL SETOR -->
<div id="modal-setor" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-sm shadow-2xl">
        <div class="flex items-center justify-between p-6 border-b border-gray-800">
            <h3 class="font-bold text-white">Novo Setor</h3>
            <button onclick="fecharModalSetor()" class="text-gray-500 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Nome do setor</label>
                <input type="text" id="setor-nome" placeholder="Ex: Financeiro"
                       class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>
        <div class="flex gap-3 px-6 pb-6">
                <button onclick="fecharModalSetor()"
                    class="btn-cancelar-modal flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl py-2.5 text-sm transition">
                Cancelar
            </button>
            <button onclick="salvarSetor()"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-2.5 text-sm font-bold transition">
                Criar Setor
            </button>
        </div>
    </div>
</div>

<script src="<?= asset('/assets/js/admin.js') ?>"></script>
<script src="<?= asset('/assets/js/theme.js') ?>"></script>
</body>
</html>
