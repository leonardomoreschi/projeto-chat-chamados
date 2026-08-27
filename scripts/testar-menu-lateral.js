/**
 * Coreografia do menu lateral ao minimizar/maximizar.
 *
 *   node scripts/testar-menu-lateral.js       (da raiz do repositório)
 *
 * `display:none` não anima. O que faz a transição parecer suave não é a
 * duração, é a ORDEM: o que entra volta ao fluxo antes do fade começar, e o que
 * sai só deixa o fluxo depois que já está invisível — assim o salto de layout
 * acontece num instante em que ninguém está olhando para ele.
 *
 * Este teste trava essa ordem. Os tempos aqui espelham os de
 * public/assets/css/menu-lateral.css: mudar um lado sem o outro traz de volta o
 * piscar que isto corrige.
 *
 * Sem dependência nenhuma além do próprio node — de propósito, para continuar
 * rodando numa VM sem npm.
 */
const fs = require('fs');
const vm = require('vm');

function criarClassList(el) {
    return {
        add: (...c) => c.forEach((x) => el.classes.add(x)),
        remove: (...c) => c.forEach((x) => el.classes.delete(x)),
        contains: (c) => el.classes.has(c),
        toggle: (c, forcar) => {
            const ligar = forcar === undefined ? !el.classes.has(c) : forcar;
            if (ligar) el.classes.add(c); else el.classes.delete(c);
            return ligar;
        },
    };
}

const casa = (el, seletor) => seletor.startsWith('[') && seletor.slice(1, -1) in el.atributos;

function elemento(atributos = {}, classes = '') {
    const el = {
        atributos,
        classes: new Set(String(classes).split(' ').filter(Boolean)),
        filhos: [],
        offsetWidth: 288,
        addEventListener: () => {},
    };
    el.classList = criarClassList(el);
    el.querySelector = (s) => el.filhos.find((f) => casa(f, s)) || null;
    el.querySelectorAll = (s) => el.filhos.filter((f) => casa(f, s));
    return el;
}

function montarAmbiente() {
    const conteudoTopo = elemento({ 'data-menu-conteudo': 1 });
    const conteudoNav = elemento({ 'data-menu-conteudo': 1 });
    const faixa = elemento({ 'data-menu-recolhido': 1 }, 'hidden flex-1 flex-col');
    const botao = elemento({ 'data-menu-toggle': 1 });
    const icone = elemento({ 'data-menu-icone': 1 });
    const menu = elemento({ 'data-menu-lateral': 1 }, 'bg-gray-900 flex flex-col shrink-0 h-screen');
    menu.filhos = [conteudoTopo, conteudoNav, faixa, botao, icone];

    const timers = [];
    const guardados = new Map();
    let aoCarregar = null;

    const janela = {
        APP_USER: { id: 1 },
        localStorage: {
            getItem: (k) => (guardados.has(k) ? guardados.get(k) : null),
            setItem: (k, v) => guardados.set(k, String(v)),
            removeItem: (k) => guardados.delete(k),
        },
        setTimeout: (fn, ms) => timers.push({ fn, ms }),
        clearTimeout: () => {},
        setInterval: () => 0,
        clearInterval: () => {},
        fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve([]) }),
        WebSocket: function () {},
        escapeHtml: (v) => String(v || ''),
        aoMudarAtividade: () => {},
        urlWebSocket: () => 'ws://teste:8080',
        console,
    };
    janela.window = janela;
    janela.document = {
        querySelector: (s) => (casa(menu, s) ? menu : null),
        querySelectorAll: () => [],
        getElementById: () => null,   // sem #menu-lista-conversas: não abre socket
        addEventListener: (evento, fn) => { if (evento === 'DOMContentLoaded') aoCarregar = fn; },
    };

    const contexto = vm.createContext(janela);
    vm.runInContext(fs.readFileSync('public/assets/js/menu-lateral.js', 'utf8'), contexto, {
        filename: 'public/assets/js/menu-lateral.js',
    });

    return {
        janela, menu, conteudoTopo, conteudoNav, faixa, botao, icone, guardados,
        carregarPagina: () => aoCarregar && aoCarregar(),
        // Devolve os timers que estavam pendentes, já executados.
        avancarFade: () => timers.splice(0).map((t) => (t.fn(), t)),
        timersPendentes: () => timers.length,
    };
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
const classes = (el) => Array.from(el.classes).join(' ');

// ── Minimizar e maximizar pelo botão ─────────────────────────────────────────
{
    const a = montarAmbiente();
    a.carregarPagina();

    console.log('\n[1] Estado inicial: menu aberto');
    conferir('faixa de ícones fora do fluxo', a.faixa.classList.contains('hidden'));
    conferir('conteúdo no fluxo', !a.conteudoNav.classList.contains('hidden'));

    console.log('\n[2] Clique em minimizar — o mesmo quadro (t=0)');
    a.janela.MenuLateral.alternar();
    conferir('classe menu-recolhido aplicada (é ela que leva a largura a 4rem)', a.menu.classList.contains('menu-recolhido'));
    // Regressão: `w-16` não aparece em HTML nenhum, então o compilador JIT do
    // Tailwind só geraria a regra dela no primeiro clique — tarde demais para a
    // transição. Era o que fazia a PRIMEIRA minimizada de cada carregamento
    // estalar. A largura tem de vir de menu-lateral.css.
    conferir('não usa classe de largura do Tailwind', !a.menu.classList.contains('w-16') && !a.menu.classList.contains('w-72'), classes(a.menu));
    conferir('faixa JÁ voltou ao fluxo, para poder aparecer', !a.faixa.classList.contains('hidden') && a.faixa.classList.contains('flex'), classes(a.faixa));
    conferir('conteúdo AINDA no fluxo (só está desvanecendo)', !a.conteudoNav.classList.contains('hidden'), classes(a.conteudoNav));
    conferir('animação liberada', !a.menu.classList.contains('menu-sem-animacao'));
    conferir('título do botão vira "Expandir menu"', a.botao.title === 'Expandir menu', String(a.botao.title));

    console.log('\n[3] Depois do fade');
    const disparados = a.avancarFade();
    conferir('exatamente 1 timer agendado', disparados.length === 1, 'timers=' + disparados.length);
    conferir('o atraso espelha o fade do CSS (110ms)', disparados[0] && disparados[0].ms === 110, String(disparados[0] && disparados[0].ms));
    conferir('só AGORA o conteúdo sai do fluxo', a.conteudoTopo.classList.contains('hidden') && a.conteudoNav.classList.contains('hidden'));
    conferir('faixa permanece', !a.faixa.classList.contains('hidden'));

    console.log('\n[4] Clique em maximizar');
    a.janela.MenuLateral.alternar();
    conferir('menu-recolhido removido (largura volta a 18rem)', !a.menu.classList.contains('menu-recolhido'));
    conferir('segue sem classe de largura do Tailwind', !a.menu.classList.contains('w-16') && !a.menu.classList.contains('w-72'), classes(a.menu));
    conferir('conteúdo JÁ voltou ao fluxo', !a.conteudoNav.classList.contains('hidden'), classes(a.conteudoNav));
    conferir('faixa AINDA no fluxo (desvanecendo)', !a.faixa.classList.contains('hidden'), classes(a.faixa));
    a.avancarFade();
    conferir('faixa sai do fluxo só depois do fade', a.faixa.classList.contains('hidden') && !a.faixa.classList.contains('flex'), classes(a.faixa));

    console.log('\n[5] O ícone é girado pelo CSS, não trocado por innerHTML');
    conferir('innerHTML do ícone intacto', a.icone.innerHTML === undefined, String(a.icone.innerHTML));
}

// ── Primeiro paint com o menu já minimizado ──────────────────────────────────
{
    const a = montarAmbiente();
    a.guardados.set('menu-lateral:recolhido', '1');
    a.carregarPagina();

    console.log('\n[6] Entrando numa tela com o menu já minimizado');
    conferir('estado aplicado (menu-recolhido)', a.menu.classList.contains('menu-recolhido'), classes(a.menu));
    conferir('conteúdo fora do fluxo NA HORA, sem esperar fade', a.conteudoNav.classList.contains('hidden'));
    conferir('nenhum timer de fade agendado', a.timersPendentes() === 0, 'timers=' + a.timersPendentes());
    conferir('trava removida ao final (a animação volta para os cliques)', !a.menu.classList.contains('menu-sem-animacao'), classes(a.menu));
}

console.log('\n' + (falhas === 0 ? '✓ COREOGRAFIA CORRETA' : '✗ ' + falhas + ' falha(s)'));
process.exit(falhas === 0 ? 0 : 1);
