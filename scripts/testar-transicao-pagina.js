/**
 * Interceptação de clique da transição de página.
 *
 *   node scripts/testar-transicao-pagina.js       (da raiz do repositório)
 *
 * A barra de progresso só pode subir quando o navegador REALMENTE vai trocar de
 * documento nesta aba. Subir em ctrl+clique, link externo ou âncora deixaria a
 * barra travada em 80% numa página que não vai a lugar nenhum — pior do que não
 * ter barra, porque mente sobre o que está acontecendo.
 *
 * Sem dependência nenhuma além do próprio node.
 */
const fs = require('fs');
const vm = require('vm');

const ORIGEM = 'https://chat.local';

function montarAmbiente() {
    let aoClicar = null;
    const barra = { id: '', classes: new Set(), isConnected: true, offsetWidth: 0 };
    barra.classList = {
        add: (c) => barra.classes.add(c),
        remove: (c) => barra.classes.delete(c),
        contains: (c) => barra.classes.has(c),
    };

    const janela = {
        location: { href: ORIGEM + '/chat', origin: ORIGEM, pathname: '/chat', search: '' },
        URL,
        addEventListener: () => {},
        console,
    };
    janela.window = janela;
    janela.document = {
        readyState: 'complete',
        body: { appendChild: () => {} },
        createElement: () => barra,
        getElementById: () => barra,
        querySelectorAll: () => [],
        addEventListener: (evento, fn) => { if (evento === 'click') aoClicar = fn; },
    };

    const contexto = vm.createContext(janela);
    vm.runInContext(fs.readFileSync('public/assets/js/transicao-pagina.js', 'utf8'), contexto, {
        filename: 'public/assets/js/transicao-pagina.js',
    });

    return { janela, barra, clicar: (evento) => aoClicar(evento) };
}

/** Link falso com o mínimo que o módulo consulta. */
function link({ href, alvo, download, noMenu }) {
    const el = {
        atributos: { href },
        target: alvo || '',
        href: new URL(href, ORIGEM + '/chat').href,
        ocupado: null,
        getAttribute: (n) => (n === 'href' ? href : null),
        hasAttribute: (n) => (n === 'download' ? Boolean(download) : false),
        setAttribute: (n, v) => { if (n === 'aria-busy') el.ocupado = v; },
        closest: (sel) => (sel === '[data-menu-lateral]' ? (noMenu ? null : {}) : el),
    };
    return el;
}

function clique(alvo, extras = {}) {
    return Object.assign({
        target: { closest: () => alvo },
        button: 0,
        metaKey: false, ctrlKey: false, shiftKey: false, altKey: false,
        defaultPrevented: false,
    }, extras);
}

let falhas = 0;
function conferir(nome, condicao, detalhe) {
    if (condicao) {
        console.log('  ✓ ' + nome);
    } else {
        falhas++;
        console.log('  ✗ ' + nome + (detalhe ? '  → ' + detalhe : ''));
    }
}

function barraSubiu(alvo, extras) {
    const a = montarAmbiente();
    a.clicar(clique(alvo, extras));
    return { subiu: a.barra.classes.has('avancando'), destacou: alvo && alvo.ocupado === 'true' };
}

console.log('\n[1] Navegação de verdade — a barra deve subir');
{
    const r = barraSubiu(link({ href: '/meus-chamados' }));
    conferir('link interno do menu sobe a barra', r.subiu);
    conferir('item clicado é destacado na hora', r.destacou);

    conferir('link interno fora do menu sobe a barra', barraSubiu(link({ href: '/agendamentos', noMenu: true })).subiu);
    conferir('mas não destaca item nenhum', !barraSubiu(link({ href: '/agendamentos', noMenu: true })).destacou);
    conferir('URL absoluta da mesma origem', barraSubiu(link({ href: ORIGEM + '/dashboard-ti' })).subiu);
    conferir('mesma rota com query diferente', barraSubiu(link({ href: '/chat?conversa=7' })).subiu);
}

console.log('\n[2] NÃO é navegação nesta aba — a barra deve ficar quieta');
{
    const casos = [
        ['ctrl+clique (abre em outra aba)', link({ href: '/agendamentos' }), { ctrlKey: true }],
        ['cmd+clique no Mac', link({ href: '/agendamentos' }), { metaKey: true }],
        ['shift+clique (nova janela)', link({ href: '/agendamentos' }), { shiftKey: true }],
        ['clique do meio', link({ href: '/agendamentos' }), { button: 1 }],
        ['target="_blank"', link({ href: '/agendamentos', alvo: '_blank' }), {}],
        ['atributo download', link({ href: '/uploads/anexo.pdf', download: true }), {}],
        ['outra origem', link({ href: 'https://google.com' }), {}],
        ['âncora na própria página', link({ href: '#topo' }), {}],
        ['mailto:', link({ href: 'mailto:ti@empresa.com' }), {}],
        ['tel:', link({ href: 'tel:+5511999999999' }), {}],
        ['javascript:', link({ href: 'javascript:void(0)' }), {}],
        ['href vazio', link({ href: '' }), {}],
        ['evento já cancelado por outro handler', link({ href: '/agendamentos' }), { defaultPrevented: true }],
    ];
    casos.forEach(function ([nome, alvo, extras]) {
        conferir(nome, !barraSubiu(alvo, extras).subiu);
    });

    const a = montarAmbiente();
    a.clicar(clique(null));
    conferir('clique fora de qualquer link', !a.barra.classes.has('avancando'));
}

console.log('\n[3] Volta pelo botão do navegador (bfcache)');
{
    const a = montarAmbiente();
    a.clicar(clique(link({ href: '/meus-chamados' })));
    conferir('barra ficou em 80% ao sair', a.barra.classes.has('avancando'));
    a.janela.TransicaoPagina.limparProgresso();
    conferir('limparProgresso zera a barra ao voltar', !a.barra.classes.has('avancando'));
}

console.log('\n' + (falhas === 0 ? '✓ INTERCEPTAÇÃO CORRETA' : '✗ ' + falhas + ' falha(s)'));
process.exit(falhas === 0 ? 0 : 1);
