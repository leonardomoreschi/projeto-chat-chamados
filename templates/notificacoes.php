<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/light-mode.css">
    <script src="/assets/js/utils.js"></script>
    <script src="/assets/js/config.js"></script>
    <script src="/assets/js/notificacoes.js"></script>
</head>
<?php
$notificacoes = $notificacoes ?? [];
$naoLidas = (int) ($notificationCount ?? 0);
?>
<body class="page-notificacoes bg-gray-950 text-white min-h-screen">
<div class="max-w-6xl mx-auto px-4 py-6 md:px-6 lg:px-8">
    <div class="relative overflow-hidden rounded-3xl border border-gray-800 bg-gradient-to-br from-gray-900 via-gray-900 to-indigo-950 shadow-2xl">
        <div class="absolute inset-0 opacity-30 pointer-events-none" style="background: radial-gradient(circle at top right, rgba(99,102,241,.22), transparent 35%), radial-gradient(circle at bottom left, rgba(16,185,129,.12), transparent 30%);"></div>
        <div class="relative p-6 md:p-8 flex flex-col gap-6">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <a href="/chat" class="text-xs font-bold uppercase tracking-widest text-indigo-300 hover:text-indigo-200 transition">← Voltar ao chat</a>
                    </div>
                    <h1 class="text-2xl md:text-4xl font-black text-white">Central de Notificações</h1>
                    <p class="text-sm text-gray-400 mt-2 max-w-2xl">Chamados e agendamentos chegam aqui em tempo real, com histórico persistente e leitura controlada.</p>
                </div>
                <div class="flex gap-3 flex-wrap items-center">
                    <button onclick="NotificationCenterUI.marcarTodasComoLidas()" class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-sm font-bold text-gray-200 transition">
                        Marcar todas como lidas
                    </button>
                    <div class="px-4 py-2 rounded-xl bg-black/20 border border-gray-800">
                        <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Não lidas</p>
                        <p class="text-2xl font-black text-white"><span data-notification-badge><?= $naoLidas ?></span></p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-black/20 border border-gray-800 rounded-2xl p-4">
                    <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Total</p>
                    <p class="text-3xl font-black mt-2 text-white"><?= count($notificacoes) ?></p>
                </div>
                <div class="bg-black/20 border border-gray-800 rounded-2xl p-4">
                    <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Não lidas</p>
                    <p class="text-3xl font-black mt-2 text-white"><?= $naoLidas ?></p>
                </div>
                <div class="bg-black/20 border border-gray-800 rounded-2xl p-4">
                    <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Lidas</p>
                    <p class="text-3xl font-black mt-2 text-white"><?= max(0, count($notificacoes) - $naoLidas) ?></p>
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

<script>
    window.NOTIFICACOES_BOOTSTRAP = {
        currentUserId: <?= (int) ($userId ?? 0) ?>,
        unreadCount: <?= $naoLidas ?>,
        page: 'notificacoes'
    };

    function abrirNotificacao(id, url) {
        if (window.NotificationCenterUI) {
            window.NotificationCenterUI.marcarComoLida(id);
        }
        if (url) {
            window.location.href = url;
        }
    }
</script>
</body>
</html>