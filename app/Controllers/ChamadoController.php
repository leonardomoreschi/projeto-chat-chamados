<?php
declare(strict_types=1);
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Helpers\Response as Json;
use App\Support\NotificationCenter;
use App\Support\SchemaInspector;

class ChamadoController
{
    use SchemaInspector;

    private const PRIORIDADES_VALIDAS = ['baixa', 'media', 'alta', 'critica'];

    // POST /api/chamados
    public function criar(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data   = (array) $request->getParsedBody();
        $files  = $request->getUploadedFiles();

        $titulo     = trim($data['titulo'] ?? '');
        $descricao  = trim($data['descricao'] ?? '');
        $prioridade = $data['prioridade'] ?? 'media';

        // Validações
        if (!$titulo) {
            return Json::erro($response, 'Título é obrigatório');
        }

        if (!in_array($prioridade, self::PRIORIDADES_VALIDAS, true)) {
            $prioridade = 'media';
        }

        $pdo = getDbConnection();

        // Salva o chamado
        $stmt = $pdo->prepare("
            INSERT INTO chamados (usuario_id, titulo, descricao_rich, prioridade, status)
            VALUES (?, ?, ?, ?, 'aberto')
        ");
        $stmt->execute([$userId, $titulo, $descricao, $prioridade]);
        $chamadoId = (int) $pdo->lastInsertId();
        NotificationCenter::registrar($pdo, [
            'usuario_id' => $userId,
            'tipo' => 'chamado',
            'evento' => 'solicitado',
            'entidade' => 'chamado',
            'entidade_id' => $chamadoId,
            'chave_evento' => 'chamado:solicitado:' . $chamadoId . ':' . $userId,
            'titulo' => 'Chamado aberto',
            'mensagem' => 'Seu chamado #' . $chamadoId . ' foi registrado com sucesso.',
            'url' => '/meus-chamados',
            'status_destino' => 'aberto',
            'metadados' => [
                'chamado_id' => $chamadoId,
                'titulo' => $titulo,
            ],
        ]);

        // Processa anexos se existirem
        $anexosSalvos = [];
        $anexosErros = [];

        $anexos = $this->normalizarArquivosAnexos($files);
        if (!empty($anexos)) {
            foreach ($anexos as $arquivo) {
                $nomeArquivo = method_exists($arquivo, 'getClientFilename') ? (string) $arquivo->getClientFilename() : 'arquivo';

                if ($arquivo->getError() !== UPLOAD_ERR_OK) {
                    $anexosErros[] = [
                        'arquivo' => $nomeArquivo,
                        'erro' => 'Falha no upload (codigo ' . $arquivo->getError() . ')',
                    ];
                    continue;
                }

                try {
                    $path = $this->salvarArquivo($arquivo, $chamadoId);

                    $stmtAnexo = $pdo->prepare("
                        INSERT INTO chamado_anexos (chamado_id, arquivo_path, arquivo_nome, mime_type, tamanho_bytes)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmtAnexo->execute([
                        $chamadoId,
                        $path,
                        $arquivo->getClientFilename(),
                        $arquivo->getClientMediaType(),
                        $arquivo->getSize(),
                    ]);

                    $anexosSalvos[] = $arquivo->getClientFilename();
                } catch (\Exception $e) {
                    error_log('Erro ao salvar anexo: ' . $e->getMessage());
                    $anexosErros[] = [
                        'arquivo' => $nomeArquivo,
                        'erro' => $e->getMessage(),
                    ];
                }
            }
        }

        $novo = $pdo->prepare("
            SELECT c.*, u.nome AS usuario_nome
            FROM chamados c
            INNER JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.id = ?
        ");
        $novo->execute([$chamadoId]);

        return Json::json($response, [
            'chamado' => $novo->fetch(),
            'anexos'  => $anexosSalvos,
            'anexo_erros' => $anexosErros,
        ], 201);
    }

    private function normalizarArquivosAnexos(array $files): array
    {
        $candidatos = [];

        if (array_key_exists('anexos', $files)) {
            $candidatos[] = $files['anexos'];
        }

        if (array_key_exists('anexos[]', $files)) {
            $candidatos[] = $files['anexos[]'];
        }

        $saida = [];
        $pilha = $candidatos;

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

    // GET /api/chamados
    public function listar(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $papel  = $request->getAttribute('user_papel');
        $params = $request->getQueryParams();
        $status = $params['status'] ?? null;

        $pdo = getDbConnection();
        $this->garantirColunaResolvidoPor($pdo);

        $joinAnexo = "
            LEFT JOIN (
                SELECT ca.chamado_id, ca.arquivo_path, ca.arquivo_nome, ca.mime_type
                FROM chamado_anexos ca
                INNER JOIN (
                    SELECT chamado_id, MIN(id) AS primeiro_id
                    FROM chamado_anexos
                    GROUP BY chamado_id
                ) primeiro ON primeiro.primeiro_id = ca.id
            ) an ON an.chamado_id = c.id
        ";

        $temResolvidoPor = $this->columnExists($pdo, 'chamados', 'resolvido_por');
        $joinResolvido = $temResolvidoPor ? 'LEFT JOIN usuarios r ON r.id = c.resolvido_por' : '';
        $selectResolvido = $temResolvidoPor
            ? 'COALESCE(r.nome, a.nome) AS resolvido_por_nome,'
            : 'a.nome AS resolvido_por_nome,';

        if (in_array($papel, ['admin', 'ti'], true)) {
            $sql    = "SELECT c.*, u.nome AS usuario_nome,
                              a.nome AS atribuido_nome,
                              {$selectResolvido}
                              an.arquivo_path AS anexo_path,
                              an.arquivo_nome AS anexo_nome,
                              an.mime_type AS anexo_mime
                       FROM chamados c
                       INNER JOIN usuarios u ON u.id = c.usuario_id
                       LEFT JOIN usuarios a ON a.id = c.atribuido_a
                       {$joinResolvido}
                       {$joinAnexo}";
            $params = [];

            if ($status) {
                $sql .= " WHERE c.status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY
                        FIELD(c.prioridade, 'critica','alta','media','baixa'),
                        c.criado_em DESC";
        } else {
            $sql    = "SELECT c.*, u.nome AS usuario_nome,
                      a.nome AS atribuido_nome,
                      {$selectResolvido}
                      an.arquivo_path AS anexo_path,
                      an.arquivo_nome AS anexo_nome,
                      an.mime_type AS anexo_mime
                       FROM chamados c
                       INNER JOIN usuarios u ON u.id = c.usuario_id
                       LEFT JOIN usuarios a ON a.id = c.atribuido_a
                       {$joinResolvido}
                  {$joinAnexo}
                       WHERE c.usuario_id = ?";
            $params = [$userId];

            if ($status) {
                $sql .= " AND c.status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY c.criado_em DESC";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $linhas = $stmt->fetchAll();
        $linhas = $this->preencherResolvidoPorFallback($pdo, $linhas);

        return Json::json($response, $linhas);
    }

    // PATCH /api/chamados/{id}/status
    public function atualizarStatus(Request $request, Response $response, array $args): Response
    {
        $papel = $request->getAttribute('user_papel');
        $userId = (int) $request->getAttribute('user_id');

        if (!in_array($papel, ['admin', 'ti'], true)) {
            return Json::erro($response, 'Apenas TI pode atualizar chamados', 403);
        }

        $chamadoId = (int) $args['id'];
        $data      = (array) $request->getParsedBody();
        $novoStatus = $data['status'] ?? '';

        $statusValidos = ['aberto', 'classificado', 'em_andamento', 'resolvido', 'cancelado'];
        if (!in_array($novoStatus, $statusValidos, true)) {
            return Json::erro($response, 'Status inválido');
        }

        $pdo  = getDbConnection();
        $this->garantirColunaResolvidoPor($pdo);
        if ($novoStatus === 'resolvido' && $this->columnExists($pdo, 'chamados', 'resolvido_por')) {
            $stmt = $pdo->prepare("UPDATE chamados SET status = ?, resolvido_por = ?, atribuido_a = ? WHERE id = ?");
            $stmt->execute([$novoStatus, $userId, $userId, $chamadoId]);
        } else {
            if ($novoStatus === 'resolvido') {
                $stmt = $pdo->prepare("UPDATE chamados SET status = ?, atribuido_a = ? WHERE id = ?");
                $stmt->execute([$novoStatus, $userId, $chamadoId]);
            } else {
                $stmt = $pdo->prepare("UPDATE chamados SET status = ? WHERE id = ?");
                $stmt->execute([$novoStatus, $chamadoId]);
            }
        }

        return Json::json($response, ['ok' => true, 'status' => $novoStatus]);
    }

    // PATCH /api/chamados/{id}/cancelar
    public function cancelarMeuChamado(Request $request, Response $response, array $args): Response
    {
        $chamadoId = (int) ($args['id'] ?? 0);
        if ($chamadoId <= 0) {
            return Json::erro($response, 'ID de chamado invalido');
        }

        $userId = (int) $request->getAttribute('user_id');
        $pdo = getDbConnection();

        $stmt = $pdo->prepare('SELECT id, usuario_id, titulo, status FROM chamados WHERE id = ? LIMIT 1');
        $stmt->execute([$chamadoId]);
        $chamado = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$chamado) {
            return Json::erro($response, 'Chamado nao encontrado', 404);
        }

        if ((int) ($chamado['usuario_id'] ?? 0) !== $userId) {
            return Json::erro($response, 'Voce so pode cancelar seus proprios chamados', 403);
        }

        $statusAtual = (string) ($chamado['status'] ?? '');
        if (in_array($statusAtual, ['resolvido', 'cancelado'], true)) {
            return Json::erro($response, 'Somente chamados ativos podem ser cancelados', 409);
        }

        $stmtUpdate = $pdo->prepare('UPDATE chamados SET status = ? WHERE id = ?');
        $stmtUpdate->execute(['cancelado', $chamadoId]);
        NotificationCenter::registrar($pdo, [
            'usuario_id' => $userId,
            'tipo' => 'chamado',
            'evento' => 'cancelado',
            'entidade' => 'chamado',
            'entidade_id' => $chamadoId,
            'chave_evento' => 'chamado:cancelado:' . $chamadoId . ':' . $userId,
            'titulo' => 'Chamado cancelado',
            'mensagem' => 'Seu chamado #' . $chamadoId . ' foi cancelado.',
            'url' => '/meus-chamados',
            'status_origem' => $statusAtual,
            'status_destino' => 'cancelado',
            'metadados' => [
                'chamado_id' => $chamadoId,
                'titulo' => (string) ($chamado['titulo'] ?? ''),
            ],
        ]);

        return Json::json($response, ['ok' => true, 'status' => 'cancelado']);
    }

    // GET /api/chamados/{id}/anexos
    public function listarAnexos(Request $request, Response $response, array $args): Response
    {
        $chamadoId = (int) ($args['id'] ?? 0);
        if ($chamadoId <= 0) {
            return Json::erro($response, 'ID de chamado invalido');
        }

        $userId = (int) $request->getAttribute('user_id');
        $papel = (string) $request->getAttribute('user_papel');
        $pdo = getDbConnection();

        if (!in_array($papel, ['admin', 'ti'], true)) {
            $stmtOwner = $pdo->prepare('SELECT usuario_id FROM chamados WHERE id = ? LIMIT 1');
            $stmtOwner->execute([$chamadoId]);
            $ownerId = (int) ($stmtOwner->fetchColumn() ?: 0);
            if ($ownerId !== $userId) {
                return Json::erro($response, 'Acesso negado', 403);
            }
        }

        $stmt = $pdo->prepare(
            'SELECT id, arquivo_path, arquivo_nome, mime_type, tamanho_bytes, criado_em
             FROM chamado_anexos
             WHERE chamado_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$chamadoId]);

        return Json::json($response, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // GET /api/chamados/{id}/comentarios
    public function listarComentarios(Request $request, Response $response, array $args): Response
    {
        $chamadoId = (int) ($args['id'] ?? 0);
        if ($chamadoId <= 0) {
            return Json::erro($response, 'ID de chamado invalido');
        }

        $userId = (int) $request->getAttribute('user_id');
        $papel = (string) $request->getAttribute('user_papel');
        $pdo = getDbConnection();
        $this->garantirEstruturaComentarios($pdo);

        $stmtExiste = $pdo->prepare('SELECT usuario_id FROM chamados WHERE id = ? LIMIT 1');
        $stmtExiste->execute([$chamadoId]);
        $ownerId = (int) ($stmtExiste->fetchColumn() ?: 0);
        if ($ownerId === 0) {
            return Json::erro($response, 'Chamado nao encontrado', 404);
        }

        if (!in_array($papel, ['admin', 'ti'], true) && $ownerId !== $userId) {
            return Json::erro($response, 'Acesso negado', 403);
        }

        $stmtComentarios = $pdo->prepare(
            "SELECT cc.id, cc.chamado_id, cc.usuario_id, cc.conteudo, cc.tipo, cc.criado_em,
                    u.nome AS usuario_nome
             FROM chamado_comentarios cc
             INNER JOIN usuarios u ON u.id = cc.usuario_id
             WHERE cc.chamado_id = ?
             ORDER BY cc.id ASC"
        );
        $stmtComentarios->execute([$chamadoId]);
        $comentarios = $stmtComentarios->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($comentarios)) {
            return Json::json($response, []);
        }

        $comentarioIds = array_map(static fn(array $row): int => (int) $row['id'], $comentarios);
        $placeholders = implode(',', array_fill(0, count($comentarioIds), '?'));
        $stmtAnexos = $pdo->prepare(
            "SELECT id, comentario_id, arquivo_path, arquivo_nome, mime_type, tamanho_bytes, criado_em
             FROM chamado_comentario_anexos
             WHERE comentario_id IN ({$placeholders})
             ORDER BY id ASC"
        );
        $stmtAnexos->execute($comentarioIds);
        $anexosRows = $stmtAnexos->fetchAll(\PDO::FETCH_ASSOC);

        $anexosPorComentario = [];
        foreach ($anexosRows as $anexo) {
            $cid = (int) ($anexo['comentario_id'] ?? 0);
            if (!isset($anexosPorComentario[$cid])) {
                $anexosPorComentario[$cid] = [];
            }
            $anexosPorComentario[$cid][] = $anexo;
        }

        foreach ($comentarios as &$comentario) {
            $cid = (int) ($comentario['id'] ?? 0);
            $comentario['anexos'] = $anexosPorComentario[$cid] ?? [];
        }
        unset($comentario);

        return Json::json($response, $comentarios);
    }

    // POST /api/chamados/{id}/comentarios
    public function adicionarComentario(Request $request, Response $response, array $args): Response
    {
        $papel = (string) $request->getAttribute('user_papel');
        if (!in_array($papel, ['admin', 'ti'], true)) {
            return Json::erro($response, 'Apenas TI pode comentar chamados', 403);
        }

        $chamadoId = (int) ($args['id'] ?? 0);
        if ($chamadoId <= 0) {
            return Json::erro($response, 'ID de chamado invalido');
        }

        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();

        $conteudo = trim((string) ($data['comentario'] ?? ''));
        $tipo = (string) ($data['tipo'] ?? 'comentario');
        $tipo = $tipo === 'resolucao' ? 'resolucao' : 'comentario';
        $anexos = $this->normalizarArquivosAnexos($files);

        if ($conteudo === '' && empty($anexos)) {
            return Json::erro($response, 'Informe um comentario ou adicione ao menos um anexo');
        }

        $pdo = getDbConnection();
        $this->garantirEstruturaComentarios($pdo);

        $stmtExiste = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE id = ?');
        $stmtExiste->execute([$chamadoId]);
        if ((int) $stmtExiste->fetchColumn() === 0) {
            return Json::erro($response, 'Chamado nao encontrado', 404);
        }

        $resultado = $this->salvarComentarioComAnexos($pdo, $chamadoId, $userId, $conteudo, $tipo, $anexos);

        return Json::json($response, [
            'ok' => true,
            'comentario_id' => $resultado['comentario_id'],
            'anexos_salvos' => $resultado['anexos_salvos'],
            'anexo_erros' => $resultado['anexo_erros'],
        ], 201);
    }

    // DELETE /api/chamados/{id}/comentarios/{comentarioId}
    public function removerComentario(Request $request, Response $response, array $args): Response
    {
        $papel = (string) $request->getAttribute('user_papel');
        if (!in_array($papel, ['admin', 'ti'], true)) {
            return Json::erro($response, 'Apenas TI pode excluir comentarios', 403);
        }

        $chamadoId = (int) ($args['id'] ?? 0);
        $comentarioId = (int) ($args['comentarioId'] ?? 0);
        if ($chamadoId <= 0 || $comentarioId <= 0) {
            return Json::erro($response, 'Identificadores invalidos');
        }

        $pdo = getDbConnection();
        $this->garantirEstruturaComentarios($pdo);

        $stmtChamado = $pdo->prepare('SELECT status FROM chamados WHERE id = ? LIMIT 1');
        $stmtChamado->execute([$chamadoId]);
        $status = (string) ($stmtChamado->fetchColumn() ?: '');
        if ($status === 'resolvido') {
            return Json::erro($response, 'Comentarios de chamados finalizados sao somente leitura', 409);
        }

        $stmtComentario = $pdo->prepare('SELECT id FROM chamado_comentarios WHERE id = ? AND chamado_id = ? LIMIT 1');
        $stmtComentario->execute([$comentarioId, $chamadoId]);
        if (!$stmtComentario->fetchColumn()) {
            return Json::erro($response, 'Comentario nao encontrado', 404);
        }

        $stmtDelete = $pdo->prepare('DELETE FROM chamado_comentarios WHERE id = ? AND chamado_id = ?');
        $stmtDelete->execute([$comentarioId, $chamadoId]);

        return Json::json($response, ['ok' => true]);
    }

    // GET /api/chamados/relatorio
    public function relatorio(Request $request, Response $response): Response
    {
        $papel = (string) $request->getAttribute('user_papel');
        if (!in_array($papel, ['admin', 'ti'], true)) {
            return Json::erro($response, 'Apenas TI/Admin pode visualizar relatorios', 403);
        }

        $pdo = getDbConnection();
        $this->garantirColunaResolvidoPor($pdo);

        return Json::json($response, $this->obterDadosRelatorio($pdo));
    }

    // GET /api/chamados/relatorio/csv
    public function exportarRelatorioCsv(Request $request, Response $response): Response
    {
        $papel = (string) $request->getAttribute('user_papel');
        if (!in_array($papel, ['admin', 'ti'], true)) {
            return Json::erro($response, 'Apenas TI/Admin pode exportar relatorios', 403);
        }

        $pdo = getDbConnection();
        $this->garantirColunaResolvidoPor($pdo);

        $dados = $this->obterDadosRelatorio($pdo);
        $csv = $this->montarCsvRelatorio($dados);

        $nomeArquivo = 'relatorio-chamados-' . date('Ymd-His') . '.csv';
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $nomeArquivo . '"');
    }

    private function obterDadosRelatorio(\PDO $pdo): array
    {
        $this->garantirColunaResolvidoPor($pdo);

        $resumoStmt = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('aberto','classificado','em_andamento') THEN 1 ELSE 0 END) AS abertos,
                SUM(CASE WHEN status = 'resolvido' THEN 1 ELSE 0 END) AS resolvidos,
                SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
                AVG(CASE WHEN status = 'resolvido' THEN TIMESTAMPDIFF(MINUTE, criado_em, atualizado_em) END) AS tempo_medio_minutos
             FROM chamados"
        );
        $resumo = $resumoStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $categoriasStmt = $pdo->query(
            "SELECT
                COALESCE(NULLIF(categoria, ''), 'Nao informada') AS categoria,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('aberto','classificado','em_andamento') THEN 1 ELSE 0 END) AS abertos,
                SUM(CASE WHEN status = 'resolvido' THEN 1 ELSE 0 END) AS resolvidos,
                AVG(CASE WHEN status = 'resolvido' THEN TIMESTAMPDIFF(MINUTE, criado_em, atualizado_em) END) AS tempo_medio_minutos
             FROM chamados
             GROUP BY COALESCE(NULLIF(categoria, ''), 'Nao informada')
             ORDER BY total DESC"
        );
        $categorias = $categoriasStmt->fetchAll(\PDO::FETCH_ASSOC);

        $subcategoriasStmt = $pdo->query(
            "SELECT
                COALESCE(NULLIF(categoria, ''), 'Nao informada') AS categoria,
                COALESCE(NULLIF(subcategoria, ''), 'Nao informada') AS subcategoria,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('aberto','classificado','em_andamento') THEN 1 ELSE 0 END) AS abertos,
                SUM(CASE WHEN status = 'resolvido' THEN 1 ELSE 0 END) AS resolvidos,
                AVG(CASE WHEN status = 'resolvido' THEN TIMESTAMPDIFF(MINUTE, criado_em, atualizado_em) END) AS tempo_medio_minutos
             FROM chamados
             GROUP BY COALESCE(NULLIF(categoria, ''), 'Nao informada'), COALESCE(NULLIF(subcategoria, ''), 'Nao informada')
             ORDER BY total DESC"
        );
        $subcategorias = $subcategoriasStmt->fetchAll(\PDO::FETCH_ASSOC);

        $solicitantesStmt = $pdo->query(
            "SELECT
                u.id AS usuario_id,
                u.nome AS usuario_nome,
                COUNT(*) AS total,
                SUM(CASE WHEN c.status IN ('aberto','classificado','em_andamento') THEN 1 ELSE 0 END) AS abertos,
                SUM(CASE WHEN c.status = 'resolvido' THEN 1 ELSE 0 END) AS resolvidos
             FROM chamados c
             INNER JOIN usuarios u ON u.id = c.usuario_id
             GROUP BY u.id, u.nome
             ORDER BY total DESC"
        );
        $solicitantes = $solicitantesStmt->fetchAll(\PDO::FETCH_ASSOC);

        $finalizadoresStmt = $pdo->query(
            "SELECT
                COALESCE(r.id, a.id) AS usuario_id,
                COALESCE(r.nome, a.nome, 'Nao informado') AS usuario_nome,
                COUNT(*) AS total_resolvidos,
                AVG(TIMESTAMPDIFF(MINUTE, c.criado_em, c.atualizado_em)) AS tempo_medio_minutos
             FROM chamados c
             LEFT JOIN usuarios r ON r.id = c.resolvido_por
             LEFT JOIN usuarios a ON a.id = c.atribuido_a
             WHERE c.status = 'resolvido'
             GROUP BY COALESCE(r.id, a.id), COALESCE(r.nome, a.nome, 'Nao informado')
             ORDER BY total_resolvidos DESC"
        );
        $finalizadores = $finalizadoresStmt->fetchAll(\PDO::FETCH_ASSOC);

        $serieStmt = $pdo->query(
            "SELECT
                DATE(dia) AS dia,
                SUM(abertos) AS abertos,
                SUM(resolvidos) AS resolvidos
             FROM (
                SELECT DATE(criado_em) AS dia, COUNT(*) AS abertos, 0 AS resolvidos
                FROM chamados
                WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(criado_em)
                UNION ALL
                SELECT DATE(atualizado_em) AS dia, 0 AS abertos, COUNT(*) AS resolvidos
                FROM chamados
                WHERE status = 'resolvido'
                  AND atualizado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(atualizado_em)
             ) t
             GROUP BY DATE(dia)
             ORDER BY DATE(dia) ASC"
        );
        $serie30dias = $serieStmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'resumo' => [
                'total' => (int) ($resumo['total'] ?? 0),
                'abertos' => (int) ($resumo['abertos'] ?? 0),
                'resolvidos' => (int) ($resumo['resolvidos'] ?? 0),
                'cancelados' => (int) ($resumo['cancelados'] ?? 0),
                'tempo_medio_minutos' => (float) ($resumo['tempo_medio_minutos'] ?? 0),
            ],
            'categorias' => $categorias,
            'subcategorias' => $subcategorias,
            'solicitantes' => $solicitantes,
            'finalizadores' => $finalizadores,
            'serie_30_dias' => $serie30dias,
        ];
    }

    private function montarCsvRelatorio(array $dados): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Secao', 'Campo', 'Valor'], ';');

        $resumo = (array) ($dados['resumo'] ?? []);
        fputcsv($stream, ['Resumo', 'Total', (string) ((int) ($resumo['total'] ?? 0))], ';');
        fputcsv($stream, ['Resumo', 'Abertos', (string) ((int) ($resumo['abertos'] ?? 0))], ';');
        fputcsv($stream, ['Resumo', 'Resolvidos', (string) ((int) ($resumo['resolvidos'] ?? 0))], ';');
        fputcsv($stream, ['Resumo', 'Cancelados', (string) ((int) ($resumo['cancelados'] ?? 0))], ';');
        fputcsv($stream, ['Resumo', 'Tempo medio minutos', (string) ((float) ($resumo['tempo_medio_minutos'] ?? 0))], ';');

        fputcsv($stream, [], ';');
        fputcsv($stream, ['Categorias', 'Categoria', 'Total', 'Abertos', 'Resolvidos', 'Tempo medio minutos'], ';');
        foreach ((array) ($dados['categorias'] ?? []) as $item) {
            fputcsv($stream, [
                'Categorias',
                (string) ($item['categoria'] ?? 'Nao informada'),
                (string) ((int) ($item['total'] ?? 0)),
                (string) ((int) ($item['abertos'] ?? 0)),
                (string) ((int) ($item['resolvidos'] ?? 0)),
                (string) ((float) ($item['tempo_medio_minutos'] ?? 0)),
            ], ';');
        }

        fputcsv($stream, [], ';');
        fputcsv($stream, ['Subcategorias', 'Categoria', 'Subcategoria', 'Total', 'Abertos', 'Resolvidos', 'Tempo medio minutos'], ';');
        foreach ((array) ($dados['subcategorias'] ?? []) as $item) {
            fputcsv($stream, [
                'Subcategorias',
                (string) ($item['categoria'] ?? 'Nao informada'),
                (string) ($item['subcategoria'] ?? 'Nao informada'),
                (string) ((int) ($item['total'] ?? 0)),
                (string) ((int) ($item['abertos'] ?? 0)),
                (string) ((int) ($item['resolvidos'] ?? 0)),
                (string) ((float) ($item['tempo_medio_minutos'] ?? 0)),
            ], ';');
        }

        fputcsv($stream, [], ';');
        fputcsv($stream, ['Solicitantes', 'Usuario', 'Total', 'Abertos', 'Resolvidos'], ';');
        foreach ((array) ($dados['solicitantes'] ?? []) as $item) {
            fputcsv($stream, [
                'Solicitantes',
                (string) ($item['usuario_nome'] ?? 'Nao informado'),
                (string) ((int) ($item['total'] ?? 0)),
                (string) ((int) ($item['abertos'] ?? 0)),
                (string) ((int) ($item['resolvidos'] ?? 0)),
            ], ';');
        }

        fputcsv($stream, [], ';');
        fputcsv($stream, ['Finalizadores', 'Usuario', 'Resolvidos', 'Tempo medio minutos'], ';');
        foreach ((array) ($dados['finalizadores'] ?? []) as $item) {
            fputcsv($stream, [
                'Finalizadores',
                (string) ($item['usuario_nome'] ?? 'Nao informado'),
                (string) ((int) ($item['total_resolvidos'] ?? 0)),
                (string) ((float) ($item['tempo_medio_minutos'] ?? 0)),
            ], ';');
        }

        fputcsv($stream, [], ';');
        fputcsv($stream, ['Serie 30 dias', 'Dia', 'Abertos', 'Resolvidos'], ';');
        foreach ((array) ($dados['serie_30_dias'] ?? []) as $item) {
            fputcsv($stream, [
                'Serie 30 dias',
                (string) ($item['dia'] ?? ''),
                (string) ((int) ($item['abertos'] ?? 0)),
                (string) ((int) ($item['resolvidos'] ?? 0)),
            ], ';');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    // Salva arquivo de forma segura
    private function salvarArquivo($arquivo, int $chamadoId): string
    {
        $mimesPermitidos = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'image/jpg', 'image/heic', 'image/heif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/octet-stream',
            'application/x-msdownload',
            'application/x-dosexec',
            'model/step',
            'application/step',
        ];
        $extensoesPermitidas = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
            'pdf', 'doc', 'docx', 'txt',
            'step', 'stp', 'exe',
        ];

        $tamanhoMax = (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760);

        if ($arquivo->getSize() > $tamanhoMax) {
            throw new \RuntimeException('Arquivo muito grande (máximo 10MB)');
        }

        $tmpPath  = $arquivo->getStream()->getMetadata('uri');
        if (!$tmpPath || !is_file($tmpPath)) {
            throw new \RuntimeException('Arquivo temporario invalido para upload');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($tmpPath);

        $ext      = strtolower(pathinfo($arquivo->getClientFilename(), PATHINFO_EXTENSION));

        if (!in_array($mimeReal, $mimesPermitidos, true) && !in_array($ext, $extensoesPermitidas, true)) {
            throw new \RuntimeException("Tipo não permitido: {$mimeReal}");
        }

        if ($ext === '') {
            $mapaExtensao = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'text/plain' => 'txt',
                'application/x-msdownload' => 'exe',
                'application/x-dosexec' => 'exe',
                'application/octet-stream' => 'bin',
            ];
            $ext = $mapaExtensao[$mimeReal] ?? 'bin';
        }

        $novoNome = bin2hex(random_bytes(16)) . '.' . $ext;
        $destDir  = __DIR__ . '/../../public/uploads/chamados/' . $chamadoId . '/';

        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $caminhoCompleto = $destDir . $novoNome;
        $arquivo->moveTo($caminhoCompleto);

        return 'chamados/' . $chamadoId . '/' . $novoNome;
    }

    public function classificar(Request $request, Response $response, array $args): Response
    {
        try {
            $papel = (string) $request->getAttribute('user_papel');
            if (!in_array($papel, ['admin', 'ti'], true)) {
                return Json::erro($response, 'Apenas TI/Admin pode classificar chamados', 403);
            }

            $id = (int) $args['id'];
            $data = (array) $request->getParsedBody();

            $prioridade   = (string) ($data['prioridade'] ?? 'media');
            $categoria    = trim((string) ($data['categoria'] ?? 'Geral'));
            $subcategoria = trim((string) ($data['subcategoria'] ?? 'Geral'));

            if (!in_array($prioridade, self::PRIORIDADES_VALIDAS, true)) {
                $prioridade = 'media';
            }
            if ($categoria === '') {
                $categoria = 'Geral';
            }
            if ($subcategoria === '') {
                $subcategoria = 'Geral';
            }

            $pdo = getDbConnection();

            $stmt = $pdo->prepare("
                UPDATE chamados 
                SET prioridade = ?, 
                    categoria = ?, 
                    subcategoria = ?, 
                    status = 'classificado' 
                WHERE id = ?
            ");
            
            $stmt->execute([$prioridade, $categoria, $subcategoria, $id]);

            $this->upsertTaxonomia($pdo, $categoria, $subcategoria);

            return Json::json($response, ['status' => 'success', 'message' => 'Classificado com sucesso']);
            
        } catch (\Exception $e) {
            error_log("Erro na classificação: " . $e->getMessage());
            return Json::json($response, ['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function atualizarClassificacao(Request $request, Response $response, array $args): Response
    {
        try {
            $papel = (string) $request->getAttribute('user_papel');
            if (!in_array($papel, ['admin', 'ti'], true)) {
                return Json::erro($response, 'Apenas TI/Admin pode editar classificacao', 403);
            }

            $id = (int) $args['id'];
            $data = (array) $request->getParsedBody();

            $prioridade = (string) ($data['prioridade'] ?? 'media');
            $categoria = trim((string) ($data['categoria'] ?? 'Geral'));
            $subcategoria = trim((string) ($data['subcategoria'] ?? 'Geral'));

            if (!in_array($prioridade, self::PRIORIDADES_VALIDAS, true)) {
                $prioridade = 'media';
            }
            if ($categoria === '') {
                $categoria = 'Geral';
            }
            if ($subcategoria === '') {
                $subcategoria = 'Geral';
            }

            $pdo = getDbConnection();

            if ($this->columnExists($pdo, 'chamados', 'categoria') && $this->columnExists($pdo, 'chamados', 'subcategoria')) {
                $stmt = $pdo->prepare("\n                    UPDATE chamados\n                    SET prioridade = ?,\n                        categoria = ?,\n                        subcategoria = ?\n                    WHERE id = ?\n                ");
                $stmt->execute([$prioridade, $categoria, $subcategoria, $id]);
                $this->upsertTaxonomia($pdo, $categoria, $subcategoria);
            } else {
                $stmt = $pdo->prepare('UPDATE chamados SET prioridade = ? WHERE id = ?');
                $stmt->execute([$prioridade, $id]);
            }

            return Json::json($response, ['status' => 'success', 'message' => 'Classificacao atualizada']);
        } catch (\Exception $e) {
            error_log('Erro ao atualizar classificacao: ' . $e->getMessage());
            return Json::erro($response, 'Erro ao atualizar classificacao', 500);
        }
    }

    public function listarTaxonomias(Request $request, Response $response): Response
    {
        try {
            $pdo = getDbConnection();

            if (!$this->tableExists($pdo, 'chamado_taxonomias')) {
                return Json::json($response, ['categorias' => $this->categoriasPadrao()]);
            }

            $stmt = $pdo->query("\n                SELECT categoria, subcategoria\n                FROM chamado_taxonomias\n                WHERE ativo = 1\n                ORDER BY categoria ASC, subcategoria ASC\n            ");

            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $categoria = (string) $row['categoria'];
                $sub = (string) $row['subcategoria'];

                if (!isset($map[$categoria])) {
                    $map[$categoria] = [];
                }
                $map[$categoria][] = $sub;
            }

            return Json::json($response, ['categorias' => $map]);
        } catch (\Exception $e) {
            error_log('Erro ao listar taxonomias: ' . $e->getMessage());
            return Json::erro($response, 'Erro ao listar taxonomias', 500);
        }
    }

    public function salvarTaxonomia(Request $request, Response $response): Response
    {
        try {
            $papel = (string) $request->getAttribute('user_papel');
            if (!in_array($papel, ['admin', 'ti'], true)) {
                return Json::erro($response, 'Apenas TI/Admin pode gerenciar categorias', 403);
            }

            $data = (array) $request->getParsedBody();
            $categoria = trim((string) ($data['categoria'] ?? ''));
            $subcategoria = trim((string) ($data['subcategoria'] ?? ''));

            if ($categoria === '' || $subcategoria === '') {
                return Json::erro($response, 'categoria e subcategoria sao obrigatorias');
            }

            $pdo = getDbConnection();
            if (!$this->tableExists($pdo, 'chamado_taxonomias')) {
                return Json::erro($response, 'Tabela de taxonomias indisponivel. Rode a migracao.', 409);
            }
            $this->upsertTaxonomia($pdo, $categoria, $subcategoria);

            return Json::json($response, ['ok' => true], 201);
        } catch (\Exception $e) {
            error_log('Erro ao salvar taxonomia: ' . $e->getMessage());
            return Json::erro($response, 'Erro ao salvar taxonomia', 500);
        }
    }

    public function removerTaxonomia(Request $request, Response $response, array $args): Response
    {
        try {
            $papel = (string) $request->getAttribute('user_papel');
            if (!in_array($papel, ['admin', 'ti'], true)) {
                return Json::erro($response, 'Apenas TI/Admin pode gerenciar categorias', 403);
            }

            $id = (int) $args['id'];
            if ($id <= 0) {
                return Json::erro($response, 'ID invalido');
            }

            $pdo = getDbConnection();
            if (!$this->tableExists($pdo, 'chamado_taxonomias')) {
                return Json::erro($response, 'Tabela de taxonomias indisponivel. Rode a migracao.', 409);
            }
            $stmt = $pdo->prepare('UPDATE chamado_taxonomias SET ativo = 0 WHERE id = ?');
            $stmt->execute([$id]);

            return Json::json($response, ['ok' => true]);
        } catch (\Exception $e) {
            error_log('Erro ao remover taxonomia: ' . $e->getMessage());
            return Json::erro($response, 'Erro ao remover taxonomia', 500);
        }
    }

    public function listarTaxonomiasDetalhe(Request $request, Response $response): Response
    {
        try {
            $pdo = getDbConnection();
            if (!$this->tableExists($pdo, 'chamado_taxonomias')) {
                return Json::json($response, []);
            }
            $stmt = $pdo->query("\n                SELECT id, categoria, subcategoria\n                FROM chamado_taxonomias\n                WHERE ativo = 1\n                ORDER BY categoria ASC, subcategoria ASC\n            ");

            return Json::json($response, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            error_log('Erro ao listar taxonomias detalhadas: ' . $e->getMessage());
            return Json::erro($response, 'Erro ao listar taxonomias', 500);
        }
    }

    public function finalizar(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $finalizadorId = (int) $request->getAttribute('user_id');
            $papel = (string) $request->getAttribute('user_papel');
            $data = (array) $request->getParsedBody();
            $files = $request->getUploadedFiles();
            $comentarioResolucao = trim((string) ($data['comentario'] ?? ''));
            $anexosResolucao = $this->normalizarArquivosAnexos($files);

            if (!in_array($papel, ['admin', 'ti'], true)) {
                return Json::erro($response, 'Apenas TI pode finalizar chamados', 403);
            }

            $pdo = getDbConnection();
            $this->garantirColunaResolvidoPor($pdo);

            // 1. Pega informações do chamado e do finalizador
            $stmtBusca = $pdo->prepare("SELECT c.titulo, c.usuario_id, c.status FROM chamados c WHERE c.id = ?");
            $stmtBusca->execute([$id]);
            $chamado = $stmtBusca->fetch(\PDO::FETCH_ASSOC);

            if (!$chamado) {
                throw new \Exception("Chamado não encontrado.");
            }

            $stmtFinalizador = $pdo->prepare("SELECT u.nome FROM usuarios u WHERE u.id = ?");
            $stmtFinalizador->execute([$finalizadorId]);
            $finalizadorRow = $stmtFinalizador->fetch(\PDO::FETCH_ASSOC);
            $finalizadorNome = $finalizadorRow ? trim((string) $finalizadorRow['nome']) : 'Equipe de TI';

            // 2. Atualiza o status
            if ($this->columnExists($pdo, 'chamados', 'resolvido_por')) {
                $stmtUp = $pdo->prepare("UPDATE chamados SET status = 'resolvido', resolvido_por = ?, atribuido_a = ? WHERE id = ?");
                $stmtUp->execute([$finalizadorId, $finalizadorId, $id]);
                NotificationCenter::registrar($pdo, [
                    'usuario_id' => (int) $chamado['usuario_id'],
                    'tipo' => 'chamado',
                    'evento' => 'resolvido',
                    'entidade' => 'chamado',
                    'entidade_id' => $id,
                    'chave_evento' => 'chamado:resolvido:' . $id . ':' . (int) $chamado['usuario_id'],
                    'titulo' => 'Chamado resolvido',
                    'mensagem' => 'Seu chamado #' . $id . ' foi finalizado pela equipe de TI.',
                    'url' => '/meus-chamados',
                    'status_origem' => (string) ($chamado['status'] ?? 'em_andamento'),
                    'status_destino' => 'resolvido',
                    'metadados' => [
                        'chamado_id' => $id,
                        'titulo' => (string) $chamado['titulo'],
                        'finalizador_id' => $finalizadorId,
                        'finalizador_nome' => $finalizadorNome,
                    ],
                ]);
            } else {
                $stmtUp = $pdo->prepare("UPDATE chamados SET status = 'resolvido', atribuido_a = ? WHERE id = ?");
                $stmtUp->execute([$finalizadorId, $id]);
            }

            if ($comentarioResolucao !== '' || !empty($anexosResolucao)) {
                try {
                    $this->garantirEstruturaComentarios($pdo);
                    $this->salvarComentarioComAnexos(
                        $pdo,
                        $id,
                        $finalizadorId,
                        $comentarioResolucao,
                        'resolucao',
                        $anexosResolucao
                    );
                } catch (\Throwable $eComentario) {
                    error_log('Aviso: nao foi possivel salvar comentario de resolucao: ' . $eComentario->getMessage());
                }
            }

            // 3. Bloco isolado para a mensagem (se falhar, o chamado fecha mesmo assim)
            try {
                $solicitanteId = (int) $chamado['usuario_id'];
                if ($solicitanteId !== $finalizadorId) {
                    $conversaId = $this->obterOuCriarConversaPrivada($pdo, $finalizadorId, $solicitanteId);
                    $mensagemAutomatica = "Chamado #{$id} (\"{$chamado['titulo']}\") foi finalizado por {$finalizadorNome}.";
                    $stmtMsg = $pdo->prepare("INSERT INTO mensagens (conversa_id, usuario_id, conteudo, criado_em) VALUES (?, ?, ?, NOW())");
                    $stmtMsg->execute([$conversaId, $finalizadorId, $mensagemAutomatica]);
                }
            } catch (\Exception $eMsg) {
                error_log("Aviso: Falha ao enviar mensagem de chat, mas chamado foi fechado. Erro: " . $eMsg->getMessage());
            }

            $resolvidoPorNome = $finalizadorNome;
            if ($this->columnExists($pdo, 'chamados', 'resolvido_por')) {
                $stmtConfirma = $pdo->prepare(
                    "SELECT c.resolvido_por, COALESCE(r.nome, a.nome) AS resolvido_por_nome
                     FROM chamados c
                     LEFT JOIN usuarios r ON r.id = c.resolvido_por
                     LEFT JOIN usuarios a ON a.id = c.atribuido_a
                     WHERE c.id = ?
                     LIMIT 1"
                );
                $stmtConfirma->execute([$id]);
                $confirmacao = $stmtConfirma->fetch(\PDO::FETCH_ASSOC);
                if ($confirmacao && !empty($confirmacao['resolvido_por_nome'])) {
                    $resolvidoPorNome = (string) $confirmacao['resolvido_por_nome'];
                }
            }

            return Json::json($response, [
                'status' => 'success',
                'chamado_id' => $id,
                'resolvido_por_nome' => $resolvidoPorNome,
            ]);
            
        } catch (\Exception $e) {
            error_log("Erro ao finalizar: " . $e->getMessage());
            return Json::json($response, ['error' => $e->getMessage()], 500);
        }
    }

    private function obterOuCriarConversaPrivada(\PDO $pdo, int $usuarioA, int $usuarioB): int
    {
        $check = $pdo->prepare("\n            SELECT c.id FROM conversas c\n            INNER JOIN participantes p1 ON p1.conversa_id = c.id AND p1.usuario_id = ?\n            INNER JOIN participantes p2 ON p2.conversa_id = c.id AND p2.usuario_id = ?\n            WHERE c.tipo = 'privada'\n            LIMIT 1\n        ");
        $check->execute([$usuarioA, $usuarioB]);
        $existente = $check->fetch(\PDO::FETCH_ASSOC);

        if ($existente && isset($existente['id'])) {
            return (int) $existente['id'];
        }

        $stmt = $pdo->prepare("INSERT INTO conversas (tipo, nome, criado_por) VALUES ('privada', NULL, ?)");
        $stmt->execute([$usuarioA]);
        $conversaId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)")->execute([$conversaId, $usuarioA]);
        $pdo->prepare("INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)")->execute([$conversaId, $usuarioB]);

        return $conversaId;
    }

    private function upsertTaxonomia(\PDO $pdo, string $categoria, string $subcategoria): void
    {
        if (!$this->tableExists($pdo, 'chamado_taxonomias')) {
            return;
        }

        $stmt = $pdo->prepare("\n            INSERT INTO chamado_taxonomias (categoria, subcategoria, ativo)\n            VALUES (?, ?, 1)\n            ON DUPLICATE KEY UPDATE ativo = 1\n        ");
        $stmt->execute([$categoria, $subcategoria]);
    }

    private function salvarComentarioComAnexos(
        \PDO $pdo,
        int $chamadoId,
        int $userId,
        string $conteudo,
        string $tipo,
        array $anexos
    ): array {
        $stmtComentario = $pdo->prepare(
            'INSERT INTO chamado_comentarios (chamado_id, usuario_id, conteudo, tipo) VALUES (?, ?, ?, ?)'
        );
        $stmtComentario->execute([
            $chamadoId,
            $userId,
            $conteudo !== '' ? $conteudo : null,
            $tipo === 'resolucao' ? 'resolucao' : 'comentario',
        ]);
        $comentarioId = (int) $pdo->lastInsertId();

        $anexosSalvos = [];
        $anexoErros = [];

        foreach ($anexos as $arquivo) {
            $nomeArquivo = method_exists($arquivo, 'getClientFilename') ? (string) $arquivo->getClientFilename() : 'arquivo';

            if ($arquivo->getError() !== UPLOAD_ERR_OK) {
                $anexoErros[] = [
                    'arquivo' => $nomeArquivo,
                    'erro' => 'Falha no upload (codigo ' . $arquivo->getError() . ')',
                ];
                continue;
            }

            try {
                $path = $this->salvarArquivoComentario($arquivo, $chamadoId, $comentarioId);

                $stmtAnexo = $pdo->prepare(
                    'INSERT INTO chamado_comentario_anexos (comentario_id, arquivo_path, arquivo_nome, mime_type, tamanho_bytes)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmtAnexo->execute([
                    $comentarioId,
                    $path,
                    (string) $arquivo->getClientFilename(),
                    (string) $arquivo->getClientMediaType(),
                    (int) $arquivo->getSize(),
                ]);

                $anexosSalvos[] = (string) $arquivo->getClientFilename();
            } catch (\Throwable $e) {
                error_log('Erro ao salvar anexo de comentario: ' . $e->getMessage());
                $anexoErros[] = [
                    'arquivo' => $nomeArquivo,
                    'erro' => $e->getMessage(),
                ];
            }
        }

        return [
            'comentario_id' => $comentarioId,
            'anexos_salvos' => $anexosSalvos,
            'anexo_erros' => $anexoErros,
        ];
    }

    private function salvarArquivoComentario($arquivo, int $chamadoId, int $comentarioId): string
    {
        $mimesPermitidos = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'image/jpg', 'image/heic', 'image/heif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/octet-stream',
            'application/x-msdownload',
            'application/x-dosexec',
            'model/step',
            'application/step',
        ];
        $extensoesPermitidas = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
            'pdf', 'doc', 'docx', 'txt',
            'step', 'stp', 'exe',
        ];

        $tamanhoMax = (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760);
        if ($arquivo->getSize() > $tamanhoMax) {
            throw new \RuntimeException('Arquivo muito grande (maximo 10MB)');
        }

        $tmpPath = $arquivo->getStream()->getMetadata('uri');
        if (!$tmpPath || !is_file($tmpPath)) {
            throw new \RuntimeException('Arquivo temporario invalido para upload');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($tmpPath);
        $ext = strtolower(pathinfo((string) $arquivo->getClientFilename(), PATHINFO_EXTENSION));

        if (!in_array($mimeReal, $mimesPermitidos, true) && !in_array($ext, $extensoesPermitidas, true)) {
            throw new \RuntimeException('Tipo nao permitido: ' . $mimeReal);
        }

        if ($ext === '') {
            $mapaExtensao = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'text/plain' => 'txt',
                'application/x-msdownload' => 'exe',
                'application/x-dosexec' => 'exe',
                'application/octet-stream' => 'bin',
            ];
            $ext = $mapaExtensao[$mimeReal] ?? 'bin';
        }

        $novoNome = bin2hex(random_bytes(16)) . '.' . $ext;
        $destDir = __DIR__ . '/../../public/uploads/chamados-comentarios/' . $chamadoId . '/' . $comentarioId . '/';

        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $caminhoCompleto = $destDir . $novoNome;
        $arquivo->moveTo($caminhoCompleto);

        return 'chamados-comentarios/' . $chamadoId . '/' . $comentarioId . '/' . $novoNome;
    }

    private function garantirEstruturaComentarios(\PDO $pdo): void
    {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS chamado_comentarios (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    chamado_id INT UNSIGNED NOT NULL,
                    usuario_id INT UNSIGNED NOT NULL,
                    conteudo TEXT NULL,
                    tipo ENUM('comentario','resolucao') NOT NULL DEFAULT 'comentario',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_chamado_criado (chamado_id, criado_em),
                    CONSTRAINT fk_chamado_comentarios_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
                    CONSTRAINT fk_chamado_comentarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS chamado_comentario_anexos (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    comentario_id INT UNSIGNED NOT NULL,
                    arquivo_path VARCHAR(500) NOT NULL,
                    arquivo_nome VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(100) NULL,
                    tamanho_bytes INT UNSIGNED NULL,
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_comentario (comentario_id),
                    CONSTRAINT fk_chamado_comentario_anexos_comentario FOREIGN KEY (comentario_id) REFERENCES chamado_comentarios(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('Aviso: nao foi possivel garantir estrutura de comentarios: ' . $e->getMessage());
        }
    }

    private function garantirColunaResolvidoPor(\PDO $pdo): void
    {
        try {
            if (!$this->columnExists($pdo, 'chamados', 'resolvido_por')) {
                $pdo->exec("ALTER TABLE chamados ADD COLUMN resolvido_por INT UNSIGNED NULL AFTER atribuido_a");
            }

            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM information_schema.TABLE_CONSTRAINTS\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = 'chamados'\n                  AND CONSTRAINT_NAME = 'fk_chamados_resolvido_por'\n            ");
            $stmt->execute();
            $temFk = (int) $stmt->fetchColumn() > 0;

            if (!$temFk) {
                $pdo->exec("ALTER TABLE chamados ADD CONSTRAINT fk_chamados_resolvido_por FOREIGN KEY (resolvido_por) REFERENCES usuarios(id) ON DELETE SET NULL");
            }
        } catch (\Throwable $e) {
            // Evita quebrar listagem/finalização caso usuário do banco não tenha permissão de ALTER.
            error_log('Aviso: não foi possível garantir coluna resolvido_por: ' . $e->getMessage());
        }
    }

    private function preencherResolvidoPorFallback(\PDO $pdo, array $linhas): array
    {
        if (empty($linhas)) {
            return $linhas;
        }

        $temResolvidoPor = $this->columnExists($pdo, 'chamados', 'resolvido_por');
        $stmtBusca = $pdo->prepare(
            "SELECT m.usuario_id, u.nome AS usuario_nome
             FROM mensagens m
             INNER JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.conteudo LIKE ?
             ORDER BY m.id DESC
             LIMIT 1"
        );
        $stmtCorrige = $temResolvidoPor
            ? $pdo->prepare('UPDATE chamados SET resolvido_por = ? WHERE id = ? AND (resolvido_por IS NULL OR resolvido_por = 0)')
            : null;

        foreach ($linhas as &$linha) {
            $status = (string) ($linha['status'] ?? '');
            $nomeAtual = trim((string) ($linha['resolvido_por_nome'] ?? ''));
            if ($status !== 'resolvido' || $nomeAtual !== '') {
                continue;
            }

            $chamadoId = (int) ($linha['id'] ?? 0);
            if ($chamadoId <= 0) {
                continue;
            }

            $padrao = 'Chamado #' . $chamadoId . ' ("%") foi finalizado por %';
            $stmtBusca->execute([$padrao]);
            $registro = $stmtBusca->fetch(\PDO::FETCH_ASSOC);
            if (!$registro || empty($registro['usuario_nome'])) {
                continue;
            }

            $linha['resolvido_por_nome'] = (string) $registro['usuario_nome'];

            if ($stmtCorrige !== null) {
                try {
                    $stmtCorrige->execute([(int) $registro['usuario_id'], $chamadoId]);
                } catch (\Throwable $e) {
                    error_log('Aviso: nao foi possivel corrigir resolvido_por do chamado ' . $chamadoId . ': ' . $e->getMessage());
                }
            }
        }
        unset($linha);

        return $linhas;
    }

    private function categoriasPadrao(): array
    {
        return [
            'ERP' => ['Financeiro', 'Fiscal', 'Contabilidade', 'Vendas', 'Estoque'],
            'Infraestrutura' => ['Servidor', 'Backup', 'Cloud', 'Banco de Dados'],
            'Engenharia' => ['AutoCAD', 'Solidworks', 'Revisão Técnica'],
            'Redes' => ['Wi-Fi', 'Cabeamento', 'VPN'],
            'Segurança' => ['Antivírus', 'Firewall', 'Câmeras'],
            'Hardware' => ['Desktop/Notebook', 'Impressora', 'Periféricos'],
            'Acessos' => ['Reset de Senha', 'Novo Usuário', 'Permissões'],
        ];
    }
}
