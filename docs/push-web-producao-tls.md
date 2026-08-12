# Notificações: arquitetura, estado atual e o que falta para produção

**Documento único do assunto notificações neste projeto** — som, toast in-page,
central do sino, pop-up do sistema operacional (Web Push) e o requisito de TLS.
Antes de mexer em qualquer coisa de notificação, leia a §1 (o que existe) e a
§1.2 (quem mostra o aviso em cada situação).

---

## ⚠ Estado da task: **pausada** (12/08/2026)

Todo o trabalho de notificação fora do navegador está na branch **`teste`**, a
partir do commit `2424cbf` ("início notificações fora do navegador"). **Não está
mergeado na `main`**, que segue como a versão testada em uso.

| | |
|---|---|
| **Por que parou** | O pop-up do SO funcionou nas primeiras tentativas e depois parou de chegar, de forma intermitente. Não foi diagnosticado. |
| **Decisão em aberto** | Avaliar trocar Web Push por um **app nativo** para as notificações. Se for esse o caminho, boa parte da §4 (TLS) deixa de ser pré-requisito para notificar — mas continua valendo para o resto do sistema. |
| **Enquanto isso** | Desenvolvimento normal na `main`. Não mergear `teste` sem retomar esta investigação. |
| **Para retomar** | §10 — checklist de diagnóstico da intermitência. |

O que já foi verificado: a lógica de presença (`user_presenca`) faz o que
promete nos quatro cenários testados — aba ativa, aba em segundo plano, duas
abas e fechamento (§1.3). Isso descarta o *desenho* da presença como causa, mas
**não** descarta uma aba rodando JS antigo (que não manda `presenca` e por isso
conta como ativa): é a primeira coisa a eliminar na §10.

---

## 1. O que foi entregue

Um sistema de notificações nativas (pop-up do sistema operacional) que funciona
mesmo com a aba do chat fechada:

```
FPM: NotificationCenter::registrar()  ─┐
Ratchet: case 'send_message'          ─┼─► push_fila ─► bin/push-worker.php ─► FCM/Mozilla ─► sw.js ─► pop-up do SO
ChatController::enviarMensagem        ─┘   (outbox)      (2º program: no supervisor)
```

### 1.1 Mapa do código

Tudo que participa de "avisar o usuário" — inclusive o que já existia antes do
push:

| Arquivo | Papel |
|---|---|
| `app/Support/NotificationCenter.php` | Central do sino: `registrar()` faz upsert por `chave_evento` e, no fim, chama `PushCenter::enfileirarDeNotificacao()` — **ponto único** que transforma notificação em push |
| `app/Support/PushCenter.php` | API do push: `enfileirar*()` para os produtores, `processarLote()` para o worker, `motivoDescarte()` para as regras da §1.2 |
| `app/Controllers/PushController.php` | `/api/push/chave-publica`, `/api/push/inscrever` (POST/DELETE), `/api/push/status` |
| `app/Services/ChatServer.php` | Broadcast WS; evento `presenca`; `sincronizarPresencaUsuario()` grava `user_presenca` |
| `app/Controllers/ChatController.php` | `enfileirarPushMensagem()` — push das mensagens enviadas por HTTP (com anexo) |
| `bin/push-worker.php` | Único processo que fala com FCM/Mozilla (2º programa do supervisor) |
| `public/sw.js` | Service Worker: exibe o pop-up, trata o clique e a rotação de inscrição |
| `public/assets/js/push.js` | Registro do SW, inscrição, botão de permissão, `PushWeb.inscrito()` |
| `public/assets/js/utils.js` | `appAtivo()`, `aoMudarAtividade()`, `avisoDoSistema()` — **a decisão de qual aviso mostrar mora aqui** |
| `public/assets/js/notificacoes.js` | Sino, badge, toast, `notificar()` e `notificarMensagemChat()` |
| `public/assets/js/som-notificacoes.js` | Timbres por tipo de evento (`SomNotificacoes.tocar`) |
| `public/assets/js/chat.js` | Avisos de mensagem na página `/chat` |
| `public/assets/js/agendamentos.js` | Mesmo tratamento nas páginas com socket próprio |
| `public/manifest.json`, `public/assets/img/icone-*.png` | PWA e ícones do pop-up (obrigatório no iOS) |
| `push_subscriptions`, `push_fila`, `user_presenca` | Tabelas (em `config/schema.sql` e `ensurePushSchema()`) |

### 1.2 Quem mostra o aviso: a regra única

Todo aviso passa por `window.avisoDoSistema()` (`public/assets/js/utils.js`).
**Não chame `new Notification` direto em lugar nenhum** — foi o que produziu o
bug em que mensagem de chat só tocava o som.

| Estado da aba do destinatário | Quem avisa | O que acontece no servidor |
|---|---|---|
| **Ativa** — visível **e** com foco | toast in-page | push descartado: `push_fila.ultimo_erro = 'usuario_ativo'` |
| **Inativa** com push inscrito | pop-up do SO, pelo `sw.js` | push enviado |
| **Inativa** sem push (origem insegura, opt-out, permissão negada) | `new Notification` da própria página; toast como último recurso | nada é enfileirado (o `INSERT` exige linha em `push_subscriptions`) |

Dois pontos que se repetem em cada camada, de propósito:

- **`appAtivo()` = `!document.hidden && document.hasFocus()`.** Só
  `document.hidden` não basta: uma janela atrás de outro aplicativo continua
  `visible` no Chrome, e um toast ali dentro ninguém vê. O `sw.js` usa o
  equivalente (`visibilityState === 'visible' && focused`) na checagem que cobre
  a corrida de ~2 s entre enfileirar e entregar.
- **Nunca sai "só o som".** Se `avisoDoSistema()` devolve `false`, o chamador é
  obrigado a mostrar o toast.

**Mensagens de chat geram pop-up mas não entram na central.** `ChatServer` e
`ChatController` chamam `PushCenter` diretamente, sem passar por
`NotificationCenter` — o sino e o contador continuam refletindo só chamados e
agendamentos. Quem mostra o aviso de mensagem é o `chat.js` na `/chat` e o
`NotificationCenterUI.notificarMensagemChat()` nas demais páginas (elas também
recebem `new_message` pelo WebSocket).

### 1.3 Presença: "aba na frente", não "socket aberto"

`user_presenca.online = 1` significa **o usuário tem alguma aba na frente** — é
esse o critério que o worker usa para decidir entre toast e pop-up.

Protocolo: o front manda `{type: 'presenca', ativo: bool}` pelo WebSocket a cada
mudança de foco/visibilidade (`aoMudarAtividade` em `utils.js`) e logo após cada
`auth` — inclusive ao reconectar, senão o estado antigo ficaria valendo. O
`ChatServer` recalcula a linha varrendo as conexões vivas
(`sincronizarPresencaUsuario`) no `auth`, no evento `presenca` e no `onClose`,
e renova `last_seen` a cada 30 s. O worker só confia na linha se `last_seen`
estiver dentro de `PRESENCA_SEGUNDOS` (90 s).

Sem esse sinal — foi o estado até 12/08/2026 — bastava manter o sistema aberto
para que **todo** push fosse descartado, e os avisos de mensagem eram engolidos.

Comportamento verificado com um cliente WS de teste:

| Cenário | `online` |
|---|---|
| conexão com `presenca ativo=1` | 1 |
| conexão com `presenca ativo=0` (aba em segundo plano) | 0 |
| duas abas, uma ativa | 1 |
| fecha a aba ativa, sobra a de segundo plano | 0 |

Duas consequências que valem saber:

- **Cliente com JS antigo em cache não manda `presenca`**; o servidor assume
  `ativo = true` (erro seguro: no máximo atrasa um push, nunca duplica). Depois
  de mexer nesse código, **recarregue as abas abertas** antes de testar.
- **A presença é por usuário, não por dispositivo.** Com o desktop ativo, o
  celular do mesmo usuário não recebe pop-up. Resolver exige marcar presença por
  inscrição — ver §9.

---

## 2. Por que hoje só funciona em `localhost`

`navigator.serviceWorker`, `PushManager` e `Notification.requestPermission()`
só existem em **contexto seguro** (*secure context*). A especificação considera
seguro:

- qualquer origem `https://`;
- `http://localhost`, `http://127.0.0.1`, `http://[::1]`.

O app é servido em `http://<ip-da-vm>:8188` (nginx com `listen 80`, sem TLS).
Para uma estação da rede, essa origem **não é segura** — e as APIs nem aparecem
no `window`. Não é um erro que dá para contornar em JavaScript.

Situação prática hoje:

| Acesso | Push funciona? |
|---|---|
| `http://localhost:8188` **na própria VM** | ✅ sim |
| `http://192.168.x.x:8188` de uma estação | ❌ não — `push.js` se desliga em silêncio |
| `https://chat.empresa.local` (depois da §4) | ✅ sim |

O `push.js` detecta isso com `window.isSecureContext` e simplesmente esconde o
botão "Ativar notificações". **Nada quebra**: chat, WebSocket, badge do sino e
toasts continuam idênticos, e não há erro no console. Na página
`/notificacoes` aparece um aviso curto explicando o requisito de HTTPS.

### Workaround só para teste

Não é solução de implantação — serve para validar o fluxo de fora da VM antes de
ter certificado:

```bash
google-chrome \
  --user-data-dir=/tmp/perfil-teste-push \
  --unsafely-treat-insecure-origin-as-secure=http://192.168.0.50:8188
```

No Firefox, `about:config` → `dom.serviceWorkers.testing.enabled = true`.

---

## 3. Configuração das chaves VAPID

Sem as chaves o recurso fica desligado por inteiro: `/api/push/chave-publica`
responde `habilitado: false`, o botão some e o `push-worker` encerra sem fazer
nada. Nada mais no sistema é afetado.

```bash
docker compose exec php php -r 'require "/var/www/html/vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
```

Sem a lib disponível, o mesmo par sai do OpenSSL puro:

```bash
openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem
# pública (65 bytes, ponto não comprimido) em base64url
openssl ec -in vapid.pem -pubout -outform DER | tail -c 65 | base64 -w0 | tr -d '=' | tr '/+' '_-'
# privada (32 bytes) em base64url
openssl ec -in vapid.pem -outform DER | tail -c +8 | head -c 32 | base64 -w0 | tr -d '=' | tr '/+' '_-'
rm vapid.pem     # não deixar no repositório
```

A pública deve ter **87 caracteres** e a privada **43**.

> **Onde colocar:** no arquivo `.env`, obrigatoriamente. O pool do PHP-FPM roda
> com `clear_env = yes`, então o bloco `environment:` do `docker-compose.yml`
> **não** chega ao `$_ENV` da aplicação HTTP. As entradas no compose existem para
> o worker (que é CLI) e como documentação.

Trocar as chaves invalida todas as inscrições existentes. Isso é tratado: o
`push.js` compara o `applicationServerKey` da inscrição atual com a chave do
servidor e se reinscreve sozinho quando diverge.

---

## 4. Habilitar em produção: TLS

### 4.1 Certificado

**Autoassinado sem CA instalada não serve.** Mesmo depois de o usuário clicar em
"aceitar o risco", o navegador não registra Service Worker numa origem com
certificado não confiável.

**Opção A — CA interna (recomendada para LAN corporativa).**

```bash
# 1. CA raiz (guardar a chave em local seguro; validade longa)
openssl req -x509 -newkey rsa:4096 -days 3650 -nodes \
  -keyout ca.key -out ca.crt \
  -subj "/C=BR/O=Empresa/CN=Empresa CA Interna"

# 2. Chave e CSR do servidor
openssl req -newkey rsa:2048 -nodes \
  -keyout chat.key -out chat.csr \
  -subj "/C=BR/O=Empresa/CN=chat.empresa.local"

# 3. Assinar com SAN de DNS *e* de IP — navegadores ignoram o CN desde 2017
cat > chat.ext <<'EOF'
subjectAltName = DNS:chat.empresa.local, IP:192.168.0.50
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
EOF

openssl x509 -req -in chat.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out chat.crt -days 825 -sha256 -extfile chat.ext
```

Distribuir `ca.crt` para as estações Windows via GPO
(*Computer Configuration → Policies → Windows Settings → Security Settings →
Public Key Policies → Trusted Root Certification Authorities*). O Firefox usa
armazenamento próprio: ou ativar `security.enterprise_roots.enabled`, ou
importar a CA por política.

**Opção B — Let's Encrypt**, se a VM ganhar um nome público. Com o serviço só na
LAN, usar o desafio DNS-01.

### 4.2 nginx

Montar os certificados e acrescentar o bloco 443 em
`docker/nginx/default.conf`:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name chat.empresa.local;
    root /var/www/html/public;
    index index.php index.html;

    ssl_certificate     /etc/nginx/certs/chat.crt;
    ssl_certificate_key /etc/nginx/certs/chat.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    # ... repetir aqui todos os location do bloco 80 atual
    # (/health, = /sw.js, = /manifest.json, estáticos, /, ~ \.php$)
}

server {
    listen 80;
    server_name _;

    # O healthcheck do compose usa http://localhost/health: manter fora do redirect.
    location /health {
        access_log off;
        return 200 "OK";
        add_header Content-Type text/plain;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

E no `docker-compose.yml`, serviço `nginx`:

```yaml
    volumes:
      - ./public:/var/www/html/public:ro
      - uploads_data:/var/www/html/public/uploads:ro
      - ./docker/nginx/certs:/etc/nginx/certs:ro
    ports:
      - "${WEB_HOST_PORT:-8188}:80"
      - "443:443"
```

### 4.3 WebSocket: `ws://` → `wss://`

Sob HTTPS o navegador bloqueia `ws://` por mixed content. **O front já está
preparado**: os três arquivos que abriam socket (`chat.js`, `notificacoes.js`,
`agendamentos.js`) passaram a usar `window.urlWebSocket()` de
`public/assets/js/utils.js`, que devolve:

- `ws://<host>:8080` quando a página é HTTP (comportamento atual, inalterado);
- `wss://<host>/ws` quando a página é HTTPS.

Falta só o lado do servidor. No bloco 443 do nginx:

```nginx
    location /ws {
        proxy_pass http://websocket:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
```

E no compose, adicionar `websocket` ao `depends_on` do serviço `nginx`. Depois
disso a porta 8080 pode deixar de ser publicada no host — todo o tráfego passa
pelo 443.

> O Ratchet não valida `Origin`. Ao expor o WS pelo nginx, considere adicionar a
> checagem (`Ratchet\Http\OriginCheck`) ou uma regra de `allow`/`deny` no
> `location /ws`.

### 4.4 Sessão

Depois — e só depois — de o TLS estar no ar, em `docker/php/php.ini`:

```ini
session.cookie_secure = 1
session.cookie_samesite = Lax
```

Fazer isso antes do TLS quebra o login: o navegador deixa de enviar o cookie em
HTTP.

---

## 5. Verificação

```bash
# Certificado servido e cadeia correta
openssl s_client -connect chat.empresa.local:443 -servername chat.empresa.local </dev/null | head -20

# Cabeçalho do Service Worker: precisa ser no-cache, não "immutable"
curl -sI https://chat.empresa.local/sw.js | grep -i cache-control
```

No navegador, DevTools:

1. **Application → Service Workers**: `/sw.js` com status *activated and running*.
2. **Application → Manifest**: sem erros, ícones carregando.
3. Botão **Push** na linha do Service Worker: dispara um push de teste.
4. `chrome://serviceworker-internals` mostra o registro e permite ver logs.

No banco:

```sql
SELECT id, usuario_id, LEFT(endpoint, 60), criado_em FROM push_subscriptions;
SELECT id, origem, status, tentativas, ultimo_erro FROM push_fila ORDER BY id DESC LIMIT 20;
```

`status` esperado: `enviado` quando o destinatário estava ausente,
`descartado` (com `ultimo_erro = 'usuario_ativo'`) quando ele estava com a aba
**na frente** — visível e com foco. Aba aberta em segundo plano, janela
minimizada ou atrás de outro aplicativo contam como ausente e recebem push: o
critério vem do evento `presenca` que o front manda pelo WebSocket, gravado em
`user_presenca.online`.

Nos logs:

```bash
docker exec chat_websocket supervisorctl status          # ratchet e push-worker RUNNING
docker exec chat_websocket tail -f /var/log/supervisor/push-worker.out.log
```

---

## 6. Checklist de corte

1. Gerar o certificado e montar `./docker/nginx/certs` no container do nginx.
2. Adicionar o bloco 443 (com todos os `location`) e o redirect no 80.
3. Adicionar `location /ws` e `depends_on: websocket` no serviço nginx.
4. Publicar a porta 443 no compose; remover a publicação da 8080.
5. Distribuir a CA interna nas estações (GPO + política do Firefox).
6. Ligar `session.cookie_secure = 1` no `php.ini`.
7. **Liberar a saída 443 da VM para os serviços de push e rodar o teste da §8.**
8. `docker compose up -d --build` e rodar a verificação da §5.

---

## 7. Compatibilidade de navegadores

Nada na implementação é específico do Chrome. O que se usa é padrão:
**Push API**, **Notifications API**, **Service Workers**, **VAPID** (RFC 8292) e
a cifragem **aes128gcm** (RFC 8291). Cada navegador apenas aponta para o serviço
de push do próprio fabricante, e esse endereço vem dentro da inscrição que o
navegador entrega — o `PushCenter` usa o que estiver lá, sem ramificar por
navegador.

| Navegador | Suporte | Serviço de push | Ressalva |
|---|---|---|---|
| Chrome / Edge / Brave / Opera | ✅ desktop e Android | `fcm.googleapis.com` | mantém processo em segundo plano no Windows por padrão |
| Firefox | ✅ desktop e Android | `updates.push.services.mozilla.com` | não fica em segundo plano: entrega no próximo start |
| Safari macOS 16.1+ | ✅ | `web.push.apple.com` | exige gesto do usuário para a permissão |
| Safari iOS/iPadOS 16.4+ | ⚠️ | `web.push.apple.com` | **só** com o site adicionado à tela de início |
| Samsung Internet | ✅ | `fcm.googleapis.com` | — |
| IE / Opera Mini | ❌ | — | `push.js` esconde o botão; nada quebra |

Duas exigências de plataforma já estão atendidas pelo desenho:

- **Safari obriga que `Notification.requestPermission()` venha de um clique.**
  É por isso que existe o botão `[data-push-toggle]` em vez do pedido automático
  no carregamento da página, que era o comportamento antigo do `chat.js`.
- **iOS obriga PWA instalada.** É o que o `public/manifest.json` com os ícones
  192/512 habilita. Sem "Adicionar à Tela de Início", o iPhone não recebe push.

Diferenças cosméticas, todas degradando em silêncio (a notificação continua
aparecendo, o campo é só ignorado):

| Campo do `showNotification` | Onde funciona |
|---|---|
| `badge`, `image` | Chrome / Android |
| `actions` (botões) | Chrome, Firefox — **não** no Safari |
| `requireInteraction` | Chrome / Edge — ignorado por Firefox e Safari |
| `tag`, `renotify`, `icon`, `body`, `data` | todos |

### A aparência do pop-up não é da aplicação

O pop-up é desenhado pelo sistema operacional (ou pelo navegador), nunca pela
página — é exatamente por isso que ele consegue aparecer sobre outras janelas e
com o navegador fechado. Consequência prática: **o mesmo código tem aparência
diferente por plataforma**.

- **Windows 10/11:** balão do Action Center, canto **inferior direito**.
- **macOS:** canto superior direito.
- **Linux (GNOME/KDE):** varia; o Chrome frequentemente desenha o próprio
  estilo em vez de usar o daemon do sistema.

Ao avaliar o visual, use uma estação **Windows** — é o parque real. O Linux da
VM de desenvolvimento não representa o que o usuário final vê.

O pop-up com a identidade da aplicação (o toast) continua existindo, mas só no
caso em que ele é possível: **aba ativa** (visível e com foco — §1.2). Nessa
situação o `sw.js` detecta a aba via `clients.matchAll()` e encaminha o aviso
para o toast in-page em vez de mostrar a notificação do SO — é o que evita dois
avisos do mesmo fato.

---

## 8. Requisito de rede: saída para os serviços de push

O `push-worker` roda **na VM** e precisa de saída HTTPS (porta 443) para os
serviços de push. Se o firewall corporativo bloquear, o push falha para todo
mundo — e falha de um jeito invisível para o usuário: aparece apenas como
`push_fila.ultimo_erro` no banco.

| Destino | Para quais navegadores |
|---|---|
| `fcm.googleapis.com` | Chrome, Edge, Brave, Opera, Samsung Internet |
| `updates.push.services.mozilla.com` | Firefox |
| `web.push.apple.com` | Safari (macOS e iOS) |

Teste a partir de dentro do container, que é quem realmente faz a chamada:

```bash
docker exec chat_websocket sh -c '
for u in "https://fcm.googleapis.com/fcm/send/teste-conectividade" \
         "https://updates.push.services.mozilla.com/" \
         "https://web.push.apple.com/"; do
  printf "%-58s " "$u"
  curl -s -o /dev/null -m 12 -w "HTTP %{http_code}\n" "$u"
done'
```

Como interpretar — o que importa é **haver resposta**, não o código:

```
https://fcm.googleapis.com/fcm/send/teste-conectividade    HTTP 400   <- OK
https://updates.push.services.mozilla.com/                 HTTP 406   <- OK
https://web.push.apple.com/                                HTTP 405   <- OK
```

- **Qualquer código 4xx/5xx = acessível.** São respostas legítimas a uma
  requisição vazia; o handshake TLS aconteceu, que é o que se queria provar.
- **`HTTP 000`, timeout ou erro de DNS = bloqueado.** Liberar a saída 443 para
  os hosts acima, ou configurar o proxy (`HTTPS_PROXY` no serviço `websocket`
  do `docker-compose.yml` — a Guzzle, usada pelo `minishlink/web-push`, respeita
  a variável).
- **Um código 200 com HTML no corpo** costuma ser proxy transparente
  interceptando. Nesse caso o TLS é reescrito e a entrega vai falhar de
  qualquer forma: é preciso liberar os hosts sem inspeção.

Diagnóstico quando o push para de chegar em produção:

```sql
SELECT status, COUNT(*), LEFT(ultimo_erro, 80)
  FROM push_fila
 WHERE criado_em > NOW() - INTERVAL 1 HOUR
 GROUP BY status, LEFT(ultimo_erro, 80);
```

`status = 'falha'` em massa com erro de conexão aponta para firewall; `410 Gone`
é normal e apenas significa que aquela inscrição foi descartada pelo navegador.

---

## 9. Limites conhecidos

- **"Navegador fechado" depende do navegador.** Chrome e Edge no Windows mantêm
  um processo em segundo plano por padrão (*Continuar executando aplicativos em
  segundo plano quando o navegador estiver fechado*) e entregam o push na hora.
  O Firefox não mantém, e entrega no próximo start. Se a entrega imediata for
  requisito, padronizar essa opção do Chrome via GPO.
- **TTL de 600 s.** Se o `push-worker` ficar parado mais de 10 minutos, os avisos
  daquele intervalo caducam em vez de chegarem atrasados. É proposital.
- **`config.platform.php = "8.1.34"`** no `composer.json` prende o
  `minishlink/web-push` na linha 9.x. As versões 10 e 11 exigem PHP ≥ 8.2; para
  subir, é preciso mover o pin e a máquina de desenvolvimento junto.
- **Rebuild obrigatório ao mexer em dependências.** O `vendor/` vem da imagem
  (volume anônimo no compose); alterar o `composer.json` sem
  `docker compose build php websocket` produz *Class not found* só em produção.
- **Presença por usuário, não por dispositivo.** Usuário ativo no desktop faz o
  push ser descartado para **todos** os dispositivos dele, inclusive o celular
  (§1.3). Corrigir exige guardar a presença por inscrição (`push_subscriptions`)
  em vez de por `usuario_id`, e o worker filtrar endpoint a endpoint.
- **Compatibilidade e requisito de saída de rede**: ver §7 e §8.

---

## 10. Retomada: diagnóstico da intermitência

Sintoma a explicar: **o pop-up chegou nas primeiras tentativas e depois parou**,
sem mudança de código no meio. Rodar na ordem — a primeira consulta costuma já
separar as três famílias de causa.

```sql
-- 1. O que aconteceu com a fila na última hora?
SELECT status, LEFT(ultimo_erro, 80) AS erro, COUNT(*), MAX(criado_em)
  FROM push_fila
 WHERE criado_em > NOW() - INTERVAL 1 HOUR
 GROUP BY status, LEFT(ultimo_erro, 80);
```

| O que aparece | Leitura | Onde continuar |
|---|---|---|
| `descartado` / `usuario_ativo` | O servidor achou que a aba estava na frente. Aba com **JS antigo em cache** não manda `presenca` e o padrão é `ativo = true` (§1.3). | Recarregar as abas; conferir `SELECT * FROM user_presenca` com a janela em segundo plano — tem que virar `0` |
| `ultimo_erro` com o motivo do provedor — `410 Gone`, `404`, `NotRegistered` | **Suspeito principal desta intermitência**: o navegador revogou/rotacionou a inscrição. O `PushCenter` **apaga a linha de `push_subscriptions` na hora** (`isSubscriptionExpired`), então a partir daí não se enfileira mais nada e o silêncio é total. | Ver bloco abaixo |
| `falha` com timeout / erro de conexão | Saída 443 bloqueada ou proxy interceptando. | §8 |
| `descartado` / `expirado` | `push-worker` parado por mais de 10 min (TTL). | `supervisorctl status` |
| **fila vazia** | Ninguém enfileirou: sem linha em `push_subscriptions` o `INSERT` é no-op. | Bloco abaixo |

```sql
-- 2. As inscrições ainda existem e batem com o navegador em uso?
SELECT id, usuario_id, LEFT(endpoint, 60), falhas, criado_em, ultimo_uso_em
  FROM push_subscriptions ORDER BY id DESC;
```

A inscrição some do banco por dois caminhos: **na hora**, quando o serviço de
push responde 404/410 (`removerInscricao`), ou depois de
`MAX_FALHAS_INSCRICAO` (10) falhas consecutivas de outro tipo. Nos dois casos o
usuário para de receber **sem nenhum sinal na interface** — o botão continua
dizendo "Notificações ativas" enquanto o Service Worker ainda tiver a inscrição
local. É exatamente o formato "funcionou e depois parou".

**Comparar o `endpoint` do banco com o que o DevTools mostra em Application →
Service Workers → Push subscription é o teste decisivo**: se divergiu (ou se o
banco não tem a linha e o navegador tem a inscrição), o `pushsubscriptionchange`
do `sw.js` não reinscreveu.

Vale olhar também `push_fila.tentativas`: item que falha é reagendado com
backoff de 2, 4, 8 e 16 minutos e só vira `falha` na 5ª tentativa
(`MAX_TENTATIVAS`) — então logo depois do problema começar ele ainda aparece
como `pendente`, não como `falha`.

Hipóteses a testar, em ordem de suspeita:

1. **Rotação de inscrição não reinscreve.** O handler `pushsubscriptionchange`
   (`sw.js`) depende de o SW estar ativo no momento do evento; se falhar, só o
   próximo carregamento de página conserta (`sincronizarInscricao`). Reproduzir:
   revogar a permissão, recarregar, reconceder, e verificar se o `endpoint` no
   banco acompanhou.
2. **Troca do par VAPID.** Se `VAPID_PUBLIC_KEY` mudar (rebuild com `.env`
   diferente), toda inscrição antiga para de decifrar. O `push.js` compara a
   chave e refaz a inscrição, mas só no próximo carregamento.
3. **`localhost` × IP.** Inscrição feita em `http://localhost:8188` vale só para
   aquela origem; abrir pelo IP não tem push (§2) e não é o mesmo registro de SW.
4. **Worker vivo mas travado.** `docker exec chat_websocket supervisorctl status`
   e o log em `/var/log/supervisor/push-worker.out.log`; itens presos em
   `processando` indicam shutdown abrupto (são destravados no boot do worker).

Se a decisão for **app nativo**, o que sobrevive desta implementação é toda a
metade de servidor — `NotificationCenter`, `PushCenter` como outbox, `push_fila`,
o worker e a regra de presença da §1.3 — trocando apenas o transporte
(FCM/APNs direto para o app) e o `sw.js`/`push.js` pelo cliente do app.
