<?php
declare(strict_types=1);
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\TemplateRenderer;

class AuthController
{
    public function exibirLogin(Request $request, Response $response): Response
    {
        // Já logado não vê formulário de login: com o cookie de uma semana, um
        // /login no histórico ou nos favoritos abriria a tela de entrada como se
        // a sessão tivesse expirado.
        if (!empty($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/chat')->withStatus(302);
        }

        return TemplateRenderer::render($response, __DIR__ . '/../../templates/login.php');
    }

    public function processarLogin(Request $request, Response $response): Response
    {
        $data  = (array) $request->getParsedBody();
        $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $senha = $data['senha'] ?? '';

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT id, nome, senha_hash, papel, ativo, sessao_versao FROM usuarios WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['ativo'] || !password_verify($senha, $usuario['senha_hash'])) {
            $_SESSION['flash_error'] = 'E-mail ou senha inválidos.';
            session_write_close(); // grava antes de redirecionar
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        session_regenerate_id(true);
        $_SESSION['user_id']    = $usuario['id'];
        $_SESSION['user_nome']  = $usuario['nome'];
        $_SESSION['user_papel'] = $usuario['papel'];
        // Carimbo do estado da conta neste login: se o admin mexer em e-mail,
        // senha, papel ou desativar, a versao no banco sobe e o AuthMiddleware
        // derruba esta sessao.
        $_SESSION['sessao_versao'] = (int) ($usuario['sessao_versao'] ?? 0);
        session_write_close(); // grava antes de redirecionar

        return $response->withHeader('Location', '/chat')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
