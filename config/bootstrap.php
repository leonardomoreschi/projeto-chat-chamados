<?php
declare(strict_types=1);

function bootstrapDefaultData(): void
{
    $pdo = getDbConnection();

    $lockName = 'bootstrap_default_data';
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$lockName]);
    $lockAcquired = (int) $lockStmt->fetchColumn() === 1;

    try {
        ensureBootstrapMarkersTable($pdo);
        ensureUniqueSectorNames($pdo);
        ensureSectorDescriptionRemoved($pdo);
        ensureSessionVersionColumn($pdo);
        ensureNotificationsSchema($pdo);
        ensureParticipantsSchema($pdo);
        ensureAgendamentoSchema($pdo);
        seedDefaultServices($pdo);
        seedDefaultSectors($pdo);
        seedDefaultAdminUser($pdo);
    } finally {
        if ($lockAcquired) {
            $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlockStmt->execute([$lockName]);
        }
    }
}

/**
 * bootstrap_marcadores — registra que uma rotina de seed ja rodou neste banco.
 *
 * O bootstrap roda a cada request HTTP, entao um seed "INSERT ... WHERE NOT
 * EXISTS" ressuscita eternamente o que o admin apagou de proposito. Gravando um
 * marcador, o seed acontece uma unica vez na vida do banco. Espelha a tabela em
 * config/schema.sql (instalacao nova).
 */
function ensureBootstrapMarkersTable(PDO $pdo): void
{
    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }

    $alreadyChecked = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bootstrap_marcadores (
            chave     VARCHAR(100) NOT NULL PRIMARY KEY,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function bootstrapMarcadorAplicado(PDO $pdo, string $chave): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM bootstrap_marcadores WHERE chave = ? LIMIT 1');
    $stmt->execute([$chave]);

    return (bool) $stmt->fetchColumn();
}

function marcarBootstrapAplicado(PDO $pdo, string $chave): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO bootstrap_marcadores (chave) VALUES (?)');
    $stmt->execute([$chave]);
}

/**
 * setores.descricao nao era exibida nem usada em lugar nenhum — o campo saiu do
 * formulario do admin e a coluna sai junto. Espelha config/schema.sql.
 */
function ensureSectorDescriptionRemoved(PDO $pdo): void
{
    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }

    $alreadyChecked = true;

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'setores'
           AND column_name = 'descricao'"
    );

    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE setores DROP COLUMN descricao');
    } catch (\PDOException $e) {
        error_log('Nao foi possivel remover setores.descricao: ' . $e->getMessage());
    }
}

/**
 * usuarios.sessao_versao — contador que invalida sessoes abertas.
 *
 * Sobe quando o admin altera e-mail, senha ou papel, ou desativa a conta. O
 * AuthMiddleware compara com o valor gravado na sessao no login e derruba quem
 * ficou para tras. Espelha a coluna em config/schema.sql (instalacao nova).
 */
function ensureSessionVersionColumn(PDO $pdo): void
{
    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }

    $alreadyChecked = true;

    try {
        $existe = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'usuarios'
                AND column_name = 'sessao_versao'"
        )->fetchColumn();

        if ((int) $existe === 0) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN sessao_versao INT UNSIGNED NOT NULL DEFAULT 0 AFTER ativo');
        }
    } catch (\Throwable $e) {
        error_log('Nao foi possivel adicionar coluna sessao_versao em usuarios: ' . $e->getMessage());
    }
}

function ensureUniqueSectorNames(PDO $pdo): void
{
    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }

    $alreadyChecked = true;

    deduplicateSectors($pdo);

    $stmtIndex = $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'setores'
           AND index_name = 'uniq_setores_nome'"
    );
    $hasUniqueIndex = (int) $stmtIndex->fetchColumn() > 0;

    if (!$hasUniqueIndex) {
        try {
            $pdo->exec('ALTER TABLE setores ADD UNIQUE KEY uniq_setores_nome (nome)');
        } catch (\PDOException $e) {
            deduplicateSectors($pdo);
            try {
                $pdo->exec('ALTER TABLE setores ADD UNIQUE KEY uniq_setores_nome (nome)');
            } catch (\PDOException $finalError) {
                error_log('Nao foi possivel criar uniq_setores_nome: ' . $finalError->getMessage());
            }
        }
    }
}

function deduplicateSectors(PDO $pdo): void
{
    $pdo->exec('UPDATE setores SET nome = TRIM(nome)');

    $rows = $pdo->query('SELECT id, nome FROM setores ORDER BY id ASC')->fetchAll();
    if (!$rows) {
        return;
    }

    $canonicalToKeepId = [];
    $duplicateIdToKeepId = [];

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['nome'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }

        $canonical = mb_strtolower($name, 'UTF-8');
        if (!isset($canonicalToKeepId[$canonical])) {
            $canonicalToKeepId[$canonical] = $id;
            continue;
        }

        $duplicateIdToKeepId[$id] = $canonicalToKeepId[$canonical];
    }

    foreach ($duplicateIdToKeepId as $duplicateId => $keepId) {
        $stmtUpdateUsers = $pdo->prepare('UPDATE usuarios SET setor_id = ? WHERE setor_id = ?');
        $stmtUpdateUsers->execute([$keepId, $duplicateId]);

        $stmtDeleteDup = $pdo->prepare('DELETE FROM setores WHERE id = ?');
        $stmtDeleteDup->execute([$duplicateId]);
    }
}

/**
 * Semeia os setores padrao uma unica vez por banco (marcador
 * `setores_padrao`). Sem o marcador, cada request HTTP reinseria a lista e o
 * setor que o admin acabara de excluir voltava sozinho na tela.
 */
function seedDefaultSectors(PDO $pdo): void
{
    if (bootstrapMarcadorAplicado($pdo, 'setores_padrao')) {
        return;
    }

    $defaultSectors = [
        'TI',
        'Administrativo',
        'Engenharia',
        'Financeiro',
        'Operacional',
        'Vendas',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO setores (nome)
         SELECT ?
         WHERE NOT EXISTS (
             SELECT 1 FROM setores WHERE nome = ? LIMIT 1
         )'
    );

    foreach ($defaultSectors as $name) {
        $stmt->execute([$name, $name]);
    }

    marcarBootstrapAplicado($pdo, 'setores_padrao');
}

function seedDefaultAdminUser(PDO $pdo): void
{
    $nome = trim((string) ($_ENV['ADMIN_NAME'] ?? 'Admin'));
    $email = trim((string) ($_ENV['ADMIN_EMAIL'] ?? 'admin@empresa.com'));
    $senha = (string) ($_ENV['ADMIN_PASSWORD'] ?? 'password');

    if ($nome === '') {
        $nome = 'Admin';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'admin@empresa.com';
    }

    $check = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetchColumn()) {
        return;
    }

    $stmtSetor = $pdo->prepare('SELECT id FROM setores WHERE nome = ? LIMIT 1');
    $stmtSetor->execute(['TI']);
    $setorId = $stmtSetor->fetchColumn();
    $setorId = $setorId !== false ? (int) $setorId : null;

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, setor_id, papel)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $nome,
        $email,
        password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]),
        $setorId,
        'admin',
    ]);
}

function seedDefaultServices(PDO $pdo): void
{
    $defaultServices = [
        ['Suporte Presencial', 'Atendimento presencial no local', '#28a745'],
        ['Consultoria Técnica', 'Sessão de consultoria técnica', '#007bff'],
        ['Instalação de Software', 'Instalação e configuração de software', '#fd7e14'],
    ];

    $adminId = null;
    try {
        $stmtAdmin = $pdo->query("SELECT id FROM usuarios WHERE papel = 'admin' LIMIT 1");
        $adminId = $stmtAdmin ? $stmtAdmin->fetchColumn() : null;
        $adminId = $adminId !== false ? (int) $adminId : null;
    } catch (\Throwable $e) {
        $adminId = null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO servicos_agendamento (nome, descricao, cor_hex, criado_por)
         SELECT ?, ?, ?, ?
         WHERE NOT EXISTS (
             SELECT 1 FROM servicos_agendamento WHERE nome = ? LIMIT 1
         )'
    );

    foreach ($defaultServices as [$nome, $descricao, $cor]) {
        try {
            $stmt->execute([$nome, $descricao, $cor, $adminId, $nome]);
        } catch (\Throwable $e) {
            error_log('seedDefaultServices: ' . $e->getMessage());
        }
    }
}

function ensureAgendamentoSchema(PDO $pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS servicos_agendamento (\n            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            nome          VARCHAR(150) NOT NULL,\n            descricao     TEXT NULL,\n            cor_hex       VARCHAR(12) NOT NULL DEFAULT '#4f46e5',\n            ativo         TINYINT(1) NOT NULL DEFAULT 1,\n            criado_por    INT UNSIGNED NULL,\n            criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY uniq_servicos_agendamento_nome (nome),\n            FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");

    try {
        $columnCheck = $pdo->query("\n            SELECT COUNT(*)\n            FROM information_schema.columns\n            WHERE table_schema = DATABASE()\n              AND table_name = 'servicos_agendamento'\n              AND column_name = 'duracao_minutos'\n        ");
        if ($columnCheck && (int) $columnCheck->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE servicos_agendamento DROP COLUMN duracao_minutos');
        }
    } catch (\Throwable $e) {
        error_log('Nao foi possivel remover duracao_minutos: ' . $e->getMessage());
    }

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS agendamentos (\n            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            servico_id          INT UNSIGNED NOT NULL,\n            solicitante_id      INT UNSIGNED NOT NULL,\n            aprovado_por_id     INT UNSIGNED NULL,\n            cancelado_por_id    INT UNSIGNED NULL,\n            encerrado_por_id    INT UNSIGNED NULL,\n            status              ENUM('solicitado','agendado','em_avaliacao','cancelado','encerrado') NOT NULL DEFAULT 'solicitado',\n            data_inicio         DATETIME NOT NULL,\n            data_fim            DATETIME NOT NULL,\n            observacoes         TEXT NULL,\n            motivo_recusa       TEXT NULL,\n            motivo_cancelamento TEXT NULL,\n            realizado            TINYINT(1) NULL DEFAULT NULL,\n            observacao_fechamento TEXT NULL,\n            aprovado_em         TIMESTAMP NULL DEFAULT NULL,\n            avaliado_em         TIMESTAMP NULL DEFAULT NULL,\n            cancelado_em        TIMESTAMP NULL DEFAULT NULL,\n            encerrado_em        TIMESTAMP NULL DEFAULT NULL,\n            criado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            atualizado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            FOREIGN KEY (servico_id) REFERENCES servicos_agendamento(id) ON DELETE RESTRICT,\n            FOREIGN KEY (solicitante_id) REFERENCES usuarios(id) ON DELETE CASCADE,\n            FOREIGN KEY (aprovado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,\n            FOREIGN KEY (cancelado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,\n            FOREIGN KEY (encerrado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,\n            INDEX idx_agendamentos_status_inicio (status, data_inicio),\n            INDEX idx_agendamentos_solicitante_status_inicio (solicitante_id, status, data_inicio),\n            INDEX idx_agendamentos_servico_inicio (servico_id, data_inicio)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");

    ensureAgendamentosEmAvaliacaoColumns($pdo);
    ensureAgendamentosReagendamentoColumns($pdo);
}

/**
 * Proposta de novo período feita pela equipe (reagendamento), pendente de
 * aceite do solicitante. Espelha as colunas de config/schema.sql.
 */
function ensureAgendamentosReagendamentoColumns(PDO $pdo): void
{
    $colunas = [
        'reagendamento_inicio' => 'DATETIME NULL DEFAULT NULL',
        'reagendamento_fim' => 'DATETIME NULL DEFAULT NULL',
        'reagendamento_motivo' => 'TEXT NULL',
        'reagendamento_por_id' => 'INT UNSIGNED NULL',
        'reagendamento_em' => 'TIMESTAMP NULL DEFAULT NULL',
    ];

    foreach ($colunas as $coluna => $definicao) {
        try {
            $existe = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'agendamentos' AND column_name = ?
            ");
            $existe->execute([$coluna]);
            if ((int) $existe->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE agendamentos ADD COLUMN {$coluna} {$definicao}");
            }
        } catch (\Throwable $e) {
            error_log("Nao foi possivel adicionar coluna {$coluna} em agendamentos: " . $e->getMessage());
        }
    }
}

function ensureAgendamentosEmAvaliacaoColumns(PDO $pdo): void
{
    try {
        $tipoColuna = $pdo->query("\n            SELECT COLUMN_TYPE FROM information_schema.columns\n            WHERE table_schema = DATABASE() AND table_name = 'agendamentos' AND column_name = 'status'\n        ")->fetchColumn();

        if ($tipoColuna !== false && !str_contains((string) $tipoColuna, 'em_avaliacao')) {
            $pdo->exec("ALTER TABLE agendamentos MODIFY COLUMN status ENUM('solicitado','agendado','em_avaliacao','cancelado','encerrado') NOT NULL DEFAULT 'solicitado'");
        }
    } catch (\Throwable $e) {
        error_log('Nao foi possivel atualizar ENUM status de agendamentos: ' . $e->getMessage());
    }

    $colunasNovas = [
        'realizado' => 'TINYINT(1) NULL DEFAULT NULL',
        'observacao_fechamento' => 'TEXT NULL',
        'avaliado_em' => 'TIMESTAMP NULL DEFAULT NULL',
    ];

    foreach ($colunasNovas as $coluna => $definicao) {
        try {
            $existe = $pdo->prepare("\n                SELECT COUNT(*) FROM information_schema.columns\n                WHERE table_schema = DATABASE() AND table_name = 'agendamentos' AND column_name = ?\n            ");
            $existe->execute([$coluna]);
            if ((int) $existe->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE agendamentos ADD COLUMN {$coluna} {$definicao}");
            }
        } catch (\Throwable $e) {
            error_log("Nao foi possivel adicionar coluna {$coluna} em agendamentos: " . $e->getMessage());
        }
    }
}

function ensureNotificationsSchema(PDO $pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS notificacoes (\n            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            usuario_id     INT UNSIGNED NOT NULL,\n            tipo           ENUM('chamado','agendamento','sistema') NOT NULL DEFAULT 'sistema',\n            evento         VARCHAR(80) NOT NULL,\n            chave_evento   VARCHAR(190) NOT NULL,\n            entidade       VARCHAR(60) NOT NULL,\n            entidade_id    INT UNSIGNED NOT NULL,\n            titulo         VARCHAR(255) NOT NULL,\n            mensagem       TEXT NOT NULL,\n            url            VARCHAR(255) NULL,\n            status_origem  VARCHAR(50) NULL,\n            status_destino VARCHAR(50) NULL,\n            metadados      JSON NULL,\n            lida_em        TIMESTAMP NULL DEFAULT NULL,\n            criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY uniq_notificacoes_chave (usuario_id, chave_evento),\n            INDEX idx_notificacoes_usuario_lida_criado (usuario_id, lida_em, criado_em),\n            INDEX idx_notificacoes_usuario_id (usuario_id, id),\n            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");
}

function ensureParticipantsSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS participantes (
            conversa_id INT UNSIGNED NOT NULL,
            usuario_id  INT UNSIGNED NOT NULL,
            entrou_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ultima_leitura TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (conversa_id, usuario_id),
            FOREIGN KEY (conversa_id) REFERENCES conversas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Verificar se a coluna ultima_leitura existe, se não existir, adicionar
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'participantes'
           AND column_name = 'ultima_leitura'"
    );
    if ($stmt && (int) $stmt->fetchColumn() === 0) {
        try {
            $pdo->exec("ALTER TABLE participantes ADD COLUMN ultima_leitura TIMESTAMP NULL DEFAULT NULL AFTER entrou_em");
        } catch (\Throwable $e) {
            error_log("Não foi possível adicionar coluna ultima_leitura em participantes: " . $e->getMessage());
        }
    }
}
