<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response as Json;
use App\Support\NotificationCenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NotificacaoController
{
    public function listar(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $userId = (int) $request->getAttribute('user_id');
        $params = $request->getQueryParams();
        $limite = (int) ($params['limite'] ?? 50);

        return Json::json($response, NotificationCenter::listar($pdo, $userId, $limite));
    }

    public function resumo(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $userId = (int) $request->getAttribute('user_id');

        return Json::json($response, NotificationCenter::resumo($pdo, $userId));
    }

    public function marcarComoLida(Request $request, Response $response, array $args): Response
    {
        $pdo = getDbConnection();
        $userId = (int) $request->getAttribute('user_id');
        $notificacaoId = (int) ($args['id'] ?? 0);

        if (!NotificationCenter::marcarComoLida($pdo, $userId, $notificacaoId)) {
            return Json::erro($response, 'Notificação não encontrada', 404);
        }

        return Json::json($response, ['ok' => true]);
    }

    public function marcarTodasComoLidas(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $userId = (int) $request->getAttribute('user_id');

        return Json::json($response, [
            'ok' => true,
            'alteradas' => NotificationCenter::marcarTodasComoLidas($pdo, $userId),
        ]);
    }
}