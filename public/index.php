<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/bootstrap.php';

date_default_timezone_set('America/Sao_Paulo');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

ini_set('session.cookie_httponly', '1');
session_start();

bootstrapDefaultData();

use Slim\Factory\AppFactory;
use App\Controllers\AuthController;
use App\Controllers\ChatController;
use App\Controllers\ChamadoController;
use App\Controllers\AdminController;
use App\Controllers\AgendamentoController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Support\TemplateRenderer;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware($_ENV['APP_ENV'] === 'development', true, true);

// ── Rotas públicas ────────────────────────────
$app->get('/login',  [AuthController::class, 'exibirLogin']);
$app->post('/login', [AuthController::class, 'processarLogin']);
$app->get('/logout', [AuthController::class, 'logout']);

// ── Rotas protegidas — Frontend ───────────────
$app->get('/admin', function ($request, $response) {
    $userName = $request->getAttribute('user_nome');
    return TemplateRenderer::render($response, __DIR__ . '/../templates/admin.php', [
        'userName' => $userName,
    ]);
})->add(new AdminMiddleware())->add(new AuthMiddleware());

$app->get('/chat', function ($request, $response) {
    $userName  = $request->getAttribute('user_nome');
    $userId    = $request->getAttribute('user_id');
    $userPapel = $request->getAttribute('user_papel');
    return TemplateRenderer::render($response, __DIR__ . '/../templates/chat.php', [
        'userName' => $userName,
        'userId' => $userId,
        'userPapel' => $userPapel,
    ]);
})->add(new AuthMiddleware());

$app->get('/agendamentos', function ($request, $response) {
    $userName  = $request->getAttribute('user_nome');
    $userId    = $request->getAttribute('user_id');
    $userPapel = $request->getAttribute('user_papel');

    return TemplateRenderer::render($response, __DIR__ . '/../templates/agendamentos.php', [
        'userName' => $userName,
        'userId' => $userId,
        'userPapel' => $userPapel,
    ]);
})->add(new AuthMiddleware());

$app->get('/painel-agendamentos', function ($request, $response) {
    $userName  = $request->getAttribute('user_nome');
    $userPapel = $request->getAttribute('user_papel');

    if (!in_array($userPapel, ['ti', 'admin'], true)) {
        return $response->withHeader('Location', '/chat')->withStatus(302);
    }

    return TemplateRenderer::render($response, __DIR__ . '/../templates/painel_agendamentos.php', [
        'userName' => $userName,
        'userPapel' => $userPapel,
    ]);
})->add(new AuthMiddleware());

$app->get('/meus-chamados', function ($request, $response) {
    $userName  = $request->getAttribute('user_nome');
    $userId    = $request->getAttribute('user_id');
    $userPapel = $request->getAttribute('user_papel');

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT c.*, u.nome AS usuario_nome,
                a.nome AS atribuido_nome,
                COALESCE(r.nome, a.nome) AS resolvido_por_nome
         FROM chamados c
         INNER JOIN usuarios u ON u.id = c.usuario_id
         LEFT JOIN usuarios a ON a.id = c.atribuido_a
         LEFT JOIN usuarios r ON r.id = c.resolvido_por
         WHERE c.usuario_id = ?
         ORDER BY FIELD(c.status, 'aberto', 'classificado', 'em_andamento', 'resolvido', 'cancelado'), c.criado_em DESC"
    );
    $stmt->execute([(int) $userId]);
    $chamadosUsuario = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return TemplateRenderer::render($response, __DIR__ . '/../templates/meus_chamados.php', [
        'userName' => $userName,
        'userId' => $userId,
        'userPapel' => $userPapel,
        'chamadosUsuario' => $chamadosUsuario,
    ]);
})->add(new AuthMiddleware());

$app->get('/dashboard-ti', function ($request, $response) {
    $userName  = $request->getAttribute('user_nome');
    $userPapel = $request->getAttribute('user_papel');

    $pdo = getDbConnection();
    $sql = "SELECT c.*, u.nome AS usuario_nome,
                   a.nome AS atribuido_nome,
                   COALESCE(r.nome, a.nome) AS resolvido_por_nome
            FROM chamados c
            INNER JOIN usuarios u ON u.id = c.usuario_id
            LEFT JOIN usuarios a ON a.id = c.atribuido_a
            LEFT JOIN usuarios r ON r.id = c.resolvido_por
            ORDER BY FIELD(c.prioridade, 'critica','alta','media','baixa'), c.criado_em DESC";
    $stmt = $pdo->query($sql);
    $chamadosBootstrap = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    
    // Se não for TI ou Admin, redireciona pro chat
    if (!in_array($userPapel, ['ti', 'admin'], true)) {
        return $response->withHeader('Location', '/chat')->withStatus(302);
    }

    return TemplateRenderer::render($response, __DIR__ . '/../templates/dashboard_ti.php', [
        'userName' => $userName,
        'userPapel' => $userPapel,
        'chamadosBootstrap' => $chamadosBootstrap,
    ]);
})->add(new AuthMiddleware());

$app->get('/dashboard-ti/relatorio', function ($request, $response) {
    $userName  = $request->getAttribute('user_nome');
    $userPapel = $request->getAttribute('user_papel');

    if (!in_array($userPapel, ['ti', 'admin'], true)) {
        return $response->withHeader('Location', '/chat')->withStatus(302);
    }

    return TemplateRenderer::render($response, __DIR__ . '/../templates/relatorio_chamados.php', [
        'userName' => $userName,
        'userPapel' => $userPapel,
    ]);
})->add(new AuthMiddleware());

// ── Rotas protegidas — API JSON ───────────────
$app->group('/api', function ($group) {
    $group->get('/conversas',        [ChatController::class, 'listarConversas']);
    $group->get('/mensagens',        [ChatController::class, 'listarMensagens']);
    $group->post('/mensagens',       [ChatController::class, 'enviarMensagem']);
    $group->delete('/mensagens/{id}',[ChatController::class, 'apagarMensagem']);
    $group->get('/usuarios/online',  [ChatController::class, 'listarUsuarios']);

    // Conversas
    $group->post('/conversas',                              [ChatController::class, 'criarConversa']);
    $group->get('/conversas/{id}',                          [ChatController::class, 'obterConversa']);
    $group->patch('/conversas/{id}',                        [ChatController::class, 'editarConversa']);
    $group->patch('/conversas/{id}/descricao',              [ChatController::class, 'atualizarDescricaoConversa']);
    $group->delete('/conversas/{id}',                       [ChatController::class, 'deletarConversa']);
    $group->post('/conversas/{id}/lida',                    [ChatController::class, 'marcarComoLida']);
    $group->get('/conversas/{id}/participantes',            [ChatController::class, 'listarParticipantes']);
    $group->post('/conversas/{id}/participantes',           [ChatController::class, 'adicionarParticipante']);
    $group->delete('/conversas/{id}/participantes/{uid}',   [ChatController::class, 'removerParticipante']);

    // Chamados
    $group->post('/chamados',              [ChamadoController::class, 'criar']);
    $group->get('/chamados',               [ChamadoController::class, 'listar']);
    $group->get('/chamados/{id}/anexos',   [ChamadoController::class, 'listarAnexos']);
    $group->get('/chamados/{id}/comentarios', [ChamadoController::class, 'listarComentarios']);
    $group->post('/chamados/{id}/comentarios', [ChamadoController::class, 'adicionarComentario']);
    $group->delete('/chamados/{id}/comentarios/{comentarioId}', [ChamadoController::class, 'removerComentario']);
    $group->get('/chamados/relatorio', [ChamadoController::class, 'relatorio']);
    $group->get('/chamados/relatorio/csv', [ChamadoController::class, 'exportarRelatorioCsv']);
    $group->patch('/chamados/{id}/status', [ChamadoController::class, 'atualizarStatus']);
    $group->patch('/chamados/{id}/cancelar', [ChamadoController::class, 'cancelarMeuChamado']);
    $group->patch('/chamados/{id}/classificar', [ChamadoController::class, 'classificar']);
    $group->patch('/chamados/{id}/classificacao', [ChamadoController::class, 'atualizarClassificacao']);
    $group->patch('/chamados/{id}/finalizar', [ChamadoController::class, 'finalizar']);
    $group->get('/chamados-taxonomias', [ChamadoController::class, 'listarTaxonomias']);
    $group->get('/chamados-taxonomias/detalhe', [ChamadoController::class, 'listarTaxonomiasDetalhe']);
    $group->post('/chamados-taxonomias', [ChamadoController::class, 'salvarTaxonomia']);
    $group->delete('/chamados-taxonomias/{id}', [ChamadoController::class, 'removerTaxonomia']);

    // Agendamentos
    $group->get('/agendamentos', [AgendamentoController::class, 'listar']);
    $group->get('/agendamentos/{id}', [AgendamentoController::class, 'obter']);
    $group->post('/agendamentos', [AgendamentoController::class, 'solicitar']);
    $group->patch('/agendamentos/{id}/aprovar', [AgendamentoController::class, 'aprovar']);
    $group->patch('/agendamentos/{id}/recusar', [AgendamentoController::class, 'recusar']);
    $group->patch('/agendamentos/{id}/cancelar', [AgendamentoController::class, 'cancelar']);
    $group->patch('/agendamentos/{id}/encerrar', [AgendamentoController::class, 'encerrar']);
    $group->get('/servicos-agendamento', [AgendamentoController::class, 'listarServicos']);
    $group->post('/servicos-agendamento', [AgendamentoController::class, 'criarServico']);
    $group->patch('/servicos-agendamento/{id}', [AgendamentoController::class, 'atualizarServico']);
    $group->delete('/servicos-agendamento/{id}', [AgendamentoController::class, 'desativarServico']);

    // Admin — usuários
    $group->get('/admin/usuarios',         [AdminController::class, 'listarUsuarios']);
    $group->post('/admin/usuarios',        [AdminController::class, 'criarUsuario']);
    $group->patch('/admin/usuarios/{id}',  [AdminController::class, 'atualizarUsuario']);
    $group->delete('/admin/usuarios/{id}', [AdminController::class, 'desativarUsuario']);

    // Admin — setores
    $group->get('/admin/setores',          [AdminController::class, 'listarSetores']);
    $group->post('/admin/setores',         [AdminController::class, 'criarSetor']);
    $group->delete('/admin/setores/{id}',  [AdminController::class, 'deletarSetor']);
})->add(new AuthMiddleware());

$app->get('/', function ($request, $response) {
    return $response->withHeader('Location', '/login')->withStatus(302);
});

$app->run();