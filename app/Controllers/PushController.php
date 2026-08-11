<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response as Json;
use App\Support\PushCenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Inscrições de Web Push. As rotas vivem dentro do grupo /api, então já chegam
 * autenticadas pelo AuthMiddleware.
 */
class PushController
{
    /**
     * A chave pública VAPID vem por endpoint (e não embutida no template) para
     * que a troca de chaves não dependa de recarregar HTML — o push.js compara
     * a chave da inscrição atual com esta e se reinscreve quando diverge.
     */
    public function chavePublica(Request $request, Response $response): Response
    {
        return Json::json($response, [
            'chave' => PushCenter::habilitado() ? PushCenter::chavePublica() : null,
            'habilitado' => PushCenter::habilitado(),
        ]);
    }

    public function inscrever(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $dados = (array) $request->getParsedBody();

        $endpoint = trim((string) ($dados['endpoint'] ?? ''));
        $chaves = (array) ($dados['keys'] ?? []);
        $p256dh = trim((string) ($chaves['p256dh'] ?? ''));
        $auth = trim((string) ($chaves['auth'] ?? ''));

        if (
            $endpoint === '' || $p256dh === '' || $auth === ''
            || !preg_match('#^https://#', $endpoint) || mb_strlen($endpoint) > 512
            || mb_strlen($p256dh) > 255 || mb_strlen($auth) > 255
        ) {
            return Json::erro($response, 'Inscrição push inválida', 422);
        }

        // p256dh é um ponto não comprimido (65 bytes) e auth tem 16 bytes. Uma
        // chave malformada só estouraria na hora de cifrar, dentro do worker.
        if (!$this->chaveTemTamanho($p256dh, 65) || !$this->chaveTemTamanho($auth, 16)) {
            return Json::erro($response, 'Chaves da inscrição push inválidas', 422);
        }

        $pdo = getDbConnection();

        // Numa máquina compartilhada o mesmo endpoint muda de dono quando outro
        // usuário loga: o upsert transfere a inscrição em vez de duplicar.
        $stmt = $pdo->prepare(
            'INSERT INTO push_subscriptions (usuario_id, endpoint, p256dh, auth, user_agent, ultimo_uso_em)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                usuario_id = VALUES(usuario_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                user_agent = VALUES(user_agent),
                falhas = 0,
                ultimo_uso_em = NOW()'
        );
        $stmt->execute([
            $userId,
            $endpoint,
            $p256dh,
            $auth,
            mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
        ]);

        return Json::json($response, ['ok' => true]);
    }

    private function chaveTemTamanho(string $base64Url, int $bytes): bool
    {
        $base64 = strtr($base64Url, '-_', '+/');
        $base64 .= str_repeat('=', (4 - (strlen($base64) % 4)) % 4);
        $bruto = base64_decode($base64, true);

        return $bruto !== false && strlen($bruto) === $bytes;
    }

    public function desinscrever(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $dados = (array) $request->getParsedBody();
        $endpoint = trim((string) ($dados['endpoint'] ?? ''));

        if ($endpoint === '') {
            return Json::erro($response, 'Informe o endpoint da inscrição', 422);
        }

        $pdo = getDbConnection();

        // O filtro por usuario_id impede que alguém derrube a inscrição de outro.
        $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND usuario_id = ?');
        $stmt->execute([$endpoint, $userId]);

        return Json::json($response, ['ok' => true, 'removidas' => $stmt->rowCount()]);
    }

    public function status(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $pdo = getDbConnection();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE usuario_id = ?');
        $stmt->execute([$userId]);

        return Json::json($response, [
            'habilitado' => PushCenter::habilitado(),
            'inscricoes' => (int) $stmt->fetchColumn(),
        ]);
    }
}
