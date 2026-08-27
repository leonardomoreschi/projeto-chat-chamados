# Notificações

**Documento único do assunto.** Som, toast in-page, central do sino, pop-up do
sistema operacional e o requisito de TLS. Leia a §1 e a §2 antes de mexer em
qualquer aviso.

> Histórico: a tentativa anterior, com **Web Push** (VAPID, `push_fila`,
> `push-worker.php`), vive na branch `teste` e continua pausada — ver
> `docs/push-web-producao-tls.md` naquela branch. O que está na `main` **não usa
> Web Push**: o pop-up é disparado pela própria aba. Isso troca "funciona com o
> navegador fechado" por "funciona sempre que houver uma aba aberta, sem
> servidor de push para dar defeito". Ver §6.

---

## 1. A regra única

Todo aviso passa por **`window.avisoDoSistema()`** (`public/assets/js/utils.js`).
**Não chame `new Notification` direto em lugar nenhum** — foi o que produziu o
bug em que mensagem de chat só tocava o som.

```
evento (WebSocket)
   │
   ├─ notificacoes.js  notificar()              ← linha da tabela `notificacoes`
   └─ notificacoes.js  notificarMensagemChat()  ← evento `new_message`
                          │
                          └─► utils.js  avisoDoSistema()
                                 │
                                 ├─ toca o som (sempre)
                                 ├─ appAtivo()  → devolve false → o chamador mostra o TOAST
                                 └─ senão      → sw.js showNotification() → POP-UP DO SO
```

| Estado da janela do destinatário | O que ele vê |
|---|---|
| **Ativa** — visível **e** com foco | som + toast in-page |
| **Minimizada, desfocada ou em outra aba** | som + pop-up do sistema operacional |
| Permissão negada, ou origem insegura | som + toast (degradação) |

Dois pontos que se repetem em cada camada, de propósito:

- **`appAtivo()` = `!document.hidden && document.hasFocus()`.** Só
  `document.hidden` não basta: uma janela atrás de outro aplicativo continua
  `visible` no Chrome, e um toast ali dentro ninguém vê.
- **Nunca sai "só o som".** Se `avisoDoSistema()` devolve `false`, o chamador é
  obrigado a mostrar o toast.

Exceção única: mensagem da conversa **aberta** com o usuário na frente da tela
toca só o som — a mensagem já apareceu no painel, um toast por cima seria ruído.
Com a janela minimizada, essa mesma conversa aberta gera pop-up.

### 1.1 Mapa do código

| Arquivo | Papel |
|---|---|
| `public/assets/js/utils.js` | `appAtivo()`, `aoMudarAtividade()`, `urlWebSocket()`, `permissaoDeAviso()`, **`avisoDoSistema()`** — a decisão mora aqui |
| `public/sw.js` | Service Worker: exibe o pop-up e trata o clique (foca a aba aberta em vez de abrir outra). **Sem handler de `fetch` e sem handler de `push`** |
| `public/assets/js/notificacoes.js` | Sino, badge, toast, `notificar()`, `notificarMensagemChat()`, banner de permissão |
| `public/assets/js/som-notificacoes.js` | Timbres por tipo de evento (`SomNotificacoes.tocar`) |
| `public/assets/js/chat.js` | Dono do socket na tela `/chat` |
| `public/assets/js/menu-lateral.js` | Dono do socket em **todas as outras telas** com menu lateral |
| `public/assets/js/agendamentos.js` | Socket extra das telas de agendamento |
| `app/Services/ChatServer.php` | Broadcast WS e o `ping`/`pong` de keepalive |
| `app/Support/NotificationCenter.php` | Central do sino: `registrar()` faz upsert por `chave_evento` |
| `scripts/testar-avisos.js` | Teste da matriz de decisão (`node scripts/testar-avisos.js`) |

### 1.2 Quem abre o socket em cada tela

Mensagem de chat **não** cria linha em `notificacoes` (fora do sino e da
central, de propósito). Quem avisa é o dono do socket da tela, e é por isso que
essa tabela importa:

| Tela | Dono do socket | `APP_USER.socketProprio` |
|---|---|---|
| `/chat` | `chat.js` | `true` |
| `/agendamentos`, `/painel-agendamentos` | `menu-lateral.js` (+ `agendamentos.js`) | `true` |
| `/dashboard-ti`, `/meus-chamados`, `/notificacoes` | `menu-lateral.js` | `true` |
| `/admin`, `/dashboard-ti/relatorio` | `notificacoes.js` (não têm menu lateral) | ausente |

**Tela nova = escolher um dono.** Se ela inclui `partials/menu-lateral.php`,
marque `socketProprio => true` e o `menu-lateral.js` cuida de tudo. Se não
inclui, **não** marque — assim o `notificacoes.js` abre a conexão mínima dele.
Marcar `socketProprio` numa tela sem menu lateral deixa a tela muda: foi
exatamente esse o estado de `/admin` e do relatório antes desta entrega.

### 1.3 Deduplicação

Duas conexões da mesma aba (menu lateral + agendamentos) recebem o mesmo evento.
`notificar()` deduplica por `notificacoes.id` e `notificarMensagemChat()` por
`mensagens.id`, ambos com teto de 500 chaves memorizadas. A `tag` do pop-up
colapsa avisos da mesma conversa num balão só.

O replay também é tratado: no `auth`, o `ChatServer` reenvia as últimas 100
mensagens como `new_message` **antes** do `auth_ok`. Todo dono de socket só
começa a avisar depois do `auth_ok` — sem isso, cada F5 viraria uma saraivada de
pop-ups.

---

## 2. TLS é pré-requisito, não enfeite

O navegador só expõe a **Notification API** e o **Service Worker** em *contexto
seguro*: HTTPS, ou `localhost`. Em `http://192.168.x.x:8188` o Chrome **nega a
permissão automaticamente** e o Firefox nem expõe a API — nenhum pop-up aparece,
por melhor que seja o código do front.

`permissaoDeAviso()` devolve `'inseguro'` nesse caso e o `notificacoes.js` grava
um aviso no console. O sistema continua funcionando: som e toast normais, só o
pop-up é que não existe.

### 2.1 Gerar a CA interna

Certificado autoassinado **sem CA instalada não serve**: mesmo depois de o
usuário clicar em "aceitar o risco", o navegador se recusa a registrar o Service
Worker numa origem com certificado não confiável.

```bash
./scripts/gerar-certificados.sh chat.empresa.local 192.168.0.50
```

Gera em `docker/nginx/certs/` (fora do git):

| Arquivo | Para quê |
|---|---|
| `ca.crt` | distribuir para as estações |
| `ca.key` | **guardar em local seguro** — quem tem esta chave emite certificado confiável para qualquer site nas máquinas que confiam na CA |
| `chat.crt`, `chat.key` | certificado do servidor, montados no nginx |

Reexecutar reaproveita a CA e só reemite o certificado do servidor — as estações
não precisam reinstalar nada.

### 2.2 Subir o nginx com TLS

```bash
docker compose up -d --build nginx
docker logs chat_nginx | grep '\[nginx\]'
# → [nginx] TLS habilitado: /etc/nginx/certs/chat.crt
```

O `docker/nginx/entrypoint.sh` decide no boot: **sem certificado, o bloco 443
não é carregado** e o sistema sobe só em HTTP. Isso é deliberado — um
`ssl_certificate` apontando para arquivo inexistente faz o nginx recusar a
configuração inteira, e derrubar o sistema por causa de uma feature opcional
seria péssimo negócio.

Portas: `WEB_HOST_PORT` (8188, HTTP) e `WEB_HOST_PORT_HTTPS` (8443) no `.env`.
**O HTTP continua servindo a aplicação**, sem redirect automático — a migração é
por URL, no seu tempo. E **sem HSTS**, de propósito: o HSTS gravado no navegador
transformaria um retorno ao HTTP num `ERR_SSL_PROTOCOL_ERROR` difícil de
reverter na máquina do usuário.

### 2.3 Distribuir a CA para as estações

- **Windows / GPO:** *Computer Configuration → Policies → Windows Settings →
  Security Settings → Public Key Policies → Trusted Root Certification
  Authorities* → importar `ca.crt`.
- **Firefox:** usa armazenamento próprio — ative
  `security.enterprise_roots.enabled` ou importe a CA por política.

Confira o cadeado **sem aviso** em `https://chat.empresa.local:8443` antes de
pedir a permissão aos usuários. Com aviso de certificado, o Service Worker não
registra e você volta ao ponto de partida.

### 2.4 O que muda sozinho sob HTTPS

Nada precisa ser reconfigurado à mão:

- `urlWebSocket()` passa de `ws://<host>:8080` para `wss://<host>/ws`, proxiado
  pelo nginx (`location /ws` em `snippets/app.conf`) — `ws://` numa página HTTPS
  seria bloqueado por mixed content;
- o cookie de sessão ganha a flag `Secure` (o `public/index.php` detecta o TLS
  pelo `fastcgi_param HTTPS`);
- o Service Worker passa a registrar e o pop-up funciona.

---

## 3. Permissão do navegador

`notificacoes.js` pede a permissão no load. O Chrome concede sem gesto; Firefox
e Safari exigem um clique, e para eles aparece um **banner de um clique** ("Ativar
avisos do sistema"). O mesmo clique resolve dois problemas de uma vez: concede a
permissão e destrava o áudio, que a política de autoplay bloqueia até o primeiro
gesto do usuário na página.

Permissão **negada** não pode ser pedida de novo por JavaScript — só pelo cadeado
da barra de endereço. O código não insiste; registra no console e segue com o
toast.

---

## 4. Janela minimizada: por que continua chegando

O Chrome congela aba de segundo plano ociosa, e aba congelada não avisa nada.
Duas coisas evitam isso:

1. **Keepalive.** Todo dono de socket manda `{type:'ping'}` a cada 25 s e o
   `ChatServer` responde `pong`. Conexão com tráfego não é ociosa.
2. **Reconciliação ao voltar.** `aoMudarAtividade()` reconecta o socket e
   recarrega os contadores quando a janela volta ao foco, cobrindo o intervalo em
   que o navegador tenha segurado os timers assim mesmo.

Na `/chat`, a conversa aberta só é marcada como lida quando o usuário está de
fato na frente dela (`appAtivo()`); minimizado, a mensagem continua não lida e
gera pop-up.

---

## 5. Diagnóstico

Na ordem, no console da aba do usuário:

```js
window.permissaoDeAviso()      // 'inseguro' → falta TLS (§2). 'denied' → cadeado da barra
window.isSecureContext         // false → o resto nem adianta olhar
window.AvisoSistema.temServiceWorker()   // false com contexto seguro → certificado não confiável
navigator.serviceWorker.getRegistrations().then(console.log)
window.appAtivo()              // o que o código acha do estado da janela
window.avisoDoSistema({ titulo: 'Teste', corpo: 'oi', forcarPopup: true })  // true = pop-up saiu
```

No servidor:

```bash
docker logs chat_nginx | grep '\[nginx\]'      # TLS habilitado?
docker logs -f chat_websocket                  # o WS está recebendo os auth?
node scripts/testar-avisos.js                  # a matriz de decisão continua correta?
```

Sintomas frequentes:

| Sintoma | Causa provável |
|---|---|
| Nenhum pop-up, em nenhuma máquina | origem insegura (§2) |
| Pop-up numa máquina só | CA não distribuída naquela estação (§2.3) |
| Som não toca | usuário ainda não clicou na página (autoplay) ou desligou em `localStorage.som_notificacoes` |
| Tela específica muda | `socketProprio` marcado numa tela sem menu lateral (§1.2) |
| Saraivada de pop-ups no F5 | dono de socket avisando antes do `auth_ok` (§1.3) |

---

## 6. Limite conhecido

O pop-up é disparado pela aba. **Com o navegador fechado, não há aviso** — isso
exigiria Web Push, que é justamente a rota pausada na branch `teste`. Janela
minimizada, desfocada, em outra aba ou atrás de outro aplicativo: funciona, que
é o caso de uso real de quem fica com o sistema aberto o dia inteiro.

Se um dia "avisar com o navegador fechado" virar requisito, as opções são
retomar o Web Push (`docs/push-web-producao-tls.md` §10 na `teste`) ou o app
nativo que estava em avaliação. A camada desta `main` não atrapalha nenhuma das
duas: `avisoDoSistema()` continua sendo o ponto único onde um canal novo entra.
