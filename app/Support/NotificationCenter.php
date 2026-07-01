<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

class NotificationCenter
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS notificacoes (\n                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                usuario_id     INT UNSIGNED NOT NULL,\n                tipo           ENUM('chamado','agendamento','sistema') NOT NULL DEFAULT 'sistema',\n                evento         VARCHAR(80) NOT NULL,\n                chave_evento   VARCHAR(190) NOT NULL,\n                entidade       VARCHAR(60) NOT NULL,\n                entidade_id    INT UNSIGNED NOT NULL,\n                titulo         VARCHAR(255) NOT NULL,\n                mensagem       TEXT NOT NULL,\n                url            VARCHAR(255) NULL,\n                status_origem  VARCHAR(50) NULL,\n                status_destino VARCHAR(50) NULL,\n                metadados      JSON NULL,\n                lida_em        TIMESTAMP NULL DEFAULT NULL,\n                criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                UNIQUE KEY uniq_notificacoes_chave (usuario_id, chave_evento),\n                INDEX idx_notificacoes_usuario_lida_criado (usuario_id, lida_em, criado_em),\n                INDEX idx_notificacoes_usuario_id (usuario_id, id),\n                FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");
    }

    public static function registrar(PDO $pdo, array $dados): ?array
    {
        $usuarioId = (int) ($dados['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            return null;
        }

        $tipo = (string) ($dados['tipo'] ?? 'sistema');
        $evento = trim((string) ($dados['evento'] ?? ''));
        $entidade = trim((string) ($dados['entidade'] ?? ''));
        $entidadeId = (int) ($dados['entidade_id'] ?? 0);
        $titulo = trim((string) ($dados['titulo'] ?? ''));
        $mensagem = trim((string) ($dados['mensagem'] ?? ''));
        $url = trim((string) ($dados['url'] ?? ''));
        $statusOrigem = array_key_exists('status_origem', $dados) ? self::normalizarValor($dados['status_origem']) : null;
        $statusDestino = array_key_exists('status_destino', $dados) ? self::normalizarValor($dados['status_destino']) : null;
        $metadados = $dados['metadados'] ?? [];
        $chaveEvento = trim((string) ($dados['chave_evento'] ?? ''));

        if ($evento === '' || $entidade === '' || $entidadeId <= 0 || $titulo === '' || $mensagem === '') {
            return null;
        }

        if ($chaveEvento === '') {
            $chaveEvento = implode(':', [$usuarioId, $tipo, $evento, $entidade, $entidadeId]);
        }

        $jsonMetadados = null;
        if (is_array($metadados) && !empty($metadados)) {
            $jsonMetadados = json_encode($metadados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO notificacoes
                (usuario_id, tipo, evento, chave_evento, entidade, entidade_id, titulo, mensagem, url, status_origem, status_destino, metadados)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tipo = VALUES(tipo),
                evento = VALUES(evento),
                entidade = VALUES(entidade),
                entidade_id = VALUES(entidade_id),
                titulo = VALUES(titulo),
                mensagem = VALUES(mensagem),
                url = VALUES(url),
                status_origem = VALUES(status_origem),
                status_destino = VALUES(status_destino),
                metadados = VALUES(metadados),
                atualizado_em = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $usuarioId,
            $tipo,
            $evento,
            $chaveEvento,
            $entidade,
            $entidadeId,
            $titulo,
            $mensagem,
            $url !== '' ? $url : null,
            $statusOrigem,
            $statusDestino,
            $jsonMetadados,
        ]);

        return self::buscarPorChave($pdo, $usuarioId, $chaveEvento);
    }

    public static function listar(PDO $pdo, int $usuarioId, int $limite = 50): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $limite = max(1, min($limite, 200));
        $stmt = $pdo->prepare(
            'SELECT id, usuario_id, tipo, evento, chave_evento, entidade, entidade_id, titulo, mensagem, url,
                    status_origem, status_destino, metadados, lida_em, criado_em, atualizado_em
             FROM notificacoes
             WHERE usuario_id = ?
             ORDER BY criado_em DESC, id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'normalizarLinha'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function listarNovas(PDO $pdo, int $usuarioId, int $ultimoId): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT id, usuario_id, tipo, evento, chave_evento, entidade, entidade_id, titulo, mensagem, url,
                    status_origem, status_destino, metadados, lida_em, criado_em, atualizado_em
             FROM notificacoes
             WHERE usuario_id = ? AND id > ?
             ORDER BY id ASC
             LIMIT 200'
        );
        $stmt->execute([$usuarioId, $ultimoId]);

        return array_map([self::class, 'normalizarLinha'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function contarNaoLidas(PDO $pdo, int $usuarioId): int
    {
        if ($usuarioId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida_em IS NULL');
        $stmt->execute([$usuarioId]);

        return (int) $stmt->fetchColumn();
    }

    public static function marcarComoLida(PDO $pdo, int $usuarioId, int $notificacaoId): bool
    {
        if ($usuarioId <= 0 || $notificacaoId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare('UPDATE notificacoes SET lida_em = COALESCE(lida_em, NOW()) WHERE id = ? AND usuario_id = ?');
        $stmt->execute([$notificacaoId, $usuarioId]);

        return $stmt->rowCount() > 0;
    }

    public static function marcarTodasComoLidas(PDO $pdo, int $usuarioId): int
    {
        if ($usuarioId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare('UPDATE notificacoes SET lida_em = COALESCE(lida_em, NOW()) WHERE usuario_id = ? AND lida_em IS NULL');
        $stmt->execute([$usuarioId]);

        return $stmt->rowCount();
    }

    public static function buscarPorChave(PDO $pdo, int $usuarioId, string $chaveEvento): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, usuario_id, tipo, evento, chave_evento, entidade, entidade_id, titulo, mensagem, url,
                    status_origem, status_destino, metadados, lida_em, criado_em, atualizado_em
             FROM notificacoes
             WHERE usuario_id = ? AND chave_evento = ?
             LIMIT 1'
        );
        $stmt->execute([$usuarioId, $chaveEvento]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        return $linha ? self::normalizarLinha($linha) : null;
    }

    public static function resumo(PDO $pdo, int $usuarioId): array
    {
        return [
            'nao_lidas' => self::contarNaoLidas($pdo, $usuarioId),
            'total' => self::contarTotal($pdo, $usuarioId),
        ];
    }

    private static function contarTotal(PDO $pdo, int $usuarioId): int
    {
        if ($usuarioId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);

        return (int) $stmt->fetchColumn();
    }

    private static function normalizarLinha(array $linha): array
    {
        if (isset($linha['metadados']) && is_string($linha['metadados']) && $linha['metadados'] !== '') {
            $decodificado = json_decode($linha['metadados'], true);
            $linha['metadados'] = is_array($decodificado) ? $decodificado : [];
        } else {
            $linha['metadados'] = [];
        }

        return $linha;
    }

    private static function normalizarValor(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);
        return $valor !== '' ? $valor : null;
    }
}