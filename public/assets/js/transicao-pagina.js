/**
 * Resposta imediata ao clique de navegação.
 *
 * O menu lateral navega por links normais: o navegador troca o documento
 * inteiro e existe um intervalo — servidor + download + primeira pintura — em
 * que a tela antiga continua no ar, congelada. Sem sinal nenhum nesse intervalo
 * o clique parece não ter funcionado, e a tela nova então estala na tela.
 *
 * Este módulo cobre só esse intervalo, na tela que está saindo: destaca o item
 * clicado e sobe uma barra de progresso. A entrada suave da tela nova é do
 * público/assets/css/transicao-pagina.css, que não depende de JS.
 *
 * Não há fade de saída de propósito — ver o comentário no CSS.
 */
(function () {
    if (window.TransicaoPagina) {
        return;
    }

    let barra = null;

    function obterBarra() {
        if (barra && barra.isConnected) return barra;

        barra = document.getElementById('barra-progresso-pagina');
        if (!barra) {
            barra = document.createElement('div');
            barra.id = 'barra-progresso-pagina';
            document.body.appendChild(barra);
        }
        return barra;
    }

    function iniciarProgresso() {
        const el = obterBarra();

        // Sem ler o layout, o navegador agrupa "largura 0" e "largura 80%" no
        // mesmo quadro e a barra saltaria pronta, sem animar.
        el.classList.remove('avancando');
        void el.offsetWidth;
        el.classList.add('avancando');
    }

    function limparProgresso() {
        const el = document.getElementById('barra-progresso-pagina');
        if (el) el.classList.remove('avancando');

        document.querySelectorAll('[aria-busy="true"]').forEach(function (item) {
            item.removeAttribute('aria-busy');
        });
    }

    /** Clique que o navegador vai tratar como navegação nesta mesma aba. */
    function ehNavegacaoInterna(evento, link) {
        if (evento.defaultPrevented) return false;
        // Ctrl/Cmd/Shift/meio: o usuário quer outra aba, a tela atual fica.
        if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) return false;
        if (link.target && link.target !== '_self') return false;
        if (link.hasAttribute('download')) return false;

        const destino = link.getAttribute('href') || '';
        if (!destino || destino.startsWith('#')) return false;
        // mailto:, tel:, javascript: — nada disso troca de página.
        if (/^[a-z][a-z0-9+.-]*:/i.test(destino) && !/^https?:/i.test(destino)) return false;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (_) {
            return false;
        }

        if (url.origin !== window.location.origin) return false;
        // Só a âncora mudou: não há documento novo para esperar.
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;

        return true;
    }

    function aoClicar(evento) {
        const link = evento.target.closest('a[href]');
        if (!link || !ehNavegacaoInterna(evento, link)) return;

        // Confirma o clique no próprio item, antes de a tela nova existir.
        if (link.closest('[data-menu-lateral]')) {
            link.setAttribute('aria-busy', 'true');
        }

        iniciarProgresso();
    }

    function iniciar() {
        document.addEventListener('click', aoClicar);

        // Voltar pelo botão do navegador restaura a página do bfcache com a
        // barra congelada em 80% e o item ainda destacado, como estavam no
        // momento em que ela saiu de cena.
        window.addEventListener('pageshow', limparProgresso);
        window.addEventListener('pagehide', limparProgresso);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    window.TransicaoPagina = {
        iniciarProgresso: iniciarProgresso,
        limparProgresso: limparProgresso,
    };
})();
