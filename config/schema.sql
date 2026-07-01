SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

CREATE TABLE setores (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(100) NOT NULL,
    descricao TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_setores_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO setores (nome, descricao) VALUES
('TI', 'Setor de tecnologia da informacao'),
('Administrativo', 'Setor administrativo'),
('Engenharia', 'Setor de engenharia'),
('Financeiro', 'Setor financeiro'),
('Operacional', 'Setor operacional'),
('Vendas', 'Setor comercial e vendas');

CREATE TABLE usuarios (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(150) NOT NULL,
    email        VARCHAR(255) NOT NULL UNIQUE,
    senha_hash   VARCHAR(255) NOT NULL,
    setor_id     INT UNSIGNED,
    papel        ENUM('admin','ti','usuario') NOT NULL DEFAULT 'usuario',
    ativo        TINYINT(1) NOT NULL DEFAULT 1,
    criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE SET NULL,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversas (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo       ENUM('privada','grupo','setor') NOT NULL DEFAULT 'privada',
    nome       VARCHAR(150),
    criado_por INT UNSIGNED NOT NULL,
    criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participantes (
    conversa_id INT UNSIGNED NOT NULL,
    usuario_id  INT UNSIGNED NOT NULL,
    entrou_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversa_id, usuario_id),
    FOREIGN KEY (conversa_id) REFERENCES conversas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mensagens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversa_id  INT UNSIGNED NOT NULL,
    usuario_id   INT UNSIGNED NOT NULL,
    conteudo     TEXT NOT NULL,
    arquivo_path VARCHAR(500),
    arquivo_nome VARCHAR(255),
    criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversa_id) REFERENCES conversas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
    INDEX idx_conversa_criado (conversa_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chamados (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id     INT UNSIGNED NOT NULL,
    atribuido_a    INT UNSIGNED NULL,
    titulo         VARCHAR(255) NOT NULL,
    descricao_rich LONGTEXT NOT NULL,
    status         ENUM('aberto','em_andamento','resolvido','cancelado') NOT NULL DEFAULT 'aberto',
    prioridade     ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
    criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (atribuido_a) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chamado_anexos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id    INT UNSIGNED NOT NULL,
    arquivo_path  VARCHAR(500) NOT NULL,
    arquivo_nome  VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100),
    tamanho_bytes INT UNSIGNED,
    criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamado_comentarios (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    conteudo   TEXT NULL,
    tipo       ENUM('comentario','resolucao') NOT NULL DEFAULT 'comentario',
    criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chamado_comentarios_chamado_criado (chamado_id, criado_em),
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamado_comentario_anexos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comentario_id INT UNSIGNED NOT NULL,
    arquivo_path  VARCHAR(500) NOT NULL,
    arquivo_nome  VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100),
    tamanho_bytes INT UNSIGNED,
    criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chamado_comentario_anexos_comentario (comentario_id),
    FOREIGN KEY (comentario_id) REFERENCES chamado_comentarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notificacoes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id     INT UNSIGNED NOT NULL,
    tipo           ENUM('chamado','agendamento','sistema') NOT NULL DEFAULT 'sistema',
    evento         VARCHAR(80) NOT NULL,
    chave_evento   VARCHAR(190) NOT NULL,
    entidade       VARCHAR(60) NOT NULL,
    entidade_id    INT UNSIGNED NOT NULL,
    titulo         VARCHAR(255) NOT NULL,
    mensagem       TEXT NOT NULL,
    url            VARCHAR(255) NULL,
    status_origem  VARCHAR(50) NULL,
    status_destino VARCHAR(50) NULL,
    metadados      JSON NULL,
    lida_em        TIMESTAMP NULL DEFAULT NULL,
    criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notificacoes_chave (usuario_id, chave_evento),
    INDEX idx_notificacoes_usuario_lida_criado (usuario_id, lida_em, criado_em),
    INDEX idx_notificacoes_usuario_id (usuario_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE chamados 
ADD COLUMN categoria VARCHAR(50) NULL AFTER titulo,
ADD COLUMN subcategoria VARCHAR(50) NULL AFTER categoria,
ADD COLUMN resolvido_por INT UNSIGNED NULL AFTER atribuido_a,
ADD CONSTRAINT fk_chamados_resolvido_por FOREIGN KEY (resolvido_por) REFERENCES usuarios(id) ON DELETE SET NULL;

-- Atualizando os status para incluir a lógica de fluxo
ALTER TABLE chamados 
MODIFY COLUMN status ENUM('aberto', 'classificado', 'em_andamento', 'resolvido', 'cancelado') 
NOT NULL DEFAULT 'aberto';

CREATE TABLE IF NOT EXISTS chamado_taxonomias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria VARCHAR(100) NOT NULL,
    subcategoria VARCHAR(100) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_categoria_subcategoria (categoria, subcategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO chamado_taxonomias (categoria, subcategoria) VALUES
('ERP', 'Financeiro'),
('ERP', 'Fiscal'),
('ERP', 'Contabilidade'),
('ERP', 'Vendas'),
('ERP', 'Estoque'),
('Infraestrutura', 'Servidor'),
('Infraestrutura', 'Backup'),
('Infraestrutura', 'Cloud'),
('Infraestrutura', 'Banco de Dados'),
('Engenharia', 'AutoCAD'),
('Engenharia', 'Solidworks'),
('Engenharia', 'Revisão Técnica'),
('Redes', 'Wi-Fi'),
('Redes', 'Cabeamento'),
('Redes', 'VPN'),
('Segurança', 'Antivírus'),
('Segurança', 'Firewall'),
('Segurança', 'Câmeras'),
('Hardware', 'Desktop/Notebook'),
('Hardware', 'Impressora'),
('Hardware', 'Periféricos'),
('Acessos', 'Reset de Senha'),
('Acessos', 'Novo Usuário'),
('Acessos', 'Permissões');

CREATE TABLE IF NOT EXISTS servicos_agendamento (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(150) NOT NULL,
    descricao       TEXT NULL,
    cor_hex         VARCHAR(12) NOT NULL DEFAULT '#4f46e5',
    ativo           TINYINT(1) NOT NULL DEFAULT 1,
    criado_por      INT UNSIGNED NULL,
    criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servicos_agendamento_nome (nome),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agendamentos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    servico_id          INT UNSIGNED NOT NULL,
    solicitante_id      INT UNSIGNED NOT NULL,
    aprovado_por_id     INT UNSIGNED NULL,
    cancelado_por_id    INT UNSIGNED NULL,
    encerrado_por_id    INT UNSIGNED NULL,
    status              ENUM('solicitado','agendado','em_avaliacao','cancelado','encerrado') NOT NULL DEFAULT 'solicitado',
    data_inicio         DATETIME NOT NULL,
    data_fim            DATETIME NOT NULL,
    observacoes         TEXT NULL,
    motivo_recusa       TEXT NULL,
    motivo_cancelamento TEXT NULL,
    realizado           TINYINT(1) NULL DEFAULT NULL,
    observacao_fechamento TEXT NULL,
    aprovado_em         TIMESTAMP NULL DEFAULT NULL,
    avaliado_em         TIMESTAMP NULL DEFAULT NULL,
    cancelado_em        TIMESTAMP NULL DEFAULT NULL,
    encerrado_em        TIMESTAMP NULL DEFAULT NULL,
    criado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (servico_id) REFERENCES servicos_agendamento(id) ON DELETE RESTRICT,
    FOREIGN KEY (solicitante_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (aprovado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (cancelado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (encerrado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_agendamentos_status_inicio (status, data_inicio),
    INDEX idx_agendamentos_solicitante_status_inicio (solicitante_id, status, data_inicio),
    INDEX idx_agendamentos_servico_inicio (servico_id, data_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE conversas
ADD COLUMN IF NOT EXISTS descricao TEXT NULL AFTER nome;

CREATE TABLE IF NOT EXISTS user_presenca (
    usuario_id INT UNSIGNED PRIMARY KEY,
    online TINYINT(1) NOT NULL DEFAULT 0,
    last_seen TIMESTAMP NULL DEFAULT NULL,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_presenca_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mensagens
ADD COLUMN IF NOT EXISTS excluida_em TIMESTAMP NULL DEFAULT NULL AFTER arquivo_nome,
ADD COLUMN IF NOT EXISTS excluida_por INT UNSIGNED NULL AFTER excluida_em,
ADD CONSTRAINT fk_mensagens_excluida_por FOREIGN KEY (excluida_por) REFERENCES usuarios(id) ON DELETE SET NULL;