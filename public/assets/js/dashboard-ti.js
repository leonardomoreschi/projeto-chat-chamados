const CONFIG = window.APP_CONFIG || { categorias: {}, prioridades: {}, status: {} };

let chamadosCache = [];
let historicoMinimizado = false;
const anexosChamadosCache = {};
const comentariosChamadosCache = {};
let comentariosModoEdicao = false;
let comentariosChamadoAtualId = 0;
let anexosComentario = null;

// Espelha a allowlist de ChamadoController::salvarArquivoComentario.
const EXTENSOES_ANEXO_COMENTARIO = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
    'pdf', 'doc', 'docx', 'txt',
    'step', 'stp', 'exe',
];

// INICIALIZAÇÃO
document.addEventListener('DOMContentLoaded', () => {
    if (Array.isArray(window.DASHBOARD_TI_BOOTSTRAP) && window.DASHBOARD_TI_BOOTSTRAP.length > 0) {
        chamadosCache = window.DASHBOARD_TI_BOOTSTRAP;
        renderizarTudo();
        marcarChamadosComoVisualizados();
    }

    carregarTaxonomias().then(() => {
        popularCategorias();
        popularFiltroCategorias();
        popularFiltroSubcategorias();
        popularFiltroHistoricoCategorias();
        popularFiltroHistoricoSubcategorias();
    });
    carregarDados();
    configurarAnexosComentario();
});

function configurarAnexosComentario() {
    if (!window.criarGerenciadorAnexos) return;

    const label = document.getElementById('label-comentario-anexos');

    anexosComentario = window.criarGerenciadorAnexos({
        input: 'comentario-anexos',
        lista: 'comentario-anexos-lista',
        extensoes: EXTENSOES_ANEXO_COMENTARIO,
        aoMudar: function (estado) {
            if (!label) return;

            if (estado.quantidade === 0) {
                label.textContent = 'Clique para selecionar arquivos';
                label.classList.remove('text-indigo-300', 'font-semibold');
                label.classList.add('text-gray-400');
                return;
            }

            label.textContent = estado.quantidade === 1
                ? '1 arquivo selecionado'
                : estado.quantidade + ' arquivos selecionados';
            label.classList.remove('text-gray-400');
            label.classList.add('text-indigo-300', 'font-semibold');
        },
    });
}

function respostaIndicaSessaoExpirada(res) {
    if (!res) return false;
    if (res.redirected && res.url && res.url.includes('/login')) return true;
    return res.status === 401 || res.status === 403;
}

function exibirAvisoCarregamento(mensagem) {
    const triagem = document.getElementById('container-triagem');
    const documentados = document.getElementById('container-documentados');
    const historico = document.getElementById('conteudo-historico');
    const html = `<div class="bg-red-500/10 border border-red-500/30 text-red-300 text-xs rounded-xl p-3">${mensagem}</div>`;

    if (triagem) triagem.innerHTML = html;
    if (documentados) documentados.innerHTML = html;
    if (historico) historico.innerHTML = html;
}

function popularCategorias() {
    const sel = document.getElementById('sel-categoria');
    if (!sel) return;
    sel.innerHTML = '<option value="">Selecione...</option>';
    Object.keys(CONFIG.categorias).forEach(cat => {
        sel.innerHTML += `<option value="${cat}">${cat}</option>`;
    });
}

function atualizarSubcategorias() {
    const cat = document.getElementById('sel-categoria').value;
    const selSub = document.getElementById('sel-subcategoria');
    selSub.innerHTML = '<option value="">Selecione...</option>';
    if (cat) CONFIG.categorias[cat].forEach(s => selSub.innerHTML += `<option value="${s}">${s}</option>`);
}

function popularFiltroCategorias() {
    const filtroSetor = document.getElementById('filtro-setor');
    if (!filtroSetor) return;

    const atual = filtroSetor.value;
    filtroSetor.innerHTML = '<option value="">TODOS OS SETORES</option>';

    Object.keys(CONFIG.categorias).forEach(cat => {
        filtroSetor.innerHTML += `<option value="${cat}">${cat.toUpperCase()}</option>`;
    });

    if (atual && CONFIG.categorias[atual]) {
        filtroSetor.value = atual;
    }
}

function popularFiltroSubcategorias() {
    const filtroSetor = document.getElementById('filtro-setor');
    const filtroSub = document.getElementById('filtro-subcategoria');
    if (!filtroSub) return;

    const categoriaSelecionada = filtroSetor ? filtroSetor.value : '';
    const valorAtual = filtroSub.value;

    filtroSub.innerHTML = '<option value="">TODAS AS SUBCATEGORIAS</option>';

    if (categoriaSelecionada && CONFIG.categorias[categoriaSelecionada]) {
        CONFIG.categorias[categoriaSelecionada].forEach(sub => {
            filtroSub.innerHTML += `<option value="${sub}">${sub.toUpperCase()}</option>`;
        });
        filtroSub.disabled = false;
    } else {
        filtroSub.disabled = true;
    }

    if (!filtroSub.disabled && valorAtual) {
        filtroSub.value = valorAtual;
    } else {
        filtroSub.value = '';
    }

    renderizarTudo();
}

// Zera os filtros da aba "Chamados Documentados". popularFiltroSubcategorias()
// já repopula a subcategoria e chama renderizarTudo() no fim.
function limparFiltrosDocumentados() {
    const filtroSetor = document.getElementById('filtro-setor');
    const filtroSub = document.getElementById('filtro-subcategoria');
    const filtroOrdenacao = document.getElementById('filtro-ordenacao');

    if (filtroSetor) filtroSetor.value = '';
    if (filtroSub) filtroSub.value = '';
    if (filtroOrdenacao) filtroOrdenacao.value = '';

    popularFiltroSubcategorias();
}

// Zera os filtros do painel de histórico (categoria, subcategoria e data).
function limparFiltrosHistorico() {
    const filtroCat = document.getElementById('filtro-historico-categoria');
    const filtroSub = document.getElementById('filtro-historico-subcategoria');
    const filtroData = document.getElementById('filtro-historico-data');

    if (filtroCat) filtroCat.value = '';
    if (filtroSub) filtroSub.value = '';
    if (filtroData) filtroData.value = '';

    popularFiltroHistoricoSubcategorias();
}

function popularFiltroHistoricoCategorias() {
    const filtro = document.getElementById('filtro-historico-categoria');
    if (!filtro) return;

    const atual = filtro.value;
    filtro.innerHTML = '<option value="">CATEGORIA (TODAS)</option>';
    Object.keys(CONFIG.categorias).forEach(cat => {
        filtro.innerHTML += `<option value="${cat}">${cat.toUpperCase()}</option>`;
    });

    if (atual && CONFIG.categorias[atual]) {
        filtro.value = atual;
    }
}

function popularFiltroHistoricoSubcategorias() {
    const filtroCat = document.getElementById('filtro-historico-categoria');
    const filtroSub = document.getElementById('filtro-historico-subcategoria');
    if (!filtroSub) return;

    const categoriaSelecionada = filtroCat ? filtroCat.value : '';
    const atual = filtroSub.value;

    filtroSub.innerHTML = '<option value="">SUBCATEGORIA (TODAS)</option>';

    if (categoriaSelecionada && CONFIG.categorias[categoriaSelecionada]) {
        CONFIG.categorias[categoriaSelecionada].forEach(sub => {
            filtroSub.innerHTML += `<option value="${sub}">${sub.toUpperCase()}</option>`;
        });
        filtroSub.disabled = false;
    } else {
        filtroSub.disabled = true;
    }

    if (!filtroSub.disabled && atual) {
        filtroSub.value = atual;
    } else {
        filtroSub.value = '';
    }

    renderizarTudo();
}

async function carregarDados() {
    try {
        const res = await fetch('/api/chamados');
        if (respostaIndicaSessaoExpirada(res)) {
            window.location.href = '/login';
            return;
        }

        const contentType = (res.headers.get('content-type') || '').toLowerCase();
        if (!contentType.includes('application/json')) {
            const texto = await res.text();
            if ((res.url && res.url.includes('/login')) || /<html|<!doctype/i.test(texto)) {
                window.location.href = '/login';
                return;
            }
            throw new Error('API /api/chamados retornou formato inesperado');
        }

        if (!res.ok) {
            throw new Error('Falha ao carregar chamados: HTTP ' + res.status);
        }

        chamadosCache = await res.json();
        if (!Array.isArray(chamadosCache)) {
            throw new Error('Resposta invalida da API de chamados');
        }
        renderizarTudo();
        marcarChamadosComoVisualizados();
    } catch (e) {
        console.error(e);
        renderizarTudo();
        if (!Array.isArray(chamadosCache) || chamadosCache.length === 0) {
            exibirAvisoCarregamento('Nao foi possivel carregar os chamados. Recarregue a pagina e faca login novamente.');
            alert('Nao foi possivel carregar os chamados. Recarregue a pagina e faca login novamente.');
        }
    }
}

function renderizarTudo() {
    const filtroEl = document.getElementById('filtro-setor');
    const filtro = filtroEl ? filtroEl.value : '';
    const filtroSubEl = document.getElementById('filtro-subcategoria');
    const filtroSub = filtroSubEl ? filtroSubEl.value : '';
    const filtroOrdenacaoEl = document.getElementById('filtro-ordenacao');
    const filtroOrdenacao = filtroOrdenacaoEl ? filtroOrdenacaoEl.value : '';
    const filtroHistCatEl = document.getElementById('filtro-historico-categoria');
    const filtroHistCat = filtroHistCatEl ? filtroHistCatEl.value : '';
    const filtroHistSubEl = document.getElementById('filtro-historico-subcategoria');
    const filtroHistSub = filtroHistSubEl ? filtroHistSubEl.value : '';
    const filtroHistDataEl = document.getElementById('filtro-historico-data');
    const filtroHistData = filtroHistDataEl ? filtroHistDataEl.value : '';
    const triagem = document.getElementById('container-triagem');
    const doc = document.getElementById('container-documentados');
    const historico = document.getElementById('conteudo-historico');

    triagem.innerHTML = '';
    doc.innerHTML = '';
    if (historico) historico.innerHTML = '';

    // Ordenação (Crítica primeiro)
    const ordem = { critica: 1, alta: 2, media: 3, baixa: 4 };
    chamadosCache.sort((a, b) => ordem[a.prioridade] - ordem[b.prioridade]);

    // Fila de triagem: gravidade indicada pelo solicitante em cima e, dentro da
    // mesma gravidade, na ordem de abertura (mais antigo primeiro). O mesmo
    // critério monta o primeiro paint na rota /dashboard-ti.
    const pendentes = chamadosCache
        .filter(c => c.status === 'aberto')
        .sort((a, b) => (ordem[a.prioridade] || 3) - (ordem[b.prioridade] || 3)
            || new Date(a.criado_em) - new Date(b.criado_em));
    pendentes.forEach(c => triagem.innerHTML += cardTriagem(c));

    const countTriagem = document.getElementById('count-triagem');
    if (countTriagem) countTriagem.innerText = pendentes.length;

    let documentados = chamadosCache.filter(c => {
        if (c.status !== 'classificado') return false;

        const matchCategoria = !filtro || filtro === 'Todos' || c.categoria === filtro;
        const matchSubcategoria = !filtroSub || c.subcategoria === filtroSub;

        return matchCategoria && matchSubcategoria;
    });

    // Aplicar ordenação: se não há filtro de data, ordena por urgência
    if (!filtroOrdenacao) {
        // Por padrão: ordenar por urgência (prioridade)
        documentados.sort((a, b) => ordem[a.prioridade] - ordem[b.prioridade]);
    } else if (filtroOrdenacao === 'antigos') {
        // Mais antigos (ordem crescente)
        documentados.sort((a, b) => new Date(a.criado_em) - new Date(b.criado_em));
    } else {
        // Mais recentes (ordem decrescente)
        documentados.sort((a, b) => new Date(b.criado_em) - new Date(a.criado_em));
    }

    documentados.forEach(c => doc.innerHTML += cardDocumentado(c));

    const finalizados = chamadosCache
        .filter(c => c.status === 'resolvido')
        .filter(c => {
            const matchCat = !filtroHistCat || c.categoria === filtroHistCat;
            const matchSub = !filtroHistSub || c.subcategoria === filtroHistSub;
            const dataRef = c.atualizado_em || c.criado_em;
            const matchData = !filtroHistData || formatarDataISO(dataRef) === filtroHistData;
            return matchCat && matchSub && matchData;
        })
        .sort((a, b) => new Date(b.atualizado_em || b.criado_em) - new Date(a.atualizado_em || a.criado_em));
    const countFinalizados = document.getElementById('count-finalizados');
    if (countFinalizados) countFinalizados.innerText = finalizados.length;

    if (historico) {
        if (finalizados.length) {
            finalizados.forEach(c => historico.innerHTML += cardHistorico(c));
        } else {
            historico.innerHTML = '<p class="text-xs text-gray-600">Nenhum chamado finalizado ainda.</p>';
        }
    }
}

function cardTriagem(c) {
    const nome = c.usuario_nome || 'Usuário';
    const inicial = nome.charAt(0).toUpperCase();
    // Antes da triagem, c.prioridade é a pré-triagem feita pelo solicitante.
    const p = CONFIG.prioridades[c.prioridade] || CONFIG.prioridades['media'];
    // border-red-500 → border-l-red-500: o card mantém a borda cinza nos outros
    // lados, então só a lateral esquerda pode receber a cor da prioridade.
    const bordaEsquerda = p.border.replace('border-', 'border-l-');

    return `
            <div class="bg-gray-900 border border-gray-800 border-l-4 ${bordaEsquerda} p-5 rounded-2xl card-anim group">
                <div class="flex justify-between items-center mb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 bg-gray-800 rounded-lg flex items-center justify-center text-[10px] font-bold text-indigo-500 border border-gray-700">${inicial}</div>
                        <span class="text-[10px] text-gray-400 font-bold">${nome}</span>
                    </div>
                    <span class="text-[10px] text-gray-600 font-bold">${formatarDataCurta(c.criado_em)}</span>
                </div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="${p.color} text-[9px] font-black text-black px-2 py-0.5 rounded uppercase">${p.label}</span>
                    <span class="text-[9px] text-gray-600 font-bold uppercase tracking-wide">indicada pelo solicitante</span>
                </div>
                <h4 class="text-white font-bold text-sm mb-2 group-hover:text-indigo-400 transition-colors">#${c.id} - ${c.titulo}</h4>
                <p class="text-gray-500 text-xs line-clamp-2 mb-4 leading-relaxed">${c.descricao_rich}</p>
                <button onclick="abrirModalClassificar(${c.id})" class="w-full py-2.5 bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white text-xs font-black rounded-xl transition-all">CLASSIFICAR</button>
            </div>`;
}

function cardDocumentado(c) {
    const p = CONFIG.prioridades[c.prioridade] || CONFIG.prioridades['media'];
    const nome = c.usuario_nome || 'Usuário';
    const inicial = nome.charAt(0).toUpperCase();
    // Literal JS dentro de um atributo onclick='...': o JSON.stringify escapa as
    // aspas duplas, e a aspa simples vira entidade — senão um título com
    // apóstrofo (D'Ávila) fecharia o atributo e quebraria o botão.
    const tituloEscapado = JSON.stringify(c.titulo || '').replace(/'/g, '&#39;');

    return `
            <div onclick="abrirModalDetalhes(${c.id})" class="bg-gray-900 border-l-4 ${p.border} p-5 rounded-r-2xl shadow-xl card-anim cursor-pointer hover:bg-gray-800/80 transition flex flex-col h-full relative group">
                <div class="flex justify-between items-start mb-3">
                    <span class="${p.color} text-[9px] font-black text-black px-2 py-0.5 rounded uppercase">${p.label}</span>
                    <span class="text-[10px] text-indigo-400 font-bold uppercase">${c.categoria}</span>
                </div>
                <h4 class="text-white font-bold text-sm mb-1">#${c.id} - ${c.titulo}</h4>
                <div class="flex items-center justify-between gap-2 mb-3">
                    <p class="text-[10px] text-gray-500 font-medium">${c.subcategoria || 'Sem subcategoria'}</p>
                    <span class="text-[10px] text-gray-500 font-bold">${formatarDataCurta(c.criado_em)}</span>
                </div>
                
                <div class="flex items-center gap-2 pt-3 border-t border-gray-800 mt-auto mb-4">
                    <div class="w-6 h-6 bg-gray-800 rounded-lg flex items-center justify-center text-[10px] font-bold text-indigo-500 border border-gray-700">${inicial}</div>
                    <span class="text-[10px] text-gray-400">${nome}</span>
                </div>

                <div class="flex gap-2" onclick="event.stopPropagation()">
                    <button onclick='abrirModalComentarios(${c.id}, ${tituloEscapado}, true)' class="flex-1 bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white text-[10px] font-bold py-2 rounded-lg transition">COMENTÁRIOS</button>
                    <button onclick='chamarSetor(${c.usuario_id}, ${c.id}, ${tituloEscapado})' class="flex-1 bg-gray-800 hover:bg-indigo-600 text-gray-300 hover:text-white text-[10px] font-bold py-2 rounded-lg transition">CHAMAR SETOR</button>
                    <button onclick='finalizarChamado(${c.id}, ${tituloEscapado})' class="flex-1 bg-gray-800 hover:bg-green-600 text-gray-300 hover:text-white text-[10px] font-bold py-2 rounded-lg transition">FINALIZAR</button>
                </div>
            </div>`;
}

function cardHistorico(c) {
    const nome = c.usuario_nome || 'Usuário';
    return `
            <button onclick="abrirModalDetalhes(${c.id})" class="w-full text-left bg-gray-900 border border-gray-800 hover:border-green-500/30 p-3 rounded-xl transition">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="text-[10px] font-black uppercase text-green-500">Finalizado</span>
                    <span class="text-[10px] text-gray-500">${formatarDataCurta(c.atualizado_em || c.criado_em)}</span>
                </div>
                <p class="text-xs font-bold text-white truncate">#${c.id} - ${c.titulo}</p>
                <p class="text-[10px] text-gray-500 mt-1 truncate">Solicitante: ${nome}</p>
                <p class="text-[10px] text-gray-600 mt-1 truncate">Resolvido por: ${c.resolvido_por_nome || 'Nao informado'}</p>
            </button>`;
}

function parseDataServidorBrasilia(valorData) {
    if (!valorData) return null;

    if (typeof valorData === 'string') {
        const base = valorData.includes('T') ? valorData : valorData.replace(' ', 'T');
        const possuiTimezone = /Z|[+-]\d{2}:?\d{2}$/.test(base);
        const normalizada = possuiTimezone ? base : (base + '-03:00');
        const data = new Date(normalizada);
        if (!isNaN(data.getTime())) return data;
    }

    const fallback = new Date(valorData);
    return isNaN(fallback.getTime()) ? null : fallback;
}

function formatarDataISO(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    if (!data) return '';
    return data.toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
}

function formatarDataCurta(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    if (!data) return 'Nao informado';
    return data.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}

function togglePainelHistorico() {
    historicoMinimizado = !historicoMinimizado;

    const painel = document.getElementById('painel-historico');
    const headerInfo = document.getElementById('historico-header-info');
    const filtros = document.getElementById('filtros-historico');
    const conteudo = document.getElementById('conteudo-historico');
    const icone = document.getElementById('icone-historico');
    const botao = document.getElementById('btn-toggle-historico');

    if (!painel || !headerInfo || !conteudo || !icone || !botao || !filtros) return;

    if (historicoMinimizado) {
        painel.classList.remove('lg:w-80');
        painel.classList.add('lg:w-16');
        headerInfo.classList.add('hidden');
        filtros.classList.add('hidden');
        conteudo.classList.add('hidden');
        icone.classList.add('rotate-180');
        botao.title = 'Expandir histórico';
    } else {
        painel.classList.remove('lg:w-16');
        painel.classList.add('lg:w-80');
        headerInfo.classList.remove('hidden');
        filtros.classList.remove('hidden');
        conteudo.classList.remove('hidden');
        icone.classList.remove('rotate-180');
        botao.title = 'Minimizar histórico';
    }
}

function getAnexoUrl(path) {
    return `/uploads/${path}`;
}

function isAnexoImagem(anexo) {
    if (!anexo) return false;
    if (anexo.mime && anexo.mime.startsWith('image/')) return true;
    return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(anexo.nome || anexo.path || '');
}

async function carregarAnexosChamado(chamadoId) {
    if (anexosChamadosCache[chamadoId]) {
        return anexosChamadosCache[chamadoId];
    }

    try {
        const res = await fetch(`/api/chamados/${chamadoId}/anexos`);
        if (!res.ok) {
            anexosChamadosCache[chamadoId] = [];
            return [];
        }

        const anexos = await res.json();
        anexosChamadosCache[chamadoId] = Array.isArray(anexos) ? anexos : [];
        return anexosChamadosCache[chamadoId];
    } catch (_) {
        anexosChamadosCache[chamadoId] = [];
        return [];
    }
}

function renderizarListaAnexos(containerId, anexos) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!anexos || anexos.length === 0) {
        container.innerHTML = '';
        return;
    }

    container.innerHTML = anexos.map(anexo => {
        const path = anexo.arquivo_path || anexo.path || '';
        const nome = anexo.arquivo_nome || anexo.nome || 'anexo';
        const mime = anexo.mime_type || anexo.mime || '';
        const anexoNormalizado = { path, nome, mime };
        const url = getAnexoUrl(path);
        const visual = isAnexoImagem(anexoNormalizado)
            ? `<a href="${url}" target="_blank" class="block"><img src="${url}" alt="${nome}" class="w-full max-h-40 object-contain bg-black/30 border border-gray-800/50 rounded-xl p-2"></a>`
            : `<a href="${url}" target="_blank" class="block text-xs text-indigo-300 underline truncate">${nome}</a>`;

        return `
                    <div class="bg-black/30 border border-gray-800/50 rounded-xl p-3">
                        ${visual}
                        <div class="flex flex-wrap gap-2 mt-2">
                            <a href="${url}" target="_blank" class="inline-flex items-center gap-2 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-xs text-white font-bold rounded-lg transition">Visualizar</a>
                            <a href="${url}" target="_blank" download="${nome}" class="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-xs text-indigo-400 font-bold rounded-lg border border-gray-700 transition">Baixar</a>
                        </div>
                    </div>
                `;
    }).join('');
}

async function abrirModalClassificar(id) {
    const c = chamadosCache.find(x => x.id === id);
    if (!c) return;

    document.getElementById('classificar-id-input').value = c.id;
    document.getElementById('classificar-id-badge').innerText = "#" + c.id;
    document.getElementById('classificar-titulo').innerText = c.titulo;
    document.getElementById('classificar-descricao').innerHTML = c.descricao_rich;

    // O chamado chega na triagem com a gravidade que o solicitante indicou:
    // deixamos ela pré-selecionada para a TI apenas confirmar ou corrigir.
    const prioridadeSolicitada = CONFIG.prioridades[c.prioridade] ? c.prioridade : 'media';
    const selPrioridade = document.getElementById('sel-prioridade');
    if (selPrioridade) selPrioridade.value = prioridadeSolicitada;

    const avisoPrioridade = document.getElementById('classificar-prioridade-solicitada');
    if (avisoPrioridade) {
        const rotulo = (CONFIG.prioridades[prioridadeSolicitada] || {}).label || prioridadeSolicitada;
        // Só faz sentido chamar de "indicada pelo solicitante" antes da triagem;
        // depois disso a prioridade já é a que a própria TI definiu.
        avisoPrioridade.innerText = c.status === 'aberto'
            ? `Solicitante indicou: ${rotulo}`
            : `Prioridade atual: ${rotulo}`;
    }

    const anexoContainer = document.getElementById('classificar-anexo-container');
    let anexos = await carregarAnexosChamado(c.id);

    // Fallback para compatibilidade com dados antigos da listagem
    if ((!anexos || anexos.length === 0) && c.anexo_path) {
        anexos = [{
            arquivo_path: c.anexo_path,
            arquivo_nome: c.anexo_nome || 'anexo',
            mime_type: c.anexo_mime || ''
        }];
    }

    if (anexos && anexos.length > 0 && anexoContainer) {
        renderizarListaAnexos('classificar-anexos-lista', anexos);
        anexoContainer.classList.remove('hidden');
    } else {
        renderizarListaAnexos('classificar-anexos-lista', []);
        if (anexoContainer) anexoContainer.classList.add('hidden');
    }

    document.getElementById('modal-classificar').classList.remove('hidden');
}

async function abrirModalDetalhes(id) {
    const c = chamadosCache.find(x => x.id === id);
    if (!c) return;
    const p = CONFIG.prioridades[c.prioridade] || CONFIG.prioridades['media'];
    const tituloEscapado = c.titulo || '';

    document.getElementById('detalhes-id-badge').innerText = "#" + c.id;
    document.getElementById('detalhes-titulo').innerText = c.titulo;
    document.getElementById('detalhes-descricao').innerHTML = c.descricao_rich;
    const metaCategoria = document.getElementById('detalhes-meta-categoria');
    const metaSubcategoria = document.getElementById('detalhes-meta-subcategoria');
    const metaDataAbertura = document.getElementById('detalhes-meta-data-abertura');
    const metaDataFechamento = document.getElementById('detalhes-meta-data-fechamento');
    const metaSolicitante = document.getElementById('detalhes-meta-solicitante');

    if (metaCategoria) metaCategoria.innerText = `Categoria: ${c.categoria || 'Nao informada'}`;
    if (metaSubcategoria) metaSubcategoria.innerText = `Subcategoria: ${c.subcategoria || 'Nao informada'}`;
    if (metaDataAbertura) metaDataAbertura.innerText = `Abertura: ${formatarDataHora(c.criado_em)}`;
    if (metaDataFechamento) metaDataFechamento.innerText = `Fechamento: ${c.status === 'resolvido' ? formatarDataHora(c.atualizado_em || c.criado_em) : 'Em andamento'}`;
    if (metaSolicitante) metaSolicitante.innerText = `Solicitante: ${c.usuario_nome || 'Nao informado'}`;
    const resolvedByEl = document.getElementById('detalhes-resolvido-por');
    if (resolvedByEl) {
        resolvedByEl.innerText = c.resolvido_por_nome ? `Resolvido por: ${c.resolvido_por_nome}` : 'Resolvido por: Nao informado';
    }

    const badgePrioridade = document.getElementById('detalhes-prioridade');
    badgePrioridade.className = `${p.color} text-[10px] font-black text-black px-2 py-0.5 rounded uppercase`;
    badgePrioridade.innerText = p.label;

    const anexoContainer = document.getElementById('detalhes-anexo-container');
    let anexos = await carregarAnexosChamado(c.id);

    if ((!anexos || anexos.length === 0) && c.anexo_path) {
        anexos = [{
            arquivo_path: c.anexo_path,
            arquivo_nome: c.anexo_nome || 'anexo',
            mime_type: c.anexo_mime || ''
        }];
    }

    if (anexos && anexos.length > 0 && anexoContainer) {
        renderizarListaAnexos('detalhes-anexos-lista', anexos);
        anexoContainer.classList.remove('hidden');
    } else {
        renderizarListaAnexos('detalhes-anexos-lista', []);
        if (anexoContainer) anexoContainer.classList.add('hidden');
    }

    const btnChamar = document.getElementById('detalhes-btn-chamar');
    const btnFinalizar = document.getElementById('detalhes-btn-finalizar');
    const btnEditar = document.getElementById('detalhes-btn-editar');
    const btnComentarios = document.getElementById('detalhes-btn-comentarios');

    if (btnComentarios) {
        btnComentarios.onclick = () => abrirModalComentarios(c.id, tituloEscapado, c.status !== 'resolvido');
    }

    if (btnEditar) {
        btnEditar.onclick = () => {
            fecharModal('modal-detalhes');
            abrirModalClassificar(c.id);
        };
    }

    if (btnChamar) {
        btnChamar.onclick = () => chamarSetor(c.usuario_id, c.id, tituloEscapado);
    }

    if (btnFinalizar) {
        if (c.status === 'resolvido') {
            btnFinalizar.disabled = true;
            btnFinalizar.innerText = 'JÁ FINALIZADO';
            btnFinalizar.classList.remove('hover:bg-green-600');
            btnFinalizar.classList.add('opacity-60', 'cursor-not-allowed');
            btnFinalizar.onclick = null;
        } else {
            btnFinalizar.disabled = false;
            btnFinalizar.innerText = 'FINALIZAR';
            btnFinalizar.classList.add('hover:bg-green-600');
            btnFinalizar.classList.remove('opacity-60', 'cursor-not-allowed');
            btnFinalizar.onclick = () => finalizarChamado(c.id, tituloEscapado);
        }
    }

    document.getElementById('modal-detalhes').classList.remove('hidden');
}

function fecharModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

/**
 * Abre o chat com o solicitante já identificando o chamado de origem.
 *
 * Com chamado, quem grava a mensagem automática é o backend
 * (ChamadoController::chamarSetor) — assim o solicitante recebe o aviso mesmo
 * que o atendente não digite nada. Sem chamado, só abre a conversa.
 */
async function chamarSetor(usuarioId, chamadoId = 0, titulo = '') {
    if (!chamadoId) {
        window.location.href = `/chat?conversa_com=${usuarioId}`;
        return;
    }

    try {
        const res = await fetch(`/api/chamados/${chamadoId}/chamar-setor`, { method: 'POST' });

        if (respostaIndicaSessaoExpirada(res)) {
            window.location.href = '/login';
            return;
        }

        const data = await res.json();
        if (!res.ok || !data.conversa_id) {
            throw new Error(data.erro || 'Falha ao abrir a conversa');
        }

        window.location.href = `/chat?conversa=${data.conversa_id}`;
    } catch (e) {
        console.error(e);
        // O chat ainda resolve: abre a conversa com o solicitante mesmo assim.
        window.location.href = `/chat?conversa_com=${usuarioId}`;
    }
}

async function finalizarChamado(id, titulo) {
    const inputId = document.getElementById('finalizar-chamado-id');
    const inputTitulo = document.getElementById('finalizar-chamado-titulo');
    if (!inputId || !inputTitulo) return;

    inputId.value = String(id);
    inputTitulo.innerText = `#${id} - ${titulo}`;

    document.getElementById('modal-finalizar').classList.remove('hidden');
}

function formatarBytes(bytes) {
    const valor = Number(bytes || 0);
    if (!valor || valor < 1024) return `${valor || 0} B`;
    const unidades = ['KB', 'MB', 'GB'];
    let tamanho = valor / 1024;
    let indice = 0;
    while (tamanho >= 1024 && indice < unidades.length - 1) {
        tamanho /= 1024;
        indice += 1;
    }
    return `${tamanho.toFixed(tamanho >= 10 ? 0 : 1)} ${unidades[indice]}`;
}

function renderizarAnexosComentario(anexos) {
    if (!Array.isArray(anexos) || anexos.length === 0) {
        return '';
    }

    return `<div class="mt-3 flex flex-col gap-2">${anexos.map((anexo) => {
        const path = anexo.arquivo_path || '';
        const nome = anexo.arquivo_nome || 'anexo';
        const mime = anexo.mime_type || '';
        const tamanho = anexo.tamanho_bytes;
        const url = getAnexoUrl(path);
        const isImg = isAnexoImagem({ path, nome, mime });
        const preview = isImg
            ? `<a href="${url}" target="_blank" class="block"><img src="${url}" alt="${escapeHtml(nome)}" class="w-full max-h-52 object-contain bg-black/40 border border-gray-700 rounded-lg p-1"></a>`
            : `<a href="${url}" target="_blank" class="text-xs text-indigo-300 underline truncate">${escapeHtml(nome)}</a>`;

        return `<div class="bg-gray-900 border border-gray-700 rounded-lg p-2">${preview}<div class="text-[10px] text-gray-500 mt-1">${escapeHtml(nome)}${tamanho ? ` • ${formatarBytes(tamanho)}` : ''}</div></div>`;
    }).join('')}</div>`;
}

function atualizarModoComentarios(permiteEdicao) {
    comentariosModoEdicao = !!permiteEdicao;
    const form = document.getElementById('form-comentario');
    const helper = document.getElementById('comentarios-helper');
    if (form) form.classList.toggle('hidden', !comentariosModoEdicao);
    if (helper) {
        helper.textContent = comentariosModoEdicao
            ? 'Você pode adicionar e excluir comentários neste chamado.'
            : 'Comentários de chamados finalizados ficam apenas para visualização.';
    }
}

async function abrirModalComentarios(chamadoId, titulo = '', permiteEdicao = false) {
    const subtitulo = document.getElementById('comentarios-subtitulo');
    const chamadoIdInput = document.getElementById('comentario-chamado-id');
    const textoInput = document.getElementById('comentario-texto');
    const anexosInput = document.getElementById('comentario-anexos');

    if (!subtitulo || !chamadoIdInput || !textoInput || !anexosInput) return;

    comentariosChamadoAtualId = chamadoId;
    chamadoIdInput.value = String(chamadoId);
    subtitulo.innerText = `Chamado #${chamadoId}${titulo ? ` - ${titulo}` : ''}`;
    textoInput.value = '';
    if (anexosComentario) {
        anexosComentario.limpar();
    } else {
        anexosInput.value = '';
    }
    atualizarModoComentarios(permiteEdicao);

    await carregarComentariosChamado(chamadoId, true, permiteEdicao);
    document.getElementById('modal-comentarios').classList.remove('hidden');
}

async function carregarComentariosChamado(chamadoId, forcar = false, permiteEdicao = comentariosModoEdicao) {
    if (!forcar && comentariosChamadosCache[chamadoId]) {
        renderizarListaComentarios(comentariosChamadosCache[chamadoId], permiteEdicao, chamadoId);
        return comentariosChamadosCache[chamadoId];
    }

    const lista = document.getElementById('comentarios-lista');
    if (lista) {
        lista.innerHTML = '<p class="text-xs text-gray-500">Carregando comentários...</p>';
    }

    try {
        const res = await fetch(`/api/chamados/${chamadoId}/comentarios`);
        if (respostaIndicaSessaoExpirada(res)) {
            window.location.href = '/login';
            return [];
        }
        if (!res.ok) {
            throw new Error('Falha ao carregar comentários');
        }
        const comentarios = await res.json();
        comentariosChamadosCache[chamadoId] = Array.isArray(comentarios) ? comentarios : [];
        renderizarListaComentarios(comentariosChamadosCache[chamadoId], permiteEdicao, chamadoId);
        return comentariosChamadosCache[chamadoId];
    } catch (e) {
        console.error(e);
        comentariosChamadosCache[chamadoId] = [];
        renderizarListaComentarios([], permiteEdicao, chamadoId);
        return [];
    }
}

function renderizarListaComentarios(comentarios, permiteEdicao = false, chamadoId = comentariosChamadoAtualId) {
    const lista = document.getElementById('comentarios-lista');
    if (!lista) return;

    if (!Array.isArray(comentarios) || comentarios.length === 0) {
        lista.innerHTML = '<p class="text-xs text-gray-500">Sem comentários ainda.</p>';
        return;
    }

    lista.innerHTML = comentarios.map((item) => {
        const usuario = escapeHtml(item.usuario_nome || 'TI');
        const data = formatarDataHora(item.criado_em);
        const conteudo = (item.conteudo || '').trim();
        const conteudoHtml = conteudo
            ? `<p class="text-sm text-gray-200 whitespace-pre-wrap leading-relaxed">${escapeHtml(conteudo)}</p>`
            : '<p class="text-xs text-gray-500 italic">Comentário sem texto</p>';
        const tipo = item.tipo === 'resolucao'
            ? '<span class="text-[10px] font-bold uppercase bg-green-500/20 text-green-400 px-2 py-0.5 rounded">Resolução</span>'
            : '<span class="text-[10px] font-bold uppercase bg-indigo-500/20 text-indigo-300 px-2 py-0.5 rounded">Comentário</span>';
        const botaoExcluir = permiteEdicao
            ? `<button type="button" onclick="removerComentario(${chamadoId}, ${item.id})" class="text-[10px] font-bold uppercase text-red-400 hover:text-red-300">Excluir</button>`
            : '';

        return `<div class="bg-gray-800/60 border border-gray-700 rounded-xl p-3">
            <div class="flex items-center justify-between gap-2 mb-2">
                <div class="flex items-center gap-2">${tipo}<span class="text-xs text-gray-300 font-semibold">${usuario}</span></div>
                <div class="flex items-center gap-3">
                    <span class="text-[10px] text-gray-500">${escapeHtml(data)}</span>
                    ${botaoExcluir}
                </div>
            </div>
            ${conteudoHtml}
            ${renderizarAnexosComentario(item.anexos || [])}
        </div>`;
    }).join('');
}

async function carregarTaxonomias() {
    try {
        const res = await fetch('/api/chamados-taxonomias');
        const data = await res.json();
        if (data && data.categorias) {
            CONFIG.categorias = data.categorias;
        }
    } catch (e) {
        console.error('Erro ao carregar taxonomias:', e);
    }
}

function marcarChamadosComoVisualizados() {
    const maxCriado = chamadosCache
        .filter(c => c.status === 'aberto')
        .map(c => normalizarDataServidor(c.criado_em))
        .reduce((acc, curr) => Math.max(acc, curr), 0);

    if (maxCriado > 0) {
        localStorage.setItem('dashboard_last_seen_aberto', String(maxCriado));
    }
}

function normalizarDataServidor(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    return data ? data.getTime() : 0;
}

async function abrirModalTaxonomias() {
    await carregarListaTaxonomias();
    document.getElementById('modal-taxonomias').classList.remove('hidden');
}

async function carregarListaTaxonomias() {
    const lista = document.getElementById('lista-taxonomias');
    if (!lista) return;

    const res = await fetch('/api/chamados-taxonomias/detalhe');
    const itens = await res.json();
    lista.innerHTML = (itens || []).map(item => `
                <div class="flex items-center justify-between bg-gray-800/70 border border-gray-700 rounded-lg px-3 py-2">
                    <span class="text-sm text-gray-200">${item.categoria} / ${item.subcategoria}</span>
                    <button onclick="removerTaxonomia(${item.id})" class="text-xs text-red-400 hover:text-red-300">Remover</button>
                </div>
            `).join('') || '<p class="text-xs text-gray-500">Nenhuma taxonomia cadastrada.</p>';
}

async function removerTaxonomia(id) {
    if (!confirm('Deseja remover esta subcategoria?')) return;
    await fetch(`/api/chamados-taxonomias/${id}`, { method: 'DELETE' });
    await carregarTaxonomias();
    popularCategorias();
    popularFiltroCategorias();
    popularFiltroSubcategorias();
    popularFiltroHistoricoCategorias();
    popularFiltroHistoricoSubcategorias();
    await carregarListaTaxonomias();
}

const formTaxonomia = document.getElementById('form-taxonomia');
if (formTaxonomia) {
    formTaxonomia.onsubmit = async (e) => {
        e.preventDefault();
        const categoria = document.getElementById('taxonomia-categoria').value.trim();
        const subcategoria = document.getElementById('taxonomia-subcategoria').value.trim();
        if (!categoria || !subcategoria) return;

        await fetch('/api/chamados-taxonomias', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ categoria, subcategoria })
        });

        document.getElementById('taxonomia-categoria').value = '';
        document.getElementById('taxonomia-subcategoria').value = '';

        await carregarTaxonomias();
        popularCategorias();
        popularFiltroCategorias();
        popularFiltroSubcategorias();
        popularFiltroHistoricoCategorias();
        popularFiltroHistoricoSubcategorias();
        await carregarListaTaxonomias();
    };
}

const formClassificar = document.getElementById('form-classificar');
if (formClassificar) {
    formClassificar.onsubmit = async (e) => {
        e.preventDefault();
        const id = document.getElementById('classificar-id-input').value;
        const chamado = chamadosCache.find(x => String(x.id) === String(id));
        const endpoint = chamado && chamado.status === 'aberto'
            ? `/api/chamados/${id}/classificar`
            : `/api/chamados/${id}/classificacao`;

        const data = {
            prioridade: document.getElementById('sel-prioridade').value,
            categoria: document.getElementById('sel-categoria').value,
            subcategoria: document.getElementById('sel-subcategoria').value
        };

        const res = await fetch(endpoint, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        if (res.ok) {
            fecharModal('modal-classificar');
            await carregarDados();
        } else {
            alert('Erro ao salvar classificação.');
        }
    };
}

const formComentario = document.getElementById('form-comentario');
if (formComentario) {
    formComentario.onsubmit = async (e) => {
        e.preventDefault();

        const chamadoId = Number(document.getElementById('comentario-chamado-id').value || 0);
        const texto = document.getElementById('comentario-texto').value.trim();
        const inputAnexos = document.getElementById('comentario-anexos');
        const quantidadeAnexos = anexosComentario
            ? anexosComentario.quantidade()
            : (inputAnexos ? (inputAnexos.files || []).length : 0);

        if (!chamadoId) return;
        if (!texto && quantidadeAnexos === 0) {
            alert('Informe um comentário ou adicione um anexo.');
            return;
        }

        const formData = new FormData();
        formData.append('comentario', texto);
        formData.append('tipo', 'comentario');

        if (anexosComentario) {
            anexosComentario.anexarEm(formData, 'anexos[]');
        } else if (inputAnexos) {
            Array.from(inputAnexos.files || []).forEach((arquivo) => formData.append('anexos[]', arquivo));
        }

        const res = await fetch(`/api/chamados/${chamadoId}/comentarios`, {
            method: 'POST',
            body: formData,
        });

        if (respostaIndicaSessaoExpirada(res)) {
            window.location.href = '/login';
            return;
        }

        if (!res.ok) {
            alert('Erro ao salvar comentário.');
            return;
        }

        document.getElementById('comentario-texto').value = '';
        if (anexosComentario) {
            anexosComentario.limpar();
        } else if (inputAnexos) {
            inputAnexos.value = '';
        }
        await carregarComentariosChamado(chamadoId, true, true);
    };
}

async function removerComentario(chamadoId, comentarioId) {
    if (!chamadoId || !comentarioId) return;
    if (!confirm('Deseja excluir este comentário?')) return;

    const res = await fetch(`/api/chamados/${chamadoId}/comentarios/${comentarioId}`, {
        method: 'DELETE',
    });

    if (respostaIndicaSessaoExpirada(res)) {
        window.location.href = '/login';
        return;
    }

    if (!res.ok) {
        alert('Erro ao excluir comentário.');
        return;
    }

    delete comentariosChamadosCache[chamadoId];
    await carregarComentariosChamado(chamadoId, true, true);
}

const formFinalizar = document.getElementById('form-finalizar');
if (formFinalizar) {
    formFinalizar.onsubmit = async (e) => {
        e.preventDefault();

        const id = Number(document.getElementById('finalizar-chamado-id').value || 0);

        if (!id) return;

        try {
            const res = await fetch(`/api/chamados/${id}/finalizar`, { method: 'PATCH' });

            if (respostaIndicaSessaoExpirada(res)) {
                window.location.href = '/login';
                return;
            }

            let data = null;
            try {
                data = await res.json();
            } catch (_) {
                data = null;
            }

            if (!res.ok || !data || data.status !== 'success') {
                alert('Erro ao finalizar chamado no servidor. Verifique os logs do PHP.');
                return;
            }

            const nomeFinalizador = data.resolvido_por_nome || 'TI';
            fecharModal('modal-finalizar');
            fecharModal('modal-detalhes');
            alert(`Chamado finalizado com sucesso por ${nomeFinalizador}! A mensagem automática foi enviada no chat do usuário.`);

            delete comentariosChamadosCache[id];
            await carregarDados();
        } catch (erro) {
            console.error(erro);
            alert('Erro de conexão ao tentar finalizar o chamado.');
        }
    };
}
