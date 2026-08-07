<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/light-mode.css">
    <script type="module" src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@4.6.4/dist/index.min.js"></script>
    <script src="/assets/js/utils.js"></script>
    <script src="/assets/js/config.js"></script>
    <script src="/assets/js/notificacoes.js"></script>
    <script>
    window.CHAT_BOOTSTRAP = <?= json_encode([
        'currentUserId' => (int) $userId,
        'currentUserName' => (string) $userName,
        'userPapel' => (string) $userPapel,
        'isAdmin' => $userPapel === 'admin',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script>
        document.documentElement.classList.add('chat-loading');
    </script>
    <style>
        html.chat-loading body { visibility: hidden; }
        #messages { scroll-behavior: smooth; }
        .msg-enter { animation: fadeUp .2s ease; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
    <!-- Sidebar mobile responsive styles -->
    <style>
        @media (max-width: 767px) {
            #chat-sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 50;
                transform: translateX(-100%);
                transition: transform .2s ease;
            }

            body.sidebar-open #chat-sidebar {
                transform: translateX(0);
            }
        }
    </style>
</head>
<?php
$notificacoes = $notificacoes ?? [];
$naoLidas = (int) ($notificationCount ?? 0);
?>
<body class="page-notificacoes bg-gray-950 text-white h-screen flex overflow-hidden">
  <!-- Sidebar: Chat menu -->
  <aside id="chat-sidebar" class="w-72 bg-gray-900 border-r border-gray-800 flex flex-col shrink-0">
    <!-- �� ═�═�═ SIDEBAR �� ═�═�═ -->
    <div class="md:hidden p-3 border-b border-gray-800 flex justify-end">
        <button onclick="toggleSidebarMobile(false)" class="w-8 h-8 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 flex items-center justify-center" title="Fechar menu">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="p-4 border-b border-gray-800 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center text-sm font-bold">
                <?= strtoupper(substr($userName, 0, 1)) ?>
            </div>
            <div>
                <p class="text-sm font-semibold text-white"><?= htmlspecialchars($userName) ?></p>
                <p class="text-xs text-green-400 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 bg-green-400 rounded-full inline-block"></span>
                    Online
                </p>
            </div>
        </div>
        <div class="flex items-center gap-1">
            <?php if ($userPapel === 'admin'): ?>
            <a href="/admin" title="Painel Admin"
               class="text-gray-500 hover:text-indigo-400 transition p-1.5 rounded-lg hover:bg-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            <?php endif; ?>
            <a href="/notificacoes" title="Central de notificações"
               class="relative text-gray-500 hover:text-indigo-400 transition p-1.5 rounded-lg hover:bg-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <span data-notification-badge class="<?= (($notificationCount ?? 0) > 0) ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-indigo-500 border border-gray-900 text-[10px] font-black text-white text-center leading-3"><?= (int) ($notificationCount ?? 0) ?></span>
            </a>
            <a href="/logout" title="Sair"
               class="text-gray-500 hover:text-red-400 transition p-1.5 rounded-lg hover:bg-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 013 3H6a3 3 0 013-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </a>
        </div>
    </div>

    <div class="p-3">
        <button onclick="abrirEmergencia()"
                class="w-full bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            Chamar TI — Emergência
        </button>
    </div>

    <div class="p-3 pt-0">
        <a href="/meus-chamados"
           class="w-full bg-gray-800 hover:bg-indigo-600 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors border border-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 002 2v6a2 2 0 002 2h2a2 2 0 002 2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2" />
            </svg>
            Meus Chamados
        </a>
    </div>

    <div class="p-3 pt-0">
        <a href="/agendamentos"
           class="w-full bg-gray-800 hover:bg-indigo-600 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors border border-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z" />
            </svg>
            Agendamentos
        </a>
    </div>

    <?php if (in_array($userPapel, ['ti', 'admin'])): ?>
    <div class="p-3 pt-0">
        <button id="btn-painel-chamados" onclick="window.location.href='/dashboard-ti'"
                class="relative w-full bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 002-2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2" />
            </svg>
            Painel de Chamados
            <span id="badge-novos-chamados" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border border-gray-900"></span>
        </button>
    </div>
    <?php endif; ?>

    <div class="p-3 flex items-center gap-2">
        <div class="relative flex-1">
            <svg class="w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
                  <input id="search-input" type="text" placeholder="Buscar..."
                    class="w-full bg-gray-800 border border-gray-700 rounded-xl pl-9 pr-4 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <button onclick="abrirModalNovaConversa()" title="Nova conversa"
                class="w-9 h-9 bg-gray-800 border border-gray-700 hover:border-indigo-500 hover:text-indigo-400 text-gray-400 rounded-xl flex items-center justify-center transition shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-2 pb-4 space-y-0.5">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-3 pb-2">Conversas</p>
        <div id="lista-conversas" class="space-y-0.5"></div>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 pt-4 pb-2">Usuários</p>
        <div id="lista-usuarios" class="space-y-0.5"></div>
    </nav>
</aside>

  <!-- Main content: Notifications -->
  <main class="flex-1 flex flex-col min-w-0 w-full">
    <div class="max-w-6xl mx-auto px-4 py-6 md:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-3xl border border-gray-800 bg-gradient-to-br from-gray-900 via-gray-900 to-indigo-950 shadow-2xl">
            <div class="absolute inset-0 opacity-30 pointer-events-none" style="background: radial-gradient(circle at top right, rgba(99,102,241,.22), transparent 35%), radial-gradient(circle at bottom left, rgba(16,185,129,.12), transparent 30%);"></div>
            <div class="relative p-6 md:p-8 flex flex-col gap-6">
                    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <a href="/chat" class="text-xs font-bold uppercase tracking-widest text-indigo-300 hover:text-indigo-200 transition">← Voltar ao chat</a>
                            </div>
                            <h1 class="text-2xl md:text-4xl font-black text-white">Notificações</h1>
                            <p class="text-sm text-gray-400 mt-2 max-w-2xl">Suas notificações recentes</p>
                        </div>
                    </div>
                </div>
            </div>

            <section class="mt-6">
                <?php if (empty($notificacoes)): ?>
                    <div class="rounded-3xl border border-gray-800 bg-gray-900 p-8 text-center text-sm text-gray-400">
                        Nenhuma notificação encontrada.
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($notificacoes as $notificacao): ?>
                            <?php
                                $ehLida = !empty($notificacao['lida_em']);
                                $tagCor = $notificacao['tipo'] === 'agendamento' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30';
                            ?>
                            <button
                                type="button"
                                onclick="abrirNotificacao(<?= (int) $notificacao['id'] ?>, '<?= htmlspecialchars((string) ($notificacao['url'] ?? '/notificacoes'), ENT_QUOTES) ?>')"
                                class="w-full text-left rounded-2xl border <?= $ehLida ? 'border-gray-800 bg-gray-900' : 'border-indigo-500/30 bg-gray-900/90' ?> p-5 shadow-lg transition hover:border-indigo-500/50">
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                                    <div class="flex gap-4 min-w-0">
                                        <div class="mt-0.5 h-10 w-10 shrink-0 rounded-2xl bg-indigo-600/20 border border-indigo-500/20 flex items-center justify-center text-indigo-300 font-black">
                                            <?= strtoupper(substr((string) $notificacao['tipo'], 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                                <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border <?= $tagCor ?>"><?= htmlspecialchars((string) $notificacao['tipo']) ?></span>
                                                <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border border-gray-700 text-gray-400"><?= htmlspecialchars((string) $notificacao['evento']) ?></span>
                                                <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border <?= $ehLida ? 'border-gray-700 text-gray-500' : 'border-amber-500/30 text-amber-300' ?>"><?= $ehLida ? 'Lida' : 'Nova' ?></span>
                                            </div>
                                            <h2 class="text-base md:text-lg font-bold text-white truncate"><?= htmlspecialchars((string) $notificacao['titulo']) ?></h2>
                                            <p class="mt-2 text-sm text-gray-300 leading-relaxed"><?= htmlspecialchars((string) $notificacao['mensagem']) ?></p>
                                        </div>
                                    </div>
                                    <div class="text-xs text-gray-500 shrink-0 md:text-right">
                                        <div><?= htmlspecialchars((string) $notificacao['criado_em']) ?></div>
                                        <div class="mt-1">#<?= (int) $notificacao['entidade_id'] ?> · <?= htmlspecialchars((string) $notificacao['entidade']) ?></div>
                                    </div>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
    </div>
  </main>
  <script src="/assets/js/chat.js"></script>
</body>
</html>