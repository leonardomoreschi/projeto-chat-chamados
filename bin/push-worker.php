<?php
declare(strict_types=1);

/**
 * Worker de Web Push.
 *
 * Roda como segundo programa do Supervisor no container do WebSocket. Existe
 * separado do Ratchet porque enviar push é HTTP bloqueante: dentro do loop de
 * 0,8s do ChatServer, um endpoint lento travaria a sincronização de todos os
 * clientes conectados; dentro do PHP-FPM, somaria latência de rede a uma
 * request do usuário.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/bootstrap.php';

date_default_timezone_set('America/Sao_Paulo');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

bootstrapDefaultData();

use App\Support\PushCenter;

if (!PushCenter::habilitado()) {
    // Sem VAPID o worker não tem o que fazer. Sai com 0 (código esperado pelo
    // supervisor) depois de uma pausa, para não virar restart-loop agressivo.
    error_log('push-worker: VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY não configuradas. Encerrando.');
    echo "push-worker: VAPID não configurado, nada a fazer.\n";
    sleep(60);
    exit(0);
}

$parar = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$parar): void { $parar = true; });
pcntl_signal(SIGINT, function () use (&$parar): void { $parar = true; });

try {
    // Itens deixados em 'processando' por um shutdown abrupto voltam à fila.
    // Este é o único consumidor da tabela, então a limpeza é segura.
    $destravados = PushCenter::destravarPendentes(getDbConnection());
    if ($destravados > 0) {
        echo "push-worker: {$destravados} item(ns) devolvido(s) à fila.\n";
    }
} catch (\Throwable $e) {
    error_log('push-worker: falha no boot: ' . $e->getMessage());
    exit(1);
}

echo "push-worker iniciado.\n";

$ultimaLimpeza = 0;

while (!$parar) {
    try {
        $pdo = getDbConnection();
        $processados = PushCenter::processarLote($pdo, PushCenter::LOTE_PADRAO);

        if (time() - $ultimaLimpeza > 600) {
            PushCenter::limparAntigos($pdo);
            $ultimaLimpeza = time();
        }
    } catch (\Throwable $e) {
        // Conexão perdida, banco fora do ar etc.: sai e deixa o supervisor
        // reiniciar com estado limpo.
        error_log('push-worker erro: ' . $e->getMessage());
        exit(1);
    }

    if ($parar) {
        break;
    }

    if ($processados === 0) {
        // Latência máxima de ~2s até o primeiro push. Com pcntl_async_signals
        // o SIGTERM interrompe o sleep, então o shutdown continua rápido.
        sleep(2);
    } else {
        usleep(200000);
    }
}

echo "push-worker encerrado.\n";
exit(0);
