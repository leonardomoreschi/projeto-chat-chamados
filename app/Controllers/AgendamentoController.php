<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response as Json;
use App\Support\NotificationCenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AgendamentoController
{
    private const STATUS_VALIDOS = ['solicitado', 'agendado', 'em_avaliacao', 'cancelado', 'encerrado'];

    public function listar(Request $request, Response $response): Response
    {
        $pdo = getDbConnection();
        $this->arquivarAgendamentosVencidos($pdo);
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

        $where = ['a.data_inicio < ? AND a.data_fim > ?'];
        $values = [$fim, $inicio];

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
                       a.reagendamento_por_id, re.nome AS reagendamento_por_nome,
                       a.reagendamento_inicio, a.reagendamento_fim, a.reagendamento_motivo, a.reagendamento_em,
                       a.status, a.data_inicio, a.data_fim, a.observacoes, a.motivo_recusa,
                       a.motivo_cancelamento, a.realizado, a.observacao_fechamento,
                       a.aprovado_em, a.avaliado_em, a.cancelado_em, a.encerrado_em,
                       a.criado_em, a.atualizado_em
                FROM agendamentos a
                INNER JOIN servicos_agendamento s ON s.id = a.servico_id
                INNER JOIN usuarios u ON u.id = a.solicitante_id
                LEFT JOIN usuarios ap ON ap.id = a.aprovado_por_id
                LEFT JOIN usuarios ca ON ca.id = a.cancelado_por_id
                LEFT JOIN usuarios en ON en.id = a.encerrado_por_id
                LEFT JOIN usuarios re ON re.id = a.reagendamento_por_id
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
            $fim = $inicio->modify('+1 hour');
        }

        try {
            $pdo->beginTransaction();

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

            $agendamento = $this->buscarAgendamentoPorId($pdo, $id);
            if ($agendamento) {
                $this->registrarNotificacaoAgendamento(
                    $pdo,
                    $agendamento,
                    'solicitado',
                    'Solicitação de agendamento enviada',
                    'Sua solicitação para o serviço "' . (string) $agendamento['servico_nome'] . '" foi registrada.',
                    'solicitado',
                    null,
                    [
                        'servico_nome' => (string) $agendamento['servico_nome'],
                        'data_inicio' => (string) $agendamento['data_inicio'],
                        'data_fim' => (string) $agendamento['data_fim'],
                    ]
                );

                // Aviso para quem administra a agenda — schedule_updated não
                // serve como gatilho porque dispara em qualquer atualização.
                NotificationCenter::registrarParaPapeis($pdo, ['ti', 'admin'], [
                    'tipo' => 'agendamento',
                    'evento' => 'novo_agendamento',
                    'entidade' => 'agendamento',
                    'entidade_id' => $id,
                    'chave_evento' => 'agendamento:novo:' . $id,
                    'titulo' => 'Novo agendamento solicitado',
                    'mensagem' => (string) $agendamento['solicitante_nome'] . ' solicitou "'
                        . (string) $agendamento['servico_nome'] . '".',
                    'url' => '/painel-agendamentos',
                    'status_destino' => 'solicitado',
                    // Fica pendente até a equipe aprovar, recusar ou sugerir outro horário.
                    'exige_acao' => true,
                    'metadados' => [
                        'agendamento_id' => $id,
                        'servico_nome' => (string) $agendamento['servico_nome'],
                        'solicitante_id' => (int) $userId,
                        'solicitante_nome' => (string) $agendamento['solicitante_nome'],
                        'data_inicio' => (string) $agendamento['data_inicio'],
                        'data_fim' => (string) $agendamento['data_fim'],
                    ],
                ], [(int) $userId]);
            }

            return Json::json($response, $agendamento, 201);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::erro($response, 'Erro ao criar agendamento', 500);
        }
    }

    public function aprovar(Request $request, Response $response, array $args): Response
    {
        return $this->alterarStatusEquipe($request, $response, (int) ($args['id'] ?? 0), 'agendado', true);
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

        $statusAnterior = (string) ($agendamento['status'] ?? '');
        $this->atualizarAgendamento((int) $agendamento['id'], [
            'status' => 'cancelado',
            'motivo_recusa' => $motivo,
            'cancelado_por_id' => (int) $request->getAttribute('user_id'),
            'cancelado_em' => date('Y-m-d H:i:s'),
        ]);

        $agendamentoAtualizado = $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        if ($agendamentoAtualizado) {
            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $agendamentoAtualizado,
                'cancelado',
                'Agendamento cancelado',
                'Sua solicitação para "' . (string) $agendamentoAtualizado['servico_nome'] . '" foi recusada por '
                    . (string) ($agendamentoAtualizado['cancelado_por_nome'] ?? 'um responsável') . '. Motivo: ' . $motivo,
                'cancelado',
                $statusAnterior,
                [
                    'motivo_recusa' => $motivo,
                    'servico_nome' => (string) $agendamentoAtualizado['servico_nome'],
                    'detalhe_equipe' => 'Motivo da recusa: ' . $motivo,
                    // Pedido recusado: cabe ao solicitante refazer com outra data.
                    'exige_acao_solicitante' => true,
                ],
                (int) $request->getAttribute('user_id')
            );
        }

        return Json::json($response, $agendamentoAtualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
    }

    // PATCH /api/agendamentos/{id}/reagendar — equipe propõe outro período.
    // A proposta fica em reagendamento_* até o solicitante aceitar; data_inicio
    // e data_fim só mudam no aceite.
    public function reagendar(Request $request, Response $response, array $args): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        if (!in_array((string) $agendamento['status'], ['solicitado', 'agendado'], true)) {
            return Json::erro($response, 'Só é possível reagendar solicitações em aberto ou já agendadas');
        }

        $data = (array) $request->getParsedBody();
        $inicioTexto = $this->normalizarDataHora((string) ($data['data_inicio'] ?? ''));
        $fimTexto = $this->normalizarDataHora((string) ($data['data_fim'] ?? ''));
        $motivo = trim((string) ($data['motivo'] ?? ''));

        if (!$inicioTexto || !$fimTexto) {
            return Json::erro($response, 'Informe a nova data de início e de término');
        }

        try {
            $inicio = new \DateTimeImmutable($inicioTexto);
            $fim = new \DateTimeImmutable($fimTexto);
        } catch (\Throwable $e) {
            return Json::erro($response, 'Datas inválidas');
        }

        if ($fim <= $inicio) {
            return Json::erro($response, 'Data de fim deve ser posterior à data de início');
        }

        $userId = (int) $request->getAttribute('user_id');
        $this->atualizarAgendamento((int) $agendamento['id'], [
            'reagendamento_inicio' => $inicio->format('Y-m-d H:i:s'),
            'reagendamento_fim' => $fim->format('Y-m-d H:i:s'),
            'reagendamento_motivo' => $motivo !== '' ? $motivo : null,
            'reagendamento_por_id' => $userId,
            'reagendamento_em' => date('Y-m-d H:i:s'),
        ]);

        $atualizado = $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        if ($atualizado) {
            $periodo = $this->formatarPeriodo($inicio, $fim);

            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $atualizado,
                'reagendamento_proposto',
                'Sugestão de novo horário',
                (string) ($atualizado['reagendamento_por_nome'] ?? 'A equipe')
                    . ' sugeriu outro horário para "' . (string) $atualizado['servico_nome'] . '": ' . $periodo . '.'
                    . ($motivo !== '' ? ' Motivo: ' . $motivo . '.' : '')
                    . ' Abra o agendamento para aceitar ou recusar a sugestão.',
                (string) $atualizado['status'],
                (string) $agendamento['status'],
                [
                    'servico_nome' => (string) $atualizado['servico_nome'],
                    'reagendamento_inicio' => $inicio->format('Y-m-d H:i:s'),
                    'reagendamento_fim' => $fim->format('Y-m-d H:i:s'),
                    'reagendamento_motivo' => $motivo !== '' ? $motivo : null,
                    'detalhe_equipe' => 'Novo período sugerido: ' . $periodo . '. Aguardando resposta do solicitante.',
                    // O solicitante precisa aceitar ou recusar a sugestão.
                    'exige_acao_solicitante' => true,
                ],
                $userId,
                '/agendamentos?agendamento=' . (int) $agendamento['id'],
                (string) ($atualizado['reagendamento_em'] ?? '')
            );
        }

        return Json::json($response, $atualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
    }

    // PATCH /api/agendamentos/{id}/reagendamento/aceitar — só o solicitante.
    public function aceitarReagendamento(Request $request, Response $response, array $args): Response
    {
        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $userId = (int) $request->getAttribute('user_id');
        if ((int) $agendamento['solicitante_id'] !== $userId) {
            return Json::erro($response, 'Apenas o solicitante pode responder à sugestão de horário', 403);
        }

        if (empty($agendamento['reagendamento_inicio']) || empty($agendamento['reagendamento_fim'])) {
            return Json::erro($response, 'Não há sugestão de horário pendente neste agendamento');
        }

        $novoInicio = (string) $agendamento['reagendamento_inicio'];
        $novoFim = (string) $agendamento['reagendamento_fim'];
        $statusAnterior = (string) $agendamento['status'];

        $this->atualizarAgendamento((int) $agendamento['id'], [
            'data_inicio' => $novoInicio,
            'data_fim' => $novoFim,
            'status' => 'agendado',
            // Quem propôs o horário é quem confirma a agenda.
            'aprovado_por_id' => (int) ($agendamento['reagendamento_por_id'] ?? 0) ?: null,
            'aprovado_em' => date('Y-m-d H:i:s'),
            'reagendamento_inicio' => null,
            'reagendamento_fim' => null,
            'reagendamento_motivo' => null,
            'reagendamento_por_id' => null,
            'reagendamento_em' => null,
        ]);

        $atualizado = $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        if ($atualizado) {
            $periodo = $this->formatarPeriodo(new \DateTimeImmutable($novoInicio), new \DateTimeImmutable($novoFim));

            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $atualizado,
                'reagendamento_aceito',
                'Novo horário confirmado',
                'Você aceitou o novo horário para "' . (string) $atualizado['servico_nome'] . '": ' . $periodo . '.',
                'agendado',
                $statusAnterior,
                [
                    'servico_nome' => (string) $atualizado['servico_nome'],
                    'data_inicio' => $novoInicio,
                    'data_fim' => $novoFim,
                    'detalhe_equipe' => 'Novo período confirmado: ' . $periodo . '.',
                ],
                $userId
            );
        }

        return Json::json($response, $atualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
    }

    // PATCH /api/agendamentos/{id}/reagendamento/recusar — o solicitante recusa
    // a sugestão e abre uma conversa no chat com quem propôs, para combinarem.
    public function recusarReagendamento(Request $request, Response $response, array $args): Response
    {
        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $userId = (int) $request->getAttribute('user_id');
        if ((int) $agendamento['solicitante_id'] !== $userId) {
            return Json::erro($response, 'Apenas o solicitante pode responder à sugestão de horário', 403);
        }

        if (empty($agendamento['reagendamento_inicio']) || empty($agendamento['reagendamento_fim'])) {
            return Json::erro($response, 'Não há sugestão de horário pendente neste agendamento');
        }

        $propostoPorId = (int) ($agendamento['reagendamento_por_id'] ?? 0);
        $periodoSugerido = $this->formatarPeriodo(
            new \DateTimeImmutable((string) $agendamento['reagendamento_inicio']),
            new \DateTimeImmutable((string) $agendamento['reagendamento_fim'])
        );
        $periodoOriginal = $this->formatarPeriodo(
            new \DateTimeImmutable((string) $agendamento['data_inicio']),
            new \DateTimeImmutable((string) $agendamento['data_fim'])
        );

        $this->atualizarAgendamento((int) $agendamento['id'], [
            'reagendamento_inicio' => null,
            'reagendamento_fim' => null,
            'reagendamento_motivo' => null,
            'reagendamento_por_id' => null,
            'reagendamento_em' => null,
        ]);

        // A conversa é aberta, mas a mensagem NÃO é enviada: ela volta como
        // sugestão e o front deixa o texto pronto no editor do chat — quem
        // envia (ou reescreve) é o solicitante.
        $mensagemSugerida = 'Sobre o agendamento #' . (int) $agendamento['id'] . ' — "'
            . (string) $agendamento['servico_nome'] . '" (' . $periodoOriginal . '): '
            . 'não consigo no horário sugerido (' . $periodoSugerido . ').'
            . ' Podemos combinar outra data por aqui?';

        $conversaId = 0;
        if ($propostoPorId > 0 && $propostoPorId !== $userId) {
            try {
                $conversaId = $this->obterOuCriarConversaPrivada(getDbConnection(), $userId, $propostoPorId);
            } catch (\Throwable $e) {
                error_log('Aviso: nao foi possivel abrir o chat apos recusa de reagendamento: ' . $e->getMessage());
                $conversaId = 0;
            }
        }

        $atualizado = $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        if ($atualizado) {
            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $atualizado,
                'reagendamento_recusado',
                'Sugestão de horário recusada',
                'Você recusou o horário sugerido para "' . (string) $atualizado['servico_nome']
                    . '". O agendamento segue previsto para ' . $periodoOriginal
                    . '. Combine o novo horário com a equipe pelo chat.',
                (string) $atualizado['status'],
                (string) $atualizado['status'],
                [
                    'servico_nome' => (string) $atualizado['servico_nome'],
                    'periodo_sugerido' => $periodoSugerido,
                    'conversa_id' => $conversaId,
                    'detalhe_equipe' => 'O solicitante recusou o período ' . $periodoSugerido
                        . ' e vai combinar outra data pelo chat.',
                    // A equipe é quem precisa propor o próximo horário.
                    'exige_acao_equipe' => true,
                ],
                $userId
            );
        }

        $corpo = $atualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        $corpo['conversa_id'] = $conversaId;
        $corpo['mensagem_sugerida'] = $mensagemSugerida;

        return Json::json($response, $corpo);
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
        // Obrigatório: o motivo é o conteúdo da notificação que o solicitante recebe.
        if ($motivo === '') {
            return Json::erro($response, 'Informe o motivo do cancelamento');
        }

        $statusAnterior = (string) ($agendamento['status'] ?? '');

        $this->atualizarAgendamento((int) $agendamento['id'], [
            'status' => 'cancelado',
            'motivo_cancelamento' => $motivo,
            'cancelado_por_id' => $userId,
            'cancelado_em' => date('Y-m-d H:i:s'),
        ]);

        $agendamentoAtualizado = $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        if ($agendamentoAtualizado) {
            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $agendamentoAtualizado,
                'cancelado',
                'Agendamento cancelado',
                'O agendamento "' . (string) $agendamentoAtualizado['servico_nome'] . '" foi cancelado por '
                    . (string) ($agendamentoAtualizado['cancelado_por_nome'] ?? 'um responsável') . '. Motivo: ' . $motivo,
                'cancelado',
                $statusAnterior,
                [
                    'motivo_cancelamento' => $motivo,
                    'servico_nome' => (string) $agendamentoAtualizado['servico_nome'],
                    'detalhe_equipe' => 'Motivo: ' . $motivo,
                ],
                $userId
            );
        }

        return Json::json($response, $agendamentoAtualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
    }

    public function encerrar(Request $request, Response $response, array $args): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $agendamento = $this->buscarAgendamentoVisivel($request, (int) ($args['id'] ?? 0));
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $data = (array) $request->getParsedBody();
        $observacaoFechamento = trim((string) ($data['observacao_fechamento'] ?? ''));
        // Obrigatório: é o parecer que vai na notificação do solicitante.
        if ($observacaoFechamento === '') {
            return Json::erro($response, 'Informe o parecer de encerramento');
        }

        $realizadoBruto = $data['realizado'] ?? null;
        $realizado = ($realizadoBruto === null || $realizadoBruto === '')
            ? null
            : (int) filter_var($realizadoBruto, FILTER_VALIDATE_BOOLEAN);

        $statusAnterior = (string) ($agendamento['status'] ?? '');
        $this->atualizarAgendamento((int) $agendamento['id'], [
            'status' => 'encerrado',
            'encerrado_por_id' => (int) $request->getAttribute('user_id'),
            'encerrado_em' => date('Y-m-d H:i:s'),
            'realizado' => $realizado,
            'observacao_fechamento' => $observacaoFechamento,
        ]);

        $agendamentoAtualizado = $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']);
        if ($agendamentoAtualizado) {
            $situacao = $realizado === null ? '' : ($realizado === 1 ? ' O serviço foi realizado.' : ' O serviço não foi realizado.');

            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $agendamentoAtualizado,
                'encerrado',
                'Agendamento encerrado',
                'O agendamento "' . (string) $agendamentoAtualizado['servico_nome'] . '" foi encerrado por '
                    . (string) ($agendamentoAtualizado['encerrado_por_nome'] ?? 'um responsável') . '.'
                    . $situacao . ' Parecer: ' . $observacaoFechamento,
                'encerrado',
                $statusAnterior,
                [
                    'realizado' => $realizado,
                    'observacao_fechamento' => $observacaoFechamento,
                    'servico_nome' => (string) $agendamentoAtualizado['servico_nome'],
                    'detalhe_equipe' => 'Parecer: ' . $observacaoFechamento,
                ],
                (int) $request->getAttribute('user_id')
            );
        }

        return Json::json($response, $agendamentoAtualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), (int) $agendamento['id']));
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

        if ($nome === '') {
            return Json::erro($response, 'Nome do serviço é obrigatório');
        }

        $pdo = getDbConnection();
        $check = $pdo->prepare('SELECT id FROM servicos_agendamento WHERE nome = ? LIMIT 1');
        $check->execute([$nome]);
        if ($check->fetchColumn()) {
            return Json::erro($response, 'Já existe um serviço com esse nome');
        }

        $stmt = $pdo->prepare('INSERT INTO servicos_agendamento (nome, descricao, cor_hex, criado_por) VALUES (?, ?, ?, ?)');
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

    private function alterarStatusEquipe(Request $request, Response $response, int $id, string $status, bool $aprovar): Response
    {
        if (!$this->ehEquipe($request)) {
            return Json::erro($response, 'Acesso restrito a Admin/TI', 403);
        }

        $agendamento = $this->buscarAgendamentoVisivel($request, $id);
        if (!$agendamento) {
            return Json::erro($response, 'Agendamento não encontrado', 404);
        }

        $statusAnterior = (string) ($agendamento['status'] ?? '');
        $campos = ['status' => $status];
        if ($aprovar) {
            $campos['aprovado_por_id'] = (int) $request->getAttribute('user_id');
            $campos['aprovado_em'] = date('Y-m-d H:i:s');
        }

        $this->atualizarAgendamento($id, $campos);
        $agendamentoAtualizado = $this->buscarAgendamentoPorId(getDbConnection(), $id);
        if ($agendamentoAtualizado) {
            $this->registrarNotificacaoAgendamento(
                getDbConnection(),
                $agendamentoAtualizado,
                $aprovar ? 'aprovado' : 'atualizado',
                $aprovar ? 'Agendamento aprovado' : 'Agendamento atualizado',
                $aprovar
                    ? 'Sua solicitação para o serviço "' . (string) $agendamentoAtualizado['servico_nome'] . '" foi aprovada.'
                    : 'O agendamento "' . (string) $agendamentoAtualizado['servico_nome'] . '" foi atualizado pela equipe.',
                $status,
                $statusAnterior,
                [
                    'servico_nome' => (string) $agendamentoAtualizado['servico_nome'],
                    'data_inicio' => (string) $agendamentoAtualizado['data_inicio'],
                    'data_fim' => (string) $agendamentoAtualizado['data_fim'],
                ],
                (int) $request->getAttribute('user_id')
            );
        }

        return Json::json($response, $agendamentoAtualizado ?: $this->buscarAgendamentoPorId(getDbConnection(), $id));
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

    private function arquivarAgendamentosVencidos(\PDO $pdo): void
    {
        $pdo->prepare(
            "UPDATE agendamentos
             SET status = 'em_avaliacao', avaliado_em = NOW()
             WHERE status = 'agendado' AND data_fim < NOW()"
        )->execute();
    }

    private function buscarAgendamentoVisivel(Request $request, int $id): ?array
    {
        $pdo = getDbConnection();
        $this->arquivarAgendamentosVencidos($pdo);
        $agendamento = $this->buscarAgendamentoPorId($pdo, $id);
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
                    a.reagendamento_por_id, re.nome AS reagendamento_por_nome,
                    a.reagendamento_inicio, a.reagendamento_fim, a.reagendamento_motivo, a.reagendamento_em,
                    a.status, a.data_inicio, a.data_fim, a.observacoes, a.motivo_recusa,
                    a.motivo_cancelamento, a.realizado, a.observacao_fechamento,
                    a.aprovado_em, a.avaliado_em, a.cancelado_em, a.encerrado_em,
                    a.criado_em, a.atualizado_em
             FROM agendamentos a
             INNER JOIN servicos_agendamento s ON s.id = a.servico_id
             INNER JOIN usuarios u ON u.id = a.solicitante_id
             LEFT JOIN usuarios ap ON ap.id = a.aprovado_por_id
             LEFT JOIN usuarios ca ON ca.id = a.cancelado_por_id
             LEFT JOIN usuarios en ON en.id = a.encerrado_por_id
             LEFT JOIN usuarios re ON re.id = a.reagendamento_por_id
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

    // Rótulo curto de cada movimentação, usado no aviso da equipe.
    private const ROTULOS_EVENTO_AGENDAMENTO = [
        'aprovado' => 'aprovado',
        'cancelado' => 'cancelado',
        'encerrado' => 'encerrado',
        'atualizado' => 'atualizado',
        'solicitado' => 'solicitado',
        'reagendamento_proposto' => 'reagendado pela equipe',
        'reagendamento_aceito' => 'confirmado no novo horário',
        'reagendamento_recusado' => 'mantido no horário original',
    ];

    private function formatarPeriodo(\DateTimeInterface $inicio, \DateTimeInterface $fim): string
    {
        // Mesmo dia: "13/08/2026 das 09:00 às 10:00". Dias diferentes: intervalo completo.
        if ($inicio->format('Y-m-d') === $fim->format('Y-m-d')) {
            return $inicio->format('d/m/Y') . ' das ' . $inicio->format('H:i') . ' às ' . $fim->format('H:i');
        }

        return $inicio->format('d/m/Y H:i') . ' até ' . $fim->format('d/m/Y H:i');
    }

    // Gêmeo de ChamadoController::obterOuCriarConversaPrivada — o chat não tem
    // camada compartilhada, cada controller fala com o banco direto.
    private function obterOuCriarConversaPrivada(\PDO $pdo, int $usuarioA, int $usuarioB): int
    {
        $check = $pdo->prepare(
            "SELECT c.id FROM conversas c
             INNER JOIN participantes p1 ON p1.conversa_id = c.id AND p1.usuario_id = ?
             INNER JOIN participantes p2 ON p2.conversa_id = c.id AND p2.usuario_id = ?
             WHERE c.tipo = 'privada'
             LIMIT 1"
        );
        $check->execute([$usuarioA, $usuarioB]);
        $existente = $check->fetch(\PDO::FETCH_ASSOC);

        if ($existente && isset($existente['id'])) {
            return (int) $existente['id'];
        }

        $stmt = $pdo->prepare("INSERT INTO conversas (tipo, nome, criado_por) VALUES ('privada', NULL, ?)");
        $stmt->execute([$usuarioA]);
        $conversaId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)')->execute([$conversaId, $usuarioA]);
        $pdo->prepare('INSERT INTO participantes (conversa_id, usuario_id) VALUES (?, ?)')->execute([$conversaId, $usuarioB]);

        return $conversaId;
    }

    private function registrarNotificacaoAgendamento(
        \PDO $pdo,
        array $agendamento,
        string $evento,
        string $titulo,
        string $mensagem,
        string $statusDestino,
        ?string $statusOrigem = null,
        array $metadados = [],
        ?int $autorId = null,
        ?string $urlSolicitante = null,
        // Diferencia eventos que podem se repetir no mesmo agendamento (uma
        // segunda sugestão de horário, por exemplo). Sem sufixo, o upsert por
        // chave_evento atualizaria a notificação antiga — que já pode estar
        // marcada como lida, e o aviso passaria despercebido.
        ?string $chaveSufixo = null
    ): void {
        $solicitanteId = (int) ($agendamento['solicitante_id'] ?? 0);
        if ($solicitanteId <= 0) {
            return;
        }

        // Marcadores de "este lado precisa responder": viajam em $metadados como
        // as demais chaves de controle e não são gravados na notificação.
        $exigeAcaoSolicitante = !empty($metadados['exige_acao_solicitante']);
        $exigeAcaoEquipe = !empty($metadados['exige_acao_equipe']);
        unset($metadados['exige_acao_solicitante'], $metadados['exige_acao_equipe']);

        $this->notificarEquipeAgendamento($pdo, $agendamento, $evento, $titulo, $statusDestino, $statusOrigem, $metadados, $autorId, $chaveSufixo, $exigeAcaoEquipe);

        // 'detalhe_equipe' só serve para montar a mensagem do painel; o
        // solicitante já recebe o motivo/parecer no corpo da própria mensagem.
        unset($metadados['detalhe_equipe']);

        NotificationCenter::registrar($pdo, [
            'usuario_id' => $solicitanteId,
            'tipo' => 'agendamento',
            'evento' => $evento,
            'entidade' => 'agendamento',
            'entidade_id' => (int) ($agendamento['id'] ?? 0),
            'chave_evento' => 'agendamento:' . $evento . ':' . (int) ($agendamento['id'] ?? 0) . ':' . $solicitanteId
                . ($chaveSufixo !== null ? ':' . $chaveSufixo : ''),
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            // Eventos que exigem ação abrem direto o agendamento (?agendamento=ID).
            'url' => $urlSolicitante ?? '/agendamentos',
            'status_origem' => $statusOrigem,
            'status_destino' => $statusDestino,
            'exige_acao' => $exigeAcaoSolicitante,
            'metadados' => array_merge([
                'agendamento_id' => (int) ($agendamento['id'] ?? 0),
                'servico_nome' => (string) ($agendamento['servico_nome'] ?? ''),
                'data_inicio' => (string) ($agendamento['data_inicio'] ?? ''),
                'data_fim' => (string) ($agendamento['data_fim'] ?? ''),
            ], $metadados),
        ]);
    }

    /**
     * Espelha a movimentação para ti/admin em /painel-agendamentos. Quem executou
     * a ação e o próprio solicitante ficam de fora (este já recebe a via dele).
     * O evento 'solicitado' é ignorado aqui: solicitar() já dispara o aviso
     * dedicado de novo_agendamento para a equipe.
     */
    private function notificarEquipeAgendamento(
        \PDO $pdo,
        array $agendamento,
        string $evento,
        string $titulo,
        string $statusDestino,
        ?string $statusOrigem,
        array $metadados,
        ?int $autorId,
        ?string $chaveSufixo = null,
        bool $exigeAcao = false
    ): void {
        if ($evento === 'solicitado') {
            return;
        }

        $agendamentoId = (int) ($agendamento['id'] ?? 0);
        $solicitanteId = (int) ($agendamento['solicitante_id'] ?? 0);
        if ($agendamentoId <= 0) {
            return;
        }

        $rotulo = self::ROTULOS_EVENTO_AGENDAMENTO[$evento] ?? $evento;
        $mensagem = 'Agendamento #' . $agendamentoId . ' de '
            . (string) ($agendamento['solicitante_nome'] ?? 'solicitante')
            . ' — "' . (string) ($agendamento['servico_nome'] ?? '') . '" foi ' . $rotulo . '.';

        // Motivo do cancelamento / parecer de encerramento, quando o evento tem um.
        $detalhe = trim((string) ($metadados['detalhe_equipe'] ?? ''));
        if ($detalhe !== '') {
            $mensagem .= ' ' . $detalhe;
        }

        NotificationCenter::registrarParaPapeis($pdo, ['ti', 'admin'], [
            'tipo' => 'agendamento',
            'evento' => $evento,
            'entidade' => 'agendamento',
            'entidade_id' => $agendamentoId,
            'chave_evento' => 'agendamento:' . $evento . ':' . $agendamentoId . ':gestor'
                . ($chaveSufixo !== null ? ':' . $chaveSufixo : ''),
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            'url' => '/painel-agendamentos',
            'status_origem' => $statusOrigem,
            'status_destino' => $statusDestino,
            'exige_acao' => $exigeAcao,
            'metadados' => array_merge([
                'agendamento_id' => $agendamentoId,
                'servico_nome' => (string) ($agendamento['servico_nome'] ?? ''),
                'solicitante_id' => $solicitanteId,
                'solicitante_nome' => (string) ($agendamento['solicitante_nome'] ?? ''),
                'data_inicio' => (string) ($agendamento['data_inicio'] ?? ''),
                'data_fim' => (string) ($agendamento['data_fim'] ?? ''),
                'autor_id' => (int) ($autorId ?? 0),
            ], $metadados),
        ], [(int) ($autorId ?? 0), $solicitanteId]);
    }
}