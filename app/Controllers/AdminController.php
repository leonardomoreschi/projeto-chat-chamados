<?php
declare(strict_types=1);
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Helpers\Response as Json;
use App\Support\SchemaInspector;

class AdminController
{
    use SchemaInspector;

    // ── USUÁRIOS ──────────────────────────────

    // GET /api/admin/usuarios
    public function listarUsuarios(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $papel = trim((string) ($params['papel'] ?? ''));
        $setor = trim((string) ($params['setor'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? 7);
        if ($perPage < 1) {
            $perPage = 7;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $pdo = getDbConnection();
        $where = [];
        $values = [];

        if ($q !== '') {
            $where[] = '(u.nome LIKE ? OR u.email LIKE ? OR s.nome LIKE ? OR u.papel LIKE ?)';
            $busca = '%' . $q . '%';
            $values[] = $busca;
            $values[] = $busca;
            $values[] = $busca;
            $values[] = $busca;
        }

        if ($papel !== '' && in_array($papel, ['admin', 'ti', 'usuario'], true)) {
            $where[] = 'u.papel = ?';
            $values[] = $papel;
        }

        if ($setor !== '') {
            $where[] = 'u.setor_id = ?';
            $values[] = (int) $setor;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $stmtTotal = $pdo->prepare(
            "SELECT COUNT(*)
             FROM usuarios u
             LEFT JOIN setores s ON s.id = u.setor_id
             {$whereSql}"
        );
        $stmtTotal->execute($values);
        $total = (int) $stmtTotal->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Presença é o estado inicial da coluna "Conexão"; a partir daí quem
        // atualiza é o evento presence_updated do WebSocket (admin.js).
        $temPresenca = $this->tableExists($pdo, 'user_presenca');
        $selectPresenca = $temPresenca
            ? 'COALESCE(up.online, 0) AS online, up.last_seen'
            : '0 AS online, NULL AS last_seen';
        $joinPresenca = $temPresenca ? 'LEFT JOIN user_presenca up ON up.usuario_id = u.id' : '';

        $stmt = $pdo->prepare(
            "SELECT u.id, u.nome, u.email, u.papel, u.ativo,
                    u.criado_em, s.id AS setor_id, s.nome AS setor,
                    {$selectPresenca}
             FROM usuarios u
             LEFT JOIN setores s ON s.id = u.setor_id
             {$joinPresenca}
             {$whereSql}
             ORDER BY u.nome ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($values);

        return Json::json($response, [
            'data' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    /**
     * Reconfere e-mail e senha do admin que está logado nesta sessão.
     *
     * Sempre as credenciais do **próprio** admin da sessão: aceitar as de
     * qualquer outro admin transformaria o campo numa segunda porta de login.
     *
     * @return string|null mensagem de erro, ou null se conferiu
     */
    private function conferirCredenciaisAdmin(Request $request, array $data): ?string
    {
        $email = trim((string) ($data['admin_email'] ?? ''));
        $senha = (string) ($data['admin_senha'] ?? '');

        if ($email === '' || $senha === '') {
            return 'Informe e-mail e senha do administrador para confirmar a alteração';
        }

        $stmt = getDbConnection()->prepare(
            'SELECT email, senha_hash, papel, ativo FROM usuarios WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int) $request->getAttribute('user_id')]);
        $admin = $stmt->fetch();

        // Mensagem única para e-mail errado e senha errada: separar as duas
        // contaria a quem tentar qual das metades já acertou.
        if (
            !$admin
            || !$admin['ativo']
            || $admin['papel'] !== 'admin'
            || strcasecmp($email, (string) $admin['email']) !== 0
            || !password_verify($senha, (string) $admin['senha_hash'])
        ) {
            return 'E-mail ou senha do administrador não conferem';
        }

        return null;
    }

    /**
     * GET /api/admin/usuarios/presenca
     *
     * Só quem está online, em resposta mínima. O painel usa isto para
     * reconciliar a coluna "Conexão" quando o WebSocket cai e volta — o tempo
     * real de verdade vem do evento `presence_updated`.
     */
    public function listarPresenca(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();

        if (!$this->tableExists($pdo, 'user_presenca')) {
            return Json::json($response, ['online' => []]);
        }

        $stmt = $pdo->query(
            'SELECT usuario_id FROM user_presenca WHERE online = 1'
        );

        return Json::json($response, [
            'online' => array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)),
        ]);
    }

    // POST /api/admin/usuarios
    public function criarUsuario(Request $request, Response $response): Response
    {
        $data  = (array) $request->getParsedBody();
        $nome  = trim($data['nome']  ?? '');
        $email = trim($data['email'] ?? '');
        $senha = $data['senha'] ?? '';
        $papel = $data['papel'] ?? 'usuario';
        $setorId = !empty($data['setor_id']) ? (int) $data['setor_id'] : null;

        if (!$nome || !$email || !$senha) {
            return Json::erro($response, 'Nome, e-mail e senha são obrigatórios');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Json::erro($response, 'E-mail inválido');
        }

        if (strlen($senha) < 6) {
            return Json::erro($response, 'Senha deve ter ao menos 6 caracteres');
        }

        $papeisValidos = ['admin', 'ti', 'usuario'];
        if (!in_array($papel, $papeisValidos, true)) {
            $papel = 'usuario';
        }

        $pdo = getDbConnection();

        // Verifica e-mail duplicado
        $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            return Json::erro($response, 'E-mail já cadastrado');
        }

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, email, senha_hash, setor_id, papel)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $nome,
            $email,
            password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]),
            $setorId,
            $papel,
        ]);

        $id = (int) $pdo->lastInsertId();
        return Json::json($response, ['id' => $id, 'nome' => $nome, 'email' => $email, 'papel' => $papel], 201);
    }

    // PATCH /api/admin/usuarios/{id}
    public function atualizarUsuario(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $pdo  = getDbConnection();

        // Alterar dados de outra pessoa exige o admin provar de novo quem é: só
        // a sessão não basta (máquina destravada, aba esquecida aberta).
        $erroCredencial = $this->conferirCredenciaisAdmin($request, $data);
        if ($erroCredencial !== null) {
            return Json::erro($response, $erroCredencial, 403);
        }

        $campos = [];
        $values = [];
        // Mudou credencial ou permissão? As sessões abertas dessa pessoa (em
        // qualquer dispositivo) precisam morrer — ver ensureSessionVersionColumn.
        $derrubarSessoes = false;

        if (!empty($data['nome'])) {
            $campos[] = 'nome = ?';
            $values[] = trim($data['nome']);
        }
        if (!empty($data['email'])) {
            $email = trim((string) $data['email']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Json::erro($response, 'E-mail inválido');
            }

            // E-mail é a credencial de login: não pode colidir com outra conta.
            $check = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
            $check->execute([$email, $id]);
            if ($check->fetch()) {
                return Json::erro($response, 'E-mail já cadastrado');
            }

            $campos[] = 'email = ?';
            $values[] = $email;
            $derrubarSessoes = true;
        }
        if (!empty($data['papel'])) {
            $campos[] = 'papel = ?';
            $values[] = $data['papel'];
            // O papel fica em cache na sessão ($_SESSION['user_papel']): sem
            // derrubar, um usuário rebaixado manteria o acesso antigo.
            $derrubarSessoes = true;
        }
        if (isset($data['setor_id'])) {
            $campos[] = 'setor_id = ?';
            $values[] = $data['setor_id'] ? (int) $data['setor_id'] : null;
        }
        if (isset($data['ativo'])) {
            $campos[] = 'ativo = ?';
            $values[] = (int) $data['ativo'];
        }
        if (!empty($data['senha'])) {
            if (strlen($data['senha']) < 6) {
                return Json::erro($response, 'Senha deve ter ao menos 6 caracteres');
            }
            $campos[] = 'senha_hash = ?';
            $values[] = password_hash($data['senha'], PASSWORD_BCRYPT, ['cost' => 12]);
            $derrubarSessoes = true;
        }

        if (empty($campos)) {
            return Json::erro($response, 'Nenhum campo para atualizar');
        }

        // Desativar já derruba pelo próprio `ativo` conferido no AuthMiddleware.
        if ($derrubarSessoes) {
            $campos[] = 'sessao_versao = sessao_versao + 1';
        }

        $values[] = $id;
        $stmt = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $campos) . " WHERE id = ?");
        $stmt->execute($values);

        return Json::json($response, ['ok' => true]);
    }

    // DELETE /api/admin/usuarios/{id} — desativa ao invés de deletar
    public function desativarUsuario(Request $request, Response $response, array $args): Response
    {
        $id      = (int) $args['id'];
        $myId    = $request->getAttribute('user_id');

        if ($id === $myId) {
            return Json::erro($response, 'Você não pode desativar sua própria conta');
        }

        // Mesma exigência do atualizarUsuario: tirar o acesso de alguém é
        // alteração de dado como qualquer outra.
        $erroCredencial = $this->conferirCredenciaisAdmin($request, (array) $request->getParsedBody());
        if ($erroCredencial !== null) {
            return Json::erro($response, $erroCredencial, 403);
        }

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("UPDATE usuarios SET ativo = 0 WHERE id = ?");
        $stmt->execute([$id]);

        return Json::json($response, ['ok' => true]);
    }

    // ── SETORES ───────────────────────────────

    // GET /api/admin/setores
    public function listarSetores(Request $request, Response $response): Response
    {
        $pdo  = getDbConnection();
        $stmt = $pdo->query("
            SELECT s.id, s.nome, s.descricao,
                   COUNT(u.id) AS total_usuarios
            FROM setores s
            LEFT JOIN usuarios u ON u.setor_id = s.id AND u.ativo = 1
            GROUP BY s.id
            ORDER BY s.nome ASC
        ");
        return Json::json($response, $stmt->fetchAll());
    }

    // POST /api/admin/setores
    public function criarSetor(Request $request, Response $response): Response
    {
        $data     = (array) $request->getParsedBody();
        $nome     = trim($data['nome'] ?? '');
        $descricao = trim($data['descricao'] ?? '');

        if (!$nome) {
            return Json::erro($response, 'Nome do setor é obrigatório');
        }

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO setores (nome, descricao) VALUES (?, ?)");
        $stmt->execute([$nome, $descricao]);

        return Json::json($response, [
            'id'   => (int) $pdo->lastInsertId(),
            'nome' => $nome,
        ], 201);
    }

    // DELETE /api/admin/setores/{id}
    public function deletarSetor(Request $request, Response $response, array $args): Response
    {
        $id  = (int) $args['id'];
        $pdo = getDbConnection();

        // Verifica se tem usuários
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE setor_id = ? AND ativo = 1");
        $check->execute([$id]);
        if ((int) $check->fetch()['total'] > 0) {
            return Json::erro($response, 'Setor possui usuários ativos. Mova-os antes de deletar.');
        }

        $stmt = $pdo->prepare("DELETE FROM setores WHERE id = ?");
        $stmt->execute([$id]);

        return Json::json($response, ['ok' => true]);
    }
}
