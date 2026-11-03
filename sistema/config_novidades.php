<?php
/**
 * CATALOGO DE NOVIDADES DO SISTEMA FINANCEIRO (CHANGELOG AMIGAVEL)
 *
 * REGRA: toda alteracao registra uma entrada NO TOPO, espelhando versao/build de config_versao.php.
 * A hora deve ser a real da release (America/Sao_Paulo); nunca estimar.
 */

if (!function_exists('sistema_obter_novidades')) {
    function sistema_obter_novidades()
    {
        return array(
            array(
                'versao' => '1.14.1', 'build' => '20261103.2', 'data' => '03/11/2026', 'hora' => '09:20',
                'titulo' => 'Projeção: totais do mês atual e média sem ajuste de saldo',
                'destaque' => 'No detalhe mês a mês, as receitas, despesas e faturas do mês atual agora somam o que já foi recebido ou pago e o que ainda falta.',
                'categorias' => array(
                    array('nome' => 'Correções', 'icone' => 'fa-bug', 'cor' => '#dc2626', 'itens' => array(
                        array('titulo' => 'Média de despesas sem o ajuste de saldo', 'descricao' => 'O reajuste de saldo de conta (como o saldo inicial zerado da carga) deixou de entrar na média de despesas e receitas avulsas da projeção, que vinha somando cerca de R$ 1.900 por mês a mais de despesa.'),
                        array('titulo' => 'Receita do mês atual não some mais', 'descricao' => 'O salário já recebido deixava a receita do mês zerada e o resultado negativo. Agora o mês mostra o total (com o valor já realizado ao passar o mouse); o saldo continua partindo do saldo de hoje.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.14.0', 'build' => '20261103.1', 'data' => '03/11/2026', 'hora' => '08:55',
                'titulo' => 'Fatura importada passa a mandar nas faturas seguintes',
                'destaque' => 'Ao importar a fatura do banco, os reajustes manuais dos meses seguintes saem e as faturas futuras mostram as parcelas que o banco informa.',
                'categorias' => array(
                    array('nome' => 'Novidades', 'icone' => 'fa-refresh', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Reajustes manuais saem sozinhos', 'descricao' => 'Ao confirmar a importação de uma fatura, o Reajuste de fatura das faturas seguintes ainda não pagas é removido, pois o que vale agora é o que o banco informou.'),
                        array('titulo' => 'Parcelas futuras espelham o banco', 'descricao' => 'As parcelas que faltam das compras parceladas da fatura passam a constar nas faturas seguintes, e as parcelas projetadas de compras que o banco não traz mais (estorno, cancelamento) são removidas.'),
                    )),
                    array('nome' => 'Melhorias', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Aviso do que foi limpo', 'descricao' => 'A mensagem depois da importação informa quantas parcelas e quantos reajustes (e o valor deles) foram removidos.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.8', 'build' => '20261101.1', 'data' => '01/11/2026', 'hora' => '11:23',
                'titulo' => 'Notificações identificadas por aplicativo',
                'destaque' => 'Os avisos mostram o nome do aplicativo e abrem seu endereço com a porta correta.',
                'categorias' => array(array('nome' => 'Aplicativo e notificações', 'icone' => 'fa-mobile', 'cor' => '#2563eb', 'itens' => array(
                    array('titulo' => 'Avisos separados', 'descricao' => 'Nome, símbolo e destino pertencem a este aplicativo. No Android, desative Abrir links compatíveis para evitar que um PWA capture páginas de outros serviços do mesmo domínio.'),
                ))),
            ),
            array(
                'versao' => '1.13.7', 'build' => '20261031.5', 'data' => '31/10/2026', 'hora' => '16:39',
                'titulo' => 'Lotes e movimentações dentro do painel do investimento',
                'destaque' => 'Os lotes, a projeção de 12 meses e o histórico de movimentações agora abrem no próprio painel do investimento, sem sair da lista.',
                'categorias' => array(
                    array('nome' => 'Novidades', 'icone' => 'fa-list', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Duas visões no mesmo painel', 'descricao' => 'O botão "Lotes e movimentações" troca o resumo (KPIs e gráficos) pelos lotes com IR e IOF, pela projeção e pelas movimentações, e "Voltar ao resumo" volta.'),
                        array('titulo' => 'Excluir e registrar sem sair', 'descricao' => 'Excluir uma movimentação ou registrar um rendimento reabre o painel do mesmo investimento na aba de movimentações.'),
                    )),
                    array('nome' => 'Melhorias', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Tela mais limpa', 'descricao' => 'A seção de detalhe que aparecia abaixo da tabela de Investimentos foi removida; tudo está no painel.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.6', 'build' => '20261031.4', 'data' => '31/10/2026', 'hora' => '16:35',
                'titulo' => 'Títulos dos indicadores sem corte',
                'destaque' => 'Os títulos longos dos indicadores (KPIs) agora quebram em mais de uma linha em vez de terminar em reticências.',
                'categorias' => array(
                    array('nome' => 'Correções', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Texto completo nos indicadores', 'descricao' => 'Vale para todas as telas, como "Rendimento líquido do ano" em Investimentos, principalmente no celular.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.5', 'build' => '20261031.3', 'data' => '31/10/2026', 'hora' => '16:26',
                'titulo' => 'Painel de KPIs e gráficos por investimento',
                'destaque' => 'Clique no nome do investimento ou no novo botão de gráfico na coluna Ações para ver os indicadores e os gráficos só daquele investimento.',
                'categorias' => array(
                    array('nome' => 'Novidades', 'icone' => 'fa-pie-chart', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'KPIs do investimento', 'descricao' => 'Saldo bruto e líquido, rendimento acumulado, do mês e do ano, bruto e líquido de imposto.'),
                        array('titulo' => 'Três gráficos', 'descricao' => 'Evolução do saldo (bruto, líquido e valor aplicado), rendimento por mês e de onde vem o saldo (aplicado, rendimento e imposto estimado). Tudo até hoje, sem projeção.'),
                        array('titulo' => 'Mais claro de navegar', 'descricao' => 'O nome do investimento agora abre este painel; os lotes e o histórico de movimentações ficam no botão "Lotes e movimentações" dentro dele.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.4', 'build' => '20261031.2', 'data' => '31/10/2026', 'hora' => '16:19',
                'titulo' => 'Rendimento do mês e do ano em Investimentos',
                'destaque' => 'Quatro novos indicadores mostram quanto seus investimentos renderam no mês e no ano, bruto e líquido de imposto, sempre até hoje e sem projeção.',
                'categorias' => array(
                    array('nome' => 'Novidades', 'icone' => 'fa-line-chart', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Bruto e líquido, mês e ano', 'descricao' => 'Aplicar e resgatar não conta como rendimento: só o que o saldo ganhou além do dinheiro que entrou e saiu. O líquido desconta o IR e o IOF pagos nos resgates e o estimado sobre o que segue aplicado.'),
                        array('titulo' => 'Só o que já aconteceu', 'descricao' => 'Aplicações e resgates agendados para depois de hoje e dias futuros não entram no cálculo.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.3', 'build' => '20261031.1', 'data' => '31/10/2026', 'hora' => '15:56',
                'titulo' => 'Resgate com a conta de destino obrigatória',
                'destaque' => 'No resgate de investimento você agora precisa escolher a conta que recebe o valor, para o resgate sempre aparecer nos lançamentos.',
                'categorias' => array(
                    array('nome' => 'Correções', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Resgate que não aparecia nos lançamentos', 'descricao' => 'A conta de destino vinha como "sem crédito em conta" e o resgate ficava só no investimento. Agora o campo começa vazio e é obrigatório; "sem crédito em conta" continua disponível, mas como escolha explícita.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.2', 'build' => '20261029.4', 'data' => '29/10/2026', 'hora' => '14:33',
                'titulo' => 'Histórico importado fora da média da projeção',
                'destaque' => 'Importações marcadas como histórico (planilhas antigas) continuam em lançamentos, faturas e relatórios, mas não entram mais na média de gastos e receitas da Projeção.',
                'categorias' => array(
                    array('nome' => 'Correções', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Média da projeção sem dupla contagem', 'descricao' => 'O histórico importado não traz a marca de série (salário, faturas) e fazia a média contar em dobro o que a projeção já agenda. Agora ele fica de fora da média.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.1', 'build' => '20261029.3', 'data' => '29/10/2026', 'hora' => '11:47',
                'titulo' => 'Manter conectado no login',
                'destaque' => 'A tela de entrada ganhou a opção "Manter conectado": a sessão não expira e dura para sempre no aparelho, até você clicar em Sair.',
                'categorias' => array(
                    array('nome' => 'Novidades', 'icone' => 'fa-lock', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Sessão que não expira', 'descricao' => 'Marcada por padrão no login. Sem marcar, a sessão continua expirando após 8 horas de inatividade. Só o botão Sair encerra uma sessão mantida. Evite usar em computador compartilhado.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.13.0', 'build' => '20261029.2', 'data' => '29/10/2026', 'hora' => '10:44',
                'titulo' => 'Notificações push no celular e no navegador',
                'destaque' => 'Os avisos do dia (contas a pagar e a receber, atrasos e fatura fechada) chegam ao celular e ao navegador mesmo com o aplicativo fechado.',
                'categorias' => array(
                    array('nome' => 'Novidades', 'icone' => 'fa-bell', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Aviso com o aplicativo fechado', 'descricao' => 'Em Meu perfil, ative as notificações em cada aparelho. Todo dia às 8h o sistema envia os avisos novos; você também pode enviar um teste e desativar o aparelho quando quiser.'),
                        array('titulo' => 'Independente do ERP', 'descricao' => 'O Financeiro tem as próprias chaves e a própria lista de aparelhos, e o título dos avisos começa com "Financeiro". Nada se mistura com as notificações do ERP.'),
                        array('titulo' => 'Compatível com iPhone', 'descricao' => 'Usa o formato atual de Web Push, aceito pelo Safari. No iPhone e no iPad é preciso instalar o aplicativo na tela inicial (iOS 16.4 ou mais novo).'),
                    )),
                ),
            ),
            array(
                'versao' => '1.12.1', 'build' => '20261029.1', 'data' => '29/10/2026', 'hora' => '10:30',
                'titulo' => 'Importação reconhece o que já foi lançado, com ajuda da IA',
                'destaque' => 'Na fatura e no extrato, o item só é tido como já lançado se valor, parcelas (n/total) e nome conferirem; quando o nome difere, a IA decide.',
                'categorias' => array(
                    array('nome' => 'Correções', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Parcela já importada detectada', 'descricao' => 'A comparação ignora o "•••• 1234" no início da descrição, então itens como Pag*Tibia 2/2 aparecem como "Já existe".'),
                        array('titulo' => 'Nome parecido e IA como desempate', 'descricao' => 'Fatura: mesmo cartão, parcela n/total e valor, com nome semelhante. Extrato: mesma conta, valor e data próxima. Se só o nome diferir, uma consulta única à IA decide; sem IA, o item vira "possível duplicado".'),
                    )),
                ),
            ),
            array(
                'versao' => '1.12.0', 'build' => '20261027.1', 'data' => '27/10/2026', 'hora' => '15:38',
                'titulo' => 'Revisão geral: totais corretos, mais segurança e acessibilidade',
                'destaque' => 'Pagamento parcial de fatura não duplica mais a despesa, os totais de Lançamentos batem com o Dashboard e "1.500" é lido como mil e quinhentos.',
                'categorias' => array(
                    array('nome' => 'Cálculos', 'icone' => 'fa-calculator', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Saldo restante não vira despesa nova', 'descricao' => 'Ao pagar só parte da fatura, o que sobra vai para a fatura seguinte mas não conta de novo em despesas, categorias e médias da projeção.'),
                        array('titulo' => 'Mesmos totais em Lançamentos e no Dashboard', 'descricao' => 'Sem filtro de conta, o pagamento da fatura não entra nas despesas (as compras do cartão já contam). Filtrando pela conta, o pagamento aparece, pois saiu dela.'),
                        array('titulo' => 'Valores digitados sem vírgula', 'descricao' => '"1.500" agora vale R$ 1.500,00 (antes virava R$ 1,50). Taxas e percentuais continuam aceitando o ponto como decimal.'),
                        array('titulo' => 'Projeção sem contar duas vezes', 'descricao' => 'O que já foi pago ou comprado no cartão neste mês sai da média do mês, em vez de ser somado de novo.'),
                        array('titulo' => 'Resgate pelo valor líquido', 'descricao' => 'O cálculo do bruto necessário também fecha em lotes recentes com IOF alto, e ficou muito mais rápido.'),
                    )),
                    array('nome' => 'Correções', 'icone' => 'fa-wrench', 'cor' => '#d97706', 'itens' => array(
                        array('titulo' => 'Editar investimento não troca mais o imposto', 'descricao' => 'Abrir um investimento para editar mantinha o produto, mas podia trocar o indexador e a tributação já salvos. Agora só muda quando você escolhe outro produto.'),
                        array('titulo' => 'Excluir o fim de uma série fixa em lote', 'descricao' => 'As ocorrências removidas não voltam mais sozinhas: a série termina onde você cortou.'),
                        array('titulo' => 'Categoria do tipo certo', 'descricao' => 'Despesa só aceita categoria de despesa e receita só de receita, também nas ações em lote.'),
                        array('titulo' => 'Meses inválidos na barra de endereço', 'descricao' => 'Um mês como 2026-13 não derruba mais as telas.'),
                        array('titulo' => 'Pagamento parcial visível', 'descricao' => 'A fatura mostra quanto foi pago e que o restante foi para a fatura seguinte.'),
                        array('titulo' => 'Usuários', 'descricao' => 'Não dá mais para inativar o próprio usuário editando, e a regra de "sempre um administrador" vale por espaço de dados.'),
                    )),
                    array('nome' => 'Segurança', 'icone' => 'fa-shield', 'cor' => '#dc2626', 'itens' => array(
                        array('titulo' => 'Bloqueio de tentativas de login', 'descricao' => 'Depois de 5 senhas erradas, o acesso daquele e-mail e aparelho fica bloqueado por 15 minutos. O tempo de resposta não revela se o e-mail existe.'),
                        array('titulo' => 'Sessão', 'descricao' => 'Cookie com SameSite e, atrás do HTTPS, Secure; a sessão dura 8 horas de inatividade (antes ~24 min).'),
                        array('titulo' => 'Servidor', 'descricao' => 'Erros não aparecem mais na tela; documentação e código de apoio não são mais servidos; cabeçalhos de segurança. Vale após reconstruir o contêiner.'),
                        array('titulo' => 'Leitura de arquivos', 'descricao' => 'A chave da IA vai no cabeçalho (não na URL) e planilhas gigantes são recusadas.'),
                    )),
                    array('nome' => 'Desempenho', 'icone' => 'fa-tachometer', 'cor' => '#0284c7', 'itens' => array(
                        array('titulo' => 'Dashboard e projeção', 'descricao' => 'Os investimentos são simulados uma vez por carga (antes, 6 vezes), e a verificação das séries fixas roda no máximo 1 vez por hora por sessão, sem duplicar quando duas abas abrem juntas.'),
                        array('titulo' => 'Banco Central fora do ar', 'descricao' => 'A atualização automática do CDI tenta uma vez, por poucos segundos, e a tela não fica mais presa.'),
                        array('titulo' => 'Importar extrato', 'descricao' => 'Consultas por linha viraram uma por montagem e o pareamento de transferências é muito mais rápido em extratos grandes.'),
                    )),
                    array('nome' => 'Acessibilidade e visual', 'icone' => 'fa-universal-access', 'cor' => '#7c3aed', 'itens' => array(
                        array('titulo' => 'Janelas e botões', 'descricao' => 'Janelas anunciadas como diálogos, foco preso dentro delas e devolvido ao fechar; botões só com ícone ganham nome; gráficos têm descrição; foco visível no teclado.'),
                        array('titulo' => 'Contraste', 'descricao' => 'Botões verdes, vermelhos e laranja e textos de aviso ficaram escuros o bastante para leitura (mínimo 4,5:1).'),
                        array('titulo' => 'Rótulos', 'descricao' => 'Dica nos cartões "Despesas do mês" e "Cartão de crédito" do Dashboard; "Rendimento bruto não resgatado" em Investimentos; fim do link do Copilot do ERP nos avisos.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.11.5', 'build' => '20261026.27', 'data' => '26/10/2026', 'hora' => '21:02',
                'titulo' => 'Situação padronizada nos lançamentos',
                'destaque' => 'Todas as linhas usam as mesmas palavras e o mesmo visual: Pendente, Paga, Recebida ou Realizada.',
                'categorias' => array(
                    array('nome' => 'Lançamentos', 'icone' => 'fa-exchange', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Mesma linguagem', 'descricao' => 'O pagamento de fatura e as compras de cartão agora mostram "Paga" (ou "Pendente" enquanto a fatura não foi paga), igual aos demais lançamentos; sumiram "Fatura paga" e "No cartão".'),
                    )),
                ),
            ),
            array(
                'versao' => '1.11.4', 'build' => '20261026.26', 'data' => '26/10/2026', 'hora' => '20:52',
                'titulo' => 'Todas as faturas em modal e contas em cards',
                'destaque' => 'Cada cartão mostra a última fatura paga e as abertas; o histórico completo abre num modal. A tela de contas também virou cards.',
                'categorias' => array(
                    array('nome' => 'Cartões', 'icone' => 'fa-credit-card', 'cor' => '#f472b6', 'itens' => array(
                        array('titulo' => 'Lista enxuta', 'descricao' => 'No card aparece só a última fatura paga e as faturas abertas (até 5 linhas).'),
                        array('titulo' => 'Todas as faturas no modal', 'descricao' => '"Ver todas as faturas" abre uma janela com a listagem completa, inclusive as pagas antigas, com as mesmas ações.'),
                    )),
                    array('nome' => 'Contas', 'icone' => 'fa-university', 'cor' => '#2563eb', 'itens' => array(
                        array('titulo' => 'Contas em cards', 'descricao' => 'Cada conta é um card (saldo atual, previsto e inicial, com as ações), em grade de duas colunas; as inativas ficam embaixo.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.11.3', 'build' => '20261026.25', 'data' => '26/10/2026', 'hora' => '20:48',
                'titulo' => 'Cards de cartões do mesmo tamanho',
                'destaque' => 'Os cartões lado a lado têm a mesma altura e mostram as 5 faturas mais relevantes, com as demais atrás de um clique.',
                'categorias' => array(
                    array('nome' => 'Cartões', 'icone' => 'fa-credit-card', 'cor' => '#f472b6', 'itens' => array(
                        array('titulo' => 'Altura uniforme', 'descricao' => 'Os cards de uma mesma linha ficam com a mesma altura; o rodapé "Mostrar todas as faturas" fica sempre embaixo.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.11.2', 'build' => '20261026.24', 'data' => '26/10/2026', 'hora' => '20:43',
                'titulo' => 'Dashboard projetado por mês e gráfico de patrimônio',
                'destaque' => 'Ao navegar para um mês futuro, saldo em contas e investimentos mostram o valor projetado no fim dele. O gráfico agora mostra o patrimônio.',
                'categorias' => array(
                    array('nome' => 'Dashboard', 'icone' => 'fa-dashboard', 'cor' => '#2563eb', 'itens' => array(
                        array('titulo' => 'Mês futuro projetado', 'descricao' => 'Saldo em contas e investimentos do mês escolhido vêm da projeção (a sobra de cada mês é investida).'),
                        array('titulo' => 'Gráfico de patrimônio', 'descricao' => 'Em vez do saldo em contas, que fica perto de zero, o gráfico mostra o patrimônio (contas + investimentos), bruto e líquido, e o saldo em contas.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.11.1', 'build' => '20261026.23', 'data' => '26/10/2026', 'hora' => '20:40',
                'titulo' => 'Saldo e investimentos lado a lado no dashboard',
                'destaque' => 'Os indicadores de saldo em contas e de investimentos ficam juntos no início do dashboard.',
                'categorias' => array(
                    array('nome' => 'Dashboard', 'icone' => 'fa-dashboard', 'cor' => '#2563eb', 'itens' => array(
                        array('titulo' => 'Indicadores agrupados', 'descricao' => 'Saldo em contas e Investimentos aparecem lado a lado, antes de receitas, despesas, resultado e cartão.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.11.0', 'build' => '20261026.22', 'data' => '26/10/2026', 'hora' => '20:25',
                'titulo' => 'Usuários e permissões, meu perfil, notificações, aplicativo e celular',
                'destaque' => 'Gestão de usuários com papéis e permissões por tela, Meu perfil, sino de avisos do dia, instalação como aplicativo e layout para celular e tablet.',
                'categorias' => array(
                    array('nome' => 'Acesso', 'icone' => 'fa-users', 'cor' => '#7c3aed', 'itens' => array(
                        array('titulo' => 'Usuários e permissões', 'descricao' => 'Administradores criam usuários (administrador, editor, leitor ou personalizado) que enxergam os mesmos dados, com acesso por tela: sem acesso, só ver ou ver e editar.'),
                        array('titulo' => 'Meu perfil', 'descricao' => 'Cada pessoa altera nome, e-mail e senha, vê as próprias permissões e ativa as notificações.'),
                        array('titulo' => 'Somente leitura', 'descricao' => 'Quem só pode ver não vê os botões de alterar, e o servidor também bloqueia qualquer gravação.'),
                    )),
                    array('nome' => 'Avisos', 'icone' => 'fa-bell', 'cor' => '#f59e0b', 'itens' => array(
                        array('titulo' => 'Sino de notificações', 'descricao' => 'Avisa as contas a pagar e a receber do dia, os lançamentos atrasados e, no dia seguinte ao fechamento, que a fatura do cartão fechou e pode ser importada.'),
                        array('titulo' => 'Notificação do navegador', 'descricao' => 'Opcional, ativada em Meu perfil; aparece quando você abre o aplicativo.'),
                    )),
                    array('nome' => 'Aplicativo e telas', 'icone' => 'fa-mobile', 'cor' => '#0d9488', 'itens' => array(
                        array('titulo' => 'Instalável (PWA)', 'descricao' => 'Dá para instalar no celular e no computador, com ícone próprio, e uma página avisa quando estiver sem conexão. Fica restrito a esta pasta e não interfere em outros serviços.'),
                        array('titulo' => 'Celular e tablet', 'descricao' => 'Indicadores em duas colunas, filtros que quebram linha, listas mais compactas e botões maiores ao toque.'),
                        array('titulo' => 'Dashboard por mês', 'descricao' => 'Setas e seletor para ver meses anteriores e futuros.'),
                        array('titulo' => 'Projeção com patrimônio', 'descricao' => 'Os indicadores mostram patrimônio (contas + investimentos), investimentos e conta corrente separadamente.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.10.0', 'build' => '20261026.21', 'data' => '26/10/2026', 'hora' => '20:16',
                'titulo' => 'Confirmação com modal, cartões em grade e pagamento parcial',
                'destaque' => 'Situação no início da linha, botão de confirmar com confirmação, cartões/contas/investimentos com inativos embaixo e pagamento parcial de fatura.',
                'categorias' => array(
                    array('nome' => 'Lançamentos', 'icone' => 'fa-exchange', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Situação no início', 'descricao' => 'A situação (pendente, paga, recebida) é a primeira coluna e deixou de ser clicável.'),
                        array('titulo' => 'Confirmar e reverter com modal', 'descricao' => 'Um botão ao lado de editar e excluir confirma (ou reverte) o recebimento/pagamento, sempre pedindo confirmação.'),
                    )),
                    array('nome' => 'Cartões e faturas', 'icone' => 'fa-credit-card', 'cor' => '#f472b6', 'itens' => array(
                        array('titulo' => 'Cards em duas colunas', 'descricao' => 'Cartões em grade (2 por linha quando a tela comporta); inativos numa área recolhida embaixo, assim como contas e investimentos.'),
                        array('titulo' => 'Faturas em ordem crescente', 'descricao' => 'A lista começa na fatura paga mais recente antes da primeira em aberto.'),
                        array('titulo' => 'Excluir só sem histórico', 'descricao' => 'Cartão com compras ou faturas pagas no passado só pode ser inativado. Inativar exclui os itens ainda a vencer e pede confirmação.'),
                        array('titulo' => 'Pagamento parcial', 'descricao' => 'Ao pagar a fatura dá para informar um valor menor; o que sobrar vai para a próxima fatura como "Saldo restante".'),
                    )),
                    array('nome' => 'Layout', 'icone' => 'fa-bars', 'cor' => '#64748b', 'itens' => array(
                        array('titulo' => 'Menu recolhido', 'descricao' => 'O menu lateral recolhido agora mostra só os ícones, como no ERP, sem quebrar.'),
                        array('titulo' => 'Confirmações', 'descricao' => 'Inativar contas, cartões e investimentos pede confirmação.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.9.3', 'build' => '20261026.20', 'data' => '26/10/2026', 'hora' => '19:19',
                'titulo' => 'Tela de lançamentos mais limpa',
                'destaque' => 'Colunas com largura adequada (uma linha por lançamento) e as ações da lista reunidas no cabeçalho.',
                'categorias' => array(
                    array('nome' => 'Lançamentos', 'icone' => 'fa-exchange', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Lista legível', 'descricao' => 'A descrição ganhou espaço e deixou de quebrar em várias linhas; conta e valor ficam alinhados.'),
                        array('titulo' => 'Ações no cabeçalho', 'descricao' => 'Visualização, Importar extrato e Novo lançamento ficam juntos no topo da lista; sumiu a faixa vazia entre os filtros e a lista.'),
                        array('titulo' => 'Saldo do dia à direita', 'descricao' => 'A linha de saldo previsto fica alinhada à direita, junto dos valores.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.9.2', 'build' => '20261026.19', 'data' => '26/10/2026', 'hora' => '19:16',
                'titulo' => 'Tela de cartões redesenhada',
                'destaque' => 'Um único botão Importar fatura no topo, resumo geral das faturas e limites, e cada cartão num cartão compacto.',
                'categorias' => array(
                    array('nome' => 'Cartões', 'icone' => 'fa-credit-card', 'cor' => '#f472b6', 'itens' => array(
                        array('titulo' => 'Resumo no topo', 'descricao' => 'Faturas em aberto, próximo vencimento, limite utilizado e disponível de todos os cartões ativos.'),
                        array('titulo' => 'Um botão de importar', 'descricao' => 'Importar fatura fica só no topo, ao lado de Novo cartão; o cartão é escolhido na importação.'),
                        array('titulo' => 'Cartão compacto', 'descricao' => 'Fatura atual, limite e ações em ícones; a lista mostra as 6 faturas mais recentes e abre as demais com um clique.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.9.1', 'build' => '20261026.18', 'data' => '26/10/2026', 'hora' => '19:09',
                'titulo' => 'Importações dentro das telas e fim da planilha',
                'destaque' => 'A importação de planilha foi retirada. Importar fatura e importar extrato saíram do menu e viraram botões nas telas de Cartões e Lançamentos.',
                'categorias' => array(
                    array('nome' => 'Importação', 'icone' => 'fa-upload', 'cor' => '#0d9488', 'itens' => array(
                        array('titulo' => 'Importar fatura', 'descricao' => 'Botão no topo da tela Cartões e faturas (e em cada cartão).'),
                        array('titulo' => 'Importar extrato', 'descricao' => 'Botão na tela Lançamentos, ao lado de Novo lançamento.'),
                        array('titulo' => 'Planilha retirada', 'descricao' => 'A tela de importar planilha do Mobills não existe mais.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.9.0', 'build' => '20261026.17', 'data' => '26/10/2026', 'hora' => '19:00',
                'titulo' => 'Agrupar lançamentos do cartão',
                'destaque' => 'Nova opção de visualização mostra uma linha por fatura (Fatura do cartão X) com a soma dos itens. O bloco de faturas do período saiu da tela.',
                'categorias' => array(
                    array('nome' => 'Lançamentos', 'icone' => 'fa-exchange', 'cor' => '#16a34a', 'itens' => array(
                        array('titulo' => 'Agrupar lançamentos do cartão', 'descricao' => 'Em Opções de visualização: cada fatura vira uma linha, com a soma dos itens do período filtrado, mesmo com vários meses no filtro.'),
                        array('titulo' => 'Sem bloco de faturas', 'descricao' => 'A caixa "Faturas de cartão com vencimento no período" foi removida: os itens de cartão já aparecem na lista.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.8.1', 'build' => '20261026.16', 'data' => '26/10/2026', 'hora' => '18:57',
                'titulo' => 'Cartões inativos em área própria',
                'destaque' => 'Na tela de cartões, os cartões inativos ficam numa área separada, recolhida, abaixo dos ativos.',
                'categorias' => array(
                    array('nome' => 'Cartões', 'icone' => 'fa-credit-card', 'cor' => '#f472b6', 'itens' => array(
                        array('titulo' => 'Área de inativos', 'descricao' => 'Cartões inativos saem da lista principal e aparecem em "Cartões inativos", que abre com um clique. Dá para reativar por lá.'),
                    )),
                ),
            ),
            array(
                'versao' => '1.8.0', 'build' => '20261026.15', 'data' => '26/10/2026', 'hora' => '18:55',
                'titulo' => 'Receita e despesa fixa sem fim; exclusão de séries',
                'destaque' => 'Fixa não pede mais quantidade: repete até você excluir. Ao excluir lançamentos de uma série, você escolhe o que apagar.',
                'categorias' => array(
                    array(
                        'nome' => 'Lançamentos',
                        'icone' => 'fa-exchange',
                        'cor' => '#16a34a',
                        'itens' => array(
                            array('titulo' => 'Fixa sem quantidade', 'descricao' => 'Receita ou despesa fixa não tem fim: o sistema mantém as próximas ocorrências (24 meses à frente) e gera mais com o passar do tempo.'),
                            array('titulo' => 'Escolha ao excluir', 'descricao' => 'Para lançamentos fixos, parcelados ou repetidos: só este, este e os próximos ainda não confirmados, ou todos da série, inclusive os confirmados.'),
                            array('titulo' => 'Fim da série', 'descricao' => 'Excluir "este e os próximos" encerra uma série fixa: ela não volta a ser gerada.'),
                        ),
                    ),
                    array(
                        'nome' => 'Faturas',
                        'icone' => 'fa-credit-card',
                        'cor' => '#f472b6',
                        'itens' => array(
                            array('titulo' => 'Faturas pagas de outubro (Itaú)', 'descricao' => 'As faturas pagas do Black e da LUIZA voltam a aparecer na listagem do cartão.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.7.0', 'build' => '20261026.14', 'data' => '26/10/2026', 'hora' => '18:49',
                'titulo' => 'Tudo é considerado e fatura Bradesco',
                'destaque' => 'A função Ignorar foi retirada: toda transação conta nos saldos e totais. Faturas do Bradesco/Bradescard são lidas pela IA.',
                'categorias' => array(
                    array(
                        'nome' => 'Simplificação',
                        'icone' => 'fa-scissors',
                        'cor' => '#64748b',
                        'itens' => array(
                            array('titulo' => 'Sem transações ignoradas', 'descricao' => 'Retirados o campo Ignorar, as ações em lote Ignorar/Considerar e a etiqueta "ignorada". Tudo entra em saldos, faturas e totais.'),
                        ),
                    ),
                    array(
                        'nome' => 'Importação de fatura',
                        'icone' => 'fa-file-pdf-o',
                        'cor' => '#f87171',
                        'itens' => array(
                            array('titulo' => 'Fatura Bradesco / Bradescard', 'descricao' => 'A IA lê a tabela de lançamentos, os créditos com sinal e as parcelas, e deixa de fora o "pagamento recebido" da fatura anterior.'),
                            array('titulo' => 'Cartão pelo nome impresso', 'descricao' => 'O cartão é sugerido pelo nome que está na fatura (ex.: Amazon Mastercard).'),
                            array('titulo' => 'Parcelas restantes corretas', 'descricao' => 'Se a fatura já traz várias parcelas da mesma compra, só as que vêm depois da última presente são lançadas nos meses seguintes.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.6.3', 'build' => '20261026.13', 'data' => '26/10/2026', 'hora' => '18:36',
                'titulo' => 'Resgate pelo valor líquido, com impostos editáveis',
                'destaque' => 'Você informa quanto deve cair na conta; o imposto sai do próprio investimento e pode ser corrigido à mão.',
                'categorias' => array(
                    array(
                        'nome' => 'Investimentos',
                        'icone' => 'fa-bar-chart',
                        'cor' => '#d97706',
                        'itens' => array(
                            array('titulo' => 'Valor líquido na conta', 'descricao' => 'Resgatar R$ 30 credita exatamente R$ 30 na conta; o investimento é debitado de R$ 30 mais IR e IOF.'),
                            array('titulo' => 'IR e IOF editáveis', 'descricao' => 'Se o banco cobrar impostos diferentes do calculado, corrija os campos e o valor debitado do investimento é refeito.'),
                            array('titulo' => 'Resgate total', 'descricao' => 'Também aceita IR e IOF informados por você.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.6.2', 'build' => '20261026.12', 'data' => '26/10/2026', 'hora' => '18:28',
                'titulo' => 'Saldo previsto do dia inclui os investimentos',
                'destaque' => 'Em Lançamentos, o saldo previsto final do dia mostra as contas, os investimentos (bruto e líquido de impostos) e o total.',
                'categorias' => array(
                    array(
                        'nome' => 'Lançamentos',
                        'icone' => 'fa-exchange',
                        'cor' => '#16a34a',
                        'itens' => array(
                            array('titulo' => 'Investimentos no saldo do dia', 'descricao' => 'Cada dia mostra: contas, investimentos (e o líquido depois de IR e IOF) e o total, com o líquido de impostos.'),
                            array('titulo' => 'Rendimento até a data', 'descricao' => 'Para dias futuros, os investimentos rendem pelo CDI e consideram aplicações e resgates já agendados.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.6.1', 'build' => '20261026.11', 'data' => '26/10/2026', 'hora' => '18:20',
                'titulo' => 'Projeção investe a sobra de cada mês',
                'destaque' => 'Tudo que sobra no mês vai para investimento a 100% do CDI e rende nos meses seguintes. O aviso de conta negativa só aparece no dia exato.',
                'categorias' => array(
                    array(
                        'nome' => 'Projeção',
                        'icone' => 'fa-line-chart',
                        'cor' => '#16a34a',
                        'itens' => array(
                            array('titulo' => 'Sobra do mês vira investimento', 'descricao' => 'Receitas − despesas − faturas de cada mês são investidas no fim do mês, a 100% do CDI (você pode mudar o percentual), e os aportes passam a render.'),
                            array('titulo' => 'Aviso no dia exato', 'descricao' => 'O alerta de conta negativa mostra a data em que o saldo das contas fica abaixo de zero, e quanto há em investimentos para cobrir com um resgate. Sem dia negativo, sem aviso.'),
                            array('titulo' => 'Faturas no dia do vencimento', 'descricao' => 'A simulação diária considera cada fatura e cada lançamento na sua data.'),
                            array('titulo' => 'Tabela com a sobra investida', 'descricao' => 'Nova coluna mostra quanto foi investido em cada mês.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.6.0', 'build' => '20261026.10', 'data' => '26/10/2026', 'hora' => '17:58',
                'titulo' => 'Saldo bruto ou líquido no reajuste e extratos de investimento',
                'destaque' => 'No reajuste do investimento você escolhe se informa o saldo bruto (como o banco mostra) ou o líquido. Extratos de custódia e data de corte na importação.',
                'categorias' => array(
                    array(
                        'nome' => 'Investimentos',
                        'icone' => 'fa-bar-chart',
                        'cor' => '#d97706',
                        'itens' => array(
                            array('titulo' => 'Reajuste: bruto ou líquido', 'descricao' => 'Informe o saldo bruto, como BV e Nubank mostram (o imposto só sai no resgate), ou o líquido; neste caso o sistema calcula o bruto equivalente. O ajuste é sempre lançado sobre o saldo bruto.'),
                            array('titulo' => 'Aplicação e resgate só com contas bancárias', 'descricao' => 'As contas do tipo investimento não aparecem mais como origem da aplicação nem destino do resgate.'),
                            array('titulo' => 'Posição inicial de investimento existente', 'descricao' => 'Ao cadastrar o que você já tinha, o saldo bruto fica exatamente o informado e o imposto estimado fica próximo ao do banco.'),
                        ),
                    ),
                    array(
                        'nome' => 'Importação de extratos',
                        'icone' => 'fa-file-text-o',
                        'cor' => '#0d9488',
                        'itens' => array(
                            array('titulo' => 'Extrato de custódia', 'descricao' => 'O extrato que mostra só o saldo de cada caixinha ou produto é lido como posição: reajusta o investimento existente ou cria um novo.'),
                            array('titulo' => 'Data de corte', 'descricao' => 'Movimentações anteriores à data escolhida são descartadas.'),
                            array('titulo' => 'Pareamento mais seguro', 'descricao' => 'Resgate de investimento não é mais pareado por engano com uma despesa de outra conta de mesmo valor, e a mesma transferência não é lançada duas vezes.'),
                            array('titulo' => 'Investimento certo pelo nome', 'descricao' => 'Linhas como "Resgate CDB 110 CDI" apontam para o CDB de 110% do mesmo banco.'),
                        ),
                    ),
                    array(
                        'nome' => 'Simplificação',
                        'icone' => 'fa-scissors',
                        'cor' => '#64748b',
                        'itens' => array(
                            array('titulo' => 'Filtros salvos removidos', 'descricao' => 'Retirados da tela de lançamentos por não agregarem.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.5.0', 'build' => '20261026.9', 'data' => '26/10/2026', 'hora' => '17:20',
                'titulo' => 'Importação de extratos com IA',
                'destaque' => 'Envie extratos de conta corrente ou de investimento; a IA entende para onde foi o dinheiro e você aprova item por item.',
                'categorias' => array(
                    array(
                        'nome' => 'Importação',
                        'icone' => 'fa-file-text-o',
                        'cor' => '#0d9488',
                        'itens' => array(
                            array('titulo' => 'Vários extratos de uma vez', 'descricao' => 'PDF (inclusive com senha), OFX, CSV ou Excel, de contas e de investimentos.'),
                            array('titulo' => 'Entende cada movimentação', 'descricao' => 'Receita, despesa, transferência, aplicação, resgate, rendimento, tarifa e pagamento de fatura.'),
                            array('titulo' => 'Transferências pareadas', 'descricao' => 'Se saiu R$ 51,20 do CDB do BV e entrou no Itaú, vira uma única transferência, mesmo vindo de dois extratos.'),
                            array('titulo' => 'Resgate com imposto', 'descricao' => 'O extrato da conta mostra o líquido; o sistema calcula o bruto, o IR e o IOF e credita na conta exatamente o valor do banco.'),
                            array('titulo' => 'Sem duplicar', 'descricao' => 'Cada linha é comparada com o que já existe e você aprova item por item, ou todos os novos.'),
                        ),
                    ),
                    array(
                        'nome' => 'Correção',
                        'icone' => 'fa-wrench',
                        'cor' => '#64748b',
                        'itens' => array(
                            array('titulo' => 'Aplicação sem conta de origem', 'descricao' => 'Registrar uma aplicação antiga, sem débito em conta, podia dar erro depois de gravar. Corrigido.'),
                            array('titulo' => 'Banco Central instável', 'descricao' => 'A consulta do CDI agora tenta de novo sozinha quando o Banco Central responde errado.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.4.0', 'build' => '20261026.8', 'data' => '26/10/2026', 'hora' => '17:05',
                'titulo' => 'Investimentos em CDI, transferências, aplicação e resgate',
                'destaque' => 'O CDI é consultado sozinho no Banco Central e o sistema calcula o rendimento, o imposto e a projeção dos seus investimentos.',
                'categorias' => array(
                    array(
                        'nome' => 'Investimentos',
                        'icone' => 'fa-bar-chart',
                        'cor' => '#d97706',
                        'itens' => array(
                            array('titulo' => 'CDI automático', 'descricao' => 'O CDI e a Selic vêm do Banco Central e são atualizados sozinhos. Há também o botão Atualizar CDI.'),
                            array('titulo' => 'Rendimento por % do CDI', 'descricao' => 'Cadastre CDB, RDB, cofrinho, LCI/LCA, poupança, Tesouro e outros, por exemplo 110% ou 130% do CDI. O saldo aparece bruto, como no app do banco.'),
                            array('titulo' => 'Aplicar e resgatar', 'descricao' => 'Aplicação tira dinheiro da conta corrente e põe no investimento; resgate faz o caminho inverso, já descontando IR e IOF.'),
                            array('titulo' => 'Reajuste de saldo', 'descricao' => 'Informe o saldo que o banco mostra e o sistema registra a diferença como rendimento.'),
                            array('titulo' => 'Projeção com e sem imposto', 'descricao' => 'A tela de projeção mostra o investimento mês a mês, bruto e líquido, com cenário de CDI à sua escolha.'),
                        ),
                    ),
                    array(
                        'nome' => 'Contas',
                        'icone' => 'fa-exchange',
                        'cor' => '#16a34a',
                        'itens' => array(
                            array('titulo' => 'Transferência entre contas', 'descricao' => 'Move saldo de uma conta para outra sem contar como receita ou despesa.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.3.3', 'build' => '20261026.7', 'data' => '26/10/2026', 'hora' => '16:38',
                'titulo' => 'Visual alinhado ao Sistema ERP',
                'destaque' => 'Cabeçalho, indicadores, tabelas, botões, modais e avisos agora seguem o mesmo padrão do ERP.',
                'categorias' => array(
                    array(
                        'nome' => 'Aparência',
                        'icone' => 'fa-paint-brush',
                        'cor' => '#2563eb',
                        'itens' => array(
                            array('titulo' => 'Cabeçalho e indicadores', 'descricao' => 'Título com subtítulo e caminho de navegação à direita; indicadores compactos com ícone circular.'),
                            array('titulo' => 'Listas em cards', 'descricao' => 'Tabelas listradas, botões de ação coloridos e o botão de criar no cabeçalho de cada lista.'),
                            array('titulo' => 'Paginação', 'descricao' => 'Resumo, páginas e seletor de itens por página (10, 20, 25, 50, 100 ou todos).'),
                            array('titulo' => 'Janelas e avisos', 'descricao' => 'Janelas no padrão do ERP, confirmações em diálogo próprio e avisos flutuantes no canto da tela.'),
                            array('titulo' => 'Campos', 'descricao' => 'O símbolo R$ e % agora fica dentro do campo.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.3.2', 'build' => '20261026.6', 'data' => '26/10/2026', 'hora' => '16:13',
                'titulo' => 'Perfis PF e PJ removidos da interface',
                'destaque' => 'O sistema agora é só pessoal: sem seletor de perfil no topo e sem a tela Perfis PF / PJ.',
                'categorias' => array(
                    array(
                        'nome' => 'Simplificação',
                        'icone' => 'fa-user',
                        'cor' => '#64748b',
                        'itens' => array(
                            array('titulo' => 'Sem PF/PJ', 'descricao' => 'Foram retirados o seletor de perfil, o item de menu e a tela de perfis. Seus dados continuam todos no mesmo lugar.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.3.1', 'build' => '20261026.5', 'data' => '26/10/2026', 'hora' => '15:26',
                'titulo' => 'Compras parceladas da fatura lançam as parcelas dos meses seguintes',
                'destaque' => 'Ao importar uma fatura, as parcelas que faltam podem ser lançadas de uma vez nas próximas faturas.',
                'categorias' => array(
                    array(
                        'nome' => 'Importação de faturas',
                        'icone' => 'fa-file-pdf-o',
                        'cor' => '#dc2626',
                        'itens' => array(
                            array('titulo' => 'Parcelas restantes em lote', 'descricao' => 'Na aprovação, itens parcelados (ex.: 09/21) mostram quantas parcelas faltam; marque por item ou para todos e elas entram nas faturas seguintes.'),
                            array('titulo' => 'Sem duplicar', 'descricao' => 'Ao ler a fatura do mês seguinte, a parcela já lançada aparece como "já existe".'),
                            array('titulo' => 'Total da fatura preservado', 'descricao' => 'Se a fatura futura já tinha um Reajuste de fatura, o total é mantido e o reajuste encolhe conforme as parcelas reais entram.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.3.0', 'build' => '20261026.4', 'data' => '26/10/2026', 'hora' => '13:46',
                'titulo' => 'Lançamentos mais completos: lote, filtros salvos, saldo do dia, anexos e mais',
                'destaque' => 'A tela de lançamentos agora tem ações em lote, filtros salvos, saldo previsto do dia e faturas agrupadas.',
                'categorias' => array(
                    array(
                        'nome' => 'Lançamentos',
                        'icone' => 'fa-exchange',
                        'cor' => '#16a34a',
                        'itens' => array(
                            array('titulo' => 'Ações em lote', 'descricao' => 'Marque vários lançamentos e efetive, ignore, mude categoria ou conta, ou exclua de uma vez.'),
                            array('titulo' => 'Filtros salvos', 'descricao' => 'Guarde combinações de filtros com um nome e aplique com um clique.'),
                            array('titulo' => 'Saldo previsto do dia', 'descricao' => 'A lista mostra o saldo previsto ao final de cada dia, considerando pendências e faturas.'),
                            array('titulo' => 'Faturas agrupadas', 'descricao' => 'As faturas do cartão com vencimento no período aparecem como uma linha, ligada à fatura.'),
                            array('titulo' => 'Ignorar transação', 'descricao' => 'A transação fica registrada, mas não entra em saldos, totais nem na projeção.'),
                            array('titulo' => 'Observação e anexos', 'descricao' => 'Escreva uma observação e anexe comprovantes (PDF, imagem, planilha).'),
                            array('titulo' => 'Repetição por dias, semanas, meses ou anos', 'descricao' => 'Lançamentos fixos e parcelados agora aceitam qualquer periodicidade.'),
                            array('titulo' => 'Salvar e criar nova', 'descricao' => 'Salve e já abra um novo lançamento em branco.'),
                            array('titulo' => 'Opções de visualização', 'descricao' => 'Escolha quais colunas aparecer na lista.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.2.0', 'build' => '20261026.3', 'data' => '26/10/2026', 'hora' => '13:36',
                'titulo' => 'Importar fatura do cartão por PDF, CSV ou Excel, com aprovação item a item',
                'destaque' => 'O PDF da fatura é lido por IA e você aprova cada item antes de gravar, vendo o que já existe no sistema.',
                'categorias' => array(
                    array(
                        'nome' => 'Importação de faturas',
                        'icone' => 'fa-file-pdf-o',
                        'cor' => '#dc2626',
                        'itens' => array(
                            array('titulo' => 'Fatura em PDF lida por IA', 'descricao' => 'Envie o PDF (com senha, se tiver): o sistema extrai as compras, encargos e créditos e sugere o cartão e a categoria.'),
                            array('titulo' => 'CSV e Excel', 'descricao' => 'Faturas em planilha seguem o mesmo fluxo de revisão.'),
                            array('titulo' => 'Itens que já existem', 'descricao' => 'Cada item é comparado com o banco e marcado como novo, possível duplicado ou já existente.'),
                            array('titulo' => 'Você aprova', 'descricao' => 'Marque item por item, ou importe todos os novos de uma vez; a fatura pode ser ajustada ao total do PDF.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.1.0', 'build' => '20261026.2', 'data' => '26/10/2026', 'hora' => '13:05',
                'titulo' => 'Reajuste de saldo e de fatura, saldo previsto e itens de cartão da planilha',
                'destaque' => 'Agora dá para informar só o saldo real da conta e o valor total da fatura, como no Mobills.',
                'categorias' => array(
                    array(
                        'nome' => 'Contas e cartões',
                        'icone' => 'fa-sliders',
                        'cor' => '#2563eb',
                        'itens' => array(
                            array('titulo' => 'Reajuste de saldo', 'descricao' => 'Informe o saldo real da conta: o sistema cria uma transação de ajuste (ou altera o saldo inicial, se preferir).'),
                            array('titulo' => 'Ajustar fatura', 'descricao' => 'Informe o valor total da fatura e o sistema cria um único item de reajuste com a diferença, sem lançar compra a compra.'),
                            array('titulo' => 'Saldo previsto', 'descricao' => 'Cada conta mostra o saldo atual e o previsto até o fim do mês, já considerando pendências e faturas.'),
                            array('titulo' => 'Página da fatura', 'descricao' => 'Veja a fatura de cada mês com status (aberta, fechada, vencida ou paga), fechamento, vencimento e itens.'),
                            array('titulo' => 'Escolha da fatura', 'descricao' => 'Ao lançar uma compra no cartão, é possível escolher em qual fatura ela entra.'),
                        ),
                    ),
                    array(
                        'nome' => 'Importação',
                        'icone' => 'fa-upload',
                        'cor' => '#d97706',
                        'itens' => array(
                            array('titulo' => 'Cartões na planilha do Mobills', 'descricao' => 'Itens de fatura exportados com o nome da conta pagadora são ligados ao cartão correto, pelo vencimento.'),
                            array('titulo' => 'Importar a partir de uma data', 'descricao' => 'Ignore lançamentos antigos e comece do zero a partir de hoje.'),
                        ),
                    ),
                ),
            ),
            array(
                'versao' => '1.0.0', 'build' => '20261026.1', 'data' => '26/10/2026', 'hora' => '00:00',
                'titulo' => 'Primeira versão do Sistema Financeiro',
                'destaque' => 'Contas, lançamentos, cartões, investimentos, importação de planilhas e projeção em um só lugar.',
                'categorias' => array(
                    array(
                        'nome' => 'Controle financeiro',
                        'icone' => 'fa-line-chart',
                        'cor' => '#16a34a',
                        'itens' => array(
                            array('titulo' => 'Contas, receitas e despesas', 'descricao' => 'Cadastre contas e lance receitas, despesas e transferências, com repetição fixa ou parcelada.'),
                            array('titulo' => 'Cartões e faturas', 'descricao' => 'Compras entram na fatura pelo fechamento do cartão; pagar a fatura debita a conta escolhida.'),
                            array('titulo' => 'Importação de planilhas', 'descricao' => 'Importe .xlsx/.csv vinculando contas e categorias já cadastradas, sem duplicar lançamentos.'),
                            array('titulo' => 'Projeção', 'descricao' => 'Veja o saldo e o patrimônio dos próximos meses com base no que já está agendado.'),
                        ),
                    ),
                ),
            ),
        );
    }
}
