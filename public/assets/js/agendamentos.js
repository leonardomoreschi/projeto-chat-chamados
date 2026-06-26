// ── Bootstrap ──────────────────────────────────────────────────────────────
const AG_BOOT = window.AGENDAMENTO_BOOTSTRAP || {};
const AG_USER_ID = Number(AG_BOOT.currentUserId || 0);
const AG_USER_NAME = String(AG_BOOT.currentUserName || '');
const AG_USER_PAPEL = String(AG_BOOT.userPapel || 'usuario');
const AG_MODO = String(AG_BOOT.mode || 'user');
const AG_EQUIP = ['admin', 'ti'].includes(AG_USER_PAPEL);

// ── Estado ─────────────────────────────────────────────────────────────────
let agendamentosCache = [];
let servicosCache = [];
let dataAtual = new Date();
let viewMode = 'month'; // 'month' | 'week' | 'day'
let diaSelecionado = null;
let agendamentoAtual = null;
let editandoServicoId = null;
let wsAgendamentos = null;
let tabAtiva = AG_MODO === 'admin' ? 'kanban' : 'calendario';

const HORA_INICIO = 7;
const HORA_FIM = 21;
const PX_HORA = 64;

// ── Utilitários de data ─────────────────────────────────────────────────────
function parseDataServidorBrasilia(v) {
    if (!v) return null;
    const s = String(v);
    const base = s.includes('T') ? s : s.replace(' ', 'T');
    const comTZ = /Z|[+-]\d{2}:?\d{2}$/.test(base) ? base : base + '-03:00';
    const d = new Date(comTZ);
    return isNaN(d.getTime()) ? null : d;
}

function formatarDataAgendamento(v) {
    const d = parseDataServidorBrasilia(v);
    return d ? d.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' }) : 'Não informado';
}

function formatarDataCurtaAgendamento(v) {
    const d = parseDataServidorBrasilia(v);
    return d ? d.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }) : '';
}

function formatarHoraAgendamento(v) {
    const d = parseDataServidorBrasilia(v);
    return d ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }) : '';
}

function chaveDia(data) {
    if (!data) return '';
    const p = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(data);
    const v = t => p.find(x => x.type === t)?.value || '00';
    return `${v('year')}-${v('month')}-${v('day')}`;
}

function inicioDia(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0, 0); }
function fimDia(d)    { return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59, 999); }
function inicioMes(d) { return new Date(d.getFullYear(), d.getMonth(), 1, 0, 0, 0, 0); }
function fimMes(d)    { return new Date(d.getFullYear(), d.getMonth() + 1, 0, 23, 59, 59, 999); }

function inicioDaSemana(d) {
    const r = new Date(d);
    r.setDate(d.getDate() - d.getDay());
    r.setHours(0, 0, 0, 0);
    return r;
}
function fimDaSemana(d) {
    const r = inicioDaSemana(d);
    r.setDate(r.getDate() + 6);
    r.setHours(23, 59, 59, 999);
    return r;
}
function addDias(d, n) { const r = new Date(d); r.setDate(r.getDate() + n); return r; }

function nomeMes(d) { return d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }); }

function agendamentoCobreDia(item, data) {
    const ini = parseDataServidorBrasilia(item.data_inicio);
    const fim = parseDataServidorBrasilia(item.data_fim || item.data_inicio);
    return ini && fim && inicioDia(data) <= fim && fimDia(data) >= ini;
}

function ehMultiDia(item) {
    const ini = parseDataServidorBrasilia(item.data_inicio);
    const fim = parseDataServidorBrasilia(item.data_fim || item.data_inicio);
    return ini && fim && chaveDia(ini) !== chaveDia(fim);
}

function dataParaBanco(data) {
    const p = n => String(n).padStart(2, '0');
    return `${data.getFullYear()}-${p(data.getMonth()+1)}-${p(data.getDate())} ${p(data.getHours())}:${p(data.getMinutes())}:00`;
}

function dataToLocalValue(d) {
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function statusLabel(s) {
    return window.APP_CONFIG?.agendamentoStatus?.[s] || s;
}

function statusClasses(s) {
    const b = 'text-xs font-black uppercase tracking-widest px-2 py-0.5 rounded agenda-pill';
    if (s === 'solicitado')   return b + ' bg-amber-600';
    if (s === 'agendado')     return b + ' bg-green-600';
    if (s === 'em_avaliacao') return b + ' bg-purple-600';
    if (s === 'cancelado')    return b + ' bg-red-600';
    return b + ' bg-indigo-600';
}

// ── Range da API por modo de visão ──────────────────────────────────────────
function rangeVisivel() {
    if (tabAtiva === 'kanban' || tabAtiva === 'meus-agendamentos') {
        const now = new Date();
        return {
            inicio: new Date(now.getFullYear(), now.getMonth() - 3, 1, 0, 0, 0),
            fim:    new Date(now.getFullYear(), now.getMonth() + 6, 0, 23, 59, 59),
        };
    }
    if (viewMode === 'week') {
        const ini = inicioDaSemana(dataAtual);
        ini.setDate(ini.getDate() - 7);
        const fim = fimDaSemana(dataAtual);
        fim.setDate(fim.getDate() + 7);
        return { inicio: ini, fim };
    }
    if (viewMode === 'day') {
        return { inicio: inicioDia(dataAtual), fim: fimDia(dataAtual) };
    }
    const ini = inicioMes(dataAtual);
    ini.setMonth(ini.getMonth() - 1);
    const fim = fimMes(dataAtual);
    fim.setMonth(fim.getMonth() + 1);
    return { inicio: ini, fim };
}

// ── API ─────────────────────────────────────────────────────────────────────
async function carregarServicos() {
    await _carregarServicosUmaVez();
}

async function carregarAgendamentos() {
    const { inicio, fim } = rangeVisivel();
    const params = new URLSearchParams({ inicio: dataParaBanco(inicio), fim: dataParaBanco(fim) });
    const res = await fetch('/api/agendamentos?' + params);
    const data = await res.json();
    agendamentosCache = Array.isArray(data) ? data : [];
    renderizarCalendario();
    renderizarSidebarStatus();
    renderizarAdminQueues();
    renderizarKanban();
}

function popularSelectServicos() {
    const sel = document.getElementById('solicitacao-servico');
    if (!sel) return;
    sel.innerHTML = servicosCache
        .filter(s => AG_EQUIP || Number(s.ativo) === 1)
        .map(s => `<option value="${s.id}">${escapeHtml(s.nome)}${Number(s.ativo) === 0 ? ' (inativo)' : ''}</option>`)
        .join('');
}

// ── Tabs ────────────────────────────────────────────────────────────────────
function _aplicarTabUI(id) {
    document.querySelectorAll('[data-tab]').forEach(btn => {
        const ativo = btn.dataset.tab === id;
        if (ativo) {
            btn.classList.add('tab-btn-ativo');
            btn.classList.remove('tab-btn-inativo');
        } else {
            btn.classList.remove('tab-btn-ativo');
            btn.classList.add('tab-btn-inativo');
        }
    });
    document.querySelectorAll('[data-tab-content]').forEach(el => {
        if (el.dataset.tabContent !== id) {
            el.style.display = 'none';
        } else {
            // Kanban tabs need display:flex so columns fill height correctly
            const isFlex = el.dataset.tabContent === 'kanban' || el.dataset.tabContent === 'meus-agendamentos';
            el.style.display = isFlex ? 'flex' : '';
        }
    });
}

async function trocarTab(id) {
    tabAtiva = id;
    _aplicarTabUI(id);
    await carregarAgendamentos();
}

// ── Navegação ───────────────────────────────────────────────────────────────
function atualizarRotulo() {
    const el = document.getElementById('agenda-mes-rotulo');
    if (!el) return;
    if (viewMode === 'month') { el.textContent = nomeMes(dataAtual); return; }
    if (viewMode === 'week') {
        const ini = inicioDaSemana(dataAtual);
        const fim = fimDaSemana(dataAtual);
        el.textContent = `${ini.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })} – ${fim.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })}`;
        return;
    }
    el.textContent = dataAtual.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
}

async function navAnterior() {
    if (viewMode === 'month') dataAtual = new Date(dataAtual.getFullYear(), dataAtual.getMonth() - 1, 1);
    else if (viewMode === 'week') dataAtual = addDias(dataAtual, -7);
    else dataAtual = addDias(dataAtual, -1);
    await carregarAgendamentos();
}

async function navProximo() {
    if (viewMode === 'month') dataAtual = new Date(dataAtual.getFullYear(), dataAtual.getMonth() + 1, 1);
    else if (viewMode === 'week') dataAtual = addDias(dataAtual, 7);
    else dataAtual = addDias(dataAtual, 1);
    await carregarAgendamentos();
}

async function navHoje() {
    dataAtual = new Date();
    await carregarAgendamentos();
}

async function mudarView(modo) {
    viewMode = modo;
    ['month', 'week', 'day'].forEach(m => {
        const btn = document.getElementById('btn-view-' + m);
        if (!btn) return;
        const ativo = m === modo;
        btn.classList.toggle('bg-indigo-600', ativo);
        btn.classList.toggle('text-white', ativo);
        btn.classList.toggle('bg-gray-800', !ativo);
        btn.classList.toggle('text-gray-300', !ativo);
        btn.classList.toggle('border-gray-700', !ativo);
    });
    await carregarAgendamentos();
}

// ── Dispatch de render ──────────────────────────────────────────────────────
function renderizarCalendario() {
    atualizarRotulo();
    if (viewMode === 'week') { renderizarSemanal(); return; }
    if (viewMode === 'day')  { renderizarDiario(); return; }
    renderizarMensal();
}

// ── Visão Mensal ────────────────────────────────────────────────────────────
function renderizarMensal() {
    const container = document.getElementById('calendario-agendamentos');
    if (!container) return;
    container.style.cssText = 'position:relative;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:0.5rem;';

    const nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const ini = inicioMes(dataAtual);
    const primeiroDow = ini.getDay();
    const totalDias = fimMes(dataAtual).getDate();
    const hoje = chaveDia(new Date());

    const partes = nomesDias.map(d =>
        `<div class="text-[11px] uppercase tracking-widest text-gray-500 font-black px-2 py-1 text-center">${d}</div>`
    );

    for (let i = 0; i < primeiroDow; i++) {
        partes.push('<div class="rounded-2xl border border-transparent" style="min-height:7rem;"></div>');
    }

    for (let dia = 1; dia <= totalDias; dia++) {
        const data = new Date(dataAtual.getFullYear(), dataAtual.getMonth(), dia, 12, 0, 0);
        const chave = chaveDia(data);
        const itens = agendamentosCache.filter(item => !['encerrado', 'em_avaliacao'].includes(item.status) && agendamentoCobreDia(item, data));
        const sel = diaSelecionado === chave;
        const isHoje = chave === hoje;

        partes.push(`
            <button type="button" data-date="${chave}" class="calendar-day rounded-2xl border ${sel ? 'is-selected bg-indigo-600 border-indigo-500 text-white' : isHoje ? 'bg-gray-800 border-indigo-500/60 text-gray-100' : 'bg-gray-800 border-gray-700 text-gray-100'} ${itens.length ? 'has-items' : ''} p-3 text-left hover:border-indigo-500 transition flex flex-col" style="min-height:7rem;">
                <div class="flex items-center justify-between gap-1 mb-1">
                    <span class="text-sm font-bold ${isHoje && !sel ? 'text-indigo-400' : ''}">${dia}</span>
                    ${itens.length ? `<span class="text-[10px] font-black px-1.5 py-0.5 rounded-full bg-white/10">${itens.length}</span>` : ''}
                </div>
                <div class="space-y-0.5 overflow-hidden flex-1">
                    ${itens.slice(0, 3).map(item => `
                        <div class="h-4 rounded-full overflow-hidden flex items-center px-2 gap-1" style="background:${escapeHtml(item.cor_hex || '#4f46e5')}">
                            <span class="text-[9px] font-bold text-white truncate leading-none">${escapeHtml(item.servico_nome)}</span>
                        </div>
                    `).join('')}
                    ${itens.length > 3 ? `<div class="text-[9px] text-gray-400 font-semibold pl-1">+${itens.length - 3} mais</div>` : ''}
                </div>
            </button>
        `);
    }

    container.innerHTML = partes.join('');
    container.querySelectorAll('[data-date]').forEach(el =>
        el.addEventListener('click', () => abrirDia(el.getAttribute('data-date')))
    );
}

// ── Layout de eventos sobrepostos ───────────────────────────────────────────
function calcularLayoutEventos(eventos) {
    if (!eventos.length) return [];

    const sorted = [...eventos].sort((a, b) => {
        const d = parseDataServidorBrasilia(a.data_inicio) - parseDataServidorBrasilia(b.data_inicio);
        if (d !== 0) return d;
        const aD = parseDataServidorBrasilia(a.data_fim) - parseDataServidorBrasilia(a.data_inicio);
        const bD = parseDataServidorBrasilia(b.data_fim) - parseDataServidorBrasilia(b.data_inicio);
        return bD - aD;
    });

    const colEndTimes = [];
    const assigned = sorted.map(ag => {
        const ini = parseDataServidorBrasilia(ag.data_inicio);
        const fim = parseDataServidorBrasilia(ag.data_fim || ag.data_inicio);
        let col = colEndTimes.findIndex(end => end <= ini);
        if (col === -1) { col = colEndTimes.length; }
        colEndTimes[col] = fim;
        return { ag, col, ini, fim };
    });

    return assigned.map(entry => {
        let maxCol = entry.col;
        assigned.forEach(other => {
            if (other.ini < entry.fim && other.fim > entry.ini) {
                maxCol = Math.max(maxCol, other.col);
            }
        });
        return { ag: entry.ag, col: entry.col, totalCols: maxCol + 1 };
    });
}

// ── Visão Semanal ───────────────────────────────────────────────────────────
function renderizarSemanal() {
    const container = document.getElementById('calendario-agendamentos');
    if (!container) return;
    container.style.cssText = '';

    const semIni = inicioDaSemana(dataAtual);
    const dias = Array.from({ length: 7 }, (_, i) => addDias(semIni, i));
    const hoje = chaveDia(new Date());
    const totalH = (HORA_FIM - HORA_INICIO) * PX_HORA;
    const gutter = '52px';
    const bgLinhas = `repeating-linear-gradient(to bottom,transparent 0px,transparent ${PX_HORA - 1}px,#1f2937 ${PX_HORA - 1}px,#1f2937 ${PX_HORA}px)`;

    const agsDaSemana = agendamentosCache.filter(ag => !['encerrado', 'em_avaliacao'].includes(ag.status) && dias.some(d => agendamentoCobreDia(ag, d)));
    const allDayAgs = agsDaSemana.filter(ehMultiDia);
    const timedAgs  = agsDaSemana.filter(ag => !ehMultiDia(ag));

    const headerCols = dias.map(dia => {
        const chave = chaveDia(dia);
        const isHoje = chave === hoje;
        const abrev = dia.toLocaleDateString('pt-BR', { weekday: 'short' }).replace('.', '');
        return `<div style="flex:1;border-left:1px solid #1f2937;text-align:center;padding:8px 4px 6px;">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:${isHoje ? '#818cf8' : '#6b7280'};">${escapeHtml(abrev)}</div>
            <div style="font-size:20px;font-weight:900;line-height:1.1;color:${isHoje ? '#fff' : '#d1d5db'};">
                ${isHoje ? `<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#4f46e5;color:#fff;">${dia.getDate()}</span>` : dia.getDate()}
            </div>
        </div>`;
    }).join('');

    const allDayCols = dias.map((dia, idx) => {
        const evsAqui = allDayAgs.filter(ag => agendamentoCobreDia(ag, dia));
        if (!evsAqui.length) return `<div style="flex:1;border-left:1px solid #1f2937;min-height:28px;padding:3px 2px;" data-date="${chaveDia(dia)}"></div>`;
        const html = evsAqui.map(ag => {
            const iniChave = chaveDia(parseDataServidorBrasilia(ag.data_inicio));
            const fimChave = chaveDia(parseDataServidorBrasilia(ag.data_fim || ag.data_inicio));
            const isFirst = iniChave === chaveDia(dia) || idx === 0;
            const isLast  = fimChave === chaveDia(dia) || idx === 6;
            const br = `${isFirst ? '9999px' : '0'} ${isLast ? '9999px' : '0'} ${isLast ? '9999px' : '0'} ${isFirst ? '9999px' : '0'}`;
            return `<button data-agendamento-id="${ag.id}" style="display:block;width:100%;height:18px;border-radius:${br};background:${ag.cor_hex || '#4f46e5'};border:none;padding:0 ${isFirst ? 6 : 0}px;color:#fff;font-size:9px;font-weight:700;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;cursor:pointer;">${isFirst ? escapeHtml(ag.servico_nome) : ''}</button>`;
        }).join('');
        return `<div style="flex:1;border-left:1px solid #1f2937;min-height:28px;padding:3px 2px;" data-date="${chaveDia(dia)}">${html}</div>`;
    }).join('');

    const eventCols = dias.map(dia => {
        const evsAqui = timedAgs.filter(ag => agendamentoCobreDia(ag, dia));
        const chave   = chaveDia(dia);
        const layout  = calcularLayoutEventos(evsAqui);
        const evHtml  = layout.map(({ ag, col, totalCols }) => {
            const ini = parseDataServidorBrasilia(ag.data_inicio);
            const fim = parseDataServidorBrasilia(ag.data_fim || ag.data_inicio);
            if (!ini || !fim) return '';
            const iniH   = ini.getHours() + ini.getMinutes() / 60;
            const fimH   = fim.getHours() + fim.getMinutes() / 60;
            const top    = Math.max(0, (iniH - HORA_INICIO) * PX_HORA);
            const height = Math.max(18, (fimH - iniH) * PX_HORA);
            const small  = height < 34;
            const lPct   = (col / totalCols * 100).toFixed(2);
            const wPct   = (100 / totalCols).toFixed(2);
            return `<button data-agendamento-id="${ag.id}" style="position:absolute;top:${top}px;height:${height}px;left:calc(${lPct}% + 2px);width:calc(${wPct}% - 4px);background:${ag.cor_hex || '#4f46e5'};border-radius:5px;overflow:hidden;z-index:2;cursor:pointer;border:none;text-align:left;box-shadow:0 1px 4px rgba(0,0,0,.3);">
                <div style="padding:${small ? '1px' : '3px'} 5px;color:#fff;height:100%;display:flex;flex-direction:column;justify-content:flex-start;gap:1px;overflow:hidden;">
                    <div style="font-size:${small ? 9 : 10}px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2;">${escapeHtml(ag.servico_nome)}</div>
                    ${!small ? `<div style="font-size:9px;opacity:.85;white-space:nowrap;overflow:hidden;">${formatarHoraAgendamento(ag.data_inicio)}–${formatarHoraAgendamento(ag.data_fim)}</div>` : ''}
                </div>
            </button>`;
        }).join('');
        return `<div style="flex:1;border-left:1px solid #1f2937;height:${totalH}px;position:relative;background-image:${bgLinhas};cursor:pointer;" data-date="${chave}">${evHtml}</div>`;
    }).join('');

    let horaLabels = '';
    for (let h = HORA_INICIO; h <= HORA_FIM; h++) {
        horaLabels += `<div style="height:${PX_HORA}px;padding-right:8px;text-align:right;font-size:10px;color:#4b5563;line-height:1;padding-top:3px;">${String(h).padStart(2,'0')}:00</div>`;
    }

    container.innerHTML = `
        <div style="min-width:480px;display:flex;flex-direction:column;overflow:hidden;">
            <div style="display:flex;background:#111827;border-bottom:2px solid #374151;">
                <div style="width:${gutter};flex-shrink:0;"></div>
                ${headerCols}
            </div>
            <div style="display:flex;background:#0f172a;border-bottom:1px solid #374151;">
                <div style="width:${gutter};flex-shrink:0;font-size:9px;color:#4b5563;text-align:right;padding-right:6px;padding-top:6px;">dia int.</div>
                ${allDayCols}
            </div>
            <div style="overflow-y:auto;max-height:520px;overflow-x:hidden;">
                <div style="display:flex;">
                    <div style="width:${gutter};flex-shrink:0;">${horaLabels}</div>
                    <div style="flex:1;display:flex;min-width:0;">${eventCols}</div>
                </div>
            </div>
        </div>`;

    container.querySelectorAll('[data-agendamento-id]').forEach(el =>
        el.addEventListener('click', e => { e.stopPropagation(); abrirDetalhe(Number(el.getAttribute('data-agendamento-id'))); })
    );
    container.querySelectorAll('[data-date]').forEach(el =>
        el.addEventListener('click', () => {
            const chave = el.getAttribute('data-date');
            if (!chave) return;
            diaSelecionado = chave;
            const lblEl = document.getElementById('dia-selecionado-rotulo');
            if (lblEl) {
                const d = parseDataServidorBrasilia(chave + 'T12:00:00');
                if (d) lblEl.textContent = d.toLocaleDateString('pt-BR', { day:'2-digit', month:'long', year:'numeric' });
            }
        })
    );
}

// ── Visão Diária ────────────────────────────────────────────────────────────
function renderizarDiario() {
    const container = document.getElementById('calendario-agendamentos');
    if (!container) return;
    container.style.cssText = '';

    const chave = chaveDia(dataAtual);
    const agsDia  = agendamentosCache.filter(ag => !['encerrado', 'em_avaliacao'].includes(ag.status) && agendamentoCobreDia(ag, dataAtual));
    const timedAgs   = agsDia.filter(ag => !ehMultiDia(ag));
    const allDayAgs  = agsDia.filter(ehMultiDia);
    const totalH  = (HORA_FIM - HORA_INICIO) * PX_HORA;
    const bgLinhas = `repeating-linear-gradient(to bottom,transparent 0px,transparent ${PX_HORA - 1}px,#1f2937 ${PX_HORA - 1}px,#1f2937 ${PX_HORA}px)`;

    let horaLabels = '';
    for (let h = HORA_INICIO; h <= HORA_FIM; h++) {
        horaLabels += `<div style="height:${PX_HORA}px;padding-right:8px;text-align:right;font-size:10px;color:#4b5563;line-height:1;padding-top:3px;">${String(h).padStart(2,'0')}:00</div>`;
    }

    const layoutDia = calcularLayoutEventos(timedAgs);
    const evHtml = layoutDia.map(({ ag, col, totalCols }) => {
        const ini = parseDataServidorBrasilia(ag.data_inicio);
        const fim = parseDataServidorBrasilia(ag.data_fim || ag.data_inicio);
        if (!ini || !fim) return '';
        const iniH   = ini.getHours() + ini.getMinutes() / 60;
        const fimH   = fim.getHours() + fim.getMinutes() / 60;
        const top    = Math.max(0, (iniH - HORA_INICIO) * PX_HORA);
        const height = Math.max(24, (fimH - iniH) * PX_HORA);
        const lPct   = (col / totalCols * 100).toFixed(2);
        const wPct   = (100 / totalCols).toFixed(2);
        return `<button data-agendamento-id="${ag.id}" style="position:absolute;top:${top}px;height:${height}px;left:calc(${lPct}% + 4px);width:calc(${wPct}% - 8px);background:${ag.cor_hex || '#4f46e5'};border-radius:8px;overflow:hidden;z-index:2;cursor:pointer;border:none;text-align:left;box-shadow:0 2px 8px rgba(0,0,0,.3);">
            <div style="padding:6px 10px;color:#fff;height:100%;display:flex;flex-direction:column;gap:2px;overflow:hidden;">
                <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(ag.servico_nome)}</div>
                <div style="font-size:10px;opacity:.9;">${formatarHoraAgendamento(ag.data_inicio)} – ${formatarHoraAgendamento(ag.data_fim)}</div>
                <div style="font-size:10px;opacity:.75;">${escapeHtml(ag.solicitante_nome || '')}</div>
                <div style="margin-top:2px;"><span class="${statusClasses(ag.status)}">${statusLabel(ag.status)}</span></div>
            </div>
        </button>`;
    }).join('');

    const allDayHtml = allDayAgs.map(ag => `
        <button data-agendamento-id="${ag.id}" class="w-full text-left rounded-xl overflow-hidden cursor-pointer border-none mb-2" style="background:${ag.cor_hex || '#4f46e5'};">
            <div style="padding:5px 10px;color:#fff;">
                <div style="font-size:11px;font-weight:700;">${escapeHtml(ag.servico_nome)}</div>
                <div style="font-size:10px;opacity:.85;">${formatarDataCurtaAgendamento(ag.data_inicio)} – ${formatarDataCurtaAgendamento(ag.data_fim)}</div>
            </div>
        </button>
    `).join('');

    container.innerHTML = `
        <div style="display:flex;flex-direction:column;min-width:220px;">
            ${allDayAgs.length ? `
                <div style="padding:8px 8px 4px 60px;background:#0f172a;border-bottom:1px solid #1f2937;">
                    <div style="font-size:9px;color:#4b5563;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px;">Dia inteiro</div>
                    ${allDayHtml}
                </div>` : ''}
            <div style="overflow-y:auto;max-height:580px;">
                <div style="display:flex;">
                    <div style="width:52px;flex-shrink:0;">${horaLabels}</div>
                    <div style="flex:1;position:relative;border-left:1px solid #374151;height:${totalH}px;background-image:${bgLinhas};cursor:pointer;" data-date="${chave}">
                        ${evHtml}
                        ${!timedAgs.length && !allDayAgs.length ? `
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#4b5563;">
                                <div style="font-size:14px;">Nenhum agendamento neste dia</div>
                                <button onclick="abrirModalSolicitacao('${chave}')" style="padding:8px 18px;background:#4f46e5;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">Solicitar serviço</button>
                            </div>` : ''}
                    </div>
                </div>
            </div>
        </div>`;

    container.querySelectorAll('[data-agendamento-id]').forEach(el =>
        el.addEventListener('click', e => { e.stopPropagation(); abrirDetalhe(Number(el.getAttribute('data-agendamento-id'))); })
    );
    container.querySelectorAll('[data-date]').forEach(el =>
        el.addEventListener('click', () => {
            const d = el.getAttribute('data-date');
            if (d) { diaSelecionado = d; abrirModalSolicitacao(d); }
        })
    );
}

// ── Sidebar legado (mantido para compatibilidade) ───────────────────────────
function renderizarSidebarStatus() {
    const grupos = { solicitado: [], agendado: [], em_avaliacao: [], cancelado: [], encerrado: [] };
    agendamentosCache.forEach(item => { if (grupos[item.status]) grupos[item.status].push(item); });

    Object.keys(grupos).forEach(status => {
        const countEl = document.getElementById('count-status-' + status);
        const listEl  = document.getElementById('lista-status-' + status);
        if (countEl) countEl.textContent = String(grupos[status].length);
        if (!listEl) return;
        if (!grupos[status].length) { listEl.innerHTML = '<p class="text-xs text-gray-600">Nenhum.</p>'; return; }
        listEl.innerHTML = grupos[status].slice(0, 5).map(cardAgendamentoCompacto).join('');
        listEl.querySelectorAll('[data-agendamento-id]').forEach(el =>
            el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id'))))
        );
    });
}

function renderizarAdminQueues() {
    if (!AG_EQUIP) return;
    const filas = [
        ['pendentes',  agendamentosCache.filter(i => i.status === 'solicitado')],
        ['abertos',    agendamentosCache.filter(i => i.status === 'agendado')],
        ['avaliacao',  agendamentosCache.filter(i => i.status === 'em_avaliacao')],
        ['arquivo',    agendamentosCache.filter(i => i.status === 'encerrado')],
    ];
    filas.forEach(([key, items]) => {
        const countEl = document.getElementById('count-' + key);
        const listEl  = document.getElementById('lista-' + key);
        if (countEl) countEl.textContent = String(items.length);
        if (!listEl) return;
        listEl.innerHTML = items.length
            ? items.slice(0, 20).map(cardFila).join('')
            : '<p class="text-xs text-gray-600">Nenhum item.</p>';
        listEl.querySelectorAll('[data-agendamento-id]').forEach(el =>
            el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id'))))
        );
    });
}

// ── Kanban ───────────────────────────────────────────────────────────────────
const KANBAN_COLUNAS = [
    { key: 'solicitado',   label: 'Solicitado',   cor: '#b45309', corBg: '#451a03' },
    { key: 'agendado',     label: 'Confirmado',   cor: '#15803d', corBg: '#052e16' },
    { key: 'em_avaliacao', label: 'Em Avaliação', cor: '#6d28d9', corBg: '#2e1065' },
    { key: 'cancelado',    label: 'Cancelado',    cor: '#b91c1c', corBg: '#450a0a' },
    { key: 'encerrado',    label: 'Encerrado',    cor: '#334155', corBg: '#0f172a' },
];

function renderizarKanban() {
    const board = document.getElementById('kanban-board');
    if (!board) return;

    board.innerHTML = KANBAN_COLUNAS.map(col => {
        const itens = agendamentosCache.filter(i => i.status === col.key);
        const podeAdicionar = col.key === 'solicitado';

        const headerAdd = podeAdicionar
            ? `<button onclick="abrirModalSolicitacao()" title="Nova solicitação" style="width:24px;height:24px;border-radius:6px;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:18px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">+</button>`
            : '';

        const cards = itens.length
            ? itens.map(item => cardKanban(item)).join('')
            : `<div style="padding:24px 12px;text-align:center;">
                <p style="font-size:12px;color:#374151;">Nenhum item</p>
               </div>`;

        return `<div style="flex-shrink:0;width:276px;display:flex;flex-direction:column;border-radius:14px;overflow:hidden;border:1px solid rgba(255,255,255,.06);">
            <div style="padding:11px 14px;background:${col.cor};display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:11px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.1em;">${col.label}</span>
                    <span style="font-size:10px;font-weight:700;color:rgba(255,255,255,.85);background:rgba(0,0,0,.28);padding:1px 7px;border-radius:999px;">${itens.length}</span>
                </div>
                ${headerAdd}
            </div>
            <div style="flex:1;overflow-y:auto;padding:8px;display:flex;flex-direction:column;gap:6px;background:${col.corBg};">
                ${cards}
            </div>
        </div>`;
    }).join('');

    board.querySelectorAll('[data-agendamento-id]').forEach(el =>
        el.addEventListener('click', () => abrirDetalhe(Number(el.getAttribute('data-agendamento-id'))))
    );
}

function cardKanban(item) {
    const ini = parseDataServidorBrasilia(item.data_inicio);
    const fim = parseDataServidorBrasilia(item.data_fim);
    const dataCurta = ini
        ? ini.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', timeZone: 'America/Sao_Paulo' })
        : '';
    const horaIni = formatarHoraAgendamento(item.data_inicio);
    const horaFim = fim ? formatarHoraAgendamento(item.data_fim) : '';
    const temFim  = horaFim && horaFim !== horaIni;

    return `<button type="button" data-agendamento-id="${item.id}"
        style="background:#111827;border:1px solid #1f2937;border-radius:10px;overflow:hidden;cursor:pointer;text-align:left;width:100%;padding:0;display:flex;flex-direction:column;transition:border-color .15s,box-shadow .15s;"
        onmouseover="this.style.borderColor='#4f46e5';this.style.boxShadow='0 0 0 1px #4f46e5';"
        onmouseout="this.style.borderColor='#1f2937';this.style.boxShadow='none';">
        <div style="height:3px;background:${escapeHtml(item.cor_hex || '#4f46e5')};width:100%;flex-shrink:0;"></div>
        <div style="padding:10px 12px;display:flex;flex-direction:column;gap:5px;">
            <div style="font-size:13px;font-weight:700;color:#f9fafb;line-height:1.35;word-break:break-word;">${escapeHtml(item.servico_nome)}</div>
            ${AG_EQUIP && item.solicitante_nome ? `
            <div style="display:flex;align-items:center;gap:4px;font-size:11px;color:#6b7280;">
                <svg style="width:11px;height:11px;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(item.solicitante_nome)}</span>
            </div>` : ''}
            <div style="display:flex;align-items:center;gap:4px;font-size:11px;color:#4b5563;">
                <svg style="width:11px;height:11px;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span>${dataCurta}${horaIni ? ' · ' + horaIni + (temFim ? '–' + horaFim : '') : ''}</span>
            </div>
        </div>
    </button>`;
}

// ── Cards legados ───────────────────────────────────────────────────────────
function cardAgendamentoCompacto(item) {
    return `<button type="button" data-agendamento-id="${item.id}" class="w-full text-left bg-gray-800/70 border border-gray-700 rounded-2xl p-3 hover:border-indigo-500 transition">
        <div class="flex items-center justify-between gap-2 mb-1">
            <span class="text-xs font-semibold text-white truncate">${escapeHtml(item.servico_nome)}</span>
            <span class="${statusClasses(item.status)}">${escapeHtml(statusLabel(item.status))}</span>
        </div>
        <p class="text-[11px] text-gray-400 truncate">${escapeHtml(item.solicitante_nome || 'Usuário')}</p>
        <p class="text-[10px] text-gray-500 mt-1">${escapeHtml(formatarDataAgendamento(item.data_inicio))}</p>
    </button>`;
}

function cardFila(item) {
    return `<button type="button" data-agendamento-id="${item.id}" class="w-full text-left bg-gray-800/70 border border-gray-700 rounded-2xl p-3 hover:border-indigo-500 transition">
        <div class="flex items-center justify-between gap-2 mb-1">
            <span class="text-xs font-semibold text-white truncate">${escapeHtml(item.servico_nome)}</span>
            <span class="${statusClasses(item.status)}">${escapeHtml(statusLabel(item.status))}</span>
        </div>
        <p class="text-[11px] text-gray-400 truncate">${escapeHtml(item.solicitante_nome || 'Usuário')}</p>
        <p class="text-[10px] text-gray-500 mt-1">${escapeHtml(formatarDataAgendamento(item.data_inicio))}</p>
    </button>`;
}

// ── Abertura de dia ─────────────────────────────────────────────────────────
function _buildItensDoDialHtml(itens) {
    if (!itens.length) {
        return '<p class="text-sm text-gray-500 px-1">Nenhum serviço agendado para este dia.</p>';
    }
    return itens.map(item => `
        <button type="button" data-agendamento-id="${item.id}" class="w-full bg-gray-800/70 border border-gray-700 rounded-2xl p-4 text-left hover:border-indigo-500 transition flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-white truncate">${escapeHtml(item.servico_nome)}</p>
                    <p class="text-xs text-gray-400 truncate">${escapeHtml(item.solicitante_nome || '')}</p>
                </div>
                <span class="${statusClasses(item.status)} shrink-0">${escapeHtml(statusLabel(item.status))}</span>
            </div>
            <div class="text-xs text-gray-400">${escapeHtml(formatarHoraAgendamento(item.data_inicio))} – ${escapeHtml(formatarHoraAgendamento(item.data_fim))}</div>
        </button>
    `).join('');
}

function _bindItensDoDialListeners(el) {
    if (!el) return;
    el.querySelectorAll('[data-agendamento-id]').forEach(btn =>
        btn.addEventListener('click', () => abrirDetalhe(Number(btn.getAttribute('data-agendamento-id'))))
    );
}

function abrirDia(dataIso) {
    diaSelecionado = dataIso;
    const data = parseDataServidorBrasilia(dataIso + 'T12:00:00');
    const itens = agendamentosCache.filter(item => agendamentoCobreDia(item, data || new Date()));
    const html  = _buildItensDoDialHtml(itens);

    if (data) {
        const longo  = data.toLocaleDateString('pt-BR', { weekday:'long', day:'2-digit', month:'long', year:'numeric', timeZone:'America/Sao_Paulo' });
        const curto  = data.toLocaleDateString('pt-BR', { day:'2-digit', month:'long', year:'numeric', timeZone:'America/Sao_Paulo' });
        const titulo = document.getElementById('modal-dia-titulo');
        const rotulo = document.getElementById('dia-selecionado-rotulo');
        if (titulo) titulo.textContent = longo;
        if (rotulo) rotulo.textContent = curto;
    }

    const painelInline = document.getElementById('lista-agendamentos-dia');
    if (painelInline) { painelInline.innerHTML = html; _bindItensDoDialListeners(painelInline); }

    const modalConteudo = document.getElementById('modal-dia-conteudo');
    if (modalConteudo) { modalConteudo.innerHTML = html; _bindItensDoDialListeners(modalConteudo); }

    if (viewMode === 'month') abrirModal('modal-dia');
}

// ── Detalhe ─────────────────────────────────────────────────────────────────
async function abrirDetalhe(id) {
    const res = await fetch('/api/agendamentos/' + id);
    const data = await res.json();
    if (!res.ok) { alert(data.erro || 'Não foi possível carregar o agendamento'); return; }

    agendamentoAtual = data;
    const set = (elId, val) => { const el = document.getElementById(elId); if (el) el.textContent = val; };
    const setHtml = (elId, html) => { const el = document.getElementById(elId); if (el) el.innerHTML = html; };

    set('detalhe-servico-nome', data.servico_nome || 'Agendamento');
    set('detalhe-solicitante', data.solicitante_nome + (data.solicitante_email ? ' • ' + data.solicitante_email : ''));
    setHtml('detalhe-status', `<span class="${statusClasses(data.status)}">${statusLabel(data.status)}</span>`);
    set('detalhe-inicio', formatarDataAgendamento(data.data_inicio));
    set('detalhe-fim', formatarDataAgendamento(data.data_fim));
    set('detalhe-observacoes', data.observacoes || 'Sem observações');

    const blocoInfo = document.getElementById('bloco-fechamento-info');
    if (data.status === 'encerrado' && (data.realizado !== null && data.realizado !== undefined || data.observacao_fechamento)) {
        blocoInfo?.classList.remove('hidden');
        set('detalhe-realizado', data.realizado === null || data.realizado === undefined ? 'Não informado' : (Number(data.realizado) === 1 ? 'Sim' : 'Não'));
        set('detalhe-observacao-fechamento', data.observacao_fechamento || '');
    } else {
        blocoInfo?.classList.add('hidden');
    }

    const checkRealizado = document.getElementById('fechamento-realizado');
    if (checkRealizado) checkRealizado.checked = true;
    const obsFechamento = document.getElementById('fechamento-observacao');
    if (obsFechamento) obsFechamento.value = '';

    configurarAcoesDetalhe(data);
    abrirModal('modal-detalhe');
}

function configurarAcoesDetalhe(data) {
    const btns = ['cancelar','aprovar','recusar','encerrar'].reduce((acc, k) => {
        acc[k] = document.getElementById('btn-detalhe-' + k);
        return acc;
    }, {});
    Object.values(btns).forEach(b => b?.classList.add('hidden'));
    document.getElementById('bloco-fechamento-form')?.classList.add('hidden');

    if (btns.cancelar && (Number(data.solicitante_id) === AG_USER_ID || AG_EQUIP) && !['encerrado','cancelado'].includes(data.status))
        btns.cancelar.classList.remove('hidden');

    if (!AG_EQUIP) return;
    if (btns.aprovar && data.status === 'solicitado') btns.aprovar.classList.remove('hidden');
    if (btns.recusar && data.status === 'solicitado') btns.recusar.classList.remove('hidden');
    if (btns.encerrar && ['agendado', 'em_avaliacao'].includes(data.status)) {
        btns.encerrar.classList.remove('hidden');
        document.getElementById('bloco-fechamento-form')?.classList.remove('hidden');
    }
}

// ── Modal de solicitação ────────────────────────────────────────────────────
function abrirModalSolicitacao(dataIso) {
    const inputIni = document.getElementById('solicitacao-data-inicio');
    if (inputIni) {
        const base = dataIso ? parseDataServidorBrasilia(dataIso + 'T09:00:00') : new Date();
        inputIni.value = dataToLocalValue(base || new Date());
    }
    const inputFim = document.getElementById('solicitacao-data-fim');
    if (inputFim) inputFim.value = '';
    const inputObs = document.getElementById('solicitacao-observacoes');
    if (inputObs) inputObs.value = '';
    abrirModal('modal-solicitacao');
}

async function enviarSolicitacao() {
    const servicoId   = Number(document.getElementById('solicitacao-servico')?.value || 0);
    const dataInicio  = document.getElementById('solicitacao-data-inicio')?.value || '';
    const dataFim     = document.getElementById('solicitacao-data-fim')?.value || '';
    const observacoes = document.getElementById('solicitacao-observacoes')?.value || '';

    const res = await fetch('/api/agendamentos', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ servico_id: String(servicoId), data_inicio: dataInicio, data_fim: dataFim, observacoes }),
    });
    const data = await res.json();
    if (!res.ok) { alert(data.erro || 'Não foi possível solicitar o serviço'); return; }
    fecharModal('modal-solicitacao');
    await carregarAgendamentos();
}

// ── Ações de status ─────────────────────────────────────────────────────────
async function alterarStatus(id, acao, payload = {}) {
    const res = await fetch(`/api/agendamentos/${id}/${acao}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload),
    });
    const data = await res.json();
    if (!res.ok) { alert(data.erro || 'Não foi possível atualizar o agendamento'); return; }
    agendamentoAtual = data;
    fecharModal('modal-detalhe');
    await carregarAgendamentos();
    if (data.id) await abrirDetalhe(data.id);
}

// ── Modais ──────────────────────────────────────────────────────────────────
function abrirModal(id)  { document.getElementById(id)?.classList.remove('hidden'); }
function fecharModal(id) { document.getElementById(id)?.classList.add('hidden'); }

// ── Serviços (CRUD) ─────────────────────────────────────────────────────────
function renderizarServicosAdmin() {
    const lista  = document.getElementById('lista-servicos');
    const count  = document.getElementById('count-servicos');
    if (!lista) return;
    if (count) count.textContent = String(servicosCache.length);
    if (!servicosCache.length) { lista.innerHTML = '<p class="text-xs text-gray-600">Nenhum serviço cadastrado.</p>'; return; }

    lista.innerHTML = servicosCache.map(s => `
        <div class="bg-gray-800/70 border border-gray-700 rounded-2xl p-3 flex items-start justify-between gap-3">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <div style="width:10px;height:10px;border-radius:50%;background:${escapeHtml(s.cor_hex || '#4f46e5')};flex-shrink:0;"></div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-white text-sm truncate">${escapeHtml(s.nome)}</p>
                    <p class="text-xs text-gray-400 truncate">${escapeHtml(s.descricao || 'Sem descrição')}</p>
                    <span class="text-[10px] px-2 py-0.5 rounded-full mt-1 inline-block ${Number(s.ativo) ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}">${Number(s.ativo) ? 'Ativo' : 'Inativo'}</span>
                </div>
            </div>
            <div class="flex flex-col gap-1 shrink-0">
                <button type="button" onclick="editarServico(${s.id})" class="text-xs text-indigo-300 hover:text-indigo-200 px-2 py-1 rounded-lg hover:bg-indigo-500/10 transition">Editar</button>
                <button type="button" onclick="desativarServico(${s.id})" class="text-xs text-red-300 hover:text-red-200 px-2 py-1 rounded-lg hover:bg-red-500/10 transition">Desativar</button>
            </div>
        </div>
    `).join('');
}

async function editarServico(id) {
    const s = servicosCache.find(i => Number(i.id) === Number(id));
    if (!s) return;
    editandoServicoId = s.id;
    const pairs = { 'servico-id': s.id, 'servico-nome': s.nome || '', 'servico-descricao': s.descricao || '', 'servico-cor': s.cor_hex || '#4f46e5' };
    Object.entries(pairs).forEach(([fid, val]) => { const el = document.getElementById(fid); if (el) el.value = val; });
    const ativo = document.getElementById('servico-ativo');
    if (ativo) ativo.checked = Number(s.ativo) === 1;
    document.getElementById('form-servico')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function limparFormularioServico() {
    editandoServicoId = null;
    ['servico-id','servico-nome','servico-descricao'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const cor = document.getElementById('servico-cor'); if (cor) cor.value = '#4f46e5';
    const ativo = document.getElementById('servico-ativo'); if (ativo) ativo.checked = true;
}

async function salvarServico() {
    const payload = {
        nome:      document.getElementById('servico-nome')?.value.trim() || '',
        descricao: document.getElementById('servico-descricao')?.value.trim() || '',
        cor_hex:   document.getElementById('servico-cor')?.value.trim() || '#4f46e5',
        ativo:     document.getElementById('servico-ativo')?.checked ? 1 : 0,
    };
    const url    = editandoServicoId ? '/api/servicos-agendamento/' + editandoServicoId : '/api/servicos-agendamento';
    const method = editandoServicoId ? 'PATCH' : 'POST';
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(payload) });
    const data = await res.json();
    if (!res.ok) { alert(data.erro || 'Não foi possível salvar o serviço'); return; }
    limparFormularioServico();
    await carregarServicos();
    await carregarAgendamentos();
}

async function desativarServico(id) {
    if (!confirm('Desativar este serviço?')) return;
    const res = await fetch('/api/servicos-agendamento/' + id, { method: 'DELETE' });
    const data = await res.json();
    if (!res.ok) { alert(data.erro || 'Não foi possível desativar'); return; }
    await carregarServicos();
}

function abrirModalServico() {
    limparFormularioServico();
    document.getElementById('form-servico')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Init ────────────────────────────────────────────────────────────────────
function prepararAgenda() {
    document.getElementById('btn-mes-anterior')?.addEventListener('click', navAnterior);
    document.getElementById('btn-mes-proximo')?.addEventListener('click', navProximo);
    document.getElementById('btn-mes-hoje')?.addEventListener('click', navHoje);

    document.getElementById('btn-view-month')?.addEventListener('click', () => mudarView('month'));
    document.getElementById('btn-view-week')?.addEventListener('click',  () => mudarView('week'));
    document.getElementById('btn-view-day')?.addEventListener('click',   () => mudarView('day'));

    document.getElementById('btn-abrir-solicitacao-dia')?.addEventListener('click', () => abrirModalSolicitacao(diaSelecionado));

    document.getElementById('form-servico')?.addEventListener('submit', async e => { e.preventDefault(); await salvarServico(); });

    document.querySelectorAll('[data-tab]').forEach(btn =>
        btn.addEventListener('click', () => trocarTab(btn.dataset.tab))
    );

    const detalheAcoes = {
        'btn-detalhe-cancelar': async () => agendamentoAtual && await alterarStatus(agendamentoAtual.id, 'cancelar'),
        'btn-detalhe-aprovar':  async () => agendamentoAtual && await alterarStatus(agendamentoAtual.id, 'aprovar'),
        'btn-detalhe-encerrar': async () => {
            if (!agendamentoAtual) return;
            const realizado = document.getElementById('fechamento-realizado')?.checked ? '1' : '0';
            const observacao_fechamento = document.getElementById('fechamento-observacao')?.value.trim() || '';
            await alterarStatus(agendamentoAtual.id, 'encerrar', { realizado, observacao_fechamento });
        },
        'btn-detalhe-recusar': async () => {
            if (!agendamentoAtual) return;
            const motivo = prompt('Informe o motivo da recusa:') || '';
            if (!motivo.trim()) return;
            await alterarStatus(agendamentoAtual.id, 'recusar', { motivo });
        },
    };
    Object.entries(detalheAcoes).forEach(([id, fn]) => document.getElementById(id)?.addEventListener('click', fn));

    document.addEventListener('click', e => {
        const key = e.target?.getAttribute?.('data-close-modal');
        if (key) fecharModal(key);
    });
}

// ── WebSocket ───────────────────────────────────────────────────────────────
function conectarWSAgendamentos() {
    try {
        wsAgendamentos = new WebSocket(`ws://${location.hostname}:8080`);
        wsAgendamentos.onopen = () => wsAgendamentos.send(JSON.stringify({
            type: 'auth', user_id: AG_USER_ID, user_nome: AG_USER_NAME, user_papel: AG_USER_PAPEL, conversa_id: 0,
        }));
        wsAgendamentos.onmessage = e => {
            try {
                const msg = JSON.parse(e.data);
                if (msg.type === 'schedule_updated') {
                    carregarServicos().catch(() => {});
                    carregarAgendamentos().catch(() => {});
                    if (agendamentoAtual && Number(msg.agendamento?.id) === Number(agendamentoAtual.id))
                        abrirDetalhe(Number(agendamentoAtual.id));
                }
            } catch (_) {}
        };
        wsAgendamentos.onclose = () => setTimeout(conectarWSAgendamentos, 3000);
    } catch (e) {
        console.error('WS agendamentos', e);
    }
}

// ── Carrega serviços uma vez só ─────────────────────────────────────────────
async function _carregarServicosUmaVez() {
    const url = AG_EQUIP ? '/api/servicos-agendamento?incluir_inativos=1' : '/api/servicos-agendamento';
    const res = await fetch(url);
    const data = await res.json();
    servicosCache = Array.isArray(data) ? data : [];
    popularSelectServicos();
    renderizarServicosAdmin();
}

document.addEventListener('DOMContentLoaded', async () => {
    prepararAgenda();
    conectarWSAgendamentos();

    // Aplicar tab inicial (sem carregar dados ainda)
    _aplicarTabUI(tabAtiva);

    // Inicializar botões de view
    ['month', 'week', 'day'].forEach(m => {
        const btn = document.getElementById('btn-view-' + m);
        if (!btn) return;
        const ativo = m === viewMode;
        btn.classList.toggle('bg-indigo-600', ativo);
        btn.classList.toggle('text-white', ativo);
        btn.classList.toggle('bg-gray-800', !ativo);
        btn.classList.toggle('text-gray-300', !ativo);
        btn.classList.toggle('border-gray-700', !ativo);
    });

    await _carregarServicosUmaVez();
    await carregarAgendamentos();
    setInterval(carregarAgendamentos, 30000);
});
