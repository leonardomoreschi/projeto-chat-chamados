<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response as Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AgendamentoController
{
    private const STATUS_VALIDOS = ['solicitado', 'agendado', 'cancelado', 'encerrado'];

    public function listar(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $userId = (int) $request->getAttribute('user_id');
        $papel = (string) $request->getAttribute('user_papel');
        $params = $request->getQueryParams();

        $inicio = $this->normalizarDataHora((string) ($params['inicio'] ?? ''));
        $fim = $this->normalizarDataHora((string) ($params['fim'] ?? ''));
        $status = trim((string) ($params['status'] ?? ''));
        $dia = trim((string) ($params['dia'] ?? ''));

        if ($dia !== '' && (!$inicio || !$fim)) {
            $inicio = $this->normalizarDataHora($dia . ' 00:00:00');
            $fim = $this->normalizarDataHora($dia . ' 23:59:59');
        }

        if (!$inicio || !$fim) {
            $inicio = (new \DateTimeImmutable('first day of this month 00:00:00'))->format('Y-m-d H:i:s');
            $fim = (new \DateTimeImmutable('last day of this month 23:59:59'))->format('Y-m-d H:i:s');
        }

        $where = ['a.data_inicio BETWEEN ? AND ?'];
        $values = [$inicio, $fim];

        if ($status !== '' && in_array($status, self::STATUS_VALIDOS, true)) {
            $where[] = 'a.status = ?';
            $values[] = $status;
        }

        if (!in_array($papel, ['admin', 'ti'], true)) {
            $where[] = 'a.solicitante_id = ?';
            $values[] = $userId;
        }

        $sql = "SELECT a.id, a.servico_id, s.nome AS servico_nome, s.descricao AS servico_descricao,
                   s.cor_hex, s.ativo AS servico_ativo,
                       a.solicitante_id, u.nome AS solicitante_nome, u.email AS solicitante_email,
                       a.aprovado_por_id, ap.nome AS aprovado_por_nome,
                       a.cancelado_por_id, ca.nome AS cancelado_por_nome,
                       a.encerrado_por_id, en.nome AS encerrado_por_nome,
                       a.status, a.data_inicio, a.data_fim, a.observacoes, a.motivo_recusa,
                       a.motivo_cancelamento, a.aprovado_em, a.cancelado_em, a.encerrado_em,
                       a.criado_em, a.atualizado_em
                FROM agendamentos a
                INNER JOIN servicos_agendamento s ON s.id = a.servico_id
                INNER JOIN usuarios u ON u.id = a.solicitante_id
                LEFT JOIN usuarios ap ON ap.id = a.aprovado_por_id
                LEFT JOIN usuarios ca ON ca.id = a.cancelado_por_id
                LEFT JOIN usuarios en ON en.id = a.encerrado_por_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.data_inicio ASC, a.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        return Json::json($response, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function obter(Request $request, Response $response, array $args): Response
    {
        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        return Json::json($response, $agendamento);
    }

    public function solicitar(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $servicoId = (int) ($data['servico_id'] ?? 0);
        $dataInicio = $this->normalizarDataHora((string) ($data['data_inicio'] ?? ''));
        $observacoes = trim((string) ($data['observacoes'] ?? ''));

        if ($servicoId <= 0 || !$dataInicio) {
            return Json::erro($response, 'Serviço e data de início são obrigatórios');
        }

        $servico = $this->buscarServicoAtivo($pdo, $servicoId);
        if (!$servico) {
            return Json::erro($response, 'Serviço não encontrado ou inativo', 404);
        }

        $inicio = new \DateTimeImmutable($dataInicio);
        $dataFim = $this->normalizarDataHora((string) ($data['data_fim'] ?? ''));
        if ($dataFim) {
            try {
                $fim = new \DateTimeImmutable($dataFim);
            } catch (\Throwable $e) {
                return Json::erro($response, 'Data de fim inválida');
            }
            if ($fim <= $inicio) {
                return Json::erro($response, 'Data de fim deve ser posterior à data de início');
            }
        } else {
            // por compatibilidade, quando não informado assume 1 hora de duração
            $fim = $inicio->modify('+1 hour');
        }

        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM agendamentos WHERE servico_id = ? AND status IN ("solicitado","agendado") AND (data_inicio < ? AND data_fim > ?)'
            );
            $check->execute([$servicoId, $fim->format('Y-m-d H:i:s'), $inicio->format('Y-m-d H:i:s')]);
            $count = (int) $check->fetchColumn();
            if ($count > 0) {
                $pdo->rollBack();
                return Json::erro($response, 'Horário em conflito com outro agendamento', 409);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO agendamentos (servico_id, solicitante_id, status, data_inicio, data_fim, observacoes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $servicoId,
                $userId,
                'solicitado',
                $inicio->format('Y-m-d H:i:s'),
                $fim->format('Y-m-d H:i:s'),
                $observacoes !== '' ? $observacoes : null,
            ]);

            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return Json::json($response, $this->buscarAgendamentoPorId($pdo, $id), 201);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::erro($response, 'Erro ao criar agendamento', 500);
        }
    }

    public function aprovar(Request $request, Response $response, array $args): Response
    {
        return $this->alterarStatusEquipe($request, $response, (int) ($args['id'] ?? 0), 'agendado', true, false);
    }

    public function recusar(Request $request, Response $response, array $args): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $data = (array) $request->getParsedBody();
        $motivo = trim((string) ($data['motivo'] ?? ''));
        if ($motivo === '') {
            return Json::erro($response, 'Informe o motivo da recusa');
        }

        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $this->atualizarAgendamento((int) $agendamento['id'], [
            'status' => 'cancelado',
            'motivo_recusa' => $motivo,
            'cancelado_por_id' => (int) $request->getAttribute('user_id'),
            'cancelado_em' => date('Y-m-d H:i:s'),
        ]);

        return Json::json($response, $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
    }

    public function cancelar(Request $request, Response $response, array $args): Response
    {
        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $userId = (int) $request->getAttribute('user_id');
        $papel = (string) $request->getAttribute('user_papel');
        if ((int) $agendamento['solicitante_id'] !== $userId && !in_array($papel, ['admin', 'ti'], true)) {
            return Json::erro($response, 'Sem permissão para cancelar este agendamento', 403);
        }

        $data = (array) $request->getParsedBody();
        $motivo = trim((string) ($data['motivo'] ?? ''));

        $this->atualizarAgendamento((int) $agendamento['id'], [
            'status' => 'cancelado',
            'motivo_cancelamento' => $motivo !== '' ? $motivo : null,
            'cancelado_por_id' => $userId,
            'cancelado_em' => date('Y-m-d H:i:s'),
        ]);

        return Json::json($response, $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
    }

    public function encerrar(Request $request, Response $response, array $args): Response
    {
        return $this->alterarStatusEquipe($request, $response, (int) ($args['id'] ?? 0), 'encerrado', false, true);
    }

    public function listarServicos(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $papel = (string) $request->getAttribute('user_papel');
        $params = $request->getQueryParams();
        $incluirInativos = in_array($papel, ['admin', 'ti'], true) && !empty($params['incluir_inativos']);

        $sql = "SELECT id, nome, descricao, cor_hex, ativo, criado_por, criado_em, atualizado_em
                FROM servicos_agendamento";
        if (!$incluirInativos) {
            $sql .= ' WHERE ativo = 1';
        }
        $sql .= ' ORDER BY ativo DESC, nome ASC';

        $stmt = $pdo->query($sql);
        return Json::json($response, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function criarServico(Request $request, Response $response): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $data = (array) $request->getParsedBody();
        $nome = trim((string) ($data['nome'] ?? ''));
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $cor = trim((string) ($data['cor_hex'] ?? '#4f46e5'));
        $cor = trim((string) ($data['cor_hex'] ?? '#4f46e5'));

        if ($nome === '') {
            return Json::erro($response, 'Nome do serviço é obrigatório');
        }

        $pdo = getDbConnection();
        $check = $pdo->prepare('SELECT id FROM servicos_agendamento WHERE nome = ? LIMIT 1');
        $check->execute([$nome]);
        if ($check->fetchColumn()) {
            return Json::erro($response, 'Já existe um serviço com esse nome');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO servicos_agendamento (nome, descricao, cor_hex, criado_por)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $nome,
            $descricao !== '' ? $descricao : null,
            $this->normalizarCorHex($cor),
            (int) $request->getAttribute('user_id'),
        ]);

        return Json::json($response, ['id' => (int) $pdo->lastInsertId(), 'ok' => true], 201);
    }

    public function atualizarServico(Request $request, Response $response, array $args): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();

        $campos = [];
        $values = [];

        if (array_key_exists('nome', $data)) {
            $nome = trim((string) $data['nome']);
            if ($nome === '') {
                return Json::erro($response, 'Nome do serviço é obrigatório');
            }
            $campos[] = 'nome = ?';
            $values[] = $nome;
        }
        if (array_key_exists('descricao', $data)) {
            $descricao = trim((string) $data['descricao']);
            $campos[] = 'descricao = ?';
            $values[] = $descricao !== '' ? $descricao : null;
        }
        if (array_key_exists('cor_hex', $data)) {
            $campos[] = 'cor_hex = ?';
            $values[] = $this->normalizarCorHex((string) $data['cor_hex']);
        }
        if (array_key_exists('ativo', $data)) {
            $campos[] = 'ativo = ?';
            $values[] = (int) ((bool) $data['ativo']);
        }

        if (empty($campos)) {
            return Json::erro($response, 'Nenhum campo para atualizar');
        }

        $values[] = $id;
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('UPDATE servicos_agendamento SET ' . implode(', ', $campos) . ' WHERE id = ?');
        $stmt->execute($values);

        return Json::json($response, ['ok' => true]);
    }

    public function desativarServico(Request $request, Response $response, array $args): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('UPDATE servicos_agendamento SET ativo = 0 WHERE id = ?');
        $stmt->execute([$id]);

        return Json::json($response, ['ok' => true]);
    }

    private function alterarStatusEquipe(Request $request, Response $response, int $id, string $status, bool $aprovar, bool $encerrar): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $agendamento = $this->buscarAgendamentoVisivel($request, $id);
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $campos = ['status' => $status];
        if ($aprovar) {
            $campos['aprovado_por_id'] = (int) $request->getAttribute('user_id');
            $campos['aprovado_em'] = date('Y-m-d H:i:s');
        }
        if ($encerrar) {
            $campos['encerrado_por_id'] = (int) $request->getAttribute('user_id');
            $campos['encerrado_em'] = date('Y-m-d H:i:s');
        }

        $this->atualizarAgendamento($id, $campos);
        return Json::json($response, $this->buscarAgendamentoPorId(getDbConnection(), $id));
    }

    private function atualizarAgendamento(int $id, array $campos): void
    {
        if (empty($campos)) {
            return;
        }

        $set = [];
        $values = [];
        foreach ($campos as $campo => $valor) {
            $set[] = $campo . ' = ?';
            $values[] = $valor;
        }

        $values[] = $id;
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('UPDATE agendamentos SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    private function buscarAgendamentoVisivel(Request $request, int $id): ?array
    {
        $agendamento = $this->buscarAgendamentoPorId(getDbConnection(), $id);
        if (!$agendamento) {
            return null;
        }

        $papel = (string) $request->getAttribute('user_papel');
        $userId = (int) $request->getAttribute('user_id');
        if (!in_array($papel, ['admin', 'ti'], true) && (int) $agendamento['solicitante_id'] !== $userId) {
            return null;
        }

        return $agendamento;
    }

    private function buscarAgendamentoPorId(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.servico_id, s.nome AS servico_nome, s.descricao AS servico_descricao,
                s.cor_hex, s.ativo AS servico_ativo,
                    a.solicitante_id, u.nome AS solicitante_nome, u.email AS solicitante_email,
                    a.aprovado_por_id, ap.nome AS aprovado_por_nome,
                    a.cancelado_por_id, ca.nome AS cancelado_por_nome,
                    a.encerrado_por_id, en.nome AS encerrado_por_nome,
                    a.status, a.data_inicio, a.data_fim, a.observacoes, a.motivo_recusa,
                    a.motivo_cancelamento, a.aprovado_em, a.cancelado_em, a.encerrado_em,
                    a.criado_em, a.atualizado_em
             FROM agendamentos a
             INNER JOIN servicos_agendamento s ON s.id = a.servico_id
             INNER JOIN usuarios u ON u.id = a.solicitante_id
             LEFT JOIN usuarios ap ON ap.id = a.aprovado_por_id
             LEFT JOIN usuarios ca ON ca.id = a.cancelado_por_id
             LEFT JOIN usuarios en ON en.id = a.encerrado_por_id
             WHERE a.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function buscarServicoAtivo(\PDO $pdo, int $servicoId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, nome, descricao, cor_hex, ativo FROM servicos_agendamento WHERE id = ? LIMIT 1');
        $stmt->execute([$servicoId]);
        $servico = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$servico || (int) ($servico['ativo'] ?? 0) !== 1) {
            return null;
        }

        return $servico;
    }

    private function ehEquipe(Request $request): bool
    {
        return in_array((string) $request->getAttribute('user_papel'), ['admin', 'ti'], true);
    }

    private function normalizarDataHora(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($valor))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizarCorHex(string $cor): string
    {
        $cor = trim($cor);
        if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $cor)) {
            return '#4f46e5';
        }

        return $cor[0] === '#' ? $cor : ('#' . $cor);
    }
}