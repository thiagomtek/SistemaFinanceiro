# Validações de funcionalidades

## Fase 1 — núcleo PF, importação e projeção (v1.0.0, build 20261026.1) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `sistema/*.php`, `sistema/servicos/*`, `sql/schema.sql`, `sistema/assets/*`.

Verificado:
- Regras de negócio (`tools/ci/testes_dominio.php`, SQLite em memória, **PHP 8.3 e 7.4**): fechamento/vencimento de fatura, parcelas com resto de centavos, transferência, escopo por perfil, pagamento e reabertura de fatura, importação da planilha modelo (129 linhas, reimportação sem duplicar, parcelas `(n/m)` reconhecidas), projeção, resumos do dashboard.
- Telas no navegador (servidor PHP local + SQLite): login/primeiro acesso, dashboard com gráficos, projeção, lançamentos (filtros, modal, criação parcelada), cartões/faturas (incluindo pagar fatura: limite utilizado caiu de R$ 1.812,50 para R$ 690,00), importação de fatura de cartão (upload → revisão → confirmação), sem erros PHP nas 12 rotas consultadas, após a reestruturação para `sistema/` rodando em PHP 7.4.

Pendências / ressalvas:
- Publicado na VM em 26/10/2026: `schema.sql` aplicou sem erro no MariaDB 10.5.29 (10 tabelas), `fin_app` respondeu 200 em `login.php` direto e via Caddy/HTTPS (:8453), `/sql/schema.sql` não é servido (404) e o ERP seguiu respondendo 200. **Ainda não exercitado com dados reais no MariaDB** (cadastro, lançamentos, importação, projeção): só o carregamento da tela de login foi verificado.
- Não verificados no navegador: reabrir fatura, edição e exclusão de lançamento, investimentos, perfis, categorias, contas (cobertos apenas por teste de domínio ou checagem de carregamento).
- Fluxo de autorização testado só no cenário autorizado de um usuário; não há perfis de permissão como no ERP.
- Padrões do ERP ainda pendentes: tooltips, paginação tripartite, ordenação de colunas.
- Em dispositivos móveis, apenas layout responsivo do `admin_layout.css`; sem teste dedicado.

## v1.1.0 — reajuste de saldo e de fatura, saldo previsto, cartões da planilha (build 20261026.2) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `sistema/servicos/AjusteService.php`, `CadastroService.php`, `CartaoService.php`, `LancamentoService.php`, `ImportacaoService.php`, `contas.php`, `cartoes.php`, `fatura.php`, `fatura_modais.php`, `lancamentos.php`, `categorias.php`, `importar.php`, `index.php`, `tools/carga/carga_inicial.php`, `sql/schema.sql`.

Verificado:
- `tools/ci/testes_reajustes_mobills.php` (PHP 7.4 e 8.3, SQLite): reajuste de saldo nos dois modos, status de fatura, fatura escolhida manualmente, ajustar fatura (criar, atualizar, negativo, remover, recusar paga) e a planilha do Mobills com os valores dos prints (limites utilizados dos 4 cartões, cartão de crédito 10.165,27, receitas 15.746,13 e despesas 13.740,19 de outubro, saldo atual 93.489,42 e saldo previsto de BV, Itaú e PicPay).
- Navegador (PHP 7.4 + SQLite local, carga simulada a partir de 26/10/2026): contas com saldo previsto, reajuste de saldo pela tela, tela de cartões, página da fatura e ajustar fatura; 14 rotas sem erro PHP.

Pendências / ressalvas:
- Saldo previsto do Nubank no print do Mobills (14.634,79) difere da regra em 861,75; a carga a partir de hoje evita o problema, mas a causa não foi confirmada.
- Não verificados no navegador: importação com os grupos de fatura pela tela (só pelo script de carga), seletor de fatura no formulário de lançamento, categorias de sistema bloqueadas, KPI do dashboard.
- Dias de fechamento do Nubank, Black Personalite e LUIZA são provisórios.

## v1.2.0 — importar fatura por PDF, CSV ou Excel (build 20261026.3) e v1.3.0 — lançamentos completos (build 20261026.4) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/FaturaPdfService.php`, `FaturaImportService.php`, `AnexoService.php`, `LoteService.php`, `LancamentoService.php`, `importar_fatura.php`, `lancamentos.php`, `anexo.php`, `assets/js/lancamentos.js`, `Dockerfile`, `sql/schema.sql`.

Verificado:
- Testes automatizados (PHP 7.4 e 8.3, SQLite): `testes_fatura_import.php` (normalização da resposta da IA, CSV, itens existentes e aprovação item a item, ajuste ao total, fatura paga) e `testes_p2.php` (ignorar, periodicidade, lote, filtros salvos, saldo do dia, anexos).
- **IA com as faturas reais, localmente e no servidor de produção** (somente leitura, nada gravado): Nubank 88 itens com total R$ 3.986,83 (soma dos itens R$ 3.986,82, diferença de 1 centavo) e Itaú Black 39 itens com total R$ 5.676,85 (soma igual), com o cartão sugerido corretamente. Demora de cerca de 50 s por PDF.
- Fluxo completo por HTTP local com o PDF do Nubank (upload → revisão → importar todos os novos com ajuste → fatura fechando em R$ 3.986,83) e segunda leitura do mesmo PDF marcando os 88 itens como "já existe". Tela de revisão conferida no navegador com um CSV (novo / já existe).
- Telas de lançamentos conferidas localmente: lote, faturas agrupadas, saldo do dia, filtro salvo, ignorar, observação e anexo (download e 404 de anexo inexistente).

Pendências / ressalvas:
- A IA não é determinística e já errou a leitura de itens em testes iniciais com PDFs de múltiplas colunas; a aprovação do usuário e a conferência de total existem por isso. PDF protegido por senha não foi testado com um arquivo real (os dois PDFs de teste não tinham senha); o caminho com `qpdf` só foi exercitado quanto à presença do binário no contêiner.
- Arquivos `.xls` antigos não são lidos (o sistema orienta salvar como `.xlsx`/`.csv`).
- Importação das faturas reais no banco de produção **não foi feita**: aguarda a aprovação do usuário na tela.
- Anexos: o upload foi testado com arquivo TXT; PDF/imagem usam o mesmo caminho.

## v1.3.1 — parcelas restantes lançadas nas faturas seguintes (build 20261026.5) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/FaturaImportService.php`, `importar_fatura.php`, `tools/ci/testes_fatura_import.php`.

Verificado:
- Testes automatizados (PHP 7.4 e 8.3): troca do número da parcela na descrição, séries de parcelas (compras gêmeas viram séries diferentes), parcelas futuras na data de vencimento de cada fatura, série até a última parcela, parcela já projetada reconhecida como "já existe" na leitura do mês seguinte, parcela que cairia em fatura paga é pulada, e preservação do total quando a fatura futura já tinha "Reajuste de fatura".
- Fluxo completo local com o PDF real do Itaú Black e os dados do Mobills carregados: 39 itens na fatura de out/26 (R$ 5.676,85) e 170 parcelas futuras; a fatura de nov/26 fechou em **R$ 3.284,56 com 23 itens, exatamente a "Próxima fatura" informada no PDF**, e 16 faturas futuras tiveram o reajuste recalculado.

Pendências / ressalvas:
- As parcelas futuras assumem o mesmo valor da parcela atual e vencimento no dia do cartão; se o banco ajustar valores (juros, câmbio), a fatura real do mês corrige pela leitura do PDF seguinte.
- Não executado em produção sobre os dados reais (aguarda a aprovação do usuário na tela).

## v1.3.2 — remoção dos perfis PF/PJ da interface (build 20261026.6) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/LayoutService.php`, `servicos/PerfilService.php`, `index.php`, `assets/css/financeiro.css`; `perfis.php` removido.

Decisão do usuário: o sistema é só pessoal. Retirados o seletor de perfil no topo, o item de menu, a tela de perfis, o rodapé com o nome do perfil e as categorias padrão de PJ.

Verificado: lint em PHP 7.4 e suítes de teste (`testes_dominio`, `testes_reajustes_mobills`, `testes_fatura_import`, `testes_p2`).

Ressalvas: internamente o perfil continua existindo (um por usuário, `perfil_id` em todas as tabelas) para isolar dados por usuário e evitar uma reescrita arriscada do banco; a tabela `perfis` e a coluna `tipo` permanecem. Remover isso por completo exigiria alterar todas as tabelas e consultas.

## v1.3.3 — alinhamento visual ao Sistema ERP (build 20261026.7) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/LayoutService.php`, `assets/css/financeiro.css`, `assets/css/erp_design_system.css` (copiado do ERP), `assets/js/erp_dialogs.js` (copiado do ERP), `assets/js/app.js`, `assets/js/lancamentos.js`, páginas de contas, cartões, categorias, investimentos, lançamentos, fatura e modais.

Verificado: lint e suítes de teste em PHP 7.4; verificação visual local (navegador) de dashboard, lançamentos (filtros, card com paginação, saldo do dia, faturas agrupadas, ações em cor), contas, cartões e modal de novo lançamento; toast do ERP exibido; erro de JavaScript encontrado e corrigido durante a verificação (chave sobrando em `app.js`).

Ressalvas: a comparação foi feita contra o `erp_design_system.css` e os documentos de padrões do ERP, não lado a lado com telas do ERP em produção (sem acesso autenticado a ele). Páginas de importação, projeção e fatura receberam o cabeçalho, as cores e os componentes novos, mas não foram refeitas card a card. Pendente: tooltips, ordenação por clique, switches/checkboxes do ERP.

## v1.4.0 — investimentos em CDI, transferências, aplicação e resgate (build 20261026.8) — 26/10/2026

**Status: validada localmente; produção conforme registro da publicação.**

Arquivos: `servicos/IndiceService.php`, `servicos/RendaFixaService.php`, `servicos/InvestimentoService.php`, `servicos/ProjecaoService.php`, `servicos/CadastroService.php`, `servicos/LoteService.php`, `investimentos.php`, `api_resgate_previa.php`, `api_projecao.php`, `projecao.php`, `assets/js/projecao.js`, `contas.php`, `lancamentos.php`, `index.php`, `tools/ci/testes_investimentos.php`.

Verificado: lint e todas as suítes em PHP 7.4 (incluindo `testes_investimentos`, com cerca de 70 verificações: calendário de dias úteis, faixas de IR e IOF, PEPS por aplicação, reajuste, pré-fixado, poupança, aplicar/resgatar com saldos, projeção); verificação visual local de investimentos e projeção, com o CDI real buscado no Banco Central.

Ressalvas: o rendimento é estimativa pelo CDI diário; o saldo real do banco pode diferir alguns centavos (use o reajuste). Os dias úteis consideram feriados nacionais; feriados bancários extras não entram. O IR/IOF mostrado é estimativa para resgate total hoje. Taxa de custódia e come-cotas de fundos não são modelados.

## v1.5.0 — importação de extratos com IA (build 20261026.9) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/ExtratoService.php`, `servicos/FaturaPdfService.php` (chamada ao Gemini extraída para `ia_gemini_json`, usada também pelas faturas), `servicos/RendaFixaService.php` (`rf_aplicar` devolve `?int`), `servicos/IndiceService.php` (nova tentativa na consulta ao Banco Central), `importar_extrato.php`, `servicos/LayoutService.php`, `bootstrap.php`, `tools/ci/testes_extrato.php`.

Verificado: lint e todas as suítes em PHP 7.4, incluindo `testes_extrato` (OFX, normalização, IR/IOF, destino sugerido, pareamento entre extratos, receita e despesa de mesmo valor que não pareiam, resgate com bruto calculado a partir do líquido e crédito exato na conta, aplicação, transferência, pagamento de fatura, duplicidade por hash e por valor/data, resgate maior que o saldo recusado). Verificação local no navegador do fluxo completo com dois arquivos OFX: leitura, destino por arquivo, par entre extratos, ações sugeridas, aprovação e gravação (4 itens: receita, despesa, resgate e aplicação) com a IA simulada.

Ressalvas: a leitura real de extratos em PDF/CSV pela IA foi testada só com respostas simuladas; a qualidade com os extratos reais de BV, Itaú, Nubank e PicPay deve ser conferida na primeira importação (a tela de aprovação existe para isso). OFX sem IA classifica só pelo sinal e, nesse caso, não pareia transferências. Em resgate, o valor do extrato da conta é tratado como líquido. Entre dois investimentos não há transferência direta (resgate e depois aplicação).

## v1.6.0 — reajuste bruto/líquido, extrato de custódia, data de corte e ajustes (build 20261026.10) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/RendaFixaService.php` (`rf_reajustar` com base bruto/líquido, `rf_reajuste_para_liquido`, `rf_posicao_inicial`, conta bancária obrigatória em aplicação/resgate), `servicos/IndiceService.php` (`indices_garantir_desde`), `servicos/ExtratoService.php` (posições de custódia, data de corte, regras de pareamento, identificação do investimento por nome e banco, trava de transferência duplicada), `importar_extrato.php`, `investimentos.php`, `lancamentos.php` e `servicos/LoteService.php` (filtros salvos removidos), `tools/ci/testes_*.php`.

Verificado: lint e todas as suítes em PHP 7.4. Casos novos: reajuste informando o líquido (o líquido calculado depois do reajuste é o informado, e o ajuste no bruto é maior que o do líquido); recusa de conta do tipo investimento em aplicação e resgate; posição inicial com saldo bruto exato e IR estimado próximo ao informado pelo banco; pareamento que NÃO cruza resgate de uma conta com despesa de outra; data de corte; extrato só de posições. Leitura real de quatro PDFs (BV, Itaú, Nubank conta e Nubank custódia) pela IA no servidor, sem gravar nada, para checar a classificação e o pareamento.

Ressalvas: a tabela `filtros_salvos` continua no banco (sem uso). Em posição inicial sem IR informado, a data da aplicação é uma data equivalente (31 dias), não a real: o IR/IOF do líquido é estimado até o usuário informar valor e data originais.

### Carga dos saldos atuais em produção (26/10/2026, sobre a v1.6.0)

Dados do usuário aplicados com backup prévio (`backups/financeiro_antes_carga_saldos_20261026_*.sql`) e simulação com reversão antes: BV corrente 0, Itaú corrente 0, Nubank corrente 62,56; investimentos: CDB BV 110% (25.470,65), CDB BV 130% (25.282,09), Itaú CDB DI (9.502,00), Nubank Turbo Croma (10.184,31) e Turbo NuCel (10.335,10). A conta "BV investimento" foi renomeada para "BV" (corrente) e as contas vazias do tipo investimento foram desativadas. Conferido: saldos das contas e saldos brutos dos investimentos iguais aos informados. Ressalvas: o Itaú CDB DI foi cadastrado a 100% do CDI por suposição; nos CDBs do BV a data da aplicação é equivalente (31 dias), então o IR/IOF do líquido é estimado até serem informados valor e data originais.

### Histórico real dos investimentos BV e Itaú (26/10/2026)

Com o extrato detalhado do BV (01/04 a 30/09 e 01 a 25/10) e o extrato do Itaú (28/07 a 26/10), as aplicações e resgates reais foram reconstruídos (com backup e simulação prévia), substituindo a posição inicial "equivalente" desses três investimentos. Conferências: o motor reproduziu ao centavo o resgate automático do CDB 130% (R$ 25.854,51 líquido de IR) e o saldo do novo lote (R$ 25.282,09); o Itaú CDB DI fechou como 100% do CDI (resgate total de 26/10 calculado em R$ 3.404,43 contra R$ 3.404,44 do extrato); o CDB 110% fechou com 5 centavos de diferença ao incluir como resgate o Pix de R$ 2.272,20 de 26/10 recebido no Itaú. IR e IOF estimados: CDB 110% 70,69 + 22,68; CDB 130% 55,22 + 36,67; Itaú 0 (aplicação feita hoje); Nubank 92,88 + 78,73 (posição equivalente, sem histórico). Ressalvas: o Pix de R$ 2.272,20 foi atribuído ao CDB 110% por coincidir com a diferença de saldo; as transferências de R$ 9.331,46 (24/10) e R$ 130,00 (08/10) recebidas no Itaú não saíram do BV e não foram lançadas.

## v1.6.1 — projeção investe a sobra e alerta no dia exato (build 20261026.11) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/ProjecaoService.php`, `api_projecao.php`, `projecao.php`, `assets/js/projecao.js`, `tools/ci/testes_projecao.php`.

Verificado: lint e todas as suítes em PHP 7.4. `testes_projecao`: sobra investida no fim do mês e rendendo a 100% do CDI (e a 110% rende mais), conta que não acumula, alerta no dia 3 quando o aluguel vem antes do salário (e nenhum alerta quando o salário vem antes), mês de déficit sai da conta, sobra só é investida depois de a conta voltar a ficar positiva, aporte extra opcional.

Ressalvas: despesas e receitas da média histórica não têm dia certo e são lançadas no último dia do mês na simulação diária. Quando a conta fica negativa o sistema não simula o resgate automático dos investimentos: só informa a data e o valor disponível. Receita e despesa de lançamentos pendentes sem data futura caem hoje.

## v1.6.2 — saldo previsto do dia com investimentos (build 20261026.12) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/LoteService.php` (`saldo_investimentos_por_dia`), `lancamentos.php`, `tools/ci/testes_investimentos.php`.

Verificado: lint e suítes em PHP 7.4; o saldo dos investimentos de hoje bate com o total do sistema (bruto e líquido); datas futuras rendem pelo CDI; líquido nunca maior que o bruto.

Ressalvas: os investimentos só aparecem quando a lista não está filtrada por conta (investimento não pertence a uma conta). A sobra mensal projetada (tela Projeção) não entra nesse saldo, que usa só o que já existe e o que está agendado. Não conferido visualmente na tela de produção (exige login).

## v1.6.3 — resgate pelo valor líquido com impostos editáveis (build 20261026.13) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/RendaFixaService.php` (`rf_previa_resgate_liquido`, `rf_resgatar_liquido`, `rf_bruto_do_liquido`, `rf_gravar_resgate`), `servicos/ExtratoService.php`, `api_resgate_previa.php`, `investimentos.php`, `tools/ci/testes_investimentos.php`.

Verificado: lint e suítes em PHP 7.4. Resgate de R$ 30 líquidos credita exatamente 30 na conta e debita do investimento 30 + IR + IOF; IR e IOF informados à mão (5,00 e 1,00) geram bruto 36,00; resgate total com IR editado; impostos que estourem o saldo e valor zero são recusados. JavaScript inline da tela com verificação de sintaxe.

Ressalvas: a tela do modal não foi exercitada no navegador (exige login em produção). Os impostos editados definem o valor debitado do investimento; o cálculo de lotes do sistema (PEPS) continua usando as faixas regressivas para consumir os lotes.

## v1.7.0 — função Ignorar removida e fatura Bradesco (build 20261026.14) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: remoção de `ignorar` em `servicos/LancamentoService.php`, `LoteService.php`, `CadastroService.php`, `CartaoService.php`, `DashboardService.php`, `AjusteService.php`, `FaturaImportService.php`, `ProjecaoService.php`, `lancamentos.php`; fatura: `servicos/FaturaPdfService.php`, `servicos/FaturaImportService.php`; testes `testes_p2.php` e `testes_fatura_import.php`.

Verificado: lint e todas as suítes em PHP 7.4 (novo teste do caso Bradesco: pagamento recebido filtrado, soma dos itens, série de parcelas na mesma fatura, parcela única com 2 restantes). Leitura real da fatura Bradesco (Amazon Mastercard, venc. 28/10/2026, total R$ 244,44) pela IA no servidor: os 22 itens saíram corretos, mas o "pagamento recebido" entrou e o produto veio errado; corrigidos no prompt e por filtro.

Ressalvas: a coluna `lancamentos.ignorar` permanece no banco sem uso (sempre 0); os registros antigos marcados como ignorados passaram a contar e os saldos foram reajustados para os valores informados pelo usuário.

## v1.8.0 — fixa sem fim e exclusão de séries (build 20261026.15) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/LancamentoService.php` (`criar_lancamento_ids`, `recorrencias_estender`, `excluir_lancamento`), `servicos/ProjecaoService.php`, `auth_check.php`, `lancamentos.php`, `assets/js/lancamentos.js`, `sql/alteracoes/20261026_recorrencia_fixa.sql`, `sql/schema.sql`, `tools/ci/testes_p2.php`.

Verificado: lint e todas as suítes em PHP 7.4. Testes novos: fixa sem quantidade gera ocorrências até o horizonte (24 meses) e é marcada como fixa; o sistema gera as próximas sem duplicar; excluir somente esta; esta + futuras não confirmadas (a futura confirmada fica, a anterior fica e a série fixa deixa de ser estendida); todas da série (inclusive confirmadas); parcelada com "esta e próximas".

Ressalvas: a quantidade continua para Parcelado (e fixa com quantidade pela API). Séries fixas antigas, criadas com quantidade, continuam finitas. Excluir "somente esta" a última ocorrência gerada de uma fixa faz o sistema gerá-la de novo; para encerrar a série use "este e os próximos". A exclusão em lote não pergunta o escopo (exclui só os marcados).

## v1.8.1 — cartões inativos em área própria (build 20261026.16) — 26/10/2026

**Status: validada com ressalvas.** Arquivo: `cartoes.php`. Verificado: lint em PHP 7.4. Ressalva: não conferido visualmente na tela de produção (exige login); a lógica só separa os cartões por situação e reaproveita o mesmo cartão visual.

## v1.9.0 — agrupar lançamentos do cartão (build 20261026.17) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `lancamentos.php`, `assets/js/lancamentos.js`. Verificado: lint em PHP 7.4 e verificação local por HTTP com banco de teste: sem o agrupamento, cada compra aparece; com ele, uma linha "Fatura do cartão Teste · 3 item(ns) − R$ 370,40" no lugar das compras; o bloco "Faturas de cartão com vencimento no período" não aparece mais; sem erros PHP. Ressalvas: o agrupamento é por cartão e mês da fatura (uma linha por fatura) e fica guardado num cookie deste navegador; os totais do topo continuam somando os itens de cartão; não conferido visualmente em produção.

## v1.9.1 — importações dentro das telas (build 20261026.18) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `servicos/LayoutService.php`, `cartoes.php`, `lancamentos.php`, `importar_fatura.php`, `importar_extrato.php`; `importar.php` removido. Verificado: lint em PHP 7.4. Ressalva: o serviço de leitura de planilha (`ImportacaoService.php`) continua, pois a importação de fatura em CSV/Excel e os testes o usam; só a tela foi retirada. Não conferido visualmente em produção.

## v1.9.2 — tela de cartões redesenhada (build 20261026.19) — 26/10/2026

**Status: validada com ressalvas.** Arquivo: `cartoes.php`. Verificado: lint em PHP 7.4 e visualização local no navegador (resumo com 4 indicadores, cabeçalho com um único Importar fatura e Novo cartão, cartão compacto com fatura atual, limite e tabela). Ressalvas: conferido com um cartão de teste; o botão de importar não leva mais o cartão pré-selecionado.

## v1.9.3 — tela de lançamentos mais limpa (build 20261026.20) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `lancamentos.php`, `assets/css/financeiro.css`. Verificado: lint em PHP 7.4 e visualização local no navegador (linhas de uma só linha, botões agrupados no cabeçalho da lista, sem faixa vazia). Ressalva: conferido em largura de desktop com poucos dados; não verificado em celular.

## v1.10.0 — confirmação com modal, cartões em grade, pagamento parcial (build 20261026.21) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `lancamentos.php`, `assets/js/lancamentos.js`, `cartoes.php`, `contas.php`, `investimentos.php`, `servicos/CartaoService.php`, `servicos/LayoutService.php`, `fatura.php`, `fatura_modais.php`, `assets/js/app.js`, `assets/css/financeiro.css`. Verificado: lint e suítes em PHP 7.4 (novos testes: histórico impede exclusão e inativar apaga o que ainda vence; pagamento parcial com restante na próxima fatura, reabrir devolve, valor maior recusado); visualização local do menu recolhido e da lista de lançamentos. Ressalvas: contas e investimentos não foram conferidos visualmente; a regra de itens a vencer considera faturas não pagas com vencimento depois de hoje.

## v1.11.0 — usuários, permissões, perfil, notificações, PWA e celular (build 20261026.22) — 26/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/PermissaoService.php`, `servicos/NotificacaoService.php`, `usuarios.php`, `perfil.php`, `api_notificacoes.php`, `manifest.php`, `sw.php`, `offline.html`, `assets/icons/*`, `auth_check.php`, `login.php`, `servicos/LayoutService.php`, `servicos/PerfilService.php`, `index.php`, `projecao.php`, `assets/js/app.js`, `assets/js/projecao.js`, `assets/css/financeiro.css`, `sql/alteracoes/20261026_usuarios_permissoes.sql`, `tools/ci/testes_usuarios.php`, `tools/auditoria/auditoria_integridade.php`.

Verificado: lint e todas as suítes em PHP 7.4 (`testes_usuarios`: papéis, matriz personalizada, dados compartilhados, inativo, e-mail/senha, sempre um administrador, meu perfil, notificações do dia e do fechamento da fatura, gravação única, lida/não lida). Por HTTP, com banco de teste: leitora acessa os mesmos dados, é redirecionada ao tentar usuários e importações, tem a gravação bloqueada e o menu esconde o que não pode abrir. Visualização local do dashboard, projeção, usuários e menu recolhido. Auditoria de integridade somente leitura na produção (sem inconsistências estruturais).

Ressalvas: a notificação do navegador aparece enquanto o aplicativo está aberto; aviso com o aplicativo fechado (push em segundo plano) exige um servidor de push e não foi implementado. Não conferido em um celular físico nem a instalação do PWA em produção.

## v1.11.2 — dashboard projetado e gráfico de patrimônio (build 20261026.24) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `index.php`, `assets/js/dashboard.js`, `projecao.php`. Verificado: lint e JavaScript. Ressalva: não conferido visualmente em produção; meses passados continuam mostrando saldos de hoje.

## v1.11.3 — cards de cartões do mesmo tamanho (build 20261026.25) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `cartoes.php`, `assets/css/financeiro.css`. Verificado: lint. Ressalva: ajuste de CSS conferido apenas pelo código e pela captura de tela enviada; saldo inicial do Itaú zerado com uma transação de ajuste para manter o saldo atual em R$ 0,00.

## v1.11.4 — modal de todas as faturas e contas em cards (build 20261026.26) — 26/10/2026

**Status: validada com ressalvas.** Arquivos: `cartoes.php`, `contas.php`. Verificado: lint em PHP 7.4 e teste local por HTTP (as duas telas carregam sem erro; cards de contas e botão de todas as faturas presentes). Ressalva: o modal e o layout não foram conferidos visualmente no navegador.

## v1.11.5 — situação padronizada (build 20261026.27) — 26/10/2026

**Status: validada com ressalvas.** Arquivo: `lancamentos.php`. Verificado: lint em PHP 7.4. Ressalva: conferido só pelo código; outras telas (cartões, fatura, importações) ainda usam seus próprios rótulos de fatura (Aberta, Fechada, Vencida, Paga), que são estados da fatura e não do lançamento.

## v1.12.0 — revisão geral: cálculos, segurança, desempenho e acessibilidade (build 20261027.1) — 27/10/2026

**Status: validada com ressalvas.**

Arquivos: `servicos/CartaoService.php`, `DashboardService.php`, `LancamentoService.php`, `LoteService.php`, `CadastroService.php`, `ProjecaoService.php`, `InvestimentoService.php`, `RendaFixaService.php`, `IndiceService.php`, `ExtratoService.php`, `PermissaoService.php`, `FormatoService.php`, `FaturaPdfService.php`, `FaturaImportService.php`, `XlsxReaderService.php`, `AjusteService.php`, `AnexoService.php`, `index.php`, `lancamentos.php`, `fatura.php`, `cartoes.php`, `categorias.php`, `investimentos.php`, `usuarios.php`, `login.php`, `bootstrap.php`, `auth_check.php`, `sw.php`, `manifest.php`, `assets/js/app.js`, `erp_dialogs.js`, `dashboard.js`, `assets/css/financeiro.css`, `docker/*`, `Dockerfile`, `sql/schema.sql`, `sql/alteracoes/20261027_indices_lancamentos.sql`, `tools/ci/testes_revisao.php`.

Verificado:
- Sintaxe e **todas as 9 suítes** (`testes_dominio`, `reajustes_mobills`, `fatura_import`, `p2`, `investimentos`, `extrato`, `projecao`, `usuarios` e a nova `revisao`) em **PHP 7.4.33** (imagem do `fin_app`, contêiner descartável sem rede e sem banco, SQLite em memória): `TODOS OS TESTES PASSARAM`. A linha de base antes das alterações também estava toda verde.
- `testes_revisao.php` cobre: `parse_valor` (milhar, decimais, taxas), `mes_valido`, saldo restante fora de resumo/categorias/gráfico/lista, pagamento de fatura nas duas visões de totais, categoria por tipo (cadastro e lote), fim de série fixa excluído em lote, projeção sem dupla contagem (com compra de cartão do mês), resgate pelo líquido (inclusive lote de poucos dias com IOF alto, que o cálculo antigo errava), administradores por espaço, bloqueio de login, pareamento de extratos e coluna absurda em planilha.
- Navegador (servidor PHP 7.4 + SQLite descartável, usuário de teste, só acessível por túnel local): dashboard, lançamentos (KPIs: despesas R$ 1.045,00 = compra de R$ 1.000 + assinatura, sem o pagamento de R$ 300 e sem o restante), fatura com pagamento parcial, investimentos; 24 rotas respondem 200 sem erro PHP (inclui `mes=2026-13` e `api_projecao` com 60 meses); modais com `role="dialog"`, `aria-modal`, nome, Tab preso, Esc fecha e o foco volta ao botão; ícones com `aria-label`; gráficos com descrição; abrir um investimento "CDI sem imposto" para editar mantém indexador e tributação, e trocar o produto pelo teclado aplica o preset.
- Login ponta a ponta por HTTP: 5 falhas, a 6ª mostra "Muitas tentativas"; senha correta durante o bloqueio também é recusada.
- Apache (contêiner descartável): `apache2ctl -t` Syntax OK; 403 em `documentacao/`, `servicos/`, `bootstrap.php`, `bd.php`, `config_versao.php` e listagem de `assets/`; 200 em `login.php`, CSS, `sw.php`, `offline.html`; cabeçalhos de segurança e `Server: Apache`. `php.ini` lido pelo PHP (`display_errors`, `log_errors`, `samesite`, `gc_maxlifetime`).

Pendências / ressalvas:
- ~~Não publicado~~ — superada: publicada em produção em 29/10/2026 (ver a seção "Publicação da v1.12.0 em produção" abaixo).
- ~~Não testado contra MariaDB 10.5~~ — superada: telas, `GET_LOCK` e bloqueio de login foram exercitados em MariaDB 10.5.29 com uma cópia do backup (seção abaixo). As suítes automáticas continuam em SQLite.
- O registro do service worker falha no navegador embutido do ambiente de teste (também com um JS estático qualquer); não foi possível testar o PWA/instalação.
- Verificação visual feita só por captura de tela e leitura de atributos; contraste calculado (não medido com ferramenta) e leitor de tela não testado.
- `projetar` continua gravando ocorrências de séries fixas até o horizonte pedido (decisão mantida e documentada).
- Não testado em PHP 8.3 nesta rodada.

## Publicação da v1.12.0 em produção (build 20261027.1) — 29/10/2026

**Status: validada com ressalvas.**

O que foi feito na VM (só `fin_db` para backup/SQL e `fin_app` para a troca; nenhum outro serviço foi tocado):
1. Comparação da pasta do serviço com o snapshot anterior à revisão: sem divergência (a VM tem a mais só `sistema/documentacao/MIGRACAO_BANCO_PRODUCAO.md`, preservado). Envio dos arquivos e conferência por MD5: a VM ficou idêntica ao repositório local.
2. Backup `backups/financeiro_antes_v1_12_0_20261029_092344.sql` (158 KB, `mysqldump --single-transaction`, terminou com "Dump completed", 16 tabelas).
3. `sql/alteracoes/20261027_indices_lancamentos.sql` aplicado em `fin_db`; `SHOW INDEX` mostrou `ix_lanc_grupo` e `ix_lanc_efet`; reaplicar o mesmo arquivo rodou sem erro.
4. Imagem anterior guardada com a tag `sistema-financeiro-fin_app:rollback-1.11.5`; `docker compose build fin_app`; conferência da imagem nova num contêiner sem rede (`apache2ctl -t` = Syntax OK, `zz-financeiro.conf` ativo, `display_errors` desligado, `SameSite=Lax`, versão 1.12.0); troca com `docker compose up -d --no-deps fin_app` (09:26, cerca de 5 s fora do ar só do Financeiro).

Verificado em produção, pelo Caddy (`https://servidor.tail074692.ts.net:8453`):
- `login.php` responde 200 com a versão 1.12.0, cookie `FINSESSID` com `Secure; HttpOnly; SameSite=Lax` e cabeçalhos `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` e `Server: Apache`.
- 403 em `documentacao/`, `servicos/`, `bootstrap.php`, `bd.php`, `config_versao.php`, `assets/` e `README.md`; 200 em CSS, JS, fontes, `sw.php`, `manifest.php` e `offline.html`; telas sem sessão redirecionam (302) para o login e `api_projecao.php` responde 401.
- Banco de produção com as mesmas contagens de antes (1 usuário, 533 lançamentos, 5 investimentos, 2 contas) e nenhuma linha `lf:` gravada; log do `fin_app` sem erros PHP; `fin_db` e os demais contêineres sem reinício.

Verificado em MariaDB 10.5.29 com uma **cópia** do backup (contêineres `fin_tmp_*` descartáveis, rede própria, removidos ao final; login com senha de teste gerada só para a cópia):
- Login (302 para o início), 12 telas e variações (mês válido/inválido, mês "todos", 4 faturas × 2 meses, 5 investimentos), `api_projecao` com 12/24/60 meses e `api_notificacoes`: todos 200, sem erro PHP no corpo nem no log.
- `GET_LOCK`/`RELEASE_LOCK` funcionam (1 e 1) e nenhuma trava ficou presa; o bloqueio de login apareceu na 6ª falha ("Muitas tentativas. Tente de novo em 15 minuto(s).") e gravou 1 linha `lf:` só na cópia.

Pendências / ressalvas:
- As telas autenticadas **de produção** não foram abertas por mim (a senha do Thiago não é usada); a cobertura delas é a da cópia em MariaDB acima. Vale uma olhada do Thiago em Início, Lançamentos, Faturas e Investimentos.
- O PWA/service worker segue sem teste de instalação (o navegador embutido não registra service worker); PHP 8.3 não testado.
- Reversão, se preciso: `docker tag sistema-financeiro-fin_app:rollback-1.11.5 sistema-financeiro-fin_app:latest` e `docker compose up -d --no-deps fin_app` (os índices novos são compatíveis com o código antigo).

## v1.12.1 — importação de fatura reconhece parcelas com prefixo de cartão (build 20261029.1) — 29/10/2026

**Status: validada com ressalvas.**

Arquivos: `sistema/servicos/FaturaImportService.php`, `tools/ci/testes_fatura_import.php`, `sistema/config_versao.php`, `sistema/config_novidades.php`.

Causa (consulta somente leitura ao banco de produção): a importação nº 3 (fatura de outubro) gravou 77 itens com `•••• 4001 ` no início da descrição; o identificador da série de parcelas usa a descrição, então `Pag*Tibia - Parcela 2/2` (id 194) não batia com o item do PDF novo e aparecia como "Novo".

Verificado:
- `fatura_descricao_base` ignora o prefixo `•••• NNNN` e a parcela final; `fatura_marcar_existentes` ganhou busca de apoio (mesmo cartão, parcela n/m, valor e descrição normalizada). Novo teste cobre o caso Tibia; `validar_php.sh` e as 10 suítes passam em PHP 7.4 (VM, SQLite).

Pendências / ressalvas:
- Evolução pedida pelo Thiago: a identificação exige cartão, parcela n/total e valor iguais, compara o nome por semelhança (`fatura_nome_similaridade`, certeza a partir de 0,85) e, em dúvida, pergunta à IA numa chamada só (`ia_mesma_transacao`, cache na sessão; sem IA vira "possível duplicado"). A mesma regra vale no extrato bancário (`extrato_candidatos`: conta, sentido, valor e data ±3 dias; nome igual = "existe", nome diferente = IA). Testes novos com IA simulada (confirma, nega, indisponível) e valor/total diferentes; 10 suítes passam em PHP 7.4.
- Ainda não publicado na VM; a IA real (Gemini) não foi exercitada, só o mock; o PDF real não foi reimportado para conferir os 70 itens "Novos" um a um (os que têm prefixo no banco devem passar a "Já existe").
- Itens de parcela 1/n sem registro anterior continuam novos, como esperado.
- **Publicado na VM em 29/10/2026 (10:39)**: backup `backups/financeiro_antes_importacao_ia_20261029_103838.sql`, tag de reversão `rollback-1.12.0`, só 4 arquivos enviados (`ExtratoService.php`, `FaturaImportService.php`, `config_versao.php`, `config_novidades.php`); o recurso de notificações push que está na pasta local não foi enviado (não faz parte desta alteração). Sem mudança de banco. `fin_app` recriado com `--no-deps`; `fin_db` e `caddy` seguiram no ar; login responde 200 direto e via Caddy; sintaxe PHP OK dentro do contêiner. Reversão: `docker tag sistema-financeiro-fin_app:rollback-1.12.0 sistema-financeiro-fin_app:latest` e `docker compose up -d --no-deps fin_app`.

## v1.13.0 — notificações push no celular e no navegador (build 20261029.2) — 29/10/2026

**Status: validada com ressalvas.**

Arquivos: `sistema/servicos/PushService.php` (novo), `servicos/NotificacaoService.php`, `api_push.php` (novo), `cron_notificacoes.php` (novo), `sw.php`, `perfil.php`, `assets/js/app.js`, `bootstrap.php`, `config_versao.php`, `config_novidades.php`, `Dockerfile`, `docker-compose.yml`, `docker/apache-financeiro.conf`, `.env.example`, `.gitignore`, `composer.json` e `composer.lock` (cópias idênticas às do ERP, só leitura), `.github/workflows/ci.yml`, `sql/schema.sql`, `sql/alteracoes/20261029_push_assinaturas.sql`, `tools/ci/testes_push.php` (novo).

Verificado:
- Sintaxe e **as 10 suítes** em PHP 7.4.33 (contêiner descartável da VM, sem rede): todas passam. `testes_push.php` (49 verificações): endereços aceitos/recusados (inclui `fcm.googleapis.com.evil.com`, IP interno, porta, usuário no endereço, http), cadastro idempotente por aparelho, chaves inválidas, usuário não cancela aparelho alheio, aparelho muda de dono, corpo da notificação (prefixo "Financeiro ·", tag, destino sempre relativo), envio simulado (sucesso, 410 remove, falha comum mantém, erro de rede não derruba), rotina diária (só ativos com aparelho, respeita permissões, não repete no mesmo dia, avisos novos no dia seguinte, sessão restaurada) e push desligado sem chaves.
- **Envio real pela biblioteca** em imagem de teste construída a partir do `Dockerfile` novo (estágio `composer:2.2`): vendor em `/var/www/vendor` (fora do DocumentRoot), imagem final sem Composer, `apache2ctl -t` = Syntax OK. Com chaves VAPID geradas na hora e um serviço de push falso local: 201 conta como entregue, 410 remove o aparelho, e o POST sai com `Content-Encoding: aes128gcm`, `Authorization: vapid t=…,k=…`, `TTL: 86400` e corpo criptografado. O teste achou que o padrão da biblioteca é o formato antigo `aesgcm` (que o Safari/iOS não aceita); corrigido para `aes128gcm`.
- Service worker: o trecho do evento `push` rodou em Node com um `self` simulado: mostra título/texto/tag, resolve a URL dentro de `/sistema/` e manda para o início qualquer destino fora do Financeiro (`https://evil.com`, `//evil.com`, ERP na porta 8452, `../`). `app.js` passa em `node --check`.

Pendências / ressalvas:
- **Não publicado** e **não testado em aparelho ou navegador reais**: o navegador embutido do ambiente não registra service worker, então a assinatura, a permissão e a chegada do aviso num celular/Chrome/Firefox/Safari ainda precisam ser conferidas pelo Thiago (Meu perfil → ativar → "Enviar teste").
- Para publicar: backup, `20261029_push_assinaturas.sql`, gerar as chaves VAPID próprias e colocá-las no `.env` da VM, reconstruir só o `fin_app` e incluir a linha do `crontab` do host (às 8h, com log próprio; as linhas do ERP não são tocadas).
- O repositório também traz a v1.12.1 (importação de fatura), feita em paralelo e ainda não publicada; ela seguirá junto na mesma publicação.
- PHP 8.3 e MariaDB 10.5 não foram usados nesta rodada (a nova tabela segue o mesmo formato das demais; o SQL é `CREATE TABLE IF NOT EXISTS`).

### Publicação da v1.13.0 (push) em produção — 29/10/2026, 10:41

Feita sobre a v1.12.1 (publicada minutos antes por outra sessão). Backup `backups/financeiro_antes_v1_13_0_push_20261029_104049.sql`; `20261029_push_assinaturas.sql` aplicado (9 colunas conferidas, reaplicação sem erro); imagem anterior guardada como `sistema-financeiro-fin_app:rollback-1.12.1`; chaves VAPID próprias geradas dentro da imagem e gravadas só no `.env` da VM (cópia do `.env` anterior em `backups/`, permissão 600; nada impresso); troca com `docker compose up -d --no-deps fin_app` (cerca de 6 s fora do ar só do Financeiro); `crontab` do host com backup em `/home/thiago/crontab_antes_push_20261029_104207.txt` e uma linha nova (`0 8 * * *` → `fin_app`, log `/home/thiago/cron_notificacoes_financeiro.log`), com as 6 linhas do ERP idênticas.
- No contêiner de produção: `push_disponivel()` = verdadeiro, chave pública de 87 caracteres; execução manual do cron: `{"usuarios":0,"avisos":0,...}`, saída 0 (ninguém tem aparelho cadastrado ainda); o cron via web responde 403.
- Pelo Caddy: login 200 na versão 1.13.0, cookie `Secure; HttpOnly; SameSite=Lax`; 403 em `cron_notificacoes.php`, `servicos/PushService.php`, `bootstrap.php`, `documentacao/`; 404 em `vendor/`, `composer.json` e `composer.lock` (a biblioteca fica fora do DocumentRoot); `sw.php` entrega o evento `push`; `api_push.php` sem sessão responde 401 (GET e POST). Log do `fin_app` sem erros PHP; banco com as mesmas contagens (1 usuário, 533 lançamentos); outros serviços sem reinício.
- **Pendente**: assinatura e chegada do aviso em aparelho real (Meu perfil → Ativar → Enviar teste).

## v1.13.1 — "Manter conectado" no login (build 20261029.3) — 29/10/2026, 11:47

**Status: validada com ressalvas.**

Arquivos: `sistema/bootstrap.php` (`sessao_configurar`, `sessao_reiniciar`), `login.php`, `logout.php`, `config_versao.php`, `config_novidades.php`.

Regra: com "Manter conectado" (marcado por padrão) o cookie `FINSESSID` dura 10 anos e o arquivo de sessão fica em `UPLOAD_DIR/.sessoes` (volume `fin_uploads`: sobrevive à troca do `fin_app` e não é apagado pelo gc das sessões normais). Sem marcar, segue a regra anterior (cookie de navegador, 8 h de inatividade). Só o Sair encerra uma sessão mantida. O marcador é o cookie `FINMANTER`.

Verificado (PHP 7.4.33, contêiner descartável da VM, sem rede, SQLite):
- Sintaxe e as 10 suítes passam.
- Servidor local com `curl`: campo presente na tela; primeiro acesso e login com a opção emitem `FINSESSID` e `FINMANTER` com `Max-Age=315360000` e a página interna responde 200 com esse cookie; login sem a opção emite cookie de navegador e `FINMANTER` expirado; o arquivo de sessão longa aparece em `.sessoes`; o logout expira `FINMANTER` e a página interna volta a redirecionar para o login.

Pendências / ressalvas:
- Não publicado e não visto em navegador real. A permissão de escrita em `/var/www/uploads/.sessoes` no `fin_app` é criada em tempo de execução (se falhar, cai no diretório padrão de sessões, que não sobrevive à troca do contêiner).
- Sessões já abertas antes desta versão seguem com a regra de 8 h até o próximo login.
- Segurança: sessão sem expiração em aparelho perdido/compartilhado só se encerra pelo Sair; não há tela para revogar sessões.

## v1.13.2 — média da projeção sem o histórico importado + carga do histórico do Mobills (build 20261029.4) — 29/10/2026, 14:36

**Status: validada com ressalvas.**

Arquivos: `sistema/servicos/ProjecaoService.php` (`sql_sem_historico`, usado na média), `tools/carga/carga_historico.php` (novo), `tools/ci/testes_projecao.php`, `config_versao.php`, `config_novidades.php`, `documentacao/REQUISITOS_E_REGRAS_DE_NEGOCIO.md` (RN-32). Sem alteração de estrutura do banco: o histórico é marcado com `importacoes.tipo = 'historico'` (coluna que já existia).

Regra (RN-32, autorizada pelo Thiago em 29/10/2026): a média dos 3 últimos meses completos da Projeção ignora lançamentos de importações marcadas como histórico. Motivo medido na cópia: sem isso a média de despesas ia de R$ 953 para R$ 11.074, a de receitas de R$ 0 para R$ 7.998 e o patrimônio projetado em set/2027 de R$ 195 mil para R$ 177 mil (salário e faturas contados em dobro).

Carga (`planilha RELATORIO_TRANSACOES`, Mobills; só contas e cartões já cadastrados; antes de 01/10/2026):
- 20 faturas pagas de cartões habilitados (Nubank 9, Black Personalite 3, AMAZON 8), cada uma como **um item único** "Fatura <cartão> <mês> (histórico)" (`ajuste = fatura`) + registro em `faturas` + pagamento na conta pagadora. O mapeamento valor → cartão é inferido (a exportação não traz o cartão) e foi confirmado pelo Thiago. LUIZA (desabilitado) não recebeu nada.
- 208 movimentações de Itaú e Nubank (inclui 37 reajustes de saldo, 5 pagamentos de fatura de cartão não habilitado e 3 categorias novas: Investimento, Restaurante e Vestuário; "Outros" de receita foi para "Outras receitas").
- Ficaram de fora: 34 linhas de BV e PicPay (contas inexistentes) + 8 transferências com essas contas; a linha pendente "INATEL(7/11)" de 30/09 (R$ 861,75, conta em aberto: mudaria o saldo previsto do Nubank); 16 linhas de outubro em diante.
- Os saldos foram preservados compensando `contas.saldo_inicial` pelo efeito líquido (Itaú 0,00 → −44.338,81; Nubank 4.036,06 → 29.779,52). O script desfaz tudo se saldo atual, saldo previsto, cadastro de contas, cartões, investimentos ou lançamentos de outubro em diante mudarem.

Verificado:
- Sintaxe e as 10 suítes de teste em PHP 7.4.33 (contêiner descartável da VM, sem rede), incluindo o teste novo de RN-32.
- Em cópia descartável do banco (MariaDB 10.5, restaurada do backup): conferência de segurança pegou o efeito da linha pendente (corrigido); carga aplicada, saldos atual e previsto idênticos, segunda execução sem duplicar (0 importados, 0 faturas novas); Projeção idêntica antes e depois (média R$ 953,05 / R$ 0); 14 telas autenticadas (dashboard, lançamentos de jun e set e "todos", cartões, 3 faturas, contas, categorias, projeção, investimentos, importar fatura) responderam 200 sem erro PHP, e a fatura Nubank jun/26 aparece "Paga em 03/06/2026" com R$ 4.253,95.
- Produção: backup `backups/financeiro_antes_carga_historico_20261029_143712.sql` (e `financeiro_antes_historico_20261029_142707.sql`); imagem anterior guardada como `sistema-financeiro-fin_app:rollback-1.13.1`; troca só do `fin_app` (`up -d --no-deps`), `fin_db` e os demais serviços sem reinício; execução da versão 1.13.2 confirmada no contêiner; login 200 e `cron_notificacoes.php` 403 pelo Caddy; log sem erro PHP. Contagens depois da carga: lançamentos 600 → 848 (+208 +20 itens +20 pagamentos), faturas 3 → 23, contas 2, cartões 4, movimentos de investimento 43 com a mesma soma (R$ 200.783,18), lançamentos de outubro em diante 451 (iguais).
- Arquivos temporários removidos (planilha do contêiner e da VM, contêineres e rede `fin_tmp_*`).

Pendências / ressalvas:
- Não vista em navegador real nem com sessão do Thiago em produção; a Projeção em produção não foi executada (a função grava índices e séries), só na cópia.
- As faturas antigas têm total, mas **não têm as compras item a item**; se o Thiago enviar os PDFs, é possível trocar o item único pelos itens reais.
- Valores altos vêm assim da planilha e não foram conferidos contra faturas reais (Black jun R$ 10.018,27 e set R$ 7.425,35). Não há fatura de ago/26 da Black na planilha.
- Cinco linhas "Reajuste de fatura" (R$ 319,70 em dez, jan e fev; R$ 76,20 em dez e jan) não foram associadas a cartão habilitado e entraram só como pagamento na conta.
- Reverter: restaurar o backup acima, ou apagar os lançamentos da importação #10, as 20 linhas novas de `faturas` (meses antigos) e devolver `saldo_inicial` (Itaú 0,00; Nubank 4.036,06).
- A hora da release (14:33) é a do fechamento do código; o contêiner novo subiu às 14:36. Nada foi commitado.

## Complemento das parcelas do financiamento do carro, 25/48 a 48/48 (carga de dados, sem alterar o código do app) — 29/10/2026, 15:20

**Status: validada com ressalvas.**

Arquivos: `tools/carga/completar_parcelas.php` (novo). Código do app, banco (estrutura) e `config_versao.php` **não mudaram**: a versão em produção segue 1.13.2 (o `AGENTS.md` pede subir a versão a cada alteração; como a imagem do app não mudou, a versão não foi incrementada, por decisão proposta ao Thiago e não contestada).

O que foi feito: a série `Carro(n/48)` (grupo `8df7ca905e68802c`, Itaú, Transporte, R$ 1.979,62, dia 4) vinha da planilha do Mobills só até 24/48 (mar/2028). Foram criadas as 24 parcelas que faltam, 25/48 (04/04/2028) a 48/48 (04/03/2030), pendentes, no mesmo grupo, ligadas à importação #11 (tipo `ajuste`, "Complemento: parcelas 25 a 48 de Carro"). Total R$ 47.510,88; o pendente do financiamento passou de R$ 33.653,54 (17 parcelas) para R$ 81.164,42 (41 parcelas). Premissa confirmada pelo Thiago: 48 parcelas fixas, sem reajuste, dia 4 mesmo em fim de semana.

Verificado:
- Cópia descartável do banco (MariaDB 10.5, restaurada de backup): plano e gravação de 24 parcelas; segunda execução responde "série já completa" e não duplica; o script confere contagem e soma de lançamentos, parcelas existentes, saldos atual e previsto, investimentos e faturas antes de confirmar. Projeção de 60 meses antes/depois: nada muda até mar/2028; de abr/2028 a mar/2030 a despesa mensal passa de R$ 953,05 para R$ 2.932,67 (+ R$ 1.979,62) e volta a R$ 953,05 em abr/2030; a média da Projeção (R$ 953,05 / R$ 0) não mudou. Telas `index`, `lancamentos` (abr/2028, mar/2030 e busca "Carro") e `projecao` com 200 e sem erro PHP; a parcela aparece como "Carro(25/48) · Transporte · Itaú · − R$ 1.979,62".
- Produção: backup `backups/financeiro_antes_parcelas_carro_prod_20261029_152012.sql`; lançamentos 849 → 873, grupo do carro 23 → 47 linhas (a 5/48, paga pelo Nubank, está em outro grupo e não foi tocada), faturas 24 e soma dos movimentos de investimento (R$ 200.783,18) e saldos iniciais das contas iguais; execução pelo `fin_app` (`docker exec`), sem rebuild nem reinício; script e cópias temporárias removidos.

Pendências / ressalvas:
- Não vista em navegador real nem com a sessão do Thiago em produção.
- As parcelas existentes foram replicadas como estão; se o financiamento for quitado antes ou tiver correção, as futuras precisam ser ajustadas ou excluídas ("todas", no grupo).
- Reverter: apagar os lançamentos com `importacao_id = 11` e a linha 11 de `importacoes` (ou restaurar o backup acima).
- Nada commitado ainda (`tools/carga/completar_parcelas.php` e esta entrada).

## v1.13.3 — conta de destino obrigatória no resgate de investimento (build 20261031.1) — 31/10/2026, 15:56

**Status: validada com ressalvas.**

Arquivos: `sistema/investimentos.php`, `sistema/config_versao.php`, `sistema/config_novidades.php`.

Problema: no modal "Resgatar", a conta de destino vinha pré-marcada como "— sem crédito em conta —". Sem escolher a conta, o resgate era gravado só no investimento (obs. "Resgate sem crédito em conta") e não aparecia em Lançamentos.

Mudança: o campo passou a ser obrigatório, começa em "Selecione a conta…" e "sem crédito em conta" ficou como última opção, explícita. O servidor também recusa o POST sem `conta_id`. Regras de `RendaFixaService` e a importação de extrato não mudaram.

Verificado: sintaxe e testes de domínio (ver execução abaixo).

Pendências / ressalvas:
- Não testado em navegador nem em produção; não publicado.
- Resgates já gravados sem conta (e eventuais duplicados das tentativas anteriores) devem ser excluídos no histórico do investimento e refeitos escolhendo a conta.

**Publicação (31/10/2026, ~16:17):** arquivos enviados à VM, tag de reversão `sistema-financeiro-fin_app:rollback-1.13.2`, `docker compose build fin_app`, `php -l` e conferência do texto na imagem nova, troca só do `fin_app` com `--no-deps` (`fin_db` seguiu no ar, healthy). Login responde 200 pelo Caddy. Sem alteração de banco. Tela de resgate não vista autenticada.

## Correção de dados: resgate do Itaú CDB DI refeito (31/10/2026) — carga de dados, sem alterar o código do app

**Status: validada com ressalvas.** Arquivo: `tools/carga/refazer_resgate.php` (novo).

Situação encontrada em produção: dois resgates idênticos de R$ 244,44 em 31/10 no investimento "Itaú CDB DI" (movimentos 221 e 222), gravados sem conta (sem lançamento) e com IR e IOF zerados. Foram removidos e refeitos como **um** resgate pelo valor líquido, na conta Itaú, pelo motor de renda fixa (`rf_resgatar_liquido`): lançamento 1011 (transferência, efetivada), bruto R$ 244,44, IR R$ 0,00, IOF R$ 0,00.

Por que IR/IOF zero: o investimento tem um único lote de R$ 9.502,00 (26/10) e, após o reajuste de −R$ 256,38 (29/10), o saldo bruto é R$ 9.269,51, abaixo do principal; sem ganho, não há IR nem IOF. Se o Itaú cobrou imposto, os campos IR/IOF do modal de resgate são editáveis.

Backup: `backups/financeiro_antes_resgate_itau_20261031_160000.sql`. Reverter: restaurar o backup (ou apagar o lançamento 1011 e seu movimento e recriar os movimentos 221 e 222). Ensaio feito antes (sem gravar) e execução pelo `fin_app`; script apagado do contêiner.

Pendências: tela não vista autenticada; o saldo do investimento depende do reajuste de 29/10 estar correto.

## v1.13.4 — KPIs de rendimento bruto e líquido, mensal e anual, em Investimentos (build 20261031.2) — 31/10/2026, 16:19

**Status: validada com ressalvas.**

Arquivos: `sistema/servicos/RendaFixaService.php` (`rf_rendimento_periodo`), `sistema/servicos/InvestimentoService.php` (`investimentos_rendimento_periodos`), `sistema/investimentos.php` (4 KPIs), `tools/ci/testes_investimentos.php`, `sistema/documentacao/REQUISITOS_E_REGRAS_DE_NEGOCIO.md` (RN-36 a RN-38), `config_versao.php`, `config_novidades.php`.

Fórmula (fonte única no motor): bruto = saldo bruto em hoje − saldo bruto na véspera do início − aportes + resgates brutos; líquido = saldo líquido (IR/IOF de resgatar tudo na data) hoje − o da véspera − aportes + resgates realmente pagos (valor − IR − IOF gravados). Mês = dia 1 até hoje; ano = 1º/jan até hoje; soma de todos os investimentos, inclusive inativos; movimentos depois de hoje ignorados.

Verificado: 21 asserções novas contra oráculos calculados à mão (pré-fixado 12%): bruto do mês, aporte e resgate no meio do período fora do rendimento, líquido com IR 22,5% e IOF de 30% no lote de 21 dias, IR corrigido no resgate reduz o líquido em exatamente R$ 1,00, movimentos futuros não mudam nada, investimento que começou no mês, LCI isenta, manual com reajuste negativo, soma da carteira com inativo. Sintaxe e suítes de teste em PHP 7.4. Com os dados reais de produção (somente leitura, nada gravado): mês R$ 781,59 bruto / R$ 550,51 líquido; ano R$ 3.287,03 / R$ 2.483,17.

Pendências / ressalvas:
- Não publicada; tela não vista no navegador.
- Dias até hoje sem CDI publicado usam o CDI atual, como o saldo exibido em Investimentos (não é projeção de data futura).
- O Itaú CDB DI aparece negativo no mês (−R$ 216,75 bruto) por causa do reajuste de −R$ 256,38 de 29/10; é dado, não erro do cálculo.

**Publicação da v1.13.4 (31/10/2026, ~16:25):** arquivos enviados, tag de reversão `sistema-financeiro-fin_app:rollback-1.13.3`, build do `fin_app`, `php -l` nos três arquivos na imagem nova, troca só do `fin_app` com `--no-deps` (`fin_db` seguiu healthy, 33 h no ar). Login responde 200 pelo Caddy. Sem alteração de banco. Tela com os KPIs não vista autenticada.

## v1.13.5 — painel de KPIs e gráficos por investimento (build 20261031.3) — 31/10/2026, 16:26

**Status: validada com ressalvas.**

Arquivos: `sistema/investimentos.php` (botão "KPIs e gráficos" na coluna Ações, nome do investimento abre o mesmo painel, modal e `id="detalhe"`), `sistema/api_investimento_painel.php` (novo), `sistema/servicos/InvestimentoService.php` (`investimento_painel`), `sistema/servicos/PermissaoService.php` (endpoint no módulo `investimentos`), `sistema/assets/js/inv_painel.js` (novo), `tools/ci/testes_investimentos.php`, `config_versao.php`, `config_novidades.php`.

Painel: 8 KPIs (saldo bruto e líquido, rendimento acumulado, do mês e do ano, bruto e líquido) e 3 gráficos (evolução do saldo com valor aplicado, rendimento por mês, composição do saldo). Tudo até hoje, últimos 36 meses, sem projeção. Rendimento mensal pela mesma fórmula de `rf_rendimento_periodo`. Os lotes e as movimentações continuam na tela de detalhe, aberta pelo botão "Lotes e movimentações" do modal (UX: antes o nome levava a uma seção escondida abaixo da tabela).

Code review (nível high): 7 achados; 5 corrigidos (resposta atrasada de um clique sobrescrevendo outro, aviso quando o Chart.js falha, uma simulação por mês no lugar de três, payload enxuto, teste de aplicações futuras que não falhava), 1 dispensado (reuso de `rf_serie`: não dá os acumulados de impostos pagos).

Verificado: sintaxe e 10 suítes em PHP 7.4; 12 asserções novas do painel (acumulado = motor, soma dos meses = acumulado, último ponto = saldo de hoje, composição fecha, aplicado/aportes sem os agendados, investimento vazio, outro perfil recusado). Com os 5 investimentos reais de produção (só leitura): JSON do painel gerado pelo código novo e `inv_painel.js` exercitado num DOM simulado (8 KPIs e 2–3 gráficos por investimento, rosca ocultada no Itaú sem rendimento positivo, corrida de cliques e falta do Chart.js).

Pendências / ressalvas:
- O visual (modal XL, grade dos gráficos, tooltip do botão) não foi visto em navegador real.

**Publicação da v1.13.5 (31/10/2026, ~16:35):** arquivos enviados, tag de reversão `sistema-financeiro-fin_app:rollback-1.13.4`, build do `fin_app`, `php -l` nos quatro PHP na imagem nova, troca só do `fin_app` com `--no-deps` (`fin_db` seguiu healthy). Pelo Caddy: login 200, `api_investimento_painel.php` sem sessão 401, `inv_painel.js` 200. Sem alteração de banco. Tela autenticada não vista.
