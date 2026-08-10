// Alertas sonoros de notificação — timbres sintetizados pela Web Audio API.
// Nenhum arquivo binário: cada evento tem uma sequência própria de tons.
(function () {
    if (window.SomNotificacoes) return;

    const CHAVE_PREFERENCIA = 'som_notificacoes';
    const TTL_DEDUP_MS = 60000;   // quanto tempo uma chave de evento continua bloqueada
    const INTERVALO_MINIMO_MS = 1200; // rajada do mesmo tipo toca no máximo 1x nesse intervalo

    // { ondas: [{ freq, inicio, duracao, volume }], onda: tipo de oscilador }
    const TIMBRES = {
        geral: {
            onda: 'sine',
            volume: 0.18,
            ondas: [
                { freq: 880.00, inicio: 0.00, duracao: 0.12 },
                { freq: 1174.66, inicio: 0.13, duracao: 0.16 },
            ],
        },
        // Três tons descendentes: leitura imediata de urgência.
        chamado: {
            onda: 'triangle',
            volume: 0.26,
            ondas: [
                { freq: 987.77, inicio: 0.00, duracao: 0.13 },
                { freq: 739.99, inicio: 0.15, duracao: 0.13 },
                { freq: 587.33, inicio: 0.30, duracao: 0.24 },
            ],
        },
        // Discreto: chega com frequência e não pode incomodar.
        mensagem: {
            onda: 'sine',
            volume: 0.14,
            ondas: [
                { freq: 659.25, inicio: 0.00, duracao: 0.09 },
                { freq: 880.00, inicio: 0.08, duracao: 0.13 },
            ],
        },
        // Movimentação neutra/positiva (aprovado, classificado, resolvido…):
        // dois tons subindo, mais calmo que os avisos de item novo.
        atualizacao: {
            onda: 'sine',
            volume: 0.16,
            ondas: [
                { freq: 587.33, inicio: 0.00, duracao: 0.11 },
                { freq: 783.99, inicio: 0.11, duracao: 0.18 },
            ],
        },
        // Cancelamento/recusa: dois tons descendo, reconhecível sem olhar a tela.
        cancelamento: {
            onda: 'triangle',
            volume: 0.18,
            ondas: [
                { freq: 622.25, inicio: 0.00, duracao: 0.12 },
                { freq: 415.30, inicio: 0.12, duracao: 0.22 },
            ],
        },
        // Arpejo ascendente, distinto dos demais.
        agendamento: {
            onda: 'sine',
            volume: 0.20,
            ondas: [
                { freq: 523.25, inicio: 0.00, duracao: 0.10 },
                { freq: 659.25, inicio: 0.09, duracao: 0.10 },
                { freq: 783.99, inicio: 0.18, duracao: 0.10 },
                { freq: 1046.50, inicio: 0.27, duracao: 0.20 },
            ],
        },
    };

    let contexto = null;
    let desbloqueado = false;
    const disparosPorChave = new Map();
    const ultimoDisparoPorTipo = new Map();

    function habilitado() {
        try {
            return window.localStorage.getItem(CHAVE_PREFERENCIA) !== 'off';
        } catch (_) {
            return true;
        }
    }

    function definirHabilitado(valor) {
        try {
            window.localStorage.setItem(CHAVE_PREFERENCIA, valor ? 'on' : 'off');
        } catch (_) {
            // sem persistência (modo privado): mantém habilitado na sessão
        }
    }

    // A política de autoplay exige um gesto do usuário antes de qualquer áudio.
    // Enquanto isso não acontece, tocar() sai em silêncio — sem NotAllowedError.
    function desbloquear() {
        const Contexto = window.AudioContext || window.webkitAudioContext;
        if (!Contexto) return;

        try {
            if (!contexto) {
                contexto = new Contexto();
            }
            if (contexto.state === 'suspended') {
                contexto.resume().then(function () {
                    desbloqueado = true;
                }).catch(function () { });
            } else {
                desbloqueado = true;
            }
        } catch (_) {
            contexto = null;
        }
    }

    function registrarGestos() {
        const eventos = ['pointerdown', 'keydown', 'touchstart'];
        function aoInteragir() {
            eventos.forEach(function (nome) {
                document.removeEventListener(nome, aoInteragir);
            });
            desbloquear();
        }
        eventos.forEach(function (nome) {
            document.addEventListener(nome, aoInteragir, { passive: true });
        });
    }

    function limparDedupExpirado(agora) {
        disparosPorChave.forEach(function (quando, chave) {
            if (agora - quando > TTL_DEDUP_MS) {
                disparosPorChave.delete(chave);
            }
        });
    }

    // Um evento só soa uma vez: a chave protege contra reentrega/re-render e o
    // intervalo mínimo protege contra rajadas de eventos distintos do mesmo tipo.
    function deveTocar(tipo, chave) {
        const agora = Date.now();
        limparDedupExpirado(agora);

        if (chave) {
            if (disparosPorChave.has(chave)) return false;
            disparosPorChave.set(chave, agora);
        }

        const ultimo = ultimoDisparoPorTipo.get(tipo) || 0;
        if (agora - ultimo < INTERVALO_MINIMO_MS) return false;
        ultimoDisparoPorTipo.set(tipo, agora);

        return true;
    }

    function emitir(timbre) {
        const inicioBase = contexto.currentTime + 0.01;

        timbre.ondas.forEach(function (nota) {
            const oscilador = contexto.createOscillator();
            const ganho = contexto.createGain();
            const inicio = inicioBase + nota.inicio;
            const fim = inicio + nota.duracao;
            const pico = nota.volume || timbre.volume;

            oscilador.type = timbre.onda;
            oscilador.frequency.setValueAtTime(nota.freq, inicio);

            // Envelope curto evita o "clique" de corte abrupto.
            ganho.gain.setValueAtTime(0.0001, inicio);
            ganho.gain.exponentialRampToValueAtTime(pico, inicio + 0.015);
            ganho.gain.exponentialRampToValueAtTime(0.0001, fim);

            oscilador.connect(ganho);
            ganho.connect(contexto.destination);
            oscilador.start(inicio);
            oscilador.stop(fim + 0.02);
        });
    }

    /**
     * @param {string} tipo  geral | chamado | mensagem | agendamento
     * @param {string} [chave] identificador único do evento (ex.: 'notif:42')
     */
    function tocar(tipo, chave) {
        const timbre = TIMBRES[tipo] || TIMBRES.geral;
        if (!habilitado()) return false;
        if (!deveTocar(tipo, chave)) return false;

        // Ainda sem gesto do usuário nesta aba: silêncio, sem erro no console.
        if (!contexto || !desbloqueado || contexto.state !== 'running') {
            desbloquear();
            return false;
        }

        try {
            emitir(timbre);
            return true;
        } catch (_) {
            return false;
        }
    }

    registrarGestos();

    window.SomNotificacoes = {
        tocar: tocar,
        desbloquear: desbloquear,
        habilitado: habilitado,
        definirHabilitado: definirHabilitado,
        estaDesbloqueado: function () { return desbloqueado && !!contexto && contexto.state === 'running'; },
        tipos: Object.keys(TIMBRES),
    };
})();
