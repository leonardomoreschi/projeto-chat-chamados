/**
 * Matriz de decisão dos avisos — o único teste automatizado do projeto.
 *
 *   node scripts/testar-avisos.js       (da raiz do repositório)
 *
 * Carrega utils.js + som-notificacoes.js + notificacoes.js num DOM falso e
 * confere, cenário a cenário, quem deve avisar o quê: toast com o usuário na
 * frente da tela, pop-up do SO com a janela minimizada ou desfocada, silêncio
 * no eco da própria mensagem, e degradação limpa quando a origem é insegura.
 *
 * Não precisa de dependência nenhuma além do próprio node — de propósito, para
 * continuar rodando numa VM sem npm.
 */
const fs = require('fs');
const vm = require('vm');

function novoAmbiente({ oculto, comFoco, permissao, seguro }) {
    const popupsSW = [];
    const popupsConstrutor = [];

    function elemento(tag) {
        return {
            tagName: tag, className: '', textContent: '', innerHTML: '', id: '',
            filhos: [],
            classList: { add() {}, remove() {}, toggle() {} },
            addEventListener() {},
            querySelector() { return elemento('div'); },
            querySelectorAll() { return []; },
            appendChild(f) { this.filhos.push(f); },
            remove() {},
        };
    }

    const corpo = elemento('body');
    const badge = elemento('span');

    const documento = {
        get hidden() { return oculto; },
        hasFocus: () => comFoco,
        body: corpo,
        createElement: elemento,
        addEventListener() {},
        getElementById: () => null,
        querySelectorAll: (sel) => (String(sel).includes('notification-badge') ? [badge] : []),
        querySelector: () => null,
    };

    function Notification(titulo, opcoes) {
        popupsConstrutor.push({ titulo, opcoes });
        this.close = () => {};
    }
    Notification.permission = permissao;
    Notification.requestPermission = () => Promise.resolve(permissao);

    const janela = {
        isSecureContext: seguro,
        location: { protocol: seguro ? 'https:' : 'http:', host: 'chat.local', hostname: 'chat.local', origin: seguro ? 'https://chat.local' : 'http://chat.local' },
        navigator: {
            serviceWorker: {
                register: () => Promise.resolve({
                    showNotification: (titulo, opcoes) => {
                        popupsSW.push({ titulo, opcoes });
                        return Promise.resolve();
                    },
                }),
            },
        },
        APP_USER: { id: 7, nome: 'Sofia', papel: 'usuario' },
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        setTimeout: () => 0,
        setInterval: () => 0,
        clearInterval() {},
        clearTimeout() {},
        fetch: () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ nao_lidas: 0 }) }),
        WebSocket: function () { this.readyState = 0; },
        console,
    };
    if (seguro) janela.Notification = Notification;
    janela.window = janela;
    janela.document = documento;

    const contexto = vm.createContext(janela);
    for (const arquivo of [
        'public/assets/js/utils.js',
        'public/assets/js/som-notificacoes.js',
        'public/assets/js/notificacoes.js',
    ]) {
        vm.runInContext(fs.readFileSync(arquivo, 'utf8'), contexto, { filename: arquivo });
    }

    return { janela, popupsSW, popupsConstrutor, toasts: corpo.filhos };
}

/** O registro do Service Worker é uma promise; deixa o microtask drenar. */
const assentar = () => new Promise((r) => setImmediate(r));

let falhas = 0;
function conferir(nome, condicao, detalhe) {
    if (condicao) {
        console.log('  ✓ ' + nome);
    } else {
        falhas++;
        console.log('  ✗ ' + nome + (detalhe ? '  → ' + detalhe : ''));
    }
}

const MSG = { id: 42, usuario_id: 99, usuario_nome: 'Admin', conversa_id: 3, conteudo: 'Servidor caiu' };

(async function () {
    // ── 1 ────────────────────────────────────────────────────────────────────
    console.log('\n[1] Janela minimizada + permissão concedida → pop-up do SO');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        conferir('pop-up disparado pelo Service Worker', a.popupsSW.length === 1, JSON.stringify(a.popupsSW));
        conferir('título traz o autor', a.popupsSW[0] && a.popupsSW[0].titulo === 'Nova mensagem de Admin');
        conferir('tag colapsa por conversa', a.popupsSW[0] && a.popupsSW[0].opcoes.tag === 'conversa:3');
        conferir('clique abre a conversa certa', a.popupsSW[0] && a.popupsSW[0].opcoes.data.url === '/chat?conversa=3');
        conferir('sem toast (ninguém veria)', a.toasts.length === 0);
    }

    // ── 2 ────────────────────────────────────────────────────────────────────
    console.log('\n[2] Janela VISÍVEL mas SEM FOCO (atrás de outro app) → pop-up, não toast');
    {
        const a = novoAmbiente({ oculto: false, comFoco: false, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        conferir('pop-up disparado', a.popupsSW.length === 1);
        conferir('sem toast', a.toasts.length === 0);
    }

    // ── 3 ────────────────────────────────────────────────────────────────────
    console.log('\n[3] Usuário na frente da tela → toast, sem pop-up');
    {
        const a = novoAmbiente({ oculto: false, comFoco: true, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        conferir('nenhum pop-up do SO', a.popupsSW.length === 0);
        conferir('toast na página', a.toasts.length === 1);
    }

    // ── 4 ────────────────────────────────────────────────────────────────────
    console.log('\n[4] Conversa ABERTA e usuário na frente → só o som (a mensagem já está na tela)');
    {
        const a = novoAmbiente({ oculto: false, comFoco: true, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG, { conversaAberta: true });
        conferir('sem pop-up', a.popupsSW.length === 0);
        conferir('sem toast duplicando a mensagem', a.toasts.length === 0);
    }

    // ── 5 ────────────────────────────────────────────────────────────────────
    console.log('\n[5] Conversa aberta MAS janela minimizada → pop-up mesmo assim');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG, { conversaAberta: true });
        conferir('pop-up disparado', a.popupsSW.length === 1);
    }

    // ── 6 ────────────────────────────────────────────────────────────────────
    console.log('\n[6] Eco da própria mensagem → silêncio total');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat({ ...MSG, usuario_id: 7 });
        conferir('nada disparado', a.popupsSW.length === 0 && a.toasts.length === 0);
    }

    // ── 7 ────────────────────────────────────────────────────────────────────
    console.log('\n[7] Mesma mensagem chegando duas vezes → avisa uma só');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        conferir('um pop-up só', a.popupsSW.length === 1, 'popups=' + a.popupsSW.length);
    }

    // ── 8 ────────────────────────────────────────────────────────────────────
    console.log('\n[8] Origem INSEGURA (http://ip:8188) → degrada para toast, sem quebrar');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'granted', seguro: false });
        await assentar();
        conferir('permissaoDeAviso() reporta "inseguro"', a.janela.permissaoDeAviso() === 'inseguro');
        conferir('urlWebSocket cai para ws://:8080', a.janela.urlWebSocket() === 'ws://chat.local:8080');
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        conferir('nenhum pop-up (o navegador bloquearia)', a.popupsSW.length === 0 && a.popupsConstrutor.length === 0);
        conferir('toast como último recurso', a.toasts.length === 1);
    }

    // ── 9 ────────────────────────────────────────────────────────────────────
    console.log('\n[9] Permissão NEGADA em origem segura → toast');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'denied', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificarMensagemChat(MSG);
        conferir('nenhum pop-up', a.popupsSW.length === 0);
        conferir('toast garantido', a.toasts.length === 1);
    }

    // ── 10 ───────────────────────────────────────────────────────────────────
    console.log('\n[10] Notificação de chamado/agendamento minimizada → pop-up com a URL da central');
    {
        const a = novoAmbiente({ oculto: true, comFoco: false, permissao: 'granted', seguro: true });
        await assentar();
        a.janela.NotificationCenterUI.notificar({
            id: 501, tipo: 'chamado', evento: 'novo_chamado',
            titulo: 'Novo chamado #88', mensagem: 'Impressora sem toner', url: '/dashboard-ti',
        });
        conferir('pop-up disparado', a.popupsSW.length === 1);
        conferir('URL da notificação preservada', a.popupsSW[0] && a.popupsSW[0].opcoes.data.url === '/dashboard-ti');
        conferir('badge somou 1', a.janela.document.querySelectorAll('[data-notification-badge]')[0].textContent === '1');
    }

    // ── 11 ───────────────────────────────────────────────────────────────────
    console.log('\n[11] wss:// sob HTTPS');
    {
        const a = novoAmbiente({ oculto: false, comFoco: true, permissao: 'granted', seguro: true });
        await assentar();
        conferir('urlWebSocket() usa o proxy do nginx', a.janela.urlWebSocket() === 'wss://chat.local/ws');
    }

    console.log('\n' + (falhas === 0 ? '✓ TODOS OS CENÁRIOS PASSARAM' : '✗ ' + falhas + ' falha(s)'));
    process.exit(falhas === 0 ? 0 : 1);
})();
