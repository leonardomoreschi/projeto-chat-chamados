(function () {
    if (window.APP_CONFIG) {
        return;
    }

    window.APP_CONFIG = {
        categorias: {
            ERP: ['Financeiro', 'Fiscal', 'Contabilidade', 'Vendas'],
            Engenharia: ['AutoCAD', 'Solidworks', 'Revisão de Projeto'],
            Infraestrutura: ['Servidor', 'Backup', 'Cloud', 'Banco de Dados'],
            Redes: ['Wi-Fi', 'Cabeamento', 'VPN'],
            Segurança: ['Antivírus', 'Firewall', 'Câmeras'],
            Hardware: ['Desktop/Notebook', 'Impressora', 'Periféricos'],
            Acessos: ['Reset de Senha', 'Novo Usuário', 'Permissões']
        },
        prioridades: {
            critica: { label: 'Crítica', color: 'bg-red-500', border: 'border-red-500' },
            alta: { label: 'Alta', color: 'bg-orange-500', border: 'border-orange-500' },
            media: { label: 'Média', color: 'bg-yellow-500', border: 'border-yellow-500' },
            baixa: { label: 'Baixa', color: 'bg-blue-500', border: 'border-blue-500' }
        },
        status: {
            aberto: 'Aberto',
            classificado: 'Classificado',
            em_andamento: 'Em andamento',
            resolvido: 'Resolvido',
            cancelado: 'Cancelado'
        },
        agendamentoStatus: {
            solicitado: 'Solicitado',
            agendado: 'Agendado',
            em_avaliacao: 'Em avaliação',
            cancelado: 'Cancelado',
            encerrado: 'Encerrado'
        }
    };
})();