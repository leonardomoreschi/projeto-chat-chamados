const MEUS_CHAMADOS_BOOTSTRAP = Array.isArray(window.MEUS_CHAMADOS_BOOTSTRAP) ? window.MEUS_CHAMADOS_BOOTSTRAP : [];
let chamadosUsuarioCache = MEUS_CHAMADOS_BOOTSTRAP.slice();
let tabAtual = 'abertos';
let chamadoAtualId = 0;

const STATUS_LABELS = (window.APP_CONFIG && window.APP_CONFIG.status) || {
    aberto: 'Aberto',
    classificado: 'Classificado',
    em_andamento: 'Em andamento',
    resolvido: 'Resolvido',
    cancelado: 'Cancelado'
};

const PRIORIDADE_LABELS = (window.APP_CONFIG && window.APP_CONFIG.prioridades) || {};

document.addEventListener('DOMContentLoaded', function () {
    renderizarDashboard();
    configurarEventos();
});

function configurarEventos() {
    const busca = document.getElementById('busca-chamados');
    if (busca) {
        busca.addEventListener('input', function () {
            renderizarLista();
        });
    }

    document.querySelectorAll('.tab-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            tabAtual = button.getAttribute('data-tab') || 'abertos';
            document.querySelectorAll('.tab-btn').forEach(function (btn) {
                const ativo = btn === button;
                btn.className = ativo
                    ? 'tab-btn px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold'
                    : 'tab-btn px-4 py-2 rounded-xl bg-gray-800 text-gray-300 text-sm font-bold border border-gray-700';
            });
            renderizarLista();
        });
    });
}

function renderizarDashboard() {
    const total = chamadosUsuarioCache.length;
    const abertos = chamadosUsuarioCache.filter(function (item) {
        return item.status !== 'resolvido' && item.status !== 'cancelado';
    }).length;
    const resolvidos = chamadosUsuarioCache.filter(function (item) {
        return item.status === 'resolvido';
    }).length;
    const cancelados = chamadosUsuarioCache.filter(function (item) {
        return item.status === 'cancelado';
    }).length;

    const countTotal = document.getElementById('count-total');
    const countAbertos = document.getElementById('count-abertos');
    const countResolvidos = document.getElementById('count-resolvidos');
    const countCancelados = document.getElementById('count-cancelados');
    if (countTotal) countTotal.textContent = String(total);
    if (countAbertos) countAbertos.textContent = String(abertos);
    if (countResolvidos) countResolvidos.textContent = String(resolvidos);
    if (countCancelados) countCancelados.textContent = String(cancelados);

    renderizarLista();
}

function renderizarLista() {
    const busca = normalizarTexto((document.getElementById('busca-chamados') || {}).value || '');
    const lista = document.getElementById('lista-chamados');
    const titulo = document.getElementById('titulo-lista');
    const contador = document.getElementById('contador-lista');

    if (!lista) return;

    let filtrados = chamadosUsuarioCache.filter(function (item) {
        if (tabAtual === 'abertos') return item.status !== 'resolvido' && item.status !== 'cancelado';
        if (tabAtual === 'resolvidos') return item.status === 'resolvido';
        if (tabAtual === 'cancelados') return item.status === 'cancelado';
        return true;
    });

    if (busca) {
        filtrados = filtrados.filter(function (item) {
            const idNumerico = String(item.id || '');
            const idComHash = '#' + idNumerico;
            const alvo = normalizarTexto([
                idNumerico,
                idComHash,
                item.titulo,
                item.categoria,
                item.subcategoria,
                STATUS_LABELS[item.status] || item.status
            ].join(' '));
            return alvo.includes(busca);
        });
    }

    if (titulo) {
        titulo.textContent = tabAtual === 'abertos'
            ? 'Chamados Abertos'
            : tabAtual === 'resolvidos'
                ? 'Chamados Resolvidos'
                : tabAtual === 'cancelados'
                    ? 'Chamados Cancelados'
                    : 'Todos os Chamados';
    }
    if (contador) contador.textContent = filtrados.length + ' chamado(s)';

    if (!filtrados.length) {
        lista.innerHTML = '<div class="md:col-span-2 xl:col-span-3 bg-black/20 border border-gray-800 rounded-2xl p-6 text-sm text-gray-400">Nenhum chamado encontrado para este filtro.</div>';
        return;
    }

    lista.innerHTML = filtrados.map(renderCard).join('');
}

function renderCard(chamado) {
    const prioridade = PRIORIDADE_LABELS[chamado.prioridade] || PRIORIDADE_LABELS.media;
    const statusCor = chamado.status === 'resolvido'
        ? 'bg-green-500/20 text-green-300 border-green-500/30'
        : chamado.status === 'cancelado'
            ? 'bg-red-500/20 text-red-300 border-red-500/30'
            : 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30';

    return `
        <button onclick="abrirDetalhes(${chamado.id})" class="text-left bg-gray-900 border border-gray-800 rounded-2xl p-5 card-anim hover:border-indigo-500/30 h-full flex flex-col gap-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded ${prioridade.color} text-black">${prioridade.label}</span>
                        <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded border ${statusCor}">${escapeHtml(STATUS_LABELS[chamado.status] || chamado.status)}</span>
                    </div>
                    <h3 class="text-white font-bold text-base leading-snug">#${chamado.id} - ${escapeHtml(chamado.titulo || '')}</h3>
                </div>
            </div>
            <div class="space-y-2 text-xs text-gray-400">
                <p><span class="text-gray-500 uppercase font-bold">Categoria:</span> ${escapeHtml(chamado.categoria || 'Não informada')}</p>
                <p><span class="text-gray-500 uppercase font-bold">Subcategoria:</span> ${escapeHtml(chamado.subcategoria || 'Não informada')}</p>
                <p><span class="text-gray-500 uppercase font-bold">Abertura:</span> ${escapeHtml(formatarDataHora(chamado.criado_em))}</p>
                <p><span class="text-gray-500 uppercase font-bold">Resolvido por:</span> ${escapeHtml(chamado.resolvido_por_nome || 'Não informado')}</p>
            </div>
            <p class="text-sm text-gray-300 line-clamp-3">${escapeHtml((chamado.descricao_rich || '').replace(/\s+/g, ' ').trim())}</p>
            <div class="mt-auto flex items-center justify-between text-[11px] text-gray-500">
                <span>Ver detalhes e comentários</span>
                <span class="text-indigo-300 font-bold">Abrir</span>
            </div>
        </button>
    `;
}

async function abrirDetalhes(id) {
    const chamado = chamadosUsuarioCache.find(function (item) {
        return Number(item.id) === Number(id);
    });
    if (!chamado) return;

    chamadoAtualId = chamado.id;
    const badgeId = document.getElementById('detalhes-badge-id');
    const statusEl = document.getElementById('detalhes-status');
    const tituloEl = document.getElementById('detalhes-titulo');
    const subtituloEl = document.getElementById('detalhes-subtitulo');
    const categoriaEl = document.getElementById('detalhes-categoria');
    const subcategoriaEl = document.getElementById('detalhes-subcategoria');
    const criadoEl = document.getElementById('detalhes-criado');
    const fechadoEl = document.getElementById('detalhes-fechado');
    const resolvidoPorEl = document.getElementById('detalhes-resolvido-por');
    const descricaoEl = document.getElementById('detalhes-descricao');

    if (badgeId) badgeId.textContent = '#' + chamado.id;
    if (statusEl) statusEl.textContent = STATUS_LABELS[chamado.status] || chamado.status;
    if (tituloEl) tituloEl.textContent = chamado.titulo || '';
    if (subtituloEl) subtituloEl.textContent = 'Solicitante: ' + (chamado.usuario_nome || 'Não informado');
    if (categoriaEl) categoriaEl.textContent = chamado.categoria || 'Não informada';
    if (subcategoriaEl) subcategoriaEl.textContent = chamado.subcategoria || 'Não informada';
    if (criadoEl) criadoEl.textContent = formatarDataHora(chamado.criado_em);
    if (fechadoEl) {
        fechadoEl.textContent = chamado.status === 'resolvido' || chamado.status === 'cancelado'
            ? formatarDataHora(chamado.atualizado_em || chamado.criado_em)
            : 'Em andamento';
    }
    if (resolvidoPorEl) {
        resolvidoPorEl.textContent = chamado.status === 'cancelado'
            ? 'Não se aplica'
            : (chamado.resolvido_por_nome || 'Não informado');
    }
    if (descricaoEl) descricaoEl.textContent = chamado.descricao_rich || '';

    const btnCancelar = document.getElementById('btn-cancelar-chamado');
    if (btnCancelar) {
        if (podeCancelarChamado(chamado)) {
            btnCancelar.classList.remove('hidden');
        } else {
            btnCancelar.classList.add('hidden');
        }
    }

    document.getElementById('modal-detalhes').classList.remove('hidden');
    await Promise.all([
        carregarAnexosDoChamado(chamado.id),
        carregarComentarios(chamado.id)
    ]);
}

// Mídias enviadas na abertura do chamado. Ficam numa tabela separada dos
// comentários, então precisam da própria chamada — era o que faltava aqui e
// fazia o modal do solicitante não mostrar nenhum anexo.
async function carregarAnexosDoChamado(chamadoId) {
    const lista = document.getElementById('detalhes-anexos-lista');
    const contador = document.getElementById('detalhes-anexos-contador');
    if (!lista) return;

    lista.innerHTML = '<div class="text-xs text-gray-500">Carregando anexos...</div>';
    if (contador) contador.textContent = '';

    try {
        const res = await fetch(`/api/chamados/${chamadoId}/anexos`);
        if (!res.ok) {
            lista.innerHTML = '<div class="text-xs text-red-300">Não foi possível carregar os anexos.</div>';
            return;
        }

        const anexos = await res.json();
        if (!Array.isArray(anexos) || anexos.length === 0) {
            lista.innerHTML = '<div class="text-xs text-gray-500">Nenhum anexo enviado.</div>';
            return;
        }

        if (contador) {
            contador.textContent = anexos.length + (anexos.length === 1 ? ' arquivo' : ' arquivos');
        }
        lista.innerHTML = anexos.map(cardAnexoChamado).join('');
    } catch (e) {
        console.error(e);
        lista.innerHTML = '<div class="text-xs text-red-300">Erro ao carregar os anexos.</div>';
    }
}

function cardAnexoChamado(anexo) {
    const url = `/uploads/${anexo.arquivo_path || ''}`;
    const nome = escapeHtml(anexo.arquivo_nome || 'anexo');
    const isImagem = /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(anexo.arquivo_nome || anexo.arquivo_path || '')
        || String(anexo.mime_type || '').startsWith('image/');
    const visual = isImagem
        ? `<a href="${url}" target="_blank" class="block"><img src="${url}" alt="${nome}" class="w-full max-h-48 object-contain bg-black/30 border border-gray-800 rounded-xl p-2"></a>`
        : `<p class="text-xs text-gray-300 truncate" title="${nome}">${nome}</p>`;

    return `
        <div class="bg-black/20 border border-gray-800 rounded-2xl p-3 space-y-2">
            ${visual}
            <div class="flex flex-wrap gap-2">
                <a href="${url}" target="_blank" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-[11px] text-white font-bold rounded-lg transition">Visualizar</a>
                <a href="${url}" target="_blank" download="${nome}" class="inline-flex items-center px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-[11px] text-indigo-300 font-bold rounded-lg border border-gray-700 transition">Baixar</a>
            </div>
        </div>`;
}

function podeCancelarChamado(chamado) {
    if (!chamado) return false;
    return chamado.status !== 'resolvido' && chamado.status !== 'cancelado';
}

async function cancelarChamadoAtual() {
    if (!chamadoAtualId) return;

    const chamado = chamadosUsuarioCache.find(function (item) {
        return Number(item.id) === Number(chamadoAtualId);
    });

    if (!podeCancelarChamado(chamado)) {
        alert('Este chamado nao pode mais ser cancelado.');
        return;
    }

    const confirmar = window.confirm('Deseja realmente cancelar este chamado?');
    if (!confirmar) return;

    try {
        const res = await fetch(`/api/chamados/${chamadoAtualId}/cancelar`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });

        let payload = null;
        try {
            payload = await res.json();
        } catch (_) {
            payload = null;
        }

        if (!res.ok) {
            const msg = payload && payload.erro ? payload.erro : 'Nao foi possivel cancelar o chamado.';
            alert(msg);
            return;
        }

        if (chamado) {
            chamado.status = 'cancelado';
            chamado.atualizado_em = new Date().toISOString();
            chamado.resolvido_por_nome = '';
        }

        renderizarDashboard();
        fecharModal('modal-detalhes');
    } catch (e) {
        console.error(e);
        alert('Erro ao cancelar chamado.');
    }
}

async function carregarComentarios(chamadoId) {
    const lista = document.getElementById('comentarios-lista');
    if (!lista) return;

    lista.innerHTML = '<div class="text-xs text-gray-500">Carregando comentários...</div>';
    try {
        const res = await fetch(`/api/chamados/${chamadoId}/comentarios`);
        if (!res.ok) {
            lista.innerHTML = '<div class="text-xs text-red-300">Não foi possível carregar os comentários.</div>';
            return;
        }
        const comentarios = await res.json();
        if (!Array.isArray(comentarios) || comentarios.length === 0) {
            lista.innerHTML = '<div class="text-xs text-gray-500">Nenhum comentário registrado.</div>';
            return;
        }

        lista.innerHTML = comentarios.map(function (item) {
            return `
                <div class="bg-gray-800/60 border border-gray-700 rounded-xl p-4">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black uppercase tracking-widest bg-indigo-500/20 text-indigo-300 px-2 py-0.5 rounded">${escapeHtml(item.tipo === 'resolucao' ? 'Resolução' : 'Comentário')}</span>
                            <span class="text-xs text-gray-300 font-semibold">${escapeHtml(item.usuario_nome || 'TI')}</span>
                        </div>
                        <span class="text-[10px] text-gray-500">${escapeHtml(formatarDataHora(item.criado_em))}</span>
                    </div>
                    <p class="text-sm text-gray-200 whitespace-pre-wrap leading-relaxed">${escapeHtml(item.conteudo || '')}</p>
                    ${renderizarAnexos(item.anexos || [])}
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error(e);
        lista.innerHTML = '<div class="text-xs text-red-300">Erro ao carregar comentários.</div>';
    }
}

function renderizarAnexos(anexos) {
    if (!Array.isArray(anexos) || anexos.length === 0) return '';

    return `<div class="mt-3 space-y-2">${anexos.map(function (anexo) {
        const url = `/uploads/${anexo.arquivo_path || ''}`;
        const nome = escapeHtml(anexo.arquivo_nome || 'anexo');
        const isImagem = /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(anexo.arquivo_nome || anexo.arquivo_path || '');
        return isImagem
            ? `<a href="${url}" target="_blank" class="block"><img src="${url}" alt="${nome}" class="w-full max-h-48 object-contain bg-black/30 border border-gray-700 rounded-xl p-2"></a>`
            : `<a href="${url}" target="_blank" class="block text-xs text-indigo-300 underline">${nome}</a>`;
    }).join('')}</div>`;
}

function fecharModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('hidden');
}
