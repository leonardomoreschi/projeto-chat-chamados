# Chat Interno + Chamados

Plataforma interna com chat corporativo em tempo real e gestão de chamados de TI.

## O que o sistema cobre

- Chat com conversas privadas, em grupo e por setor.
- Chamados com prioridade, categoria e subcategoria.
- Comentários técnicos com anexos.
- Fluxo de triagem, classificação, resolução e histórico.
- Painel administrativo para usuários e setores.
- Relatórios de chamados com exportação CSV.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.3 (compatível com requisito de projeto >= 8.1) |
| Framework | Slim 4 |
| Tempo real | Ratchet (WebSocket) |
| Banco | MySQL 8 |
| Infra | Docker Compose (mysql, php, nginx, websocket) |
| Frontend | HTML + Tailwind + JavaScript Vanilla |

## Arquitetura atual

- mysql: banco de dados.
- php: aplicação HTTP em PHP-FPM.
- nginx: entrada web em porta publicada no host.
- websocket: processo dedicado Ratchet com Supervisor e healthcheck TCP na porta 8080.

## Seed e bootstrap automáticos

Na inicialização:

- o bootstrap roda automaticamente na aplicação HTTP e no serviço WebSocket;
- setores padrão são garantidos de forma idempotente;
- usuário admin inicial é criado automaticamente (se não existir);
- setores duplicados são deduplicados com reassociação de usuários;
- chave única de nome de setor é garantida para evitar duplicação futura.

## Variáveis de ambiente

Arquivo base:

- .env.docker.example

Passos:

```bash
cp .env.docker.example .env
```

## Subir com Docker

```bash
docker compose up -d --build
docker compose ps
```

Serviços esperados:

- mysql: healthy
- php: healthy
- nginx: healthy
- websocket: healthy

## Acessos padrão

Com WEB_HOST_PORT=8188:

- Login: http://localhost:8188/login
- Chat: http://localhost:8188/chat
- Agendamentos: http://localhost:8188/agendamentos
- Meus chamados: http://localhost:8188/meus-chamados
- Dashboard TI: http://localhost:8188/dashboard-ti
- Painel de agendamentos: http://localhost:8188/painel-agendamentos
- Relatório TI: http://localhost:8188/dashboard-ti/relatorio
- Admin: http://localhost:8188/admin
- WebSocket: ws://localhost:8080

Credenciais iniciais (se não alteradas no .env):

- E-mail: admin@empresa.com
- Senha: password

## Comandos úteis

Subir stack:

```bash
docker compose up -d
```

Rebuild completo:

```bash
docker compose up -d --build
```

Reiniciar apenas websocket:

```bash
docker compose restart websocket
```

Ver status:

```bash
docker compose ps
```

Logs do websocket:

```bash
docker logs -f chat_websocket
```

Parar stack:

```bash
docker compose down
```

Reset total (remove volumes de dados):

```bash
docker compose down -v
```

## Endpoints principais

Todas as rotas de API exigem sessão autenticada.

### Chat

- GET /api/conversas
- POST /api/conversas
- GET /api/conversas/{id}
- PATCH /api/conversas/{id}
- PATCH /api/conversas/{id}/descricao
- DELETE /api/conversas/{id}
- POST /api/conversas/{id}/lida
- GET /api/conversas/{id}/participantes
- POST /api/conversas/{id}/participantes
- DELETE /api/conversas/{id}/participantes/{uid}
- GET /api/mensagens
- POST /api/mensagens
- DELETE /api/mensagens/{id}
- GET /api/usuarios/online

### Chamados

- POST /api/chamados
- GET /api/chamados
- GET /api/chamados/{id}/anexos
- GET /api/chamados/{id}/comentarios
- POST /api/chamados/{id}/comentarios
- DELETE /api/chamados/{id}/comentarios/{comentarioId}
- PATCH /api/chamados/{id}/status
- PATCH /api/chamados/{id}/cancelar
- PATCH /api/chamados/{id}/classificar
- PATCH /api/chamados/{id}/classificacao
- PATCH /api/chamados/{id}/finalizar
- GET /api/chamados/relatorio
- GET /api/chamados/relatorio/csv
- GET /api/chamados-taxonomias
- GET /api/chamados-taxonomias/detalhe
- POST /api/chamados-taxonomias
- DELETE /api/chamados-taxonomias/{id}

### Agendamentos

- GET /agendamentos
- GET /painel-agendamentos
- GET /api/agendamentos
- GET /api/agendamentos/{id}
- POST /api/agendamentos
- PATCH /api/agendamentos/{id}/aprovar
- PATCH /api/agendamentos/{id}/recusar
- PATCH /api/agendamentos/{id}/cancelar
- PATCH /api/agendamentos/{id}/encerrar
- GET /api/servicos-agendamento
- POST /api/servicos-agendamento
- PATCH /api/servicos-agendamento/{id}
- DELETE /api/servicos-agendamento/{id}

### Admin

- GET /api/admin/usuarios
- POST /api/admin/usuarios
- PATCH /api/admin/usuarios/{id}
- DELETE /api/admin/usuarios/{id}
- GET /api/admin/setores
- POST /api/admin/setores
- DELETE /api/admin/setores/{id}

## WebSocket

Processo dedicado rodando no container websocket, gerenciado por Supervisor.

Comportamento:

- auto-restart em falhas do processo Ratchet;
- healthcheck por conexão TCP em localhost:8080;
- sincronização periódica para refletir eventos gerados fora do socket.

Eventos usados no canal:

- auth
- auth_ok
- join
- send_message
- new_message
- typing
- message_deleted

## Estrutura resumida de dados

Tabelas principais:

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

## Execução sem Docker

Guia dedicado em:

- docs/rodar-sem-docker-na-vm.md

## Observações de operação

- Timezone padrão: America/Sao_Paulo.
- Uploads ficam em disco e o banco salva metadados.
- O schema inicial é aplicado automaticamente no primeiro boot do MySQL quando o volume está vazio.