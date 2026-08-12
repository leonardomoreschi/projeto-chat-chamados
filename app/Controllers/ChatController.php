<?php
declare(strict_types=1);
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Helpers\Response as Json;
use App\Support\SchemaInspector;

class ChatController
{
    use SchemaInspector;

    /**
     * Janela para o autor apagar a própria mensagem.
     *
     * O mesmo limite existe em `ChatServer` (case 'delete_message', o caminho
     * usado quando o WebSocket está vivo) e em `chat.js`
     * (`PRAZO_APAGAR_MENSAGEM_MS`, que só esconde o botão). Mudou aqui, mude
     * nos três — não há classe compartilhada porque o autoloader é otimizado e
     * arquivo novo só entra depois de `composer dump-autoload`.
     */
    public const PRAZO_APAGAR_SEGUNDOS = 60;

    // GET /api/conversas
    public function listarConversas(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo    = getDbConnection();

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.tipo,
                CASE
                    WHEN c.tipo = 'privada' THEN (
                        SELECT u.nome FROM usuarios u
                        INNER JOIN participantes p2 ON p2.usuario_id = u.id
                        WHERE p2.conversa_id = c.id AND u.id != ?
                        LIMIT 1
                    )
                    ELSE c.nome
                END AS nome,
                (SELECT m.conteudo FROM mensagens m
                 WHERE m.conversa_id = c.id
                    AND m.criado_em >= p.entrou_em
                 ORDER BY m.criado_em DESC LIMIT 1) AS ultima_mensagem,
                (SELECT COUNT(*) FROM mensagens m
                 WHERE m.conversa_id = c.id
                 AND m.usuario_id != ?
                    AND m.criado_em >= p.entrou_em
                 AND (p.ultima_leitura IS NULL OR m.criado_em > p.ultima_leitura)
                ) AS nao_lidas
                ,COALESCE(
                    (SELECT MAX(m2.criado_em)
                     FROM mensagens m2
                     WHERE m2.conversa_id = c.id
                       AND m2.criado_em >= p.entrou_em),
                    c.criado_em
                ) AS ultima_atividade
            FROM conversas c
            INNER JOIN participantes p ON p.conversa_id = c.id
            WHERE p.usuario_id = ?
            ORDER BY ultima_atividade DESC, c.id DESC
        ");
        $stmt->execute([$userId, $userId, $userId]);

        return Json::json($response, $stmt->fetchAll());
    }

    // GET /api/mensagens?conversa_id=1&pagina=1
    public function listarMensagens(Request $request, Response $response): Response
    {
        $userId     = $request->getAttribute('user_id');
        $params     = $request->getQueryParams();
        $conversaId = (int) ($params['conversa_id'] ?? 0);
        $pagina     = max(1, (int) ($params['pagina'] ?? 1));
        $porPagina  = 50;
        $offset     = ($pagina - 1) * $porPagina;

        if (!$conversaId) {
            return Json::erro($response, 'conversa_id é obrigatório');
        }

        $pdo = getDbConnection();

        $check = $pdo->prepare("SELECT entrou_em FROM participantes WHERE conversa_id = ? AND usuario_id = ?");
        $check->execute([$conversaId, $userId]);
        $participacao = $check->fetch();
        if (!$participacao) {
            return Json::erro($response, 'Acesso negado', 403);
        }

        $entrouEm = $participacao['entrou_em'] ?? null;

        $temExclusao = $this->columnExists($pdo, 'mensagens', 'excluida_em') && $this->columnExists($pdo, 'mensagens', 'excluida_por');
        $selectExclusao = $temExclusao
            ? 'm.excluida_em, m.excluida_por,'
            : 'NULL AS excluida_em, NULL AS excluida_por,';

        $stmt = $pdo->prepare("
            SELECT m.id, m.conteudo, m.arquivo_path, m.arquivo_nome, {$selectExclusao} m.criado_em,
                   u.id AS usuario_id, u.nome AS usuario_nome
            FROM mensagens m
            INNER JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.conversa_id = ?
              AND m.criado_em >= ?
            ORDER BY m.criado_em DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$conversaId, $entrouEm, $porPagina, $offset]);

        return Json::json($response, array_reverse($stmt->fetchAll()));
    }

    // POST /api/mensagens
    public function enviarMensagem(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data   = (array) $request->getParsedBody();
        $files  = $request->getUploadedFiles();

        $conversaId = (int) ($data['conversa_id'] ?? 0);
        $conteudo   = trim($data['conteudo'] ?? '');
        $arquivosBrutos = $files['arquivos'] ?? $files['arquivos[]'] ?? $files['arquivo'] ?? null;
        $arquivos = $this->normalizarArquivosUpload($arquivosBrutos);

        if (!$conversaId || ($conteudo === '' && count($arquivos) === 0)) {
            return Json::erro($response, 'conversa_id e conteudo ou arquivo são obrigatórios');
        }

        if (mb_strlen($conteudo) > 5000) {
            return Json::erro($response, 'Mensagem muito longa (máximo 5000 caracteres)');
        }

        if (count($arquivos) > 10) {
            return Json::erro($response, 'Limite de 10 anexos por envio');
        }

        $pdo = getDbConnection();

        $check = $pdo->prepare("SELECT 1 FROM participantes WHERE conversa_id = ? AND usuario_id = ?");
        $check->execute([$conversaId, $userId]);
        if (!$check->fetch()) {
            return Json::erro($response, 'Acesso negado', 403);
        }

        $mensagensCriadas = [];
        $anexoErros = [];
        $textoFoiUsado = false;

        foreach ($arquivos as $arquivo) {
            if ($arquivo->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $nomeOriginal = (string) $arquivo->getClientFilename();

            // Um anexo inválido não invalida os que já foram gravados: acumula
            // o erro e devolve tudo junto (mesmo contrato de /api/chamados).
            if ($arquivo->getError() !== UPLOAD_ERR_OK) {
                $anexoErros[] = ['arquivo' => $nomeOriginal, 'erro' => 'Falha no upload'];
                continue;
            }

            try {
                [$arquivoPath, $arquivoNome] = $this->salvarArquivoMensagem($arquivo, $conversaId);
            } catch (\Throwable $e) {
                $anexoErros[] = ['arquivo' => $nomeOriginal, 'erro' => $e->getMessage()];
                continue;
            }

            $conteudoMensagem = (!$textoFoiUsado && $conteudo !== '') ? $conteudo : '';

            $stmt = $pdo->prepare("INSERT INTO mensagens (conversa_id, usuario_id, conteudo, arquivo_path, arquivo_nome) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$conversaId, $userId, $conteudoMensagem, $arquivoPath, $arquivoNome]);
            $msgId = (int) $pdo->lastInsertId();
            $mensagensCriadas[] = $this->buscarMensagemPorId($pdo, $msgId);
            if ($conteudoMensagem !== '') {
                $textoFoiUsado = true;
            }
        }

        // Todos os anexos falharam e não havia texto: nada a gravar.
        if (count($mensagensCriadas) === 0 && $conteudo === '') {
            return Json::json($response, [
                'erro' => 'Nenhum anexo pôde ser enviado',
                'anexo_erros' => $anexoErros,
            ], 400);
        }

        if (count($mensagensCriadas) === 0 || ($conteudo !== '' && !$textoFoiUsado)) {
            $stmt = $pdo->prepare("INSERT INTO mensagens (conversa_id, usuario_id, conteudo, arquivo_path, arquivo_nome) VALUES (?, ?, ?, NULL, NULL)");
            $stmt->execute([$conversaId, $userId, $conteudo]);
            $msgId = (int) $pdo->lastInsertId();
            $mensagensCriadas[] = $this->buscarMensagemPorId($pdo, $msgId);
        }

        if (count($mensagensCriadas) === 1 && count($anexoErros) === 0) {
            return Json::json($response, $mensagensCriadas[0], 201);
        }

        return Json::json($response, [
            'mensagens' => $mensagensCriadas,
            'anexo_erros' => $anexoErros,
        ], 201);
    }

    // DELETE /api/mensagens/{id}
    public function apagarMensagem(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $papel = (string) $request->getAttribute('user_papel');
        $mensagemId = (int) $args['id'];
        $pdo = getDbConnection();

        $stmtBusca = $pdo->prepare('SELECT id, usuario_id, criado_em FROM mensagens WHERE id = ? LIMIT 1');
        $stmtBusca->execute([$mensagemId]);
        $msg = $stmtBusca->fetch(\PDO::FETCH_ASSOC);

        if (!$msg) {
            return Json::erro($response, 'Mensagem não encontrada', 404);
        }

        $dono = (int) $msg['usuario_id'] === $userId;
        if (!$dono && $papel !== 'admin') {
            return Json::erro($response, 'Sem permissão para apagar esta mensagem', 403);
        }

        // Prazo vale para todo mundo, inclusive admin: passou de 1 minuto, a
        // mensagem é definitiva. Data ilegível conta como fora do prazo, para
        // não virar exclusão liberada para sempre.
        $criadoEm = strtotime((string) ($msg['criado_em'] ?? ''));
        if ($criadoEm === false || (time() - $criadoEm) > self::PRAZO_APAGAR_SEGUNDOS) {
            return Json::erro($response, 'O prazo para apagar esta mensagem (1 minuto) já passou', 403);
        }

        $temExclusao = $this->columnExists($pdo, 'mensagens', 'excluida_em') && $this->columnExists($pdo, 'mensagens', 'excluida_por');
        if ($temExclusao) {
            $stmt = $pdo->prepare("\n                UPDATE mensagens\n                SET conteudo = '',\n                    arquivo_path = NULL,\n                    arquivo_nome = NULL,\n                    excluida_em = NOW(),\n                    excluida_por = ?\n                WHERE id = ?\n            ");
            $stmt->execute([$userId, $mensagemId]);
        } else {
            $stmt = $pdo->prepare("\n                UPDATE mensagens\n                SET conteudo = '[mensagem apagada]',\n                    arquivo_path = NULL,\n                    arquivo_nome = NULL\n                WHERE id = ?\n            ");
            $stmt->execute([$mensagemId]);
        }

        return Json::json($response, ['ok' => true]);
    }

    /**
     * GET /api/usuarios
     *
     * Lista para montar conversas (sidebar, nova conversa, grupos). **Não**
     * devolve presença de propósito: quem está online só o admin vê, no painel
     * administrativo (`/api/admin/usuarios/presenca`).
     */
    public function listarUsuarios(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo    = getDbConnection();

        $stmt = $pdo->prepare('
            SELECT u.id, u.nome, u.papel, s.nome AS setor, u.ativo
            FROM usuarios u
            LEFT JOIN setores s ON s.id = u.setor_id
            WHERE u.ativo = 1 AND u.id != ?
            ORDER BY u.nome ASC
        ');
        $stmt->execute([$userId]);

        return Json::json($response, $stmt->fetchAll());
    }

    // POST /api/conversas
    public function criarConversa(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $papel  = $request->getAttribute('user_papel');
        $data   = (array) $request->getParsedBody();
        $tipo   = $data['tipo'] ?? 'privada';

        if ($tipo === 'grupo' && $papel !== 'admin') {
            return Json::erro($response, 'Apenas administradores podem criar grupos', 403);
        }

        $pdo = getDbConnection();

        if ($tipo === 'privada') {
            $outroId = (int) ($data['usuario_id'] ?? 0);
            if (!$outroId || $outroId === $userId) {
                return Json::erro($response, 'Informe um usuário válido');
            }

            $check = $pdo->prepare("
                SELECT c.id FROM conversas c
                INNER JOIN participantes p1 ON p1.conversa_id = c.id AND p1.usuario_id = ?
                INNER JOIN participantes p2 ON p2.conversa_id = c.id AND p2.usuario_id = ?
                WHERE c.tipo = 'privada' LIMIT 1
            ");
            $check->execute([$userId, $outroId]);
            $existente = $check->fetch();

            if ($existente) {
                return Json::json($response, ['id' => $existente['id'], 'ja_existe' => true]);
            }

            $stmt = $pdo->prepare("INSERT INTO conversas (tipo, nome, criado_por) VALUES ('privada', NULL, ?)");
            $stmt->execute([$userId]);
            $conversaId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)")->execute([$conversaId, $userId]);
            $pdo->prepare("INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)")->execute([$conversaId, $outroId]);

            $outro = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $outro->execute([$outroId]);

            return Json::json($response, [
                'id'        => $conversaId,
                'tipo'      => 'privada',
                'nome'      => $outro->fetchColumn(),
                'ja_existe' => false,
            ], 201);
        }

        // Grupo
        $nome = trim($data['nome'] ?? '');
        if (!$nome) {
            return Json::erro($response, 'Nome do grupo é obrigatório');
        }

        $stmt = $pdo->prepare("INSERT INTO conversas (tipo, nome, criado_por) VALUES ('grupo', ?, ?)");
        $stmt->execute([$nome, $userId]);
        $conversaId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)")->execute([$conversaId, $userId]);

        $participantes = $data['participantes'] ?? '';
        if ($participantes) {
            $ids = array_filter(array_map('intval', explode(',', $participantes)));
            foreach ($ids as $pid) {
                if ($pid === $userId) continue;
                $pdo->prepare("INSERT IGNORE INTO participantes (conversa_id, usuario_id) VALUES (?, ?)")->execute([$conversaId, $pid]);
            }
        }

        return Json::json($response, ['id' => $conversaId, 'tipo' => 'grupo', 'nome' => $nome], 201);
    }

    // PATCH /api/conversas/{id}
    public function editarConversa(Request $request, Response $response, array $args): Response
    {
        $papel      = $request->getAttribute('user_papel');
        $conversaId = (int) $args['id'];

        if ($papel !== 'admin') {
            return Json::erro($response, 'Apenas administradores podem editar grupos', 403);
        }

        $data = (array) $request->getParsedBody();
        $nome = trim($data['nome'] ?? '');

        if (!$nome) {
            return Json::erro($response, 'Nome é obrigatório');
        }

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("UPDATE conversas SET nome = ? WHERE id = ? AND tipo != 'privada'");
        $stmt->execute([$nome, $conversaId]);

        return Json::json($response, ['ok' => true, 'nome' => $nome]);
    }

    // GET /api/conversas/{id}
    public function obterConversa(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $conversaId = (int) $args['id'];
        $pdo = getDbConnection();

        $check = $pdo->prepare('SELECT 1 FROM participantes WHERE conversa_id = ? AND usuario_id = ?');
        $check->execute([$conversaId, $userId]);
        if (!$check->fetch()) {
            return Json::erro($response, 'Acesso negado', 403);
        }

        $temDescricao = $this->columnExists($pdo, 'conversas', 'descricao');
        $selectDescricao = $temDescricao ? 'c.descricao' : 'NULL AS descricao';

        $stmt = $pdo->prepare("\n            SELECT c.id, c.tipo, c.nome, {$selectDescricao}, c.criado_em,\n                   u.nome AS criado_por_nome,\n                   (SELECT COUNT(*) FROM participantes p WHERE p.conversa_id = c.id) AS participantes_count\n            FROM conversas c\n            LEFT JOIN usuarios u ON u.id = c.criado_por\n            WHERE c.id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$conversaId]);
        $conversa = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$conversa) {
            return Json::erro($response, 'Conversa não encontrada', 404);
        }

        return Json::json($response, $conversa);
    }

    // PATCH /api/conversas/{id}/descricao
    public function atualizarDescricaoConversa(Request $request, Response $response, array $args): Response
    {
        $papel = (string) $request->getAttribute('user_papel');
        if ($papel !== 'admin') {
            return Json::erro($response, 'Apenas administradores podem editar descrição do grupo', 403);
        }

        $conversaId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $pdo = getDbConnection();

        if (!$this->columnExists($pdo, 'conversas', 'descricao')) {
            return Json::erro($response, 'Coluna descricao ainda não existe no banco', 409);
        }

        $stmt = $pdo->prepare("UPDATE conversas SET descricao = ? WHERE id = ? AND tipo IN ('grupo','setor')");
        $stmt->execute([$descricao !== '' ? $descricao : null, $conversaId]);

        return Json::json($response, ['ok' => true, 'descricao' => $descricao]);
    }

    // DELETE /api/conversas/{id}
    public function deletarConversa(Request $request, Response $response, array $args): Response
    {
        $papel      = $request->getAttribute('user_papel');
        $conversaId = (int) $args['id'];

        if ($papel !== 'admin') {
            return Json::erro($response, 'Apenas administradores podem excluir grupos', 403);
        }

        $pdo   = getDbConnection();
        $check = $pdo->prepare("SELECT tipo FROM conversas WHERE id = ?");
        $check->execute([$conversaId]);
        $conversa = $check->fetch();

        if (!$conversa) {
            return Json::erro($response, 'Conversa não encontrada', 404);
        }

        if ($conversa['tipo'] === 'privada') {
            return Json::erro($response, 'Não é possível excluir conversas privadas');
        }

        $pdo->prepare("DELETE FROM conversas WHERE id = ?")->execute([$conversaId]);

        return Json::json($response, ['ok' => true]);
    }

    // POST /api/conversas/{id}/lida
    public function marcarComoLida(Request $request, Response $response, array $args): Response
    {
        $userId     = $request->getAttribute('user_id');
        $conversaId = (int) $args['id'];

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("UPDATE participantes SET ultima_leitura = NOW() WHERE conversa_id = ? AND usuario_id = ?");
        $stmt->execute([$conversaId, $userId]);

        return Json::json($response, ['ok' => true]);
    }

    // GET /api/conversas/{id}/participantes
    public function listarParticipantes(Request $request, Response $response, array $args): Response
    {
        $userId     = $request->getAttribute('user_id');
        $conversaId = (int) $args['id'];
        $pdo        = getDbConnection();

        // Verifica se tem acesso
        $check = $pdo->prepare("SELECT 1 FROM participantes WHERE conversa_id = ? AND usuario_id = ?");
        $check->execute([$conversaId, $userId]);
        if (!$check->fetch()) {
            return Json::erro($response, 'Acesso negado', 403);
        }

        $stmt = $pdo->prepare("
            SELECT u.id, u.nome, u.papel, s.nome AS setor
            FROM participantes p
            INNER JOIN usuarios u ON u.id = p.usuario_id
            LEFT JOIN setores s ON s.id = u.setor_id
            WHERE p.conversa_id = ?
            ORDER BY u.nome ASC
        ");
        $stmt->execute([$conversaId]);

        return Json::json($response, $stmt->fetchAll());
    }

    // POST /api/conversas/{id}/participantes
    public function adicionarParticipante(Request $request, Response $response, array $args): Response
    {
        $papel      = $request->getAttribute('user_papel');
        $conversaId = (int) $args['id'];
        $data       = (array) $request->getParsedBody();
        $usuarioId  = (int) ($data['usuario_id'] ?? 0);

        if ($papel !== 'admin') {
            return Json::erro($response, 'Apenas administradores podem adicionar participantes', 403);
        }

        if (!$usuarioId) {
            return Json::erro($response, 'usuario_id é obrigatório');
        }

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("INSERT IGNORE INTO participantes (conversa_id, usuario_id) VALUES (?, ?)");
        $stmt->execute([$conversaId, $usuarioId]);

        return Json::json($response, ['ok' => true]);
    }

    // DELETE /api/conversas/{id}/participantes/{uid}
    public function removerParticipante(Request $request, Response $response, array $args): Response
    {
        $papel      = $request->getAttribute('user_papel');
        $myId       = $request->getAttribute('user_id');
        $conversaId = (int) $args['id'];
        $usuarioId  = (int) $args['uid'];

        if ($papel !== 'admin') {
            return Json::erro($response, 'Apenas administradores podem remover participantes', 403);
        }

        if ($usuarioId === $myId) {
            return Json::erro($response, 'Você não pode se remover do grupo');
        }

        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM participantes WHERE conversa_id = ? AND usuario_id = ?");
        $stmt->execute([$conversaId, $usuarioId]);

        return Json::json($response, ['ok' => true]);
    }

    private function salvarArquivoMensagem($arquivo, int $conversaId): array
    {
        $mimesPermitidos = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/wav', 'audio/ogg',
            'video/mp4', 'video/webm', 'video/quicktime',
            'application/zip', 'application/x-rar-compressed', 'application/octet-stream',
        ];
        $extensoesPermitidas = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'csv', 'zip', 'rar', '7z',
            'mp3', 'wav', 'ogg', 'm4a', 'mp4', 'mov', 'webm',
        ];

        $max = (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760);
        if ($arquivo->getSize() > $max) {
            throw new \RuntimeException('Arquivo muito grande (máximo 10MB)');
        }

        $tmpPath = $arquivo->getStream()->getMetadata('uri');
        if (!$tmpPath || !is_file($tmpPath)) {
            throw new \RuntimeException('Arquivo temporario inválido');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $orig = (string) $arquivo->getClientFilename();
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        if (!in_array($mime, $mimesPermitidos, true) && !in_array($ext, $extensoesPermitidas, true)) {
            throw new \RuntimeException('Tipo de arquivo não permitido');
        }

        if ($ext === '') {
            $ext = $mime === 'application/pdf' ? 'pdf' : 'bin';
        }

        $novoNome = bin2hex(random_bytes(16)) . '.' . $ext;
        $destDir = __DIR__ . '/../../public/uploads/chat-mensagens/' . $conversaId . '/';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $arquivo->moveTo($destDir . $novoNome);

        return ['chat-mensagens/' . $conversaId . '/' . $novoNome, $orig !== '' ? $orig : $novoNome];
    }

    private function buscarMensagemPorId(\PDO $pdo, int $msgId): array
    {
        $nova = $pdo->prepare("
            SELECT m.id, m.conteudo, m.arquivo_path, m.arquivo_nome, m.criado_em,
                   u.id AS usuario_id, u.nome AS usuario_nome, m.conversa_id
            FROM mensagens m
            INNER JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.id = ?
        ");
        $nova->execute([$msgId]);
        return (array) $nova->fetch(\PDO::FETCH_ASSOC);
    }

    private function normalizarArquivosUpload($arquivosBrutos): array
    {
        if ($arquivosBrutos === null) {
            return [];
        }

        if (!is_array($arquivosBrutos)) {
            return [$arquivosBrutos];
        }

        $saida = [];
        $pilha = $arquivosBrutos;

        while (!empty($pilha)) {
            $item = array_pop($pilha);
            if (is_array($item)) {
                foreach ($item as $subItem) {
                    $pilha[] = $subItem;
                }
                continue;
            }

            if (is_object($item) && method_exists($item, 'getClientFilename') && method_exists($item, 'getError')) {
                $saida[] = $item;
            }
        }

        return $saida;
    }
}
