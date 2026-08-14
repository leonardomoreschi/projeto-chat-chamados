const PAPEIS = { admin: 'Admin', ti: 'TI', usuario: 'Usuário' };
const CORES_PAPEL = { admin: 'bg-purple-500/20 text-purple-400', ti: 'bg-blue-500/20 text-blue-400', usuario: 'bg-gray-500/20 text-gray-400' };
let usuarioEditandoId = null;
let usuariosPaginaAtual = [];
let debounceBuscaUsuarios = null;
let presencaOnline = new Set();
let wsPresenca = null;
let reconectarPresencaTimer = null;
// Cancelamento do modal de confirmação em aberto (definido por pedirCredenciaisAdmin).
let confirmacaoAdminPendente = null;
const estadoUsuarios = {
    page: 1,
    perPage: 7,
    q: '',
    papel: '',
    setor: '',
    total: 0,
    totalPages: 1,
};

// ── Tabs ──────────────────────────────────────
function trocarAba(aba) {
    document.getElementById('aba-usuarios').classList.toggle('hidden', aba !== 'usuarios');
    document.getElementById('aba-setores').classList.toggle('hidden', aba !== 'setores');
    document.getElementById('tab-usuarios').className = `tab-btn px-5 py-3 text-sm font-medium border-b-2 transition ${aba === 'usuarios' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-gray-400 hover:text-white'}`;
    document.getElementById('tab-setores').className = `tab-btn px-5 py-3 text-sm font-medium border-b-2 transition ${aba === 'setores' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-gray-400 hover:text-white'}`;
    if (aba === 'setores') carregarSetores();
}

// ── Usuários ──────────────────────────────────
async function carregarUsuarios() {
    const params = new URLSearchParams({
        page: String(estadoUsuarios.page),
        per_page: String(estadoUsuarios.perPage),
    });
    if (estadoUsuarios.q) params.set('q', estadoUsuarios.q);
    if (estadoUsuarios.papel) params.set('papel', estadoUsuarios.papel);
    if (estadoUsuarios.setor) params.set('setor', estadoUsuarios.setor);

    const res = await fetch('/api/admin/usuarios?' + params.toString());
    const payload = await res.json();
    const lista = Array.isArray(payload) ? payload : (payload.data || []);
    const pag = payload && payload.pagination ? payload.pagination : null;

    if (pag) {
        estadoUsuarios.page = Number(pag.page || 1);
        estadoUsuarios.perPage = Number(pag.per_page || estadoUsuarios.perPage || 10);
        estadoUsuarios.total = Number(pag.total || 0);
        estadoUsuarios.totalPages = Number(pag.total_pages || 1);
    } else {
        estadoUsuarios.total = lista.length;
        estadoUsuarios.totalPages = 1;
    }

    usuariosPaginaAtual = lista;
    const tbody = document.getElementById('tabela-usuarios');
    atualizarRodapePaginacao();

    if (!lista.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-gray-500 text-sm">Nenhum usuário cadastrado</td></tr>';
        return;
    }

    // A listagem traz o estado atual da presença; a partir daqui quem manda é
    // o WebSocket (presence_updated).
    lista.forEach(u => {
        if (Number(u.online || 0) === 1) {
            presencaOnline.add(Number(u.id));
        } else {
            presencaOnline.delete(Number(u.id));
        }
    });

    tbody.innerHTML = lista.map(u => `
        <tr class="hover:bg-gray-800/50 transition">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-indigo-700 rounded-lg flex items-center justify-center text-xs font-bold shrink-0">
                        ${u.nome.charAt(0).toUpperCase()}
                    </div>
                    <span class="text-sm font-medium text-white">${u.nome}</span>
                </div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-400">${u.email}</td>
            <td class="px-6 py-4 text-sm text-gray-400">${u.setor ?? '—'}</td>
            <td class="px-6 py-4">
                <span class="text-xs font-medium px-2.5 py-1 rounded-lg ${CORES_PAPEL[u.papel] ?? ''}">
                    ${PAPEIS[u.papel] ?? u.papel}
                </span>
            </td>
            <td class="px-6 py-4">
                <span class="text-xs font-medium px-2.5 py-1 rounded-lg ${u.ativo ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}">
                    ${u.ativo ? 'Ativo' : 'Inativo'}
                </span>
            </td>
            <td class="px-6 py-4" data-presenca-usuario="${u.id}">${badgePresenca(u.id)}</td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <button onclick="editarUsuario(${u.id})"
                            class="text-xs text-indigo-400 hover:text-indigo-300 transition px-2 py-1 rounded-lg hover:bg-indigo-500/10">
                        Editar
                    </button>
                    ${u.ativo
            ? `<button onclick="desativarUsuario(${u.id}, '${u.nome}')"
                            class="text-xs text-red-400 hover:text-red-300 transition px-2 py-1 rounded-lg hover:bg-red-500/10">
                        Desativar
                    </button>`
            : `<button onclick="reativarUsuario(${u.id}, '${u.nome}')"
                            class="text-xs text-green-400 hover:text-green-300 transition px-2 py-1 rounded-lg hover:bg-green-500/10">
                        Reativar
                    </button>`}
                </div>
            </td>
        </tr>`).join('');
}

function atualizarRodapePaginacao() {
    const info = document.getElementById('usuarios-paginacao-info');
    const page = document.getElementById('usuarios-paginacao-page');
    const prev = document.getElementById('usuarios-paginacao-prev');
    const next = document.getElementById('usuarios-paginacao-next');

    if (info) {
        if (estadoUsuarios.total === 0) {
            info.textContent = '0 usuários';
        } else {
            const inicio = ((estadoUsuarios.page - 1) * estadoUsuarios.perPage) + 1;
            const fim = Math.min(estadoUsuarios.page * estadoUsuarios.perPage, estadoUsuarios.total);
            info.textContent = `Mostrando ${inicio}-${fim} de ${estadoUsuarios.total} usuários`;
        }
    }
    if (page) page.textContent = `Página ${estadoUsuarios.page} de ${Math.max(1, estadoUsuarios.totalPages)}`;
    if (prev) prev.disabled = estadoUsuarios.page <= 1;
    if (next) next.disabled = estadoUsuarios.page >= Math.max(1, estadoUsuarios.totalPages);
    if (prev) prev.classList.toggle('opacity-50', prev.disabled);
    if (next) next.classList.toggle('opacity-50', next.disabled);
}

function abrirModalUsuario() {
    usuarioEditandoId = null;
    document.getElementById('modal-usuario-titulo').textContent = 'Novo Usuário';
    document.getElementById('senha-hint').textContent = '(mínimo 6 caracteres)';
    document.getElementById('usuario-id').value = '';
    document.getElementById('usuario-nome').value = '';
    document.getElementById('usuario-email').value = '';
    document.getElementById('usuario-senha').value = '';
    document.getElementById('usuario-papel').value = 'usuario';
    document.getElementById('usuario-setor').value = '';
    document.getElementById('usuario-email').disabled = false;
    document.getElementById('modal-usuario').classList.remove('hidden');
}

async function editarUsuario(id) {
    const u = usuariosPaginaAtual.find(x => Number(x.id) === Number(id));
    if (!u) return;

    usuarioEditandoId = id;
    document.getElementById('modal-usuario-titulo').textContent = 'Editar Usuário';
    document.getElementById('senha-hint').textContent = '(deixe em branco para não alterar)';
    document.getElementById('usuario-nome').value = u.nome;
    document.getElementById('usuario-email').value = u.email;
    // E-mail é editável também na edição; a troca é confirmada com a senha do
    // admin no salvar, e o backend recusa e-mail repetido.
    document.getElementById('usuario-email').disabled = false;
    document.getElementById('usuario-senha').value = '';
    document.getElementById('usuario-papel').value = u.papel;
    document.getElementById('modal-usuario').classList.remove('hidden');

    // Seleciona o setor correto após carregar
    await carregarSetoresNoSelect();
    if (u.setor_id) {
        document.getElementById('usuario-setor').value = String(u.setor_id);
    }
}

function fecharModalUsuario() {
    document.getElementById('modal-usuario').classList.add('hidden');
}

async function salvarUsuario() {
    const body = new URLSearchParams({
        nome: document.getElementById('usuario-nome').value.trim(),
        email: document.getElementById('usuario-email').value.trim(),
        senha: document.getElementById('usuario-senha').value,
        papel: document.getElementById('usuario-papel').value,
        setor_id: document.getElementById('usuario-setor').value,
    });

    // Editar exige reconferir quem está no teclado; criar não altera dado de
    // ninguém e segue direto.
    if (usuarioEditandoId) {
        const credenciais = await pedirCredenciaisAdmin(
            'Confirme seu e-mail e senha de administrador para salvar as alterações deste usuário.'
        );
        if (!credenciais) return;

        body.set('admin_email', credenciais.email);
        body.set('admin_senha', credenciais.senha);
    }

    const btn = document.getElementById('btn-salvar-usuario');
    btn.disabled = true;
    btn.textContent = 'Salvando...';

    try {
        const url = usuarioEditandoId ? `/api/admin/usuarios/${usuarioEditandoId}` : '/api/admin/usuarios';
        const method = usuarioEditandoId ? 'PATCH' : 'POST';
        const res = await fetch(url, { method, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();

        if (!res.ok) throw new Error(data.erro ?? 'Erro ao salvar');

        fecharModalUsuario();
        carregarUsuarios();
        mostrarToast(usuarioEditandoId ? 'Usuário atualizado!' : 'Usuário criado com sucesso!');
    } catch (err) {
        alert('Erro: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Salvar';
    }
}

async function desativarUsuario(id, nome) {
    if (!confirm(`Desativar o usuário "${nome}"? Ele não conseguirá mais fazer login.`)) return;

    const credenciais = await pedirCredenciaisAdmin(
        `Confirme seu e-mail e senha de administrador para desativar "${nome}".`
    );
    if (!credenciais) return;

    const res = await fetch(`/api/admin/usuarios/${id}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            admin_email: credenciais.email,
            admin_senha: credenciais.senha,
        }),
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        alert('Erro: ' + (data.erro || 'não foi possível desativar'));
        return;
    }

    carregarUsuarios();
    mostrarToast('Usuário desativado.');
}

async function reativarUsuario(id, nome) {
    if (!confirm(`Reativar o usuário "${nome}"?`)) return;

    // Mesma exigência do salvar: toda alteração de usuário passa pela
    // reconferência, sem exceção por campo.
    const credenciais = await pedirCredenciaisAdmin(
        `Confirme seu e-mail e senha de administrador para reativar "${nome}".`
    );
    if (!credenciais) return;

    const body = new URLSearchParams({
        ativo: '1',
        admin_email: credenciais.email,
        admin_senha: credenciais.senha,
    });

    const res = await fetch(`/api/admin/usuarios/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        alert('Erro: ' + (data.erro || 'não foi possível reativar'));
        return;
    }

    carregarUsuarios();
    mostrarToast('Usuário reativado!');
}

// ── Confirmação de identidade do admin ────────
//
// Abre o modal e resolve com {email, senha} ou null se o admin cancelar. O
// backend é quem valida: aqui só se coleta.
function pedirCredenciaisAdmin(descricao) {
    return new Promise(resolve => {
        const modal = document.getElementById('modal-confirmar-admin');
        const campoEmail = document.getElementById('confirmar-admin-email');
        const campoSenha = document.getElementById('confirmar-admin-senha');
        const erro = document.getElementById('confirmar-admin-erro');
        const btn = document.getElementById('btn-confirmar-admin');
        const texto = document.getElementById('confirmar-admin-descricao');

        if (!modal || !campoEmail || !campoSenha || !btn) {
            resolve(null);
            return;
        }

        if (texto && descricao) texto.textContent = descricao;
        // Os dois campos sempre em branco: a conferência é digitar as
        // credenciais de novo, não confirmar o que já está na tela.
        campoEmail.value = '';
        campoSenha.value = '';
        erro.classList.add('hidden');
        erro.textContent = '';

        modal.classList.remove('hidden');
        setTimeout(() => campoEmail.focus(), 50);

        function encerrar(resultado) {
            btn.removeEventListener('click', confirmar);
            campoSenha.removeEventListener('keydown', porEnter);
            campoEmail.removeEventListener('keydown', porEnter);
            confirmacaoAdminPendente = null;
            modal.classList.add('hidden');
            resolve(resultado);
        }

        function confirmar() {
            const email = campoEmail.value.trim();
            const senha = campoSenha.value;

            if (!email || !senha) {
                erro.textContent = 'Informe e-mail e senha.';
                erro.classList.remove('hidden');
                return;
            }

            encerrar({ email, senha });
        }

        function porEnter(evento) {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                confirmar();
            }
        }

        // fecharConfirmacaoAdmin() (botões Cancelar e X) cai aqui.
        confirmacaoAdminPendente = () => encerrar(null);

        btn.addEventListener('click', confirmar);
        campoEmail.addEventListener('keydown', porEnter);
        campoSenha.addEventListener('keydown', porEnter);
    });
}

function fecharConfirmacaoAdmin() {
    if (confirmacaoAdminPendente) {
        confirmacaoAdminPendente();
        return;
    }
    document.getElementById('modal-confirmar-admin').classList.add('hidden');
}

// ── Setores ───────────────────────────────────
async function carregarSetores() {
    const res = await fetch('/api/admin/setores');
    const lista = await res.json();
    const grid = document.getElementById('grid-setores');

    if (!lista.length) {
        grid.innerHTML = '<div class="text-center py-8 text-gray-500 text-sm col-span-3">Nenhum setor cadastrado</div>';
        return;
    }

    grid.innerHTML = lista.map(s => `
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5 flex items-start justify-between gap-3">
            <div>
                <p class="font-semibold text-white">${s.nome}</p>
                <p class="text-xs text-indigo-400 mt-2">${s.total_usuarios} usuário(s)</p>
            </div>
            <button onclick="deletarSetor(${s.id}, '${s.nome}')"
                    class="text-gray-600 hover:text-red-400 transition p-1 shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </div>`).join('');

    await carregarSetoresNoSelect();
}

async function carregarSetoresNoSelect() {
    const res = await fetch('/api/admin/setores');
    const lista = await res.json();
    const select = document.getElementById('usuario-setor');
    const filtroSetor = document.getElementById('filtro-usuarios-setor');
    const atual = select.value;
    select.innerHTML = '<option value="">Sem setor</option>' +
        lista.map(s => `<option value="${s.id}">${s.nome}</option>`).join('');
    if (atual) select.value = atual;

    if (filtroSetor) {
        const atualFiltro = filtroSetor.value;
        filtroSetor.innerHTML = '<option value="">Todos os setores</option>' +
            lista.map(s => `<option value="${s.id}">${s.nome}</option>`).join('');
        if (atualFiltro) filtroSetor.value = atualFiltro;
    }
}

function configurarFiltrosUsuarios() {
    const inputBusca = document.getElementById('filtro-usuarios-busca');
    const filtroPapel = document.getElementById('filtro-usuarios-papel');
    const filtroSetor = document.getElementById('filtro-usuarios-setor');
    const perPage = document.getElementById('filtro-usuarios-per-page');
    const prev = document.getElementById('usuarios-paginacao-prev');
    const next = document.getElementById('usuarios-paginacao-next');

    if (inputBusca) {
        inputBusca.addEventListener('input', function () {
            clearTimeout(debounceBuscaUsuarios);
            debounceBuscaUsuarios = setTimeout(function () {
                estadoUsuarios.q = inputBusca.value.trim();
                estadoUsuarios.page = 1;
                carregarUsuarios();
            }, 250);
        });
    }

    if (filtroPapel) {
        filtroPapel.addEventListener('change', function () {
            estadoUsuarios.papel = filtroPapel.value;
            estadoUsuarios.page = 1;
            carregarUsuarios();
        });
    }

    if (filtroSetor) {
        filtroSetor.addEventListener('change', function () {
            estadoUsuarios.setor = filtroSetor.value;
            estadoUsuarios.page = 1;
            carregarUsuarios();
        });
    }

    if (perPage) {
        perPage.addEventListener('change', function () {
            estadoUsuarios.perPage = Number(perPage.value || 7);
            estadoUsuarios.page = 1;
            carregarUsuarios();
        });
    }

    if (prev) {
        prev.addEventListener('click', function () {
            if (estadoUsuarios.page <= 1) return;
            estadoUsuarios.page -= 1;
            carregarUsuarios();
        });
    }

    if (next) {
        next.addEventListener('click', function () {
            if (estadoUsuarios.page >= estadoUsuarios.totalPages) return;
            estadoUsuarios.page += 1;
            carregarUsuarios();
        });
    }
}

function abrirModalSetor() {
    document.getElementById('setor-nome').value = '';
    document.getElementById('modal-setor').classList.remove('hidden');
}

function fecharModalSetor() {
    document.getElementById('modal-setor').classList.add('hidden');
}

async function salvarSetor() {
    const nome = document.getElementById('setor-nome').value.trim();
    if (!nome) { alert('Informe o nome do setor.'); return; }

    const res = await fetch('/api/admin/setores', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ nome }),
    });
    const data = await res.json();
    if (!res.ok) { alert(data.erro); return; }

    fecharModalSetor();
    carregarSetores();
    mostrarToast('Setor criado!');
}

async function deletarSetor(id, nome) {
    if (!confirm(`Deletar o setor "${nome}"?`)) return;
    const res = await fetch(`/api/admin/setores/${id}`, { method: 'DELETE' });
    const data = await res.json();
    if (!res.ok) { alert(data.erro); return; }
    carregarSetores();
    mostrarToast('Setor removido.');
}

// ── Presença (coluna "Conexão") ───────────────
//
// Online = o usuário tem o sistema aberto em alguma aba (conexão WebSocket
// viva). O estado inicial vem junto da listagem; as mudanças chegam pelo evento
// `presence_updated`, que o ChatServer manda só para conexões de admin. O poll
// de 30s é rede de segurança para o intervalo em que o WebSocket esteve caído.

function badgePresenca(usuarioId) {
    const online = presencaOnline.has(Number(usuarioId));
    const cor = online ? 'bg-green-500/20 text-green-400' : 'bg-gray-500/20 text-gray-400';
    const ponto = online ? 'bg-green-400' : 'bg-gray-500';

    return `<span class="text-xs font-medium px-2.5 py-1 rounded-lg inline-flex items-center gap-1.5 ${cor}">
                <span class="w-1.5 h-1.5 rounded-full ${ponto}"></span>${online ? 'Online' : 'Offline'}
            </span>`;
}

/** Repinta só as células de presença, sem redesenhar a tabela inteira. */
function aplicarPresencaNaTabela() {
    document.querySelectorAll('[data-presenca-usuario]').forEach(celula => {
        celula.innerHTML = badgePresenca(celula.dataset.presencaUsuario);
    });
}

function marcarPresenca(usuarioId, online) {
    const id = Number(usuarioId);
    if (!id) return;

    if (online) {
        presencaOnline.add(id);
    } else {
        presencaOnline.delete(id);
    }

    const celula = document.querySelector(`[data-presenca-usuario="${id}"]`);
    if (celula) celula.innerHTML = badgePresenca(id);
}

async function sincronizarPresenca() {
    try {
        const res = await fetch('/api/admin/usuarios/presenca');
        if (!res.ok) return;

        const data = await res.json();
        presencaOnline = new Set((data.online || []).map(Number));
        aplicarPresencaNaTabela();
    } catch (_) {
        // Sem fallback visível: a coluna mantém o último estado conhecido.
    }
}

function indicarConexaoPainel(ativa) {
    const el = document.getElementById('presenca-indicador');
    if (!el) return;
    el.classList.toggle('text-green-500', ativa);
    el.classList.toggle('text-gray-600', !ativa);
    el.title = ativa ? 'Atualização em tempo real ativa' : 'Reconectando…';
}

function conectarPresencaWS() {
    const usuario = window.APP_USER || {};
    if (!usuario.id || !('WebSocket' in window)) return;

    try {
        wsPresenca = new WebSocket('ws://' + window.location.hostname + ':8080');
    } catch (_) {
        return;
    }

    wsPresenca.onopen = () => {
        indicarConexaoPainel(true);
        wsPresenca.send(JSON.stringify({
            type: 'auth',
            user_id: usuario.id,
            user_nome: usuario.nome || '',
            user_papel: usuario.papel || 'admin',
            conversa_id: 0,
            // Esta aba só quer presença: dispensa o replay de mensagens e o
            // ciclo de sincronização de 0,8s do servidor.
            somente_presenca: true,
        }));

        // Reconecta depois de uma queda: o que mudou enquanto esteve fora não
        // chegou por evento nenhum.
        sincronizarPresenca();
    };

    wsPresenca.onmessage = evento => {
        try {
            const msg = JSON.parse(evento.data);
            if (msg.type === 'presence_updated') {
                marcarPresenca(msg.usuario_id, !!msg.online);
            }
        } catch (_) {
            // payload inesperado: ignora
        }
    };

    wsPresenca.onclose = () => {
        indicarConexaoPainel(false);
        clearTimeout(reconectarPresencaTimer);
        reconectarPresencaTimer = setTimeout(conectarPresencaWS, 3000);
    };
}

// ── Toast ─────────────────────────────────────
function mostrarToast(msg) {
    const t = document.createElement('div');
    t.className = 'fixed bottom-6 right-6 bg-gray-800 border border-green-500/30 text-white rounded-xl px-4 py-3 shadow-2xl z-50 text-sm flex items-center gap-2';
    t.innerHTML = `<span class="text-green-400">✓</span> ${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Init ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    await carregarSetoresNoSelect();
    configurarFiltrosUsuarios();
    carregarUsuarios();

    conectarPresencaWS();
    // Reconciliação periódica: cobre evento perdido e aba que ficou em segundo
    // plano com o socket suspenso pelo navegador.
    setInterval(sincronizarPresenca, 30000);
});
