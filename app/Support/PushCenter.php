<?php
declare(strict_types=1);

namespace App\Support;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;

/**
 * Web Push (VAPID) — API única, no mesmo espírito do NotificationCenter.
 *
 * O desenho é uma outbox: quem produz o aviso (controllers HTTP via
 * NotificationCenter::registrar, ou o ChatServer numa mensagem de chat) chama
 * apenas enfileirar*() e segue a vida. Só o bin/push-worker.php chama
 * processarLote(), porque falar com FCM/Mozilla é HTTP bloqueante e não pode
 * entrar nem no caminho de uma request nem no loop de 0,8s do Ratchet.
 */
class PushCenter
{
    /** Máximo de itens que o worker reserva por ciclo. */
    public const LOTE_PADRAO = 20;

    /** Depois disso o aviso perdeu a validade e não vale mais interromper ninguém. */
    private const VALIDADE_MINUTOS = 60;

    /** Tentativas por item antes de desistir (backoff exponencial em minutos). */
    private const MAX_TENTATIVAS = 5;

    /** Falhas consecutivas de um endpoint antes de considerá-lo morto. */
    private const MAX_FALHAS_INSCRICAO = 10;

    /**
     * Janela em que uma linha de user_presenca ainda é confiável. O ChatServer
     * renova last_seen a cada 30s, então 90s tolera dois ciclos perdidos.
     */
    private const PRESENCA_SEGUNDOS = 90;

    public static function habilitado(): bool
    {
        return self::chavePublica() !== '' && self::chavePrivada() !== '';
    }

    public static function chavePublica(): string
    {
        return trim((string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''));
    }

    private static function chavePrivada(): string
    {
        return trim((string) ($_ENV['VAPID_PRIVATE_KEY'] ?? ''));
    }

    private static function assunto(): string
    {
        $assunto = trim((string) ($_ENV['VAPID_SUBJECT'] ?? ''));

        return $assunto !== '' ? $assunto : 'mailto:admin@localhost';
    }

    // ------------------------------------------------------------------
    // Produção (chamado por FPM e pelo ChatServer)
    // ------------------------------------------------------------------

    /**
     * Enfileira um push para um destinatário.
     *
     * Não faz nada se o usuário não tem nenhuma inscrição — a fila fica vazia
     * para quem nunca ativou o recurso, que é a maioria.
     *
     * @param array{usuario_id:int,titulo:string,corpo:string,origem?:string,url?:string,tag?:string,chave_dedupe?:string,notificacao_id?:int|null} $dados
     */
    public static function enfileirar(PDO $pdo, array $dados): bool
    {
        $usuarioId = (int) ($dados['usuario_id'] ?? 0);
        $titulo = self::limitar((string) ($dados['titulo'] ?? ''), 120);
        $corpo = self::limitar((string) ($dados['corpo'] ?? ''), 300);

        if ($usuarioId <= 0 || $titulo === '' || $corpo === '') {
            return false;
        }

        $origem = (string) ($dados['origem'] ?? 'sistema');
        if (!in_array($origem, ['notificacao', 'mensagem', 'sistema'], true)) {
            $origem = 'sistema';
        }

        $notificacaoId = isset($dados['notificacao_id']) ? (int) $dados['notificacao_id'] : 0;
        $chaveDedupe = self::limitar((string) ($dados['chave_dedupe'] ?? ''), 190);
        if ($chaveDedupe === '') {
            $chaveDedupe = $origem . ':' . $usuarioId . ':' . substr(sha1($titulo . '|' . $corpo), 0, 40);
        }

        try {
            // O WHERE EXISTS evita encher a fila de quem não tem dispositivo
            // inscrito. O ON DUPLICATE ... id = id transforma um reenqueue do
            // mesmo fato em no-op (é o que neutraliza o upsert do
            // NotificationCenter::registrar, que devolve sempre o mesmo id).
            $stmt = $pdo->prepare(
                // FROM DUAL é obrigatório: no MySQL um SELECT com WHERE e sem
                // FROM é erro de sintaxe.
                'INSERT INTO push_fila
                    (usuario_id, origem, notificacao_id, chave_dedupe, titulo, corpo, url, tag)
                 SELECT ?, ?, ?, ?, ?, ?, ?, ?
                   FROM DUAL
                  WHERE EXISTS (SELECT 1 FROM push_subscriptions WHERE usuario_id = ? LIMIT 1)
                 ON DUPLICATE KEY UPDATE push_fila.id = push_fila.id'
            );
            $stmt->execute([
                $usuarioId,
                $origem,
                $notificacaoId > 0 ? $notificacaoId : null,
                $chaveDedupe,
                $titulo,
                $corpo,
                self::limitarOuNulo((string) ($dados['url'] ?? ''), 255),
                self::limitarOuNulo((string) ($dados['tag'] ?? ''), 120),
                $usuarioId, // WHERE EXISTS
            ]);

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('PushCenter::enfileirar falhou: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Mesmo aviso para vários destinatários. `chave_base` ganha o id de cada um,
     * mantendo a dedupe por usuário (mesmo padrão do
     * NotificationCenter::registrarParaPapeis).
     *
     * @param int[] $usuarioIds
     */
    public static function enfileirarParaUsuarios(PDO $pdo, array $usuarioIds, array $dados): int
    {
        $chaveBase = (string) ($dados['chave_base'] ?? '');
        $enfileirados = 0;

        foreach (array_unique(array_map('intval', $usuarioIds)) as $usuarioId) {
            if ($usuarioId <= 0) {
                continue;
            }

            $enfileirados += self::enfileirar($pdo, array_merge($dados, [
                'usuario_id' => $usuarioId,
                'chave_dedupe' => $chaveBase !== '' ? ($chaveBase . ':' . $usuarioId) : '',
            ])) ? 1 : 0;
        }

        return $enfileirados;
    }

    /**
     * Adapta uma linha de `notificacoes` (já normalizada) para a fila.
     *
     * A chave é o id da notificação: como registrar() faz upsert e devolve o
     * mesmo id ao reprocessar um evento, o segundo enqueue é descartado sozinho.
     */
    public static function enfileirarDeNotificacao(PDO $pdo, array $notificacao): bool
    {
        $id = (int) ($notificacao['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        return self::enfileirar($pdo, [
            'usuario_id' => (int) ($notificacao['usuario_id'] ?? 0),
            'origem' => 'notificacao',
            'notificacao_id' => $id,
            'chave_dedupe' => 'notif:' . $id,
            'titulo' => (string) ($notificacao['titulo'] ?? ''),
            'corpo' => (string) ($notificacao['mensagem'] ?? ''),
            'url' => (string) ($notificacao['url'] ?? '/notificacoes'),
            'tag' => 'notif:' . $id,
        ]);
    }

    // ------------------------------------------------------------------
    // Consumo (exclusivo do bin/push-worker.php)
    // ------------------------------------------------------------------

    /**
     * Reserva um lote de pendentes, decide o que ainda vale enviar e despacha.
     *
     * @return int quantidade de itens tirados da fila (enviados ou descartados)
     */
    public static function processarLote(PDO $pdo, int $limite = self::LOTE_PADRAO): int
    {
        if (!self::habilitado()) {
            return 0;
        }

        $itens = self::reservarLote($pdo, max(1, min($limite, 100)));
        if (!$itens) {
            return 0;
        }

        $webPush = self::criarWebPush();

        // O MessageSentReport só identifica o destino pelo endpoint, então cada
        // endpoint pode receber no máximo uma mensagem por flush. O que colidir
        // volta para 'pendente' e sai no ciclo seguinte.
        $endpointsUsados = [];
        $itensPorEndpoint = [];
        $adiados = [];
        $processados = 0;

        foreach ($itens as $item) {
            $motivo = self::motivoDescarte($pdo, $item);
            if ($motivo !== null) {
                self::marcar($pdo, (int) $item['id'], 'descartado', $motivo);
                $processados++;
                continue;
            }

            $inscricoes = self::inscricoesDoUsuario($pdo, (int) $item['usuario_id']);
            if (!$inscricoes) {
                self::marcar($pdo, (int) $item['id'], 'descartado', 'sem_inscricao');
                $processados++;
                continue;
            }

            $enviou = false;
            $colidiu = false;
            foreach ($inscricoes as $inscricao) {
                $endpoint = (string) $inscricao['endpoint'];
                if (isset($endpointsUsados[$endpoint])) {
                    $colidiu = true;
                    continue;
                }
                $endpointsUsados[$endpoint] = true;

                // Só entra no mapa se de fato foi enfileirado: senão nenhum
                // relatório chegaria por esse endpoint e o item viraria órfão.
                if (self::enfileirarNoWebPush($pdo, $webPush, $inscricao, $item)) {
                    $itensPorEndpoint[$endpoint] = (int) $item['id'];
                    $enviou = true;
                }
            }

            if (!$enviou) {
                if ($colidiu) {
                    // Os endpoints do usuário já estão neste flush por causa de
                    // outro item: adia sem gastar tentativa.
                    $adiados[] = (int) $item['id'];
                } else {
                    // Nenhuma inscrição utilizável: conta tentativa para não
                    // ficar girando na fila para sempre.
                    self::reagendar($pdo, (int) $item['id'], 'inscricao_invalida');
                    $processados++;
                }
            }
        }

        if ($adiados) {
            self::devolverParaFila($pdo, $adiados);
        }

        $processados += self::coletarRelatorios($pdo, $webPush, $itensPorEndpoint);

        return $processados;
    }

    /**
     * Devolve à fila o que ficou preso em 'processando' por um shutdown abrupto.
     * Chamado no boot do worker, que é o único consumidor da tabela.
     */
    public static function destravarPendentes(PDO $pdo): int
    {
        try {
            return (int) $pdo->exec("UPDATE push_fila SET status = 'pendente' WHERE status = 'processando'");
        } catch (\Throwable $e) {
            error_log('PushCenter::destravarPendentes falhou: ' . $e->getMessage());

            return 0;
        }
    }

    public static function limparAntigos(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "DELETE FROM push_fila
                  WHERE status IN ('enviado','descartado','falha')
                    AND criado_em < NOW() - INTERVAL 7 DAY"
            );
        } catch (\Throwable $e) {
            error_log('PushCenter::limparAntigos falhou: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private static function criarWebPush(): WebPush
    {
        $webPush = new WebPush(
            [
                'VAPID' => [
                    'subject' => self::assunto(),
                    'publicKey' => self::chavePublica(),
                    'privateKey' => self::chavePrivada(),
                ],
            ],
            // TTL curto: se o worker ficar parado, ninguém quer receber o aviso
            // de um chamado horas depois.
            ['TTL' => 600, 'urgency' => 'normal'],
            10
        );
        $webPush->setReuseVAPIDHeaders(true);

        return $webPush;
    }

    /** @return array<int, array<string, mixed>> */
    private static function reservarLote(PDO $pdo, int $limite): array
    {
        try {
            $pdo->beginTransaction();

            // SKIP LOCKED deixa a reserva segura mesmo se um dia rodar mais de
            // um worker em paralelo.
            $stmt = $pdo->prepare(
                "SELECT id, usuario_id, origem, notificacao_id, chave_dedupe, titulo, corpo, url, tag, tentativas, criado_em
                   FROM push_fila
                  WHERE status = 'pendente' AND disponivel_em <= NOW()
                  ORDER BY id ASC
                  LIMIT {$limite}
                    FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute();
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($itens) {
                $ids = array_map(static fn (array $i): int => (int) $i['id'], $itens);
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE push_fila SET status = 'processando' WHERE id IN ({$marcadores})")
                    ->execute($ids);
            }

            $pdo->commit();

            return $itens;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('PushCenter::reservarLote falhou: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Razão para não enviar, ou null se o push ainda faz sentido.
     */
    private static function motivoDescarte(PDO $pdo, array $item): ?string
    {
        $criadoEm = strtotime((string) ($item['criado_em'] ?? '')) ?: 0;
        if ($criadoEm > 0 && $criadoEm < time() - (self::VALIDADE_MINUTOS * 60)) {
            return 'expirado';
        }

        // Quem está com WebSocket vivo já é atendido por notificacoes.js/chat.js
        // (toast ou Notification na própria aba). Mandar push também renderia
        // dois avisos para o mesmo fato.
        if (self::usuarioOnline($pdo, (int) $item['usuario_id'])) {
            return 'usuario_online';
        }

        $notificacaoId = (int) ($item['notificacao_id'] ?? 0);
        if ($notificacaoId > 0 && self::notificacaoJaLida($pdo, $notificacaoId)) {
            return 'ja_lida';
        }

        return null;
    }

    private static function usuarioOnline(PDO $pdo, int $usuarioId): bool
    {
        try {
            // A janela é constante de classe e entra interpolada de propósito:
            // com prepares reais o MySQL recusa um placeholder em INTERVAL.
            $stmt = $pdo->prepare(
                'SELECT 1 FROM user_presenca
                  WHERE usuario_id = ? AND online = 1
                    AND last_seen >= NOW() - INTERVAL ' . self::PRESENCA_SEGUNDOS . ' SECOND
                  LIMIT 1'
            );
            $stmt->execute([$usuarioId]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Sem presença confiável é melhor entregar: o SW ainda suprime o
            // popup se achar uma aba visível.
            error_log('PushCenter::usuarioOnline falhou: ' . $e->getMessage());

            return false;
        }
    }

    private static function notificacaoJaLida(PDO $pdo, int $notificacaoId): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT lida_em FROM notificacoes WHERE id = ? LIMIT 1');
            $stmt->execute([$notificacaoId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);

            return $linha !== false && $linha['lida_em'] !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function inscricoesDoUsuario(PDO $pdo, int $usuarioId): array
    {
        try {
            $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE usuario_id = ?');
            $stmt->execute([$usuarioId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('PushCenter::inscricoesDoUsuario falhou: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Valida o material criptográfico antes de entrar no flush.
     *
     * A cifragem do payload só acontece em flush(), e uma chave malformada faz
     * o gerador lançar exceção antes de emitir qualquer relatório — envenenando
     * o lote inteiro. Barrar aqui mantém o flush limpo.
     */
    private static function chavesValidas(array $inscricao): bool
    {
        $p256dh = self::decodificarBase64Url((string) ($inscricao['p256dh'] ?? ''));
        $auth = self::decodificarBase64Url((string) ($inscricao['auth'] ?? ''));

        return $p256dh !== null && strlen($p256dh) === 65
            && $auth !== null && strlen($auth) === 16;
    }

    private static function decodificarBase64Url(string $valor): ?string
    {
        if ($valor === '') {
            return null;
        }

        $base64 = strtr($valor, '-_', '+/');
        $base64 .= str_repeat('=', (4 - (strlen($base64) % 4)) % 4);
        $bruto = base64_decode($base64, true);

        return $bruto === false ? null : $bruto;
    }

    private static function enfileirarNoWebPush(PDO $pdo, WebPush $webPush, array $inscricao, array $item): bool
    {
        $endpoint = (string) $inscricao['endpoint'];

        if (!self::chavesValidas($inscricao)) {
            // Nunca vai funcionar: remove em vez de deixar envenenando os lotes.
            error_log('PushCenter: inscrição com chaves inválidas, removendo: ' . $endpoint);
            self::removerInscricao($pdo, $endpoint);

            return false;
        }

        try {
            $assinatura = Subscription::create([
                'endpoint' => (string) $inscricao['endpoint'],
                'keys' => [
                    'p256dh' => (string) $inscricao['p256dh'],
                    'auth' => (string) $inscricao['auth'],
                ],
            ]);

            $payload = json_encode([
                'titulo' => (string) $item['titulo'],
                'corpo' => (string) $item['corpo'],
                'url' => (string) ($item['url'] ?? '/notificacoes'),
                'tag' => (string) ($item['tag'] ?? $item['chave_dedupe']),
                'origem' => (string) $item['origem'],
                'notificacao_id' => $item['notificacao_id'] !== null ? (int) $item['notificacao_id'] : 0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $webPush->queueNotification($assinatura, $payload !== false ? $payload : null);

            return true;
        } catch (\Throwable $e) {
            error_log('PushCenter: inscrição inválida (' . $inscricao['endpoint'] . '): ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, int> $itensPorEndpoint endpoint => id do item
     */
    private static function coletarRelatorios(PDO $pdo, WebPush $webPush, array $itensPorEndpoint): int
    {
        if (!$itensPorEndpoint) {
            return 0;
        }

        $resolvidos = [];
        $sucessos = [];
        $falhas = [];

        try {
            // Coleta antes de decidir: um usuário com dois dispositivos, um deles
            // morto, não pode ter o item reagendado por causa do endpoint ruim
            // quando o outro já recebeu.
            foreach ($webPush->flush() as $relatorio) {
                if ($relatorio->isSuccess()) {
                    $sucessos[] = $relatorio;
                } else {
                    $falhas[] = $relatorio;
                }
            }
        } catch (\Throwable $e) {
            error_log('PushCenter::coletarRelatorios falhou: ' . $e->getMessage());
        }

        foreach ($sucessos as $relatorio) {
            $endpoint = (string) $relatorio->getEndpoint();
            self::registrarSucessoInscricao($pdo, $endpoint);

            $itemId = $itensPorEndpoint[$endpoint] ?? 0;
            if ($itemId > 0 && !isset($resolvidos[$itemId])) {
                self::marcar($pdo, $itemId, 'enviado', null);
                $resolvidos[$itemId] = true;
            }
        }

        foreach ($falhas as $relatorio) {
            $endpoint = (string) $relatorio->getEndpoint();
            $motivo = substr((string) $relatorio->getReason(), 0, 255);

            if ($relatorio->isSubscriptionExpired()) {
                // 404/410: o navegador descartou a inscrição, não adianta insistir.
                self::removerInscricao($pdo, $endpoint);
            } else {
                self::registrarFalhaInscricao($pdo, $endpoint);
                error_log('PushCenter falha em ' . $endpoint . ': ' . $motivo);
            }

            $itemId = $itensPorEndpoint[$endpoint] ?? 0;
            if ($itemId > 0 && !isset($resolvidos[$itemId])) {
                self::reagendar($pdo, $itemId, $motivo);
                $resolvidos[$itemId] = true;
            }
        }

        // Item sem relatório (exceção no meio do flush) não pode ficar preso em
        // 'processando' — mas também não pode voltar a 'pendente' de graça: sem
        // contar tentativa, uma falha determinística giraria a fila para sempre.
        $orfaos = array_values(array_diff(array_values($itensPorEndpoint), array_keys($resolvidos)));
        foreach ($orfaos as $itemId) {
            self::reagendar($pdo, (int) $itemId, 'sem_relatorio_no_flush');
        }

        return count($resolvidos) + count($orfaos);
    }

    private static function marcar(PDO $pdo, int $itemId, string $status, ?string $erro): void
    {
        try {
            $stmt = $pdo->prepare(
                'UPDATE push_fila SET status = ?, ultimo_erro = ?, processado_em = NOW() WHERE id = ?'
            );
            $stmt->execute([$status, $erro, $itemId]);
        } catch (\Throwable $e) {
            error_log('PushCenter::marcar falhou: ' . $e->getMessage());
        }
    }

    /** Backoff exponencial: 2, 4, 8, 16 minutos; desiste na MAX_TENTATIVAS. */
    private static function reagendar(PDO $pdo, int $itemId, string $erro): void
    {
        try {
            // O MySQL avalia os SET na ordem escrita e as cláusulas seguintes já
            // enxergam o valor novo — por isso `tentativas` é incrementada por
            // último e as demais usam `tentativas + 1`.
            $stmt = $pdo->prepare(
                "UPDATE push_fila
                    SET ultimo_erro = ?,
                        status = IF(tentativas + 1 >= ?, 'falha', 'pendente'),
                        disponivel_em = NOW() + INTERVAL (POW(2, LEAST(tentativas + 1, 5))) MINUTE,
                        processado_em = IF(tentativas + 1 >= ?, NOW(), processado_em),
                        tentativas = tentativas + 1
                  WHERE id = ?"
            );
            $stmt->execute([$erro, self::MAX_TENTATIVAS, self::MAX_TENTATIVAS, $itemId]);
        } catch (\Throwable $e) {
            error_log('PushCenter::reagendar falhou: ' . $e->getMessage());
        }
    }

    /** @param int[] $ids */
    private static function devolverParaFila(PDO $pdo, array $ids): void
    {
        if (!$ids) {
            return;
        }

        try {
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE push_fila SET status = 'pendente' WHERE id IN ({$marcadores})")->execute($ids);
        } catch (\Throwable $e) {
            error_log('PushCenter::devolverParaFila falhou: ' . $e->getMessage());
        }
    }

    private static function registrarSucessoInscricao(PDO $pdo, string $endpoint): void
    {
        try {
            $pdo->prepare('UPDATE push_subscriptions SET falhas = 0, ultimo_uso_em = NOW() WHERE endpoint = ?')
                ->execute([$endpoint]);
        } catch (\Throwable $e) {
            error_log('PushCenter::registrarSucessoInscricao falhou: ' . $e->getMessage());
        }
    }

    private static function registrarFalhaInscricao(PDO $pdo, string $endpoint): void
    {
        try {
            $pdo->prepare('UPDATE push_subscriptions SET falhas = falhas + 1 WHERE endpoint = ?')->execute([$endpoint]);
            $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND falhas >= ?')
                ->execute([$endpoint, self::MAX_FALHAS_INSCRICAO]);
        } catch (\Throwable $e) {
            error_log('PushCenter::registrarFalhaInscricao falhou: ' . $e->getMessage());
        }
    }

    /** 404/410: o navegador jogou fora a inscrição, não adianta insistir. */
    private static function removerInscricao(PDO $pdo, string $endpoint): void
    {
        try {
            $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
        } catch (\Throwable $e) {
            error_log('PushCenter::removerInscricao falhou: ' . $e->getMessage());
        }
    }

    private static function limitar(string $valor, int $tamanho): string
    {
        return trim(mb_substr(trim($valor), 0, $tamanho));
    }

    private static function limitarOuNulo(string $valor, int $tamanho): ?string
    {
        $valor = self::limitar($valor, $tamanho);

        return $valor !== '' ? $valor : null;
    }
}
