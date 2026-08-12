<?php
declare(strict_types=1);
namespace App\Services;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;
use React\EventLoop\Loop;
use App\Support\NotificationCenter;
use App\Support\PushCenter;
use App\Support\SchemaInspector;

class ChatServer implements MessageComponentInterface
{
    use SchemaInspector;

    private SplObjectStorage $clients;

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
        echo "Servidor de chat iniciado!\n";

        // Este processo e o unico que escreve user_presenca: se ele caiu com
        // gente conectada, sobraram linhas online=1 mentindo para sempre - e o
        // push worker deixaria de notificar esses usuarios.
        $this->zerarPresencaResidual();

        // Busca mudancas geradas fora do WS (ex.: mensagens automaticas de chamado)
        Loop::addPeriodicTimer(0.8, function (): void {
            $this->sincronizarAtualizacoes();
        });

        // Sem esta renovacao o last_seen so seria escrito no connect/disconnect,
        // e quem estivesse conectado ha mais de 90s pareceria ausente para o
        // push worker - resultando em popup do SO duplicando o toast da aba.
        Loop::addPeriodicTimer(30, function (): void {
            $this->renovarPresencaConectados();
        });

        // Move agendamentos vencidos para avaliacao mesmo sem requisicoes HTTP ativas
        Loop::addPeriodicTimer(60, function (): void {
            $this->arquivarAgendamentosVencidos();
        });
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $conn->userId     = null;
        $conn->userName   = null;
        $conn->userPapel  = null;
        $conn->conversaId = null;
        $conn->lastSeenMessageId = 0;
        $conn->lastSeenConversationId = 0;
        $conn->lastSeenDeletionAt = date('Y-m-d H:i:s');
        $conn->lastSeenAgendamentoUpdateAt = date('Y-m-d H:i:s');
        $conn->lastSeenNotificationId = 0;
        // Ate o cliente mandar o primeiro 'presenca', assume aba ativa: e o caso
        // comum e o erro seguro (no maximo atrasa um push, nunca duplica).
        $conn->ativo = true;
        echo "Nova conexão: #{$conn->resourceId} | Total: {$this->clients->count()}\n";
    }

    public function onMessage(ConnectionInterface $from, $msgRaw): void
    {
        $data = json_decode($msgRaw, true);
        if (!$data || !isset($data['type'])) return;

        switch ($data['type']) {

            case 'auth':
                $from->userId     = (int) ($data['user_id']    ?? 0);
                $from->userName   = $data['user_nome']          ?? 'Anônimo';
                $from->userPapel  = $data['user_papel']         ?? $this->buscarPapelUsuario((int) $from->userId);
                $from->conversaId = (int) ($data['conversa_id'] ?? 0);
                $from->lastSeenMessageId = 0;
                $from->lastSeenConversationId = 0;
                $from->lastSeenDeletionAt = date('Y-m-d H:i:s');
                $from->lastSeenAgendamentoUpdateAt = date('Y-m-d H:i:s');
                $from->ativo = (bool) ($data['ativo'] ?? true);
                $this->sincronizarPresencaUsuario((int) $from->userId);
                try {
                    $pdo = getDbConnection();
                    $stmtNotif = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notificacoes WHERE usuario_id = ?');
                    $stmtNotif->execute([(int) $from->userId]);
                    $from->lastSeenNotificationId = (int) $stmtNotif->fetchColumn();
                } catch (\Throwable $e) {
                    $from->lastSeenNotificationId = 0;
                }
                echo "Autenticado: {$from->userName} (#{$from->userId})\n";
                // Sincronização inicial para pegar mensagens recentes
                $this->sincronizacaoInicial($from);
                $from->send(json_encode(['type' => 'auth_ok', 'userId' => $from->userId]));
                break;

            case 'join':
                $from->conversaId = (int) ($data['conversa_id'] ?? 0);
                break;

            // Aba passou a frente / saiu de frente. E este sinal - e nao o mero
            // fato de existir uma conexao - que decide se o push worker manda o
            // popup do SO: com a aba na frente o proprio front mostra o toast.
            case 'presenca':
                $from->ativo = (bool) ($data['ativo'] ?? true);
                if (!empty($from->userId)) {
                    $this->sincronizarPresencaUsuario((int) $from->userId);
                }
                break;

            case 'send_message':
                if (!$from->userId) return;

                $conversaId = (int) ($data['conversa_id'] ?? 0);
                $conteudo   = trim($data['conteudo'] ?? '');

                if (!$conversaId || !$conteudo || mb_strlen($conteudo) > 5000) return;

                try {
                    $pdo = getDbConnection();

                    // Verifica se é participante
                    $check = $pdo->prepare('SELECT 1 FROM participantes WHERE conversa_id = ? AND usuario_id = ?');
                    $check->execute([$conversaId, $from->userId]);
                    if (!$check->fetch()) return;

                    // Salva no banco
                    $stmt = $pdo->prepare('INSERT INTO mensagens (conversa_id, usuario_id, conteudo) VALUES (?, ?, ?)');
                    $stmt->execute([$conversaId, $from->userId, $conteudo]);
                    $msgId = (int) $pdo->lastInsertId();

                    // Busca participantes ANTES do loop de clientes
                    $partic = $pdo->prepare('SELECT usuario_id FROM participantes WHERE conversa_id = ?');
                    $partic->execute([$conversaId]);
                    $participanteIds = array_column($partic->fetchAll(\PDO::FETCH_ASSOC), 'usuario_id');
                    $participanteIds = array_map('intval', $participanteIds);

                    $payload = json_encode([
                        'type'    => 'new_message',
                        'message' => [
                            'id'           => $msgId,
                            'conteudo'     => $conteudo,
                            'criado_em'    => date('Y-m-d H:i:s'),
                            'usuario_id'   => $from->userId,
                            'usuario_nome' => $from->userName,
                            'conversa_id'  => $conversaId,
                        ]
                    ], JSON_UNESCAPED_UNICODE);

                    // Envia para todos os participantes conectados
                    foreach ($this->clients as $client) {
                        if ($client->userId && in_array($client->userId, $participanteIds, true)) {
                            $client->send($payload);
                            $client->lastSeenMessageId = max((int) ($client->lastSeenMessageId ?? 0), $msgId);
                        }
                    }

                    // Push para os participantes ausentes. De proposito NAO passa
                    // por NotificationCenter: mensagem de chat nao entra na
                    // central nem no contador do sino, so vira popup do SO.
                    // Quem estiver com o WS vivo e descartado pelo worker.
                    $destinatarios = array_values(array_filter(
                        $participanteIds,
                        static fn (int $id): bool => $id !== (int) $from->userId
                    ));

                    PushCenter::enfileirarParaUsuarios($pdo, $destinatarios, [
                        'origem'     => 'mensagem',
                        'chave_base' => 'msg:' . $msgId,
                        'titulo'     => 'Nova mensagem de ' . (string) $from->userName,
                        'corpo'      => mb_substr($conteudo, 0, 140),
                        'url'        => '/chat?conversa=' . $conversaId,
                        // Mensagens da mesma conversa colapsam num popup so.
                        'tag'        => 'conversa:' . $conversaId,
                    ]);

                    echo "Mensagem de {$from->userName} na conversa #{$conversaId}\n";

                } catch (\Exception $e) {
                    error_log('ChatServer erro: ' . $e->getMessage());
                    echo "ERRO: " . $e->getMessage() . "\n";
                }
                break;

            case 'typing':
                if (!$from->userId) return;
                $conversaId = (int) ($data['conversa_id'] ?? 0);
                $payload    = json_encode([
                    'type'        => 'typing',
                    'user_nome'   => $from->userName,
                    'conversa_id' => $conversaId,
                ]);
                foreach ($this->clients as $client) {
                    if ($client !== $from && $client->conversaId === $conversaId) {
                        $client->send($payload);
                    }
                }
                break;

            case 'delete_message':
                if (!$from->userId) return;

                $mensagemId = (int) ($data['message_id'] ?? 0);
                if ($mensagemId <= 0) {
                    $from->send(json_encode(['type' => 'action_error', 'action' => 'delete_message', 'message' => 'Mensagem invalida']));
                    return;
                }

                try {
                    $pdo = getDbConnection();

                    $stmtMsg = $pdo->prepare(
                        'SELECT m.id, m.conversa_id, m.usuario_id
                         FROM mensagens m
                         INNER JOIN participantes p ON p.conversa_id = m.conversa_id AND p.usuario_id = ?
                         WHERE m.id = ?
                         LIMIT 1'
                    );
                    $stmtMsg->execute([$from->userId, $mensagemId]);
                    $msg = $stmtMsg->fetch(\PDO::FETCH_ASSOC);

                    if (!$msg) {
                        $from->send(json_encode(['type' => 'action_error', 'action' => 'delete_message', 'message' => 'Mensagem nao encontrada']));
                        return;
                    }

                    $stmtRole = $pdo->prepare('SELECT papel FROM usuarios WHERE id = ? LIMIT 1');
                    $stmtRole->execute([$from->userId]);
                    $papel = (string) ($stmtRole->fetchColumn() ?: 'usuario');

                    $dono = (int) $msg['usuario_id'] === (int) $from->userId;
                    if (!$dono && $papel !== 'admin') {
                        $from->send(json_encode(['type' => 'action_error', 'action' => 'delete_message', 'message' => 'Sem permissao para apagar']));
                        return;
                    }

                    $temExclusao = $this->columnExists($pdo, 'mensagens', 'excluida_em') && $this->columnExists($pdo, 'mensagens', 'excluida_por');
                    if ($temExclusao) {
                        $stmtDel = $pdo->prepare(
                            'UPDATE mensagens
                             SET conteudo = "",
                                 arquivo_path = NULL,
                                 arquivo_nome = NULL,
                                 excluida_em = NOW(),
                                 excluida_por = ?
                             WHERE id = ?'
                        );
                        $stmtDel->execute([$from->userId, $mensagemId]);
                    } else {
                        $stmtDel = $pdo->prepare(
                            "UPDATE mensagens
                             SET conteudo = '[mensagem apagada]',
                                 arquivo_path = NULL,
                                 arquivo_nome = NULL
                             WHERE id = ?"
                        );
                        $stmtDel->execute([$mensagemId]);
                    }

                    $conversaId = (int) $msg['conversa_id'];
                    $stmtPart = $pdo->prepare('SELECT usuario_id FROM participantes WHERE conversa_id = ?');
                    $stmtPart->execute([$conversaId]);
                    $participanteIds = array_map('intval', array_column($stmtPart->fetchAll(\PDO::FETCH_ASSOC), 'usuario_id'));

                    $payload = json_encode([
                        'type' => 'message_deleted',
                        'message_id' => $mensagemId,
                        'conversa_id' => $conversaId,
                        'deleted_at' => date('Y-m-d H:i:s'),
                    ], JSON_UNESCAPED_UNICODE);

                    foreach ($this->clients as $client) {
                        if ($client->userId && in_array((int) $client->userId, $participanteIds, true)) {
                            $client->send($payload);
                            $client->lastSeenDeletionAt = date('Y-m-d H:i:s');
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('Erro delete_message WS: ' . $e->getMessage());
                    $from->send(json_encode(['type' => 'action_error', 'action' => 'delete_message', 'message' => 'Erro ao apagar mensagem']));
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        // Detach antes de recalcular: senao a conexao que esta fechando ainda
        // contaria como aba ativa. Recalcular (em vez de zerar direto) evita
        // que fechar uma aba apague a presenca de outra ainda aberta.
        $userId = (int) ($conn->userId ?? 0);
        $this->clients->detach($conn);
        if ($userId > 0) {
            $this->sincronizarPresencaUsuario($userId);
        }
        echo "Conexão fechada: #{$conn->resourceId} ({$conn->userName}) | Total: {$this->clients->count()}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log("WebSocket erro: " . $e->getMessage());
        $conn->close();
    }

    /**
     * Recalcula a presenca do usuario a partir das conexoes vivas.
     *
     * `online = 1` significa "tem pelo menos uma aba na frente do usuario", nao
     * "tem socket aberto": e assim que o push worker consegue distinguir quem ja
     * esta vendo o toast in-page de quem precisa do popup do sistema.
     */
    private function sincronizarPresencaUsuario(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $ativo = false;
        foreach ($this->clients as $client) {
            if ((int) ($client->userId ?? 0) === $userId && !empty($client->ativo)) {
                $ativo = true;
                break;
            }
        }

        $this->atualizarPresenca($userId, $ativo);
    }

    private function atualizarPresenca(int $userId, bool $online): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $pdo = getDbConnection();
            $pdo->exec("\n                CREATE TABLE IF NOT EXISTS user_presenca (\n                    usuario_id INT UNSIGNED PRIMARY KEY,\n                    online TINYINT(1) NOT NULL DEFAULT 0,\n                    last_seen TIMESTAMP NULL DEFAULT NULL,\n                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                    CONSTRAINT fk_user_presenca_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n            ");

            $stmt = $pdo->prepare("\n                INSERT INTO user_presenca (usuario_id, online, last_seen)\n                VALUES (?, ?, NOW())\n                ON DUPLICATE KEY UPDATE\n                    online = VALUES(online),\n                    last_seen = NOW()\n            ");
            $stmt->execute([$userId, $online ? 1 : 0]);
        } catch (\Throwable $e) {
            error_log('Falha ao atualizar presenca: ' . $e->getMessage());
        }
    }

    /**
     * Renova o last_seen de todos os usuarios com conexao viva, marcando como
     * online so quem tem alguma aba na frente.
     *
     * O push worker so confia na linha se o last_seen for recente; sem a
     * renovacao periodica ele acharia que todo mundo saiu depois de 90s.
     */
    private function renovarPresencaConectados(): void
    {
        $ativos = [];
        $inativos = [];

        foreach ($this->clients as $client) {
            $userId = (int) ($client->userId ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if (!empty($client->ativo)) {
                $ativos[$userId] = true;
            } elseif (!isset($ativos[$userId])) {
                $inativos[$userId] = true;
            }
        }

        // Uma aba ativa vence qualquer numero de abas em segundo plano.
        $inativos = array_diff_key($inativos, $ativos);

        try {
            $pdo = getDbConnection();
            foreach ([1 => array_keys($ativos), 0 => array_keys($inativos)] as $online => $ids) {
                if (!$ids) {
                    continue;
                }
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare(
                    "UPDATE user_presenca SET online = {$online}, last_seen = NOW() WHERE usuario_id IN ({$marcadores})"
                );
                $stmt->execute($ids);
            }
        } catch (\Throwable $e) {
            error_log('Falha ao renovar presenca: ' . $e->getMessage());
        }
    }

    private function zerarPresencaResidual(): void
    {
        try {
            getDbConnection()->exec('UPDATE user_presenca SET online = 0 WHERE online = 1');
        } catch (\Throwable $e) {
            error_log('Falha ao zerar presenca residual: ' . $e->getMessage());
        }
    }

    private function sincronizarAtualizacoes(): void
    {
        foreach ($this->clients as $client) {
            if (empty($client->userId)) {
                continue;
            }

            try {
                $this->sincronizarNovasConversas($client);
                $this->sincronizarNovasMensagens($client);
                $this->sincronizarApagamentos($client);
                $this->sincronizarAgendamentos($client);
                $this->sincronizarNotificacoes($client);
            } catch (\Throwable $e) {
                error_log('Falha na sincronizacao WS: ' . $e->getMessage());
            }
        }
    }

    private function sincronizacaoInicial(ConnectionInterface $client): void
    {
        $pdo = getDbConnection();
        $userId = (int) $client->userId;

        if ($userId <= 0) {
            return;
        }

        try {
            // Sincroniza conversas novas (todas as que participa)
            $stmtConv = $pdo->prepare(
                "SELECT c.id, c.tipo, c.nome, c.criado_em,
                        criador.nome AS criado_por_nome,
                        CASE
                            WHEN c.tipo = 'privada' THEN (
                                SELECT u.nome FROM usuarios u
                                INNER JOIN participantes p2 ON p2.usuario_id = u.id
                                WHERE p2.conversa_id = c.id AND u.id != ?
                                LIMIT 1
                            )
                            ELSE c.nome
                        END AS display_nome
                 FROM conversas c
                 INNER JOIN participantes p ON p.conversa_id = c.id AND p.usuario_id = ?
                 LEFT JOIN usuarios criador ON criador.id = c.criado_por
                 ORDER BY c.id DESC
                 LIMIT 50"
            );
            $stmtConv->execute([$userId, $userId]);
            $conversas = $stmtConv->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($conversas as $conv) {
                $convId = (int) ($conv['id'] ?? 0);
                $client->send(json_encode([
                    'type' => 'new_conversation',
                    'conversa' => [
                        'id' => $convId,
                        'tipo' => (string) ($conv['tipo'] ?? ''),
                        'nome' => (string) ($conv['display_nome'] ?? $conv['nome'] ?? 'Conversa'),
                        'criado_em' => (string) ($conv['criado_em'] ?? ''),
                        'criado_por_nome' => (string) ($conv['criado_por_nome'] ?? ''),
                    ],
                ], JSON_UNESCAPED_UNICODE));

                if ($convId > 0) {
                    $client->lastSeenConversationId = max((int) $client->lastSeenConversationId, $convId);
                }
            }

            // Sincroniza mensagens recentes (últimas 100 de todas as conversas)
            $stmtMsg = $pdo->prepare(
                'SELECT m.id, m.conteudo, m.arquivo_path, m.arquivo_nome, m.criado_em,
                        u.id AS usuario_id, u.nome AS usuario_nome,
                        m.conversa_id
                 FROM mensagens m
                 INNER JOIN usuarios u ON u.id = m.usuario_id
                 INNER JOIN participantes p ON p.conversa_id = m.conversa_id AND p.usuario_id = ?
                 WHERE (p.entrou_em IS NULL OR m.criado_em >= p.entrou_em)
                 ORDER BY m.id DESC
                 LIMIT 100'
            );
            $stmtMsg->execute([$userId]);
            $mensagens = array_reverse($stmtMsg->fetchAll(\PDO::FETCH_ASSOC));

            foreach ($mensagens as $msg) {
                $client->send(json_encode([
                    'type' => 'new_message',
                    'message' => $msg,
                ], JSON_UNESCAPED_UNICODE));

                $msgId = (int) ($msg['id'] ?? 0);
                if ($msgId > 0) {
                    $client->lastSeenMessageId = max((int) $client->lastSeenMessageId, $msgId);
                }
            }
        } catch (\Throwable $e) {
            error_log('Falha na sincronizacao inicial: ' . $e->getMessage());
        }
    }

    private function sincronizarNovasConversas(ConnectionInterface $client): void
    {
        $pdo = getDbConnection();
        $ultimoId = (int) ($client->lastSeenConversationId ?? 0);

        $stmt = $pdo->prepare(
            "SELECT c.id, c.tipo, c.nome, c.criado_em,
                    criador.nome AS criado_por_nome,
                    CASE
                        WHEN c.tipo = 'privada' THEN (
                            SELECT u.nome FROM usuarios u
                            INNER JOIN participantes p2 ON p2.usuario_id = u.id
                            WHERE p2.conversa_id = c.id AND u.id != ?
                            LIMIT 1
                        )
                        ELSE c.nome
                    END AS display_nome
             FROM conversas c
             INNER JOIN participantes p ON p.conversa_id = c.id AND p.usuario_id = ?
             LEFT JOIN usuarios criador ON criador.id = c.criado_por
             WHERE c.id > ?
             ORDER BY c.id ASC
             LIMIT 100"
        );
        $stmt->execute([(int) $client->userId, (int) $client->userId, $ultimoId]);
        $novas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$novas) {
            return;
        }

        foreach ($novas as $conv) {
            $client->send(json_encode([
                'type' => 'new_conversation',
                'conversa' => [
                    'id' => (int) ($conv['id'] ?? 0),
                    'tipo' => (string) ($conv['tipo'] ?? ''),
                    'nome' => (string) ($conv['display_nome'] ?? $conv['nome'] ?? 'Conversa'),
                    'criado_em' => (string) ($conv['criado_em'] ?? ''),
                    'criado_por_nome' => (string) ($conv['criado_por_nome'] ?? ''),
                ],
            ], JSON_UNESCAPED_UNICODE));

            $convId = (int) ($conv['id'] ?? 0);
            if ($convId > 0) {
                $client->lastSeenConversationId = max((int) $client->lastSeenConversationId, $convId);
            }
        }
    }

    private function sincronizarNovasMensagens(ConnectionInterface $client): void
    {
        $pdo = getDbConnection();
        $ultimoId = (int) ($client->lastSeenMessageId ?? 0);

        $stmt = $pdo->prepare(
            'SELECT m.id, m.conteudo, m.arquivo_path, m.arquivo_nome, m.criado_em,
                    u.id AS usuario_id, u.nome AS usuario_nome,
                    m.conversa_id
             FROM mensagens m
             INNER JOIN usuarios u ON u.id = m.usuario_id
             INNER JOIN participantes p ON p.conversa_id = m.conversa_id AND p.usuario_id = ?
             WHERE m.id > ?
               AND (p.entrou_em IS NULL OR m.criado_em >= p.entrou_em)
             ORDER BY m.id ASC
             LIMIT 200'
        );
        $stmt->execute([(int) $client->userId, $ultimoId]);
        $novas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$novas) {
            return;
        }

        foreach ($novas as $msg) {
            $client->send(json_encode([
                'type' => 'new_message',
                'message' => $msg,
            ], JSON_UNESCAPED_UNICODE));

            $msgId = (int) ($msg['id'] ?? 0);
            if ($msgId > 0) {
                $client->lastSeenMessageId = max((int) $client->lastSeenMessageId, $msgId);
            }
        }
    }

    private function sincronizarApagamentos(ConnectionInterface $client): void
    {
        $pdo = getDbConnection();

        if (!$this->columnExists($pdo, 'mensagens', 'excluida_em')) {
            return;
        }

        $ultimoApagamento = (string) ($client->lastSeenDeletionAt ?? '1970-01-01 00:00:00');

        $stmt = $pdo->prepare(
            'SELECT m.id, m.conversa_id, m.excluida_em
             FROM mensagens m
             INNER JOIN participantes p ON p.conversa_id = m.conversa_id AND p.usuario_id = ?
             WHERE m.excluida_em IS NOT NULL
               AND m.excluida_em > ?
               AND m.criado_em >= p.entrou_em
             ORDER BY m.excluida_em ASC
             LIMIT 200'
        );
        $stmt->execute([(int) $client->userId, $ultimoApagamento]);
        $apagadas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$apagadas) {
            return;
        }

        foreach ($apagadas as $apagada) {
            $client->send(json_encode([
                'type' => 'message_deleted',
                'message_id' => (int) ($apagada['id'] ?? 0),
                'conversa_id' => (int) ($apagada['conversa_id'] ?? 0),
                'deleted_at' => (string) ($apagada['excluida_em'] ?? ''),
            ], JSON_UNESCAPED_UNICODE));
        }

        $ultima = end($apagadas);
        if ($ultima && !empty($ultima['excluida_em'])) {
            $client->lastSeenDeletionAt = (string) $ultima['excluida_em'];
        }
    }

    private function arquivarAgendamentosVencidos(): void
    {
        try {
            $pdo = getDbConnection();
            $pdo->prepare(
                "UPDATE agendamentos
                 SET status = 'em_avaliacao', avaliado_em = NOW()
                 WHERE status = 'agendado' AND data_fim < NOW()"
            )->execute();
        } catch (\Throwable $e) {
            error_log('Falha ao arquivar agendamentos vencidos: ' . $e->getMessage());
        }
    }

    private function sincronizarAgendamentos(ConnectionInterface $client): void
    {
        $pdo = getDbConnection();
        $ultimoUpdate = (string) ($client->lastSeenAgendamentoUpdateAt ?? '1970-01-01 00:00:00');
        $papel = (string) ($client->userPapel ?? $this->buscarPapelUsuario((int) ($client->userId ?? 0)));
        $userId = (int) ($client->userId ?? 0);

        if ($userId <= 0) {
            return;
        }

        $whereVisibilidade = in_array($papel, ['admin', 'ti'], true)
            ? '1 = 1'
            : 'a.solicitante_id = ?';

        $sql = "SELECT a.id, a.servico_id, s.nome AS servico_nome, s.cor_hex, a.solicitante_id,
                       u.nome AS solicitante_nome, a.status, a.data_inicio, a.data_fim,
                       a.observacoes, a.aprovado_em, a.cancelado_em, a.encerrado_em, a.atualizado_em
                FROM agendamentos a
                INNER JOIN servicos_agendamento s ON s.id = a.servico_id
                INNER JOIN usuarios u ON u.id = a.solicitante_id
                WHERE a.atualizado_em > ? AND {$whereVisibilidade}
                ORDER BY a.atualizado_em ASC, a.id ASC";

        $stmt = $pdo->prepare($sql);
        $params = [$ultimoUpdate];
        if (!in_array($papel, ['admin', 'ti'], true)) {
            $params[] = $userId;
        }
        $stmt->execute($params);
        $agendamentos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$agendamentos) {
            return;
        }

        foreach ($agendamentos as $agendamento) {
            $payload = json_encode([
                'type' => 'schedule_updated',
                'agendamento' => $agendamento,
            ], JSON_UNESCAPED_UNICODE);

            if (!$payload) {
                continue;
            }

            $client->send($payload);
        }

        $ultimo = end($agendamentos);
        if ($ultimo && !empty($ultimo['atualizado_em'])) {
            $client->lastSeenAgendamentoUpdateAt = (string) $ultimo['atualizado_em'];
        }
    }

    private function sincronizarNotificacoes(ConnectionInterface $client): void
    {
        $pdo = getDbConnection();
        $ultimoId = (int) ($client->lastSeenNotificationId ?? 0);
        $userId = (int) ($client->userId ?? 0);

        if ($userId <= 0) {
            return;
        }

        try {
            $novas = NotificationCenter::listarNovas($pdo, $userId, $ultimoId);
            if (!$novas) {
                return;
            }

            foreach ($novas as $notificacao) {
                $payload = json_encode([
                    'type' => 'notification_created',
                    'notification' => $notificacao,
                ], JSON_UNESCAPED_UNICODE);

                if ($payload) {
                    $client->send($payload);
                }

                $notifId = (int) ($notificacao['id'] ?? 0);
                if ($notifId > 0) {
                    $client->lastSeenNotificationId = max((int) $client->lastSeenNotificationId, $notifId);
                }
            }
        } catch (\Throwable $e) {
            error_log('Falha ao sincronizar notificacoes: ' . $e->getMessage());
        }
    }

    private function buscarPapelUsuario(int $userId): string
    {
        if ($userId <= 0) {
            return 'usuario';
        }

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('SELECT papel FROM usuarios WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            return (string) ($stmt->fetchColumn() ?: 'usuario');
        } catch (\Throwable) {
            return 'usuario';
        }
    }

}
