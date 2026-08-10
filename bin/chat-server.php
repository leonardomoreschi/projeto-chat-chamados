<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/bootstrap.php';

date_default_timezone_set('America/Sao_Paulo');

// Carrega o .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

bootstrapDefaultData();

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use App\Services\ChatServer;

$port = 8080;

// IoServer::factory() cria um loop novo (LoopFactory::create), enquanto
// ChatServer registra seus timers no loop global (Loop::addPeriodicTimer).
// Montar o servidor com o MESMO loop global é o que faz os timers de
// sincronização (0,8s) e de agendamentos vencidos (60s) realmente rodarem.
$loop = Loop::get();

$server = new IoServer(
    new HttpServer(
        new WsServer(
            new ChatServer()
        )
    ),
    new SocketServer('0.0.0.0:' . $port, [], $loop),
    $loop
);

echo "WebSocket rodando na porta {$port}...\n";
echo "Pressione Ctrl+C para parar.\n\n";

$server->run();
