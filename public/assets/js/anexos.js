// Gerenciador de anexos compartilhado (chamado de emergência e chat).
// Mantém um array de File como fonte de verdade — o FileList do <input> é
// esvaziado a cada seleção para permitir reescolher o mesmo arquivo e para que
// nenhum nome fique preso no DOM depois do envio.
(function () {
    if (window.criarGerenciadorAnexos) return;

    const MEGA = 1024 * 1024;

    // Espelha UPLOAD_MAX_SIZE (10MB por arquivo) e client_max_body_size 12M do
    // nginx — o total precisa caber no POST inteiro, com folga para os campos.
    const MAX_POR_ARQUIVO_PADRAO = 10 * MEGA;
    const MAX_TOTAL_PADRAO = 11 * MEGA;

    const EXTENSOES_IMAGEM = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];

    window.formatarTamanhoArquivo = function formatarTamanhoArquivo(bytes) {
        const valor = Number(bytes) || 0;
        if (valor < 1024) return valor + ' B';
        if (valor < MEGA) return (valor / 1024).toFixed(1).replace('.', ',') + ' KB';
        return (valor / MEGA).toFixed(1).replace('.', ',') + ' MB';
    };

    function extensaoDe(nome) {
        const partes = String(nome || '').split('.');
        return partes.length > 1 ? partes.pop().toLowerCase() : '';
    }

    function ehImagem(arquivo) {
        return /^image\//.test(arquivo.type || '') || EXTENSOES_IMAGEM.indexOf(extensaoDe(arquivo.name)) !== -1;
    }

    function chaveDe(arquivo) {
        return arquivo.name + '|' + arquivo.size + '|' + (arquivo.lastModified || 0);
    }

    function escapar(texto) {
        return window.escapeHtml ? window.escapeHtml(texto) : String(texto == null ? '' : texto);
    }

    function resolverElemento(alvo) {
        if (!alvo) return null;
        return typeof alvo === 'string' ? document.getElementById(alvo) : alvo;
    }

    /**
     * @param {Object} opcoes
     * @param {HTMLInputElement|string} opcoes.input        input[type=file] (deve ter multiple)
     * @param {HTMLElement|string}      opcoes.lista        container onde a lista é renderizada
     * @param {string[]}                opcoes.extensoes    allowlist espelhando o backend
     * @param {number}                  [opcoes.maxArquivos]
     * @param {number}                  [opcoes.maxPorArquivo]
     * @param {number}                  [opcoes.maxTotal]
     * @param {Function}                [opcoes.aoMudar]    recebe { quantidade, tamanhoTotal }
     */
    window.criarGerenciadorAnexos = function criarGerenciadorAnexos(opcoes) {
        const input = resolverElemento(opcoes.input);
        const lista = resolverElemento(opcoes.lista);
        if (!input || !lista) return null;

        const extensoes = (opcoes.extensoes || []).map(function (ext) { return ext.toLowerCase(); });
        const maxArquivos = opcoes.maxArquivos || 0;
        const maxPorArquivo = opcoes.maxPorArquivo || MAX_POR_ARQUIVO_PADRAO;
        const maxTotal = opcoes.maxTotal || MAX_TOTAL_PADRAO;
        const aoMudar = typeof opcoes.aoMudar === 'function' ? opcoes.aoMudar : null;

        // { arquivo, previewUrl } — previewUrl só existe para imagens e é
        // revogada em remover()/limpar() para não vazar memória.
        let itens = [];
        let erros = [];

        function tamanhoTotal() {
            return itens.reduce(function (soma, item) { return soma + item.arquivo.size; }, 0);
        }

        function validar(arquivo, totalAtual) {
            const ext = extensaoDe(arquivo.name);

            if (extensoes.length > 0 && extensoes.indexOf(ext) === -1) {
                return 'tipo não permitido' + (ext ? ' (.' + ext + ')' : '');
            }
            if (arquivo.size === 0) {
                return 'arquivo vazio';
            }
            if (arquivo.size > maxPorArquivo) {
                return 'excede ' + window.formatarTamanhoArquivo(maxPorArquivo) + ' por arquivo';
            }
            if (totalAtual + arquivo.size > maxTotal) {
                return 'excede o total de ' + window.formatarTamanhoArquivo(maxTotal) + ' por envio';
            }
            return null;
        }

        function adicionar(arquivosNovos) {
            const novos = Array.prototype.slice.call(arquivosNovos || []);
            erros = [];
            let total = tamanhoTotal();

            novos.forEach(function (arquivo) {
                if (maxArquivos > 0 && itens.length >= maxArquivos) {
                    erros.push({ nome: arquivo.name, motivo: 'limite de ' + maxArquivos + ' arquivos por envio' });
                    return;
                }

                const jaExiste = itens.some(function (item) { return chaveDe(item.arquivo) === chaveDe(arquivo); });
                if (jaExiste) {
                    erros.push({ nome: arquivo.name, motivo: 'já está na lista' });
                    return;
                }

                const motivo = validar(arquivo, total);
                if (motivo) {
                    erros.push({ nome: arquivo.name, motivo: motivo });
                    return;
                }

                total += arquivo.size;
                itens.push({
                    arquivo: arquivo,
                    previewUrl: ehImagem(arquivo) ? URL.createObjectURL(arquivo) : null,
                });
            });

            renderizar();
        }

        function liberarPreview(item) {
            if (item && item.previewUrl) {
                URL.revokeObjectURL(item.previewUrl);
                item.previewUrl = null;
            }
        }

        function remover(indice) {
            const item = itens[indice];
            if (!item) return;
            liberarPreview(item);
            itens.splice(indice, 1);
            erros = [];
            renderizar();
        }

        function limpar() {
            itens.forEach(liberarPreview);
            itens = [];
            erros = [];
            input.value = '';
            renderizar();
        }

        function htmlMiniatura(item) {
            if (item.previewUrl) {
                return '<img src="' + item.previewUrl + '" alt="" class="w-10 h-10 rounded-lg object-cover border border-gray-700 shrink-0">';
            }
            return '<div class="w-10 h-10 rounded-lg bg-gray-900 border border-gray-700 flex items-center justify-center shrink-0">'
                + '<svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
                + '</svg></div>';
        }

        function htmlItem(item, indice) {
            return '<li class="flex items-center gap-3 bg-gray-800 border border-gray-700 rounded-xl px-3 py-2">'
                + htmlMiniatura(item)
                + '<div class="min-w-0 flex-1">'
                + '<p class="text-xs text-gray-200 truncate" title="' + escapar(item.arquivo.name) + '">' + escapar(item.arquivo.name) + '</p>'
                + '<p class="text-[11px] text-gray-500">' + window.formatarTamanhoArquivo(item.arquivo.size) + '</p>'
                + '</div>'
                + '<button type="button" data-anexo-remover="' + indice + '" title="Remover anexo" aria-label="Remover ' + escapar(item.arquivo.name) + '"'
                + ' class="text-gray-500 hover:text-red-400 transition shrink-0 p-1 rounded-lg hover:bg-gray-700">'
                + '<svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-9 0h14"/>'
                + '</svg></button>'
                + '</li>';
        }

        function htmlErros() {
            if (erros.length === 0) return '';
            const linhas = erros.map(function (erro) {
                return '<li>' + escapar(erro.nome) + ' — ' + escapar(erro.motivo) + '</li>';
            }).join('');
            return '<div class="bg-red-500/10 border border-red-500/30 rounded-xl px-3 py-2">'
                + '<p class="text-[11px] font-semibold text-red-400">Arquivo(s) não adicionado(s):</p>'
                + '<ul class="mt-1 space-y-0.5 text-[11px] text-red-300 list-disc list-inside">' + linhas + '</ul>'
                + '</div>';
        }

        function htmlRodape() {
            const limite = maxArquivos > 0 ? ' de ' + maxArquivos : '';
            return '<div class="flex items-center justify-between gap-3 flex-wrap">'
                + '<p class="text-[11px] text-gray-500">' + itens.length + ' arquivo' + (itens.length === 1 ? '' : 's') + limite
                + ' · ' + window.formatarTamanhoArquivo(tamanhoTotal()) + '</p>'
                + '<button type="button" data-anexo-adicionar class="text-[11px] font-medium text-indigo-400 hover:text-indigo-300 transition">'
                + '+ Adicionar mais arquivos</button>'
                + '</div>';
        }

        function renderizar(silencioso) {
            if (itens.length === 0 && erros.length === 0) {
                lista.innerHTML = '';
                lista.classList.add('hidden');
            } else {
                lista.innerHTML = htmlErros()
                    + (itens.length > 0
                        ? '<ul class="space-y-2">' + itens.map(htmlItem).join('') + '</ul>' + htmlRodape()
                        : '');
                lista.classList.remove('hidden');
            }

            if (aoMudar && !silencioso) {
                aoMudar({ quantidade: itens.length, tamanhoTotal: tamanhoTotal() });
            }
        }

        input.addEventListener('change', function () {
            adicionar(input.files);
            // Zera o FileList: as seleções seguintes acumulam em vez de
            // sobrescrever, e o mesmo arquivo pode ser reescolhido após remoção.
            input.value = '';
        });

        lista.addEventListener('click', function (evento) {
            const botaoRemover = evento.target.closest('[data-anexo-remover]');
            if (botaoRemover) {
                evento.preventDefault();
                remover(parseInt(botaoRemover.getAttribute('data-anexo-remover'), 10));
                return;
            }

            const botaoAdicionar = evento.target.closest('[data-anexo-adicionar]');
            if (botaoAdicionar) {
                evento.preventDefault();
                input.click();
            }
        });

        renderizar(true);

        return {
            arquivos: function () { return itens.map(function (item) { return item.arquivo; }); },
            quantidade: function () { return itens.length; },
            vazio: function () { return itens.length === 0; },
            tamanhoTotal: tamanhoTotal,
            adicionar: adicionar,
            remover: remover,
            limpar: limpar,
            abrirSeletor: function () { input.click(); },
            anexarEm: function (formData, campo) {
                itens.forEach(function (item) { formData.append(campo, item.arquivo); });
                return formData;
            },
        };
    };
})();
