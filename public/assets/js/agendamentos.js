const AG_BOOT = window.AGENDAMENTO_BOOTSTRAP || {};
const AG_USER_ID = Number(AG_BOOT.currentUserId || 0);
const AG_USER_NAME = String(AG_BOOT.currentUserName || '');
const AG_USER_PAPEL = String(AG_BOOT.userPapel || 'usuario');
const AG_MODO = String(AG_BOOT.mode || 'user');
const AG_EQUIP = ['admin', 'ti'].includes(AG_USER_PAPEL);

let agendamentosCache = [];
let servicosCache = [];
let mesAtual = new Date();
let diaSelecionado = null;
let agendamentoAtual = null;
let editandoServicoId = null;
let wsAgendamentos = null;

function parseDataServidorBrasilia(valorData) {
    if (!valorData) return null;
    if (typeof valorData === 'string') {
        const base = valorData.includes('T') ? valorData : valorData.replace(' ', 'T');
        const possuiTimezone = /Z|[+-]\d{2}:?\d{2}$/.test(base);
        const normalizada = possuiTimezone ? base : (base + '-03:00');
        const data = new Date(normalizada);
        if (!Number.isNaN(data.getTime())) return data;
    }
    const fallback = new Date(valorData);
    return Number.isNaN(fallback.getTime()) ? null : fallback;
}

function formatarDataAgendamento(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    if (!data) return 'Não informado';
    return data.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}

function formatarDataCurtaAgendamento(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    if (!data) return '';
    return data.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}

function formatarHoraAgendamento(valorData) {
    const data = parseDataServidorBrasilia(valorData);
    if (!data) return '';
    return data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
}

function dataParaLocalInput(data) {
    const pad = (n) => String(n).padStart(2, '0');
    return [
        data.getFullYear(),
        pad(data.getMonth() + 1),
        pad(data.getDate())
    ].join('-') + 'T' + [pad(data.getHours()), pad(data.getMinutes())].join(':');
}

function chaveDia(data) {
    if (!data) return '';
    const partes = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Sao_Paulo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(data);
    const valor = (tipo) => partes.find((item) => item.type === tipo)?.value || '00';
    return `${valor('year')}-${valor('month')}-${valor('day')}`;
}

function inicioDia(data) {
    return new Date(data.getFullYear(), data.getMonth(), data.getDate(), 0, 0, 0, 0);
}

function fimDia(data) {
    return new Date(data.getFullYear(), data.getMonth(), data.getDate(), 23, 59, 59, 999);
}

function agendamentoCobreDia(item, data) {
    const inicio = parseDataServidorBrasilia(item.data_inicio);
    const fim = parseDataServidorBrasilia(item.data_fim || item.data_inicio);
    if (!inicio || !fim) return false;

    return inicioDia(data) <= fimDia(fim) && fimDia(data) >= inicioDia(inicio);
}

function agendamentosDoDia(data) {
    return agendamentosCache.filter((item) => agendamentoCobreDia(item, data));
}

function intervaloFaixaClass(item, data) {
    const inicio = parseDataServidorBrasilia(item.data_inicio);
    const fim = parseDataServidorBrasilia(item.data_fim || item.data_inicio);
    if (!inicio || !fim) return 'rounded-full';

    const chaveAtual = chaveDia(data);
    const chaveInicio = chaveDia(inicio);
    const chaveFim = chaveDia(fim);

    if (chaveInicio === chaveFim) {
        return 'rounded-full';
    }
    if (chaveAtual === chaveInicio) {
        return 'rounded-l-full rounded-r-none';
    }
    if (chaveAtual === chaveFim) {
        return 'rounded-r-full rounded-l-none';
    }
    return 'rounded-none';
}

function diasVisiveisDoEvento(item, inicioMesVisivel, fimMesVisivel) {
    const inicio = parseDataServidorBrasilia(item.data_inicio);
    const fim = parseDataServidorBrasilia(item.data_fim || item.data_inicio);
    if (!inicio || !fim) return [];

    const dias = [];
    let cursor = inicioDia(inicio > inicioMesVisivel ? inicio : inicioMesVisivel);
    const ultimoDia = fimDia(fim < fimMesVisivel ? fim : fimMesVisivel);

    while (cursor <= ultimoDia) {
        if (cursor >= inicioMesVisivel && cursor <= fimMesVisivel) {
            dias.push(new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate(), 12, 0, 0, 0));
        }
        cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1, 12, 0, 0, 0);
    }

    return dias;
}

function renderizarFaixasCalendario(container, itens, inicioVisivel, fimVisivel) {
    const antigas = container.querySelector('.calendar-overlay-layer');
    if (antigas) antigas.remove();

    const overlay = document.createElement('div');
    overlay.className = 'calendar-overlay-layer';
    overlay.style.position = 'absolute';
    overlay.style.inset = '0';
    overlay.style.pointerEvents = 'none';
    overlay.style.zIndex = '5';

    const celulas = new Map();
    container.querySelectorAll('[data-date]').forEach((el) => {
        celulas.set(el.getAttribute('data-date'), el.getBoundingClientRect());
    });

    const rectContainer = container.getBoundingClientRect();
    const lanePorLinha = new Map();

    itens.forEach((item) => {
        const dias = diasVisiveisDoEvento(item, inicioVisivel, fimVisivel);
        if (!dias.length) return;

        const agrupadoPorLinha = new Map();
        dias.forEach((dia) => {
            const chave = chaveDia(dia);
            const cellRect = celulas.get(chave);
            if (!cellRect) return;
            const linha = Math.round((cellRect.top - rectContainer.top) / cellRect.height);
            if (!agrupadoPorLinha.has(linha)) {
                agrupadoPorLinha.set(linha, []);
            }
            agrupadoPorLinha.get(linha).push({ chave, rect: cellRect, data: dia });
        });

        agrupadoPorLinha.forEach((lista, linha) => {
            lista.sort((a, b) => a.rect.left - b.rect.left);
            const laneAtual = lanePorLinha.get(linha) || 0;
            lanePorLinha.set(linha, laneAtual + 1);

            const primeiro = lista[0].rect;
            const ultimo = lista[lista.length - 1].rect;
            const faixa = document.createElement('div');
            faixa.className = 'agenda-range-band';
            faixa.style.position = 'absolute';
            faixa.style.left = `${primeiro.left - rectContainer.left + 6}px`;
            faixa.style.width = `${(ultimo.right - primeiro.left) - 12}px`;
            faixa.style.top = `${primeiro.top - rectContainer.top + 26 + (laneAtual * 28)}px`;
            faixa.style.height = '22px';
            faixa.style.backgroundColor = item.cor_hex || '#4f46e5';
            faixa.style.border = '1px solid rgba(255,255,255,0.22)';
            faixa.style.boxShadow = '0 8px 18px rgba(0,0,0,0.18)';
            faixa.style.borderRadius = '9999px';
            faixa.style.overflow = 'hidden';

            if (lista.length > 1) {
                faixa.style.borderTopLeftRadius = '9999px';
                faixa.style.borderBottomLeftRadius = '9999px';
                faixa.style.borderTopRightRadius = '9999px';
                faixa.style.borderBottomRightRadius = '9999px';
            }

            const label = document.createElement('div');
            label.style.position = 'absolute';
            label.style.inset = '0';
            label.style.display = 'flex';
            label.style.alignItems = 'center';
            label.style.padding = '0 10px';
            label.style.color = '#ffffff';
            label.style.fontSize = '10px';
            label.style.fontWeight = '700';
            label.style.whiteSpace = 'nowrap';
            label.style.overflow = 'hidden';
            label.style.textOverflow = 'ellipsis';
            label.textContent = item.servico_nome;
            faixa.appendChild(label);

            overlay.appendChild(faixa);
        });
    });

    container.appendChild(overlay);
}

function inicioMes(data) {
    return new Date(data.getFullYear(), data.getMonth(), 1, 0, 0, 0, 0);
}

function fimMes(data) {
    return new Date(data.getFullYear(), data.getMonth() + 1, 0, 23, 59, 59, 999);
}

function nomeMes(data) {
    return data.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}

function statusLabel(status) {
    return (window.APP_CONFIG && window.APP_CONFIG.agendamentoStatus && window.APP_CONFIG.agendamentoStatus[status]) || status;
}

function statusClasses(status) {
    const base = 'text-xs font-black uppercase tracking-widest px-2 py-0.5 rounded agenda-pill';
    if (status === 'solicitado') return base + ' bg-amber-600';
    if (status === 'agendado') return base + ' bg-green-600';
    if (status === 'cancelado') return base + ' bg-red-600';
    return base + ' bg-indigo-600';
}

async function carregarServicos() {
    const url = AG_EQUIP ? '/api/servicos-agendamento?incluir_inativos=1' : '/api/servicos-agendamento';
    const res = await fetch(url);
    const data = await res.json();
    servicosCache = Array.isArray(data) ? data : [];
    popularSelectServicos();
    renderizarServicosAdmin();
}

function popularSelectServicos() {
    const select = document.getElementById('solicitacao-servico');
    if (!select) return;
    select.innerHTML = servicosCache
        .filter((servico) => AG_EQUIP || Number(servico.ativo || 0) === 1)
        .map((servico) => `<option value="${servico.id}">${escapeHtml(servico.nome)}${Number(servico.ativo || 0) === 0 ? ' (inativo)' : ''}</option>`)
        .join('');
}

async function carregarAgendamentos() {
    const inicio = inicioMes(mesAtual);
    inicio.setMonth(inicio.getMonth() - 1);
    const fim = fimMes(mesAtual);
    fim.setMonth(fim.getMonth() + 1);

    const params = new URLSearchParams({
        inicio: dataParaBanco(inicio),
        fim: dataParaBanco(fim),
    });

    const res = await fetch('/api/agendamentos?' + params.toString());
    const data = await res.json();
    agendamentosCache = Array.isArray(data) ? data : [];
    renderizarCalendario();
    renderizarSidebarStatus();
    renderizarAdminQueues();
}

function prepararAgenda() {
    const mesRef = document.getElementById('agenda-mes-rotulo');
    if (mesRef) mesRef.textContent = nomeMes(mesAtual);

    const btnAnterior = document.getElementById('btn-mes-anterior');
    const btnProximo = document.getElementById('btn-mes-proximo');
    const btnHoje = document.getElementById('btn-mes-hoje');
    const btnSolicitarDia = document.getElementById('btn-abrir-solicitacao-dia');

    if (btnAnterior) btnAnterior.onclick = async () => { mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth() - 1, 1); await carregarAgendamentos(); };
    if (btnProximo) btnProximo.onclick = async () => { mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth() + 1, 1); await carregarAgendamentos(); };
    if (btnHoje) btnHoje.onclick = async () => { mesAtual = new Date(); await carregarAgendamentos(); };
    if (btnSolicitarDia) btnSolicitarDia.onclick = () => abrirModalSolicitacao(diaSelecionado);

    const formServico = document.getElementById('form-servico');
    if (formServico) {
        formServico.addEventListener('submit', async (event) => {
            event.preventDefault();
            await salvarServico();
        });
    }

    const btnCancelar = document.getElementById('btn-detalhe-cancelar');
    const btnAprovar = document.getElementById('btn-detalhe-aprovar');
    const btnRecusar = document.getElementById('btn-detalhe-recusar');
    const btnEncerrar = document.getElementById('btn-detalhe-encerrar');
    if (btnCancelar) btnCancelar.onclick = async () => { if (agendamentoAtual) await alterarStatus(agendamentoAtual.id, 'cancelar'); };
    if (btnAprovar) btnAprovar.onclick = async () => { if (agendamentoAtual) await alterarStatus(agendamentoAtual.id, 'aprovar'); };
    if (btnRecusar) btnRecusar.onclick = async () => {
        if (!agendamentoAtual) return;
        const motivo = prompt('Informe o motivo da recusa:') || '';
        if (!motivo.trim()) return;
        await alterarStatus(agendamentoAtual.id, 'recusar', { motivo });
    };
    if (btnEncerrar) btnEncerrar.onclick = async () => { if (agendamentoAtual) await alterarStatus(agendamentoAtual.id, 'encerrar'); };
}

function renderizarCalendario() {
    const container = document.getElementById('calendario-agendamentos');
    const titulo = document.getElementById('agenda-mes-rotulo');
    if (!container) return;

    container.style.position = 'relative';
    if (titulo) titulo.textContent = nomeMes(mesAtual);

    const dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const inicio = inicioMes(mesAtual);
    const fim = fimMes(mesAtual);
    const primeiroDiaSemana = inicio.getDay();
    const totalDias = fim.getDate();

    const partes = dias.map((dia) => `<div class="text-[11px] uppercase tracking-widest text-gray-500 font-black px-2 py-1 text-center">${dia}</div>`);

    for (let i = 0; i < primeiroDiaSemana; i += 1) {
        partes.push('<div class="h-24 rounded-2xl border border-transparent"></div>');
    }

    for (let dia = 1; dia <= totalDias; dia += 1) {
        const data = new Date(mesAtual.getFullYear(), mesAtual.getMonth(), dia, 12, 0, 0, 0);
        const chave = chaveDia(data);
        const itens = agendamentosDoDia(data);
        const isSelected = diaSelecionado === chave;

        partes.push(`
            <button type="button" data-date="${chave}" class="calendar-day min-h-32 rounded-2xl border ${isSelected ? 'is-selected bg-indigo-600 border-indigo-500 text-white' : 'bg-gray-800 border-gray-700 text-gray-100'} ${itens.length ? 'has-items' : ''} p-3 text-left hover:border-indigo-500 transition flex flex-col justify-between">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-bold">${dia}</span>
                    ${itens.length ? `<span class="text-[10px] font-black px-2 py-0.5 rounded-full bg-white/10">${itens.length}</span>` : ''}
                </div>
                <div class="space-y-1 overflow-hidden mt-2">
                    ${itens.slice(0, 4).map((item) => `
                        <div class="relative h-6 overflow-hidden ${intervaloFaixaClass(item, data)}" style="background-color:${escapeHtml(item.cor_hex || '#4f46e5')}">
                            <div class="absolute inset-0 flex items-center gap-2 px-2 text-[10px] font-semibold text-white whitespace-nowrap overflow-hidden">
                                <span class="w-2 h-2 rounded-full bg-white/80 shrink-0"></span>
                                <span class="truncate">${escapeHtml(item.servico_nome)}</span>
                            </div>
                        </div>
                    `).join('')}
                    ${itens.length > 4 ? `<div class="text-[10px] text-gray-400 font-semibold">+${itens.length - 4} serviços</div>` : ''}
                </div>
            </button>
        `);
    }

    container.innerHTML = partes.join('');
    container.querySelectorAll('[data-date]').forEach((el) => {
        el.addEventListener('click', () => abrirDia(el.getAttribute('data-date')));
    });

    renderizarFaixasCalendario(container, agendamentosCache, inicioMes(mesAtual), fimMes(mesAtual));
}

function renderizarSidebarStatus() {
    const grupos = { solicitado: [], agendado: [], cancelado: [], encerrado: [] };
    agendamentosCache.forEach((item) => {
        if (grupos[item.status]) grupos[item.status].push(item);
    });

    Object.keys(grupos).forEach((status) => {
        const countEl = document.getElementById('count-status-' + status);
        const listEl = document.getElementById('lista-status-' + status);
        if (countEl) countEl.textContent = String(grupos[status].length);
        if (!listEl) return;

        if (!grupos[status].length) {
            listEl.innerHTML = '<p class="text-xs text-gray-600">Nenhum serviço nesta categoria.</p>';
            return;
        }

        listEl.innerHTML = grupos[status].slice(0, 5).map((item) => cardAgendamentoCompacto(item)).join('');
        listEl.querySelectorAll('[data-agendamento-id]').forEach((el) => {
            el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id'))));
        });
    });
}

function renderizarAdminQueues() {
    if (!AG_EQUIP) return;
    const pendentes = agendamentosCache.filter((item) => item.status === 'solicitado');
    const abertos = agendamentosCache.filter((item) => item.status === 'agendado');
    const arquivo = agendamentosCache.filter((item) => item.status === 'encerrado');

    const pendentesCount = document.getElementById('count-pendentes');
    const abertosCount = document.getElementById('count-abertos');
    const arquivoCount = document.getElementById('count-arquivo');
    const listaPendentes = document.getElementById('lista-pendentes');
    const listaAbertos = document.getElementById('lista-abertos');
    const listaArquivo = document.getElementById('lista-arquivo');
    if (pendentesCount) pendentesCount.textContent = String(pendentes.length);
    if (abertosCount) abertosCount.textContent = String(abertos.length);
    if (arquivoCount) arquivoCount.textContent = String(arquivo.length);
    if (listaPendentes) {
        listaPendentes.innerHTML = pendentes.length ? pendentes.map((item) => cardFila(item)).join('') : '<p class="text-xs text-gray-600">Sem solicitações pendentes.</p>';
        listaPendentes.querySelectorAll('[data-agendamento-id]').forEach((el) => el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id')))));
    }
    if (listaAbertos) {
        listaAbertos.innerHTML = abertos.length ? abertos.map((item) => cardFila(item)).join('') : '<p class="text-xs text-gray-600">Nenhum agendamento aberto.</p>';
        listaAbertos.querySelectorAll('[data-agendamento-id]').forEach((el) => el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id')))));
    }
    if (listaArquivo) {
        listaArquivo.innerHTML = arquivo.length ? arquivo.slice(0, 20).map((item) => cardFila(item)).join('') : '<p class="text-xs text-gray-600">Nenhum item no arquivo.</p>';
        listaArquivo.querySelectorAll('[data-agendamento-id]').forEach((el) => el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id')))));
    }
}

function renderizarServicosAdmin() {
    const lista = document.getElementById('lista-servicos');
    const count = document.getElementById('count-servicos');
    if (!lista) return;

    if (count) count.textContent = String(servicosCache.length);

    if (!servicosCache.length) {
        lista.innerHTML = '<p class="text-xs text-gray-600">Nenhum serviço cadastrado.</p>';
        return;
    }

    lista.innerHTML = servicosCache.map((servico) => `
        <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3 flex items-start justify-between gap-3">
            <button type="button" class="text-left flex-1" data-servico-id="${servico.id}">
                <p class="font-semibold text-white">${escapeHtml(servico.nome)}</p>
                <p class="text-xs text-gray-400 mt-1">${escapeHtml(servico.descricao || 'Sem descrição')}</p>
                <div class="flex items-center gap-2 mt-2 text-[10px] text-gray-500">
                    <span class="px-2 py-0.5 rounded-full ${Number(servico.ativo || 0) ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}">${Number(servico.ativo || 0) ? 'Ativo' : 'Inativo'}</span>
                </div>
            </button>
            <div class="flex flex-col gap-2 shrink-0">
                <button type="button" onclick="editarServico(${servico.id})" class="text-xs text-indigo-300 hover:text-indigo-200 px-2 py-1 rounded-lg hover:bg-indigo-500/10">Editar</button>
                <button type="button" onclick="desativarServico(${servico.id})" class="text-xs text-red-300 hover:text-red-200 px-2 py-1 rounded-lg hover:bg-red-500/10">Desativar</button>
            </div>
        </div>
    `).join('');

    lista.querySelectorAll('[data-servico-id]').forEach((el) => {
        el.addEventListener('click', () => editarServico(Number(el.getAttribute('data-servico-id'))));
    });
}

function cardAgendamentoCompacto(item) {
    return `
        <button type="button" data-agendamento-id="${item.id}" class="w-full text-left bg-gray-800/70 border border-gray-700 rounded-2xl p-3 hover:border-indigo-500 transition">
            <div class="flex items-center justify-between gap-2 mb-1">
                <span class="text-xs font-semibold text-white truncate">${escapeHtml(item.servico_nome)}</span>
                <span class="text-[10px] ${statusClasses(item.status)}">${escapeHtml(statusLabel(item.status))}</span>
            </div>
            <p class="text-[11px] text-gray-400 truncate">${escapeHtml(item.solicitante_nome || 'Usuário')}</p>
            <p class="text-[10px] text-gray-500 mt-1">${escapeHtml(formatarHoraAgendamento(item.data_inicio))}</p>
        </button>
    `;
}

function cardFila(item) {
    return `
        <button type="button" data-agendamento-id="${item.id}" class="w-full text-left bg-gray-800/70 border border-gray-700 rounded-2xl p-3 hover:border-indigo-500 transition">
            <div class="flex items-center justify-between gap-2 mb-1">
                <span class="text-xs font-semibold text-white truncate">${escapeHtml(item.servico_nome)}</span>
                <span class="text-[10px] ${statusClasses(item.status)}">${escapeHtml(statusLabel(item.status))}</span>
            </div>
            <p class="text-[11px] text-gray-400 truncate">${escapeHtml(item.solicitante_nome || 'Usuário')}</p>
            <p class="text-[10px] text-gray-500 mt-1">${escapeHtml(formatarDataAgendamento(item.data_inicio))}</p>
        </button>
    `;
}

function abrirDia(dataIso) {
    diaSelecionado = dataIso;
    const data = parseDataServidorBrasilia(dataIso + 'T12:00:00');
    const titulo = document.getElementById('modal-dia-titulo');
    const conteudo = document.getElementById('lista-agendamentos-dia') || document.getElementById('modal-dia-conteudo');
    const rótulo = document.getElementById('dia-selecionado-rotulo');

    if (titulo && data) titulo.textContent = data.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', timeZone: 'America/Sao_Paulo' });
    if (rótulo && data) rótulo.textContent = data.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric', timeZone: 'America/Sao_Paulo' });

    const itens = agendamentosDoDia(data || new Date());
    if (!conteudo) return;

    if (!itens.length) {
        conteudo.innerHTML = '<p class="text-sm text-gray-500">Nenhum serviço agendado para este dia.</p>';
    } else {
        conteudo.innerHTML = itens.map((item) => `
            <button type="button" data-agendamento-id="${item.id}" class="w-full bg-gray-800/70 border border-gray-700 rounded-2xl p-4 text-left hover:border-indigo-500 transition flex flex-col gap-2">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-white">${escapeHtml(item.servico_nome)}</p>
                        <p class="text-xs text-gray-400">${escapeHtml(item.solicitante_nome || 'Usuário')}</p>
                    </div>
                    <span class="text-[10px] ${statusClasses(item.status)}">${escapeHtml(statusLabel(item.status))}</span>
                </div>
                <div class="text-xs text-gray-400 flex items-center gap-2">
                    <span>${escapeHtml(formatarHoraAgendamento(item.data_inicio))}</span>
                    <span>•</span>
                    <span>${escapeHtml(formatarHoraAgendamento(item.data_fim))}</span>
                </div>
            </button>
        `).join('');
        conteudo.querySelectorAll('[data-agendamento-id]').forEach((el) => el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id')))));
    }

    abrirModal('modal-dia');
}

async function abrirDetalhe(id) {
    const res = await fetch('/api/agendamentos/' + id);
    const data = await res.json();
    if (!res.ok) {
        alert(data.erro || 'Não foi possível carregar o agendamento');
        return;
    }

    agendamentoAtual = data;
    const nome = document.getElementById('detalhe-servico-nome');
    const solicitante = document.getElementById('detalhe-solicitante');
    const status = document.getElementById('detalhe-status');
    const inicio = document.getElementById('detalhe-inicio');
    const fim = document.getElementById('detalhe-fim');
    const obs = document.getElementById('detalhe-observacoes');

    if (nome) nome.textContent = data.servico_nome || 'Agendamento';
    if (solicitante) solicitante.textContent = data.solicitante_nome + (data.solicitante_email ? ' • ' + data.solicitante_email : '');
    if (status) status.innerHTML = `<span class="${statusClasses(data.status)}">${statusLabel(data.status)}</span>`;
    if (inicio) inicio.textContent = formatarDataAgendamento(data.data_inicio);
    if (fim) fim.textContent = formatarDataAgendamento(data.data_fim);
    if (obs) obs.textContent = data.observacoes || 'Sem observações';

    configurarAcoesDetalhe(data);
    abrirModal('modal-detalhe');
}

function configurarAcoesDetalhe(data) {
    const btnCancelar = document.getElementById('btn-detalhe-cancelar');
    const btnAprovar = document.getElementById('btn-detalhe-aprovar');
    const btnRecusar = document.getElementById('btn-detalhe-recusar');
    const btnEncerrar = document.getElementById('btn-detalhe-encerrar');

    [btnCancelar, btnAprovar, btnRecusar, btnEncerrar].forEach((btn) => btn && btn.classList.add('hidden'));

    const podeCancelar = Number(data.solicitante_id || 0) === AG_USER_ID || AG_EQUIP;
    if (btnCancelar && podeCancelar && data.status !== 'encerrado') btnCancelar.classList.remove('hidden');
    if (!AG_EQUIP) return;

    if (btnAprovar && data.status === 'solicitado') btnAprovar.classList.remove('hidden');
    if (btnRecusar && data.status === 'solicitado') btnRecusar.classList.remove('hidden');
    if (btnEncerrar && data.status === 'agendado') btnEncerrar.classList.remove('hidden');
}

function abrirModalSolicitacao(dataIso) {
    const modal = document.getElementById('modal-solicitacao');
    const inputData = document.getElementById('solicitacao-data-inicio');
    if (inputData) {
        const dataBase = dataIso ? parseDataServidorBrasilia(dataIso + 'T09:00:00') : new Date();
        inputData.value = dataToLocalValue(dataBase);
    }
    const inputFim = document.getElementById('solicitacao-data-fim');
    if (inputFim) {
        inputFim.value = '';
    }
    abrirModal('modal-solicitacao');
}

function dataToLocalValue(data) {
    const pad = (n) => String(n).padStart(2, '0');
    return data.getFullYear() + '-' + pad(data.getMonth() + 1) + '-' + pad(data.getDate()) + 'T' + pad(data.getHours()) + ':' + pad(data.getMinutes());
}

function dataParaBanco(data) {
    const pad = (n) => String(n).padStart(2, '0');
    return data.getFullYear() + '-' + pad(data.getMonth() + 1) + '-' + pad(data.getDate()) + ' ' + pad(data.getHours()) + ':' + pad(data.getMinutes()) + ':00';
}

async function enviarSolicitacao() {
    const servicoId = Number(document.getElementById('solicitacao-servico')?.value || 0);
    const dataInicio = document.getElementById('solicitacao-data-inicio')?.value || '';
    const dataFim = document.getElementById('solicitacao-data-fim')?.value || '';
    const observacoes = document.getElementById('solicitacao-observacoes')?.value || '';

    const res = await fetch('/api/agendamentos', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ servico_id: String(servicoId), data_inicio: dataInicio, data_fim: dataFim, observacoes })
    });
    const data = await res.json();
    if (!res.ok) {
        if (res.status === 409) {
            alert(data.erro || 'Horário em conflito com outro agendamento');
            return;
        }
        alert(data.erro || 'Não foi possível solicitar o serviço');
        return;
    }

    fecharModal('modal-solicitacao');
    await carregarAgendamentos();
    if (diaSelecionado) abrirDia(diaSelecionado);
}

async function alterarStatus(id, acao, payload = {}) {
    const rotas = {
        aprovar: '/api/agendamentos/' + id + '/aprovar',
        recusar: '/api/agendamentos/' + id + '/recusar',
        cancelar: '/api/agendamentos/' + id + '/cancelar',
        encerrar: '/api/agendamentos/' + id + '/encerrar',
    };
    const res = await fetch(rotas[acao], {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload)
    });
    const data = await res.json();
    if (!res.ok) {
        alert(data.erro || 'Não foi possível atualizar o agendamento');
        return;
    }

    agendamentoAtual = data;
    await carregarAgendamentos();
    if (diaSelecionado) abrirDia(diaSelecionado);
    if (data.id) {
        await abrirDetalhe(data.id);
    }
}

function abrirModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('hidden');
}

function fecharModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('hidden');
}

function abrirModalServico() {
    limparFormularioServico();
    const form = document.getElementById('form-servico');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function limparFormularioServico() {
    editandoServicoId = null;
    const id = document.getElementById('servico-id');
    const nome = document.getElementById('servico-nome');
    const descricao = document.getElementById('servico-descricao');
    const cor = document.getElementById('servico-cor');
    const ativo = document.getElementById('servico-ativo');
    if (id) id.value = '';
    if (nome) nome.value = '';
    if (descricao) descricao.value = '';
    if (cor) cor.value = '#4f46e5';
    if (ativo) ativo.checked = true;
}

async function editarServico(id) {
    const servico = servicosCache.find((item) => Number(item.id) === Number(id));
    if (!servico) return;
    editandoServicoId = servico.id;
    const idInput = document.getElementById('servico-id');
    const nome = document.getElementById('servico-nome');
    const descricao = document.getElementById('servico-descricao');
    const cor = document.getElementById('servico-cor');
    const ativo = document.getElementById('servico-ativo');
    if (idInput) idInput.value = servico.id;
    if (nome) nome.value = servico.nome || '';
    if (descricao) descricao.value = servico.descricao || '';
    if (cor) cor.value = servico.cor_hex || '#4f46e5';
    if (ativo) ativo.checked = Number(servico.ativo || 0) === 1;
}

async function salvarServico() {
    const payload = {
        nome: document.getElementById('servico-nome')?.value.trim() || '',
        descricao: document.getElementById('servico-descricao')?.value.trim() || '',
        cor_hex: document.getElementById('servico-cor')?.value.trim() || '#4f46e5',
        ativo: document.getElementById('servico-ativo')?.checked ? 1 : 0,
    };

    const url = editandoServicoId ? '/api/servicos-agendamento/' + editandoServicoId : '/api/servicos-agendamento';
    const method = editandoServicoId ? 'PATCH' : 'POST';

    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload)
    });
    const data = await res.json();
    if (!res.ok) {
        alert(data.erro || 'Não foi possível salvar o serviço');
        return;
    }

    limparFormularioServico();
    await carregarServicos();
    await carregarAgendamentos();
}

async function desativarServico(id) {
    if (!confirm('Desativar este serviço?')) return;
    const res = await fetch('/api/servicos-agendamento/' + id, { method: 'DELETE' });
    const data = await res.json();
    if (!res.ok) {
        alert(data.erro || 'Não foi possível desativar');
        return;
    }

    await carregarServicos();
}

document.addEventListener('DOMContentLoaded', async () => {
    prepararAgenda();
    conectarWSAgendamentos();
    document.addEventListener('click', (event) => {
        if (event.target && event.target.matches('[data-close-modal]')) {
            fecharModal(event.target.getAttribute('data-close-modal'));
        }
    });
    await carregarServicos();
    await carregarAgendamentos();
    setInterval(carregarAgendamentos, 20000);
});

function conectarWSAgendamentos() {
    try {
        const host = window.location.hostname;
        wsAgendamentos = new WebSocket('ws://' + host + ':8080');

        wsAgendamentos.onopen = function () {
            wsAgendamentos.send(JSON.stringify({
                type: 'auth',
                user_id: AG_USER_ID,
                user_nome: AG_USER_NAME,
                user_papel: AG_USER_PAPEL,
                conversa_id: 0,
            }));
        };

        wsAgendamentos.onmessage = function (event) {
            const data = JSON.parse(event.data);
            if (data.type === 'schedule_updated') {
                carregarServicos().catch(() => { });
                carregarAgendamentos().catch(() => { });
                if (agendamentoAtual && Number(data.agendamento?.id || 0) === Number(agendamentoAtual.id || 0)) {
                    abrirDetalhe(Number(agendamentoAtual.id));
                }
            }
        };

        wsAgendamentos.onclose = function () {
            setTimeout(conectarWSAgendamentos, 3000);
        };
    } catch (error) {
        console.error('Falha ao conectar WS de agendamentos', error);
    }
}