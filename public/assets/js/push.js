/**
 * Web Push — registro do Service Worker e gestão da inscrição.
 *
 * Fica separado do notificacoes.js de propósito: aquele módulo só inicia quando
 * a página tem `[data-notification-badge]`, e o push precisa registrar/renovar
 * independentemente disso.
 *
 * Em origem insegura (http://<ip>:8188, que é o acesso pela LAN hoje) as APIs
 * nem existem no window: o módulo se desliga em silêncio, esconde os controles
 * e não escreve nada no console. Ver docs/push-web-producao-tls.md.
 */
(function () {
    if (window.PushWeb) {
        return;
    }

    const SUPORTADO = window.isSecureContext
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    const estado = {
        registro: null,
        inscricao: null,
        chavePublica: '',
        habilitadoNoServidor: null,
        sincronizando: null,
    };

    // Sem isso, quem clicasse em "desativar" seria reinscrito automaticamente
    // no próximo carregamento de página (a permissão continua 'granted').
    const CHAVE_OPTOUT = 'push:desativado';

    function optOut(valor) {
        try {
            if (valor === undefined) {
                return window.localStorage.getItem(CHAVE_OPTOUT) === '1';
            }
            if (valor) {
                window.localStorage.setItem(CHAVE_OPTOUT, '1');
            } else {
                window.localStorage.removeItem(CHAVE_OPTOUT);
            }
        } catch (_) {
            // localStorage bloqueado: segue sem memória do opt-out.
        }
        return !!valor;
    }

    function botoes() {
        return Array.from(document.querySelectorAll('[data-push-toggle]'));
    }

    function avisosDeContexto() {
        return Array.from(document.querySelectorAll('[data-push-inseguro]'));
    }

    function esconderControles() {
        botoes().forEach(function (botao) {
            botao.classList.add('hidden');
        });
        // O único caso em que vale explicar: a página tem um aviso preparado
        // dizendo que o recurso exige HTTPS.
        avisosDeContexto().forEach(function (aviso) {
            aviso.classList.remove('hidden');
        });
    }

    if (!SUPORTADO) {
        window.PushWeb = {
            suportado: false,
            ativar: function () { return Promise.resolve(false); },
            desativar: function () { return Promise.resolve(false); },
            sincronizar: function () { return Promise.resolve(false); },
        };
        document.addEventListener('DOMContentLoaded', esconderControles);
        return;
    }

    // ------------------------------------------------------------------
    // Conversões base64url <-> bytes exigidas pelo applicationServerKey
    // ------------------------------------------------------------------

    function base64UrlParaUint8Array(base64Url) {
        const preenchimento = '='.repeat((4 - (base64Url.length % 4)) % 4);
        const base64 = (base64Url + preenchimento).replace(/-/g, '+').replace(/_/g, '/');
        const bruto = window.atob(base64);
        const saida = new Uint8Array(bruto.length);
        for (let i = 0; i < bruto.length; i++) {
            saida[i] = bruto.charCodeAt(i);
        }
        return saida;
    }

    function uint8ArrayParaBase64Url(buffer) {
        if (!buffer) return '';
        const bytes = new Uint8Array(buffer);
        let bruto = '';
        for (let i = 0; i < bytes.length; i++) {
            bruto += String.fromCharCode(bytes[i]);
        }
        return window.btoa(bruto).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // ------------------------------------------------------------------
    // Service Worker e inscrição
    // ------------------------------------------------------------------

    async function registrarServiceWorker() {
        if (estado.registro) {
            return estado.registro;
        }
        // Sem ?v= aqui: a query string faz parte da identidade do Service
        // Worker, então versionar a URL recriaria o registro a cada deploy. O
        // frescor do arquivo vem do Cache-Control no nginx.
        estado.registro = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        return estado.registro;
    }

    async function buscarConfiguracao() {
        const resposta = await fetch('/api/push/chave-publica', { credentials: 'same-origin' });
        if (!resposta.ok) {
            return null;
        }
        return resposta.json();
    }

    /**
     * Garante que a inscrição deste navegador está registrada no servidor.
     *
     * @param {boolean} forcar cria a inscrição mesmo que ainda não exista
     *                         (só faz sentido logo após um gesto do usuário)
     */
    function sincronizarInscricao(forcar) {
        if (estado.sincronizando) {
            return estado.sincronizando;
        }

        estado.sincronizando = (async function () {
            try {
                const registro = await registrarServiceWorker();
                const cfg = await buscarConfiguracao();

                estado.habilitadoNoServidor = !!(cfg && cfg.habilitado && cfg.chave);
                if (!estado.habilitadoNoServidor) {
                    renderizarBotao();
                    return false;
                }
                estado.chavePublica = String(cfg.chave);

                let inscricao = await registro.pushManager.getSubscription();

                // Se o servidor trocou o par VAPID, a inscrição antiga não
                // decifra mais nada — descarta e refaz.
                if (inscricao) {
                    const chaveAtual = uint8ArrayParaBase64Url(
                        inscricao.options && inscricao.options.applicationServerKey
                    );
                    if (chaveAtual && chaveAtual !== estado.chavePublica) {
                        await inscricao.unsubscribe();
                        inscricao = null;
                    }
                }

                if (!inscricao) {
                    if (!forcar || Notification.permission !== 'granted') {
                        estado.inscricao = null;
                        renderizarBotao();
                        return false;
                    }
                    inscricao = await registro.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: base64UrlParaUint8Array(estado.chavePublica),
                    });
                }

                const envio = await fetch('/api/push/inscrever', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(inscricao.toJSON()),
                });

                if (!envio.ok) {
                    renderizarBotao();
                    return false;
                }

                estado.inscricao = inscricao;
                renderizarBotao();
                return true;
            } catch (_) {
                renderizarBotao();
                return false;
            } finally {
                estado.sincronizando = null;
            }
        })();

        return estado.sincronizando;
    }

    async function ativar() {
        // requestPermission tem que sair de um gesto: fora dele o Chrome usa a
        // "quiet UI" e o Safari simplesmente ignora.
        if (Notification.permission === 'denied') {
            renderizarBotao();
            return false;
        }

        try {
            const permissao = await Notification.requestPermission();
            if (permissao !== 'granted') {
                renderizarBotao();
                return false;
            }
        } catch (_) {
            renderizarBotao();
            return false;
        }

        optOut(false);
        return sincronizarInscricao(true);
    }

    async function desativar() {
        optOut(true);
        try {
            const registro = await registrarServiceWorker();
            const inscricao = await registro.pushManager.getSubscription();
            if (!inscricao) {
                estado.inscricao = null;
                renderizarBotao();
                return true;
            }

            const endpoint = inscricao.endpoint;
            await inscricao.unsubscribe();
            estado.inscricao = null;

            await fetch('/api/push/inscrever', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: endpoint }),
            });

            renderizarBotao();
            return true;
        } catch (_) {
            renderizarBotao();
            return false;
        }
    }

    // ------------------------------------------------------------------
    // UI
    // ------------------------------------------------------------------

    function renderizarBotao() {
        const lista = botoes();
        if (!lista.length) return;

        const permissao = Notification.permission;
        let rotulo;
        let titulo;
        let desabilitado = false;
        let ativo = false;

        if (estado.habilitadoNoServidor === false) {
            rotulo = 'Notificações indisponíveis';
            titulo = 'O servidor ainda não tem as chaves VAPID configuradas.';
            desabilitado = true;
        } else if (permissao === 'denied') {
            rotulo = 'Notificações bloqueadas';
            // requestPermission() não reabre o prompt depois de 'denied':
            // a única saída é o usuário mudar a permissão do site.
            titulo = 'Você bloqueou as notificações para este site. Para reativar, clique no cadeado ao lado do endereço → Notificações → Permitir.';
            desabilitado = true;
        } else if (permissao === 'granted' && estado.inscricao) {
            rotulo = 'Notificações ativas';
            titulo = 'Clique para desativar as notificações neste dispositivo.';
            ativo = true;
        } else {
            rotulo = 'Ativar notificações';
            titulo = 'Receber avisos deste sistema mesmo com a aba fechada.';
        }

        lista.forEach(function (botao) {
            botao.classList.remove('hidden');
            botao.disabled = desabilitado;
            botao.title = titulo;
            botao.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            botao.dataset.pushEstado = ativo ? 'ativo' : (desabilitado ? 'bloqueado' : 'inativo');

            const texto = botao.querySelector('[data-push-texto]');
            if (texto) {
                texto.textContent = rotulo;
            } else {
                botao.textContent = rotulo;
            }
        });
    }

    function configurarBotoes() {
        botoes().forEach(function (botao) {
            if (botao.dataset.pushLigado === '1') return;
            botao.dataset.pushLigado = '1';
            botao.addEventListener('click', function () {
                if (botao.disabled) return;
                if (botao.dataset.pushEstado === 'ativo') {
                    desativar();
                } else {
                    ativar();
                }
            });
        });
    }

    /**
     * No logout, remove a inscrição deste navegador. Não dá para fazer isso no
     * servidor: lá não se sabe qual dos dispositivos do usuário está saindo.
     */
    function configurarLogout() {
        document.querySelectorAll('a[href="/logout"]').forEach(function (link) {
            if (link.dataset.pushLogout === '1') return;
            link.dataset.pushLogout = '1';

            link.addEventListener('click', function (evento) {
                if (!estado.inscricao) return;

                evento.preventDefault();
                const seguir = function () { window.location.href = '/logout'; };
                // Rede lenta não pode prender o usuário na página.
                Promise.race([
                    desativar(),
                    new Promise(function (resolver) { setTimeout(resolver, 1200); }),
                ]).then(seguir, seguir);
            });
        });
    }

    // Push que chegou com a aba visível: o SW encaminha para cá em vez de
    // mostrar popup do SO, e o aviso vira o toast já existente.
    navigator.serviceWorker.addEventListener('message', function (evento) {
        const msg = evento.data || {};
        if (msg.tipo !== 'push_recebido' || !msg.payload) return;
        if (!window.NotificationCenterUI) return;

        const aviso = {
            id: Number(msg.payload.notificacao_id || 0),
            titulo: msg.payload.titulo,
            mensagem: msg.payload.corpo,
            url: msg.payload.url,
        };

        // Mensagem de chat não entra na central: só o toast, sem tocar no
        // contador do sino (notificar() incrementaria o badge).
        if (msg.payload.origem === 'mensagem') {
            window.NotificationCenterUI.mostrarToast(aviso);
            return;
        }

        // notificar() deduplica por id, então não conflita com o mesmo evento
        // chegando pelo WebSocket.
        window.NotificationCenterUI.notificar(aviso);
    });

    document.addEventListener('DOMContentLoaded', function () {
        const usuario = window.APP_USER || {};
        if (!usuario.id) {
            esconderControles();
            return;
        }

        configurarBotoes();
        configurarLogout();
        renderizarBotao();

        // Registra sempre (barato e deixa o SW pronto), mas só recria a
        // inscrição sozinho se a permissão já foi concedida antes.
        registrarServiceWorker().then(function () {
            // Recria a inscrição sozinho só se o usuário já concedeu permissão e
            // não desligou o recurso de propósito.
            const recriar = Notification.permission === 'granted' && !optOut();
            return sincronizarInscricao(recriar);
        }).catch(function () {
            renderizarBotao();
        });
    });

    window.PushWeb = {
        suportado: true,
        ativar: ativar,
        desativar: desativar,
        sincronizar: sincronizarInscricao,
    };
})();
