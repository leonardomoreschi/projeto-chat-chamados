<?php $this->extend('layout.php') ?>

<?php $this->section('content') ?>
<div class="max-w-4xl mx-auto py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Notificações</h1>
        <a href="/chat" class="btn btn-secondary">Voltar ao Chat</a>
    </div>
    
    <?php if (empty($notificacoes)): ?>
        <div class="text-center py-12">
            <p class="text-gray-500">Nenhuma notificação encontrada.</p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($notificacoes as $notificacao): ?>
                <div class="p-4 bg-gray-800 rounded-lg border-l-4 <?= $notificacao['lida'] ? 'border-blue-500' : 'border-blue-400' ?>">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 bg-blue-500 rounded-full flex-shrink-0"></div>
                            <div>
                                <h3 class="font-semibold text-white"><?= htmlspecialchars($notificacao['mensagem']) ?></h3>
                                <p class="text-sm text-gray-400">
                                    <?= ucfirst($notificacao['tipo']) ?> #<?= $notificacao['referencia_id'] ?>
                                    • <?= $notificacao['status_novo'] ?>
                                </p>
                            </div>
                        </div>
                        <span class="text-xs <?= $notificacao['lida'] ? 'text-gray-400' : 'text-yellow-400' ?>">
                            <?= $notificacao['lida'] ? 'Lida' : 'Nova' ?>
                        </span>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?= $notificacao['criado_em'] ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php $this->end() ?>