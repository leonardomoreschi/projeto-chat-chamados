<?php
declare(strict_types=1);
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            return $this->encerrar($request);
        }

        // Alteração de e-mail, senha ou papel (e desativação) sobe
        // usuarios.sessao_versao: quem estiver logado com a versão anterior
        // precisa entrar de novo, inclusive em outros dispositivos.
        if (!$this->sessaoAindaValida((int) $_SESSION['user_id'])) {
            $_SESSION = [];
            session_destroy();

            return $this->encerrar($request, 'Seus dados de acesso foram alterados. Entre novamente.');
        }

        $request = $request->withAttribute('user_id',    $_SESSION['user_id']);
        $request = $request->withAttribute('user_nome',  $_SESSION['user_nome']);
        $request = $request->withAttribute('user_papel', $_SESSION['user_papel']);

        return $handler->handle($request);
    }

    /**
     * A conta continua ativa e com a mesma versão de sessão do login?
     *
     * Uma consulta por PK a cada request. Em caso de falha no banco a resposta
     * é "sim": derrubar todo mundo por causa de uma indisponibilidade seria
     * pior que manter as sessões vivas.
     */
    private function sessaoAindaValida(int $userId): bool
    {
        try {
            $stmt = getDbConnection()->prepare(
                'SELECT ativo, sessao_versao FROM usuarios WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $usuario = $stmt->fetch();

            if (!$usuario) {
                return false;
            }

            if ((int) $usuario['ativo'] !== 1) {
                return false;
            }

            return (int) $usuario['sessao_versao'] === (int) ($_SESSION['sessao_versao'] ?? 0);
        } catch (\Throwable $e) {
            error_log('Falha ao validar sessão: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Página perdida vai para /login; chamada de API recebe 401 em JSON, senão
     * o JavaScript receberia o HTML da tela de login dentro de um fetch.
     */
    private function encerrar(Request $request, string $mensagem = 'Sessão expirada'): Response
    {
        $response = new SlimResponse();

        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            $response->getBody()->write(json_encode([
                'erro' => $mensagem,
                'sessao_encerrada' => true,
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        if ($mensagem !== 'Sessão expirada') {
            // A tela de login mostra o flash; sem sessão viva não há onde
            // guardar, então recomeça uma só para a mensagem.
            session_start();
            $_SESSION['flash_error'] = $mensagem;
            session_write_close();
        }

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
