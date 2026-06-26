# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

PHP 8.3 / Slim 4 / Ratchet WebSocket / MySQL 8 / Docker Compose. No ORM — raw PDO throughout. No test framework; CI only runs PHP syntax lint.

## Commands

```bash
# Start full stack
docker compose up -d --build

# Restart only WebSocket server
docker compose restart websocket

# View WebSocket logs
docker logs -f chat_websocket

# PHP syntax lint (what CI runs)
git ls-files '*.php' | while read -r file; do php -l "$file"; done

# Install/update dependencies
composer install

# Reset everything (drops DB volume)
docker compose down -v && docker compose up -d --build
```

## Architecture

### Request flow

All HTTP requests enter through `public/index.php`, which bootstraps Slim 4, loads `.env`, runs `bootstrapDefaultData()`, and registers every route inline. There is no separate routes file.

### Authentication

Session-based (`$_SESSION['user_id']`, `user_nome`, `user_papel`). `AuthMiddleware` sets these as Slim request attributes. `AdminMiddleware` enforces `papel = 'admin'`. User roles are `'admin'`, `'ti'`, or regular (no special role). Pages like `/dashboard-ti` and `/painel-agendamentos` do inline role checks and redirect to `/chat` for unauthorized users.

### Database

`getDbConnection()` in `config/database.php` returns a singleton PDO. All controllers call it directly — no repository layer. Schema migrations run inline in `config/bootstrap.php` via `ensureAgendamentoSchema()`, which uses `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE` guards. The base schema is in `config/schema.sql` and applied automatically on first MySQL container boot.

### App layers

| Directory | Purpose |
|---|---|
| `app/Controllers/` | HTTP controllers — one per domain (Auth, Chat, Chamado, Admin, Agendamento) |
| `app/Services/ChatServer.php` | Ratchet `MessageComponentInterface` — handles WS events and periodic timers |
| `app/Middleware/` | `AuthMiddleware` (session gate), `AdminMiddleware` (role gate) |
| `app/Helpers/Response.php` | `Response::json()` and `Response::erro()` — all API responses go through here |
| `app/Support/TemplateRenderer.php` | `extract($data)` + `include` for PHP templates |
| `app/Support/SchemaInspector.php` | Trait mixed into `ChatServer` for DB-driven sync |
| `config/bootstrap.php` | Idempotent seed for default sectors, admin user, and agendamento schema |
| `bin/chat-server.php` | Entry point for the Ratchet server (used by the `websocket` Docker service) |
| `templates/` | PHP templates rendered by `TemplateRenderer::render()` |

### WebSocket

The `websocket` container runs `bin/chat-server.php` under Supervisor on port 8080. `ChatServer` maintains two periodic timers: every 0.8 s it polls for new messages/events generated outside the socket (e.g., by HTTP requests), and every 60 s it auto-archives expired agendamentos. WebSocket events: `auth`, `auth_ok`, `join`, `send_message`, `new_message`, `typing`, `message_deleted`.

### Agendamentos (scheduling)

Status flow: `solicitado` → `agendado` (TI approves) → `em_avaliacao` (auto-archived when `data_fim` passes) → `encerrado` (TI closes). Cancellation is possible from most states. The auto-archiving logic runs both in `AgendamentoController::listar()` (on every list request) and in the WebSocket 60 s timer.

## Environment

Copy `.env.docker.example` to `.env` before first run. Key variables: `DB_*`, `APP_SECRET`, `WEB_HOST_PORT` (default 8188), `DB_HOST_PORT` (default 3307), `ADMIN_EMAIL`, `ADMIN_PASSWORD`.
