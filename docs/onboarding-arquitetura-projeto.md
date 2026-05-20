# Onboarding Técnico: Arquitetura e Fluxo do Projeto Chat Interno

Este documento explica como o sistema funciona de ponta a ponta, como os arquivos estão organizados e como os módulos se comunicam.

## 1) Objetivo do sistema

O projeto une dois domínios no mesmo produto:

- Chat corporativo (conversas privadas, grupos, presença online, anexos, mensagens em tempo real)
- Gestão de chamados de TI (abertura, triagem, classificação, comentários, anexos, resolução, relatório)

A stack principal é:

- Backend: PHP 8 + Slim 4
- Tempo real: Ratchet WebSocket
- Banco: MySQL
- Frontend: templates PHP + JavaScript Vanilla + Tailwind (CDN)
- Infra: Docker Compose (nginx, php-fpm, mysql, websocket)

## 2) Visão de arquitetura

Existem dois canais de execução em paralelo:

1. Canal HTTP/REST

Navegador -> Nginx -> PHP-FPM -> Slim (rotas/controllers) -> MySQL -> resposta HTML/JSON

2. Canal WebSocket (tempo real)

Navegador -> WebSocket (porta 8080) -> ChatServer (Ratchet) -> MySQL -> broadcast para clientes conectados

Esses dois canais compartilham o mesmo banco de dados.

## 3) Pontos de entrada

### 3.1 Entrada HTTP

Arquivo: public/index.php

Responsabilidades:

- Carrega autoload do Composer
- Carrega conexão de banco e bootstrap
- Inicia sessão
- Configura Slim e middlewares
- Declara rotas públicas, protegidas de frontend e APIs JSON
- Executa o app

### 3.2 Entrada WebSocket

Arquivo: bin/chat-server.php

Responsabilidades:

- Carrega autoload e configs
- Executa bootstrap de dados padrão
- Inicializa servidor Ratchet na porta 8080
- Usa App\Services\ChatServer como motor de eventos

## 4) Configuração e bootstrap

### config/database.php

- Cria conexão PDO singleton
- Lê credenciais de variáveis de ambiente
- Configura charset UTF-8 e timezone SQL

### config/bootstrap.php

- Garante dados básicos e consistência no startup:
  - setores padrão
  - usuário admin padrão
  - deduplicação de setores
  - tentativa de garantir índice único para nome de setor
- Usa lock no MySQL para evitar corrida em inicializações concorrentes

### config/app.php

- Configuração simples de ambiente (nome app, URL, debug)

### config/schema.sql

- Define o schema principal de tabelas e constraints
- Inclui evolução de esquema para recursos mais novos (ex.: presença, taxonomias, campos extras)

## 5) Camada web (rotas, middleware e controllers)

## 5.1 Middlewares

### app/Middleware/AuthMiddleware.php

- Valida sessão ativa
- Injeta user_id, user_nome, user_papel na request
- Redireciona para login quando não autenticado

### app/Middleware/AdminMiddleware.php

- Restringe acesso para papel admin
- Retorna erro JSON 403 quando não autorizado

## 5.2 Controllers

### app/Controllers/AuthController.php

- exibirLogin: renderiza tela de login
- processarLogin: valida credencial e cria sessão
- logout: encerra sessão

### app/Controllers/ChatController.php

Conjunto de APIs de chat:

- Conversas: listar, criar, obter, editar, deletar, marcar como lida
- Participantes: listar, adicionar, remover
- Mensagens: listar, enviar, apagar
- Usuários online

Pontos importantes:

- Permissão por participação em conversa
- Upload de anexos com validação de mime/extensão/tamanho
- Compatibilidade com esquemas antigos via inspeção de tabela/coluna

### app/Controllers/ChamadoController.php

Conjunto de APIs de chamados:

- Criar/listar chamados
- Atualizar status/cancelar/finalizar
- Classificar e editar classificação
- Gerenciar taxonomias (categoria/subcategoria)
- Comentários técnicos e anexos de comentário
- Relatório agregado + exportação CSV

Pontos importantes:

- Regras por papel (ti/admin para ações de gestão)
- Autoajuste de schema quando necessário (ex.: campo resolvido_por)
- Finalização pode gerar mensagem automática no chat com conversa privada

### app/Controllers/AdminController.php

Conjunto de APIs de administração:

- Usuários: listar (com paginação/filtros), criar, atualizar, desativar
- Setores: listar, criar, deletar (com validação de dependências)

## 5.3 Helpers e suporte

### app/Helpers/Response.php

- Padroniza respostas JSON e payload de erro

### app/Support/TemplateRenderer.php

- Renderização simples de templates PHP

### app/Support/SchemaInspector.php

- Verificação de existência de tabela/coluna em runtime
- Suporte à compatibilidade entre versões de banco

## 6) Camada de tempo real

### app/Services/ChatServer.php

Implementa MessageComponentInterface do Ratchet.

Eventos principais:

- auth: autentica conexão WS com user_id
- join: define conversa atual do cliente
- send_message: persiste e distribui mensagem
- typing: sinalização de digitação
- delete_message: soft-delete e broadcast

Comportamentos adicionais:

- Atualiza presença online em user_presenca
- Sincronização periódica para captar novidades feitas fora do canal WS
- Sincronização inicial de conversas e mensagens ao autenticar

## 7) Interface (templates + JS)

## 7.1 Templates

Pasta: templates/

- login.php: autenticação
- chat.php: interface principal de conversa
- meus_chamados.php: visão do solicitante
- dashboard_ti.php: triagem/classificação para TI/Admin
- relatorio_chamados.php: analytics e exportações
- admin.php: gestão de usuários e setores
- chamado-form.html: template residual simples

## 7.2 JavaScript por tela

Pasta: public/assets/js/

- chat.js: lógica da tela de chat (REST + WS, UI, anexos, notificações)
- dashboard-ti.js: triagem, filtros, histórico, classificação, comentários
- meus-chamados.js: dashboard do usuário solicitante
- relatorio-chamados.js: gráficos, KPIs, CSV/PDF
- admin.js: CRUD de usuários e setores com paginação/filtros

Scripts compartilhados:

- config.js: constantes globais (categorias, prioridades, status)
- utils.js: utilitários comuns (escapes, datas, normalização, duração)
- theme.js: alternância claro/escuro com persistência

## 7.3 CSS

Pasta: public/assets/css/

- light-mode.css: centraliza tokens e regras visuais do modo claro

## 8) Modelo de dados (tabelas-chave)

- setores
- usuarios
- conversas
- participantes
- mensagens
- chamados
- chamado_anexos
- chamado_comentarios
- chamado_comentario_anexos
- chamado_taxonomias
- servicos_agendamento
- agendamentos
- user_presenca

Relações centrais:

- usuario -> conversa por participantes
- conversa -> mensagens
- usuario -> chamados (solicitante)
- chamado -> anexos/comentários
- comentário -> anexos

## 9) Infraestrutura e execução

### docker-compose.yml

Define serviços:

- mysql
- php (PHP-FPM)
- nginx
- websocket (Ratchet com Supervisor)

### docker/php/supervisor.conf

- Mantém chat-server.php vivo
- Reinício automático em falhas

### docker/nginx/default.conf

- Root no diretório public
- try_files para roteamento Slim
- FastCGI para PHP
- endpoint /health

### entrypoint.sh

- Aguarda banco
- Ajusta permissões de uploads/logs
- Inicia processo principal (php-fpm)

### scripts/

- start.sh: build + up
- stop.sh: down
- reset.sh: reset destrutivo com confirmação

## 10) Fluxos de negócio essenciais

### 10.1 Login

1. POST /login
2. AuthController valida credenciais
3. sessão criada
4. redireciona para /chat

### 10.2 Mensagem de chat

1. UI seleciona conversa
2. Envio via POST /api/mensagens (com ou sem arquivo)
3. Registro em mensagens
4. WebSocket distribui new_message para participantes conectados

### 10.3 Triagem de chamado

1. Usuário abre chamado em /api/chamados
2. Chamado entra como aberto
3. TI/Admin classifica (prioridade/categoria/subcategoria)
4. Status muda para classificado

### 10.4 Finalização de chamado

1. TI/Admin chama /api/chamados/{id}/finalizar
2. Status vai para resolvido (com resolvido_por)
3. Opcionalmente salva comentário de resolução e anexos
4. Sistema tenta enviar mensagem automática no chat para solicitante

## 11) Como os módulos se comunicam

- Templates carregam JS específico da tela
- JS consome APIs REST em /api/*
- Sessão HTTP define identidade e permissões
- WebSocket recebe identidade via evento auth
- Controllers e ChatServer leem/escrevem nas mesmas tabelas
- Mudanças feitas por API podem aparecer em tempo real via sincronização WS

## 12) Ordem sugerida de estudo para dominar o projeto

1. README.md
2. public/index.php
3. config/database.php e config/bootstrap.php
4. AuthMiddleware/AdminMiddleware
5. ChatController
6. ChamadoController
7. ChatServer
8. templates/chat.php + public/assets/js/chat.js
9. templates/dashboard_ti.php + public/assets/js/dashboard-ti.js
10. AdminController + admin.js
11. schema.sql e docker-compose.yml

## 13) Resumo final

A lógica do projeto está organizada de forma clara:

- Slim controla entrada e roteamento HTTP
- Controllers concentram regra de negócio
- ChatServer concentra tempo real
- MySQL é a fonte única de verdade
- Frontend é orientado a templates com JS por tela
- Docker isola a operação em serviços independentes

Com essa estrutura em mente, você consegue explicar o sistema como um produto com backend REST + canal WebSocket, compartilhando domínio de chat e chamados sobre o mesmo modelo de dados e mesma autenticação por sessão.
