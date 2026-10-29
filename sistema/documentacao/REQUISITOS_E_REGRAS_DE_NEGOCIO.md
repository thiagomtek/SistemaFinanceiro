# Requisitos e regras de negócio

Origem: 14 prints da tela do Mobills (cartão, contas, reajuste de saldo, transações, dashboard, novos lançamentos, filtros) e a planilha `RELATORIO_TRANSACOES` (129 linhas, 4 abas). Tudo aqui foi **inferido** e conferido contra os valores dos prints. O que não deu para confirmar está em "Decisões pendentes". Nenhuma regra abaixo foi implementada ainda sem sua aprovação, conforme o `AGENTS.md`.

Legenda de status: ✅ já existe no sistema · 🟡 existe em parte · ⬜ a fazer. Prioridade: P1 (necessário para carregar seus dados e substituir o Mobills), P2 (importante), P3 (desejável).

---

## 1. Como o Mobills funciona, segundo os prints

| Print | O que mostra | Conclusão |
|---|---|---|
| Cartão: AMAZON (2 prints) | Seletor de cartão, navegação por mês de fatura, lista de itens da fatura, e quatro cartões à direita: **Valor da fatura**, **Status** (Fatura fechada), **Dia de fechamento** (15/out), **Data de vencimento** (28/out). Menu ⋮ leva a **Ajustar fatura** | Fatura é uma entidade com status, fechamento e vencimento próprios |
| Ajustar fatura | Mostra a fatura (28 de outubro de 2026), "Valor atual" e o campo "O valor da sua fatura é de:". Ao confirmar, a fatura passa a valer o número digitado | O reajuste cria ou altera **um item "Reajuste de fatura"** dentro da fatura, com a diferença |
| Contas | Um cartão por conta com **Saldo atual** e **Saldo previsto** (ícone ⓘ), botão "Adicionar despesa", menu ⋮, "Nova conta". À direita, soma de Saldo atual (R$ 93.489,42) e Saldo previsto (R$ 78.929,68) | Duas visões de saldo por conta |
| Reajuste de saldo | Campo com o saldo desejado (R$ 63.817,22), "Saldo inicial da conta R$ 0,00" e duas opções: **Criar transação de ajuste** (cria uma despesa/receita de ajuste) ou **Modificar saldo inicial** (altera o saldo inicial e, segundo o aviso, impacta os saldos de fim de mês). Campo de descrição | Dois modos de reajuste de conta |
| Transações (3 prints) | Cartões Receitas, Despesas, Balanço mensal (e Saldo atual), navegação por mês, tabela com seleção em lote, ícones de situação, linhas "Saldo previsto final do dia", filtros em chips, menu de **Opções de visualização** e painel **Filtro de transações** (período, categorias, contas, tags, situações, tipos, salvar filtro) | Tela central de consulta com saldo corrente projetado |
| Dashboard | Saldo atual, Receitas, Despesas, **Cartão de crédito**, atalho "Meu Desempenho", rosca de despesas e de receitas por categoria | Resumo mensal |
| Botão "+" | Despesa, Receita, Despesa cartão, Transferência | Quatro tipos de lançamento |
| Nova despesa / receita | Valor, "Foi paga/recebida", data (Hoje, Ontem, Outros), descrição (com ♥ de favorito), categoria, conta, anexar arquivo, ignorar transação, tags, observação, **despesa/receita fixa**, **repetir N vezes por período**, "Salvar e criar nova" | |
| Nova despesa cartão | Valor, data, descrição, categoria, **cartão**, **fatura** (seletor, ex.: "4 de nov de 2026"), ignorar transação, tags, observação, despesa fixa, **parcelado N vezes** | A fatura pode ser escolhida manualmente |
| Nova transferência | Valor, data, conta de origem, conta de destino, mais detalhes | |

---

## 2. Regras de negócio inferidas (e conferidas contra os valores dos prints)

**Contas e saldos**

- **RN-01 Saldo atual da conta** = saldo inicial + receitas efetivadas − despesas efetivadas ± transferências efetivadas. Conferido: BV 63.817,22 + Itaú 42,52 + Nubank 20.345,12 + PicPay 9.284,56 = 93.489,42, igual ao "Saldo atual" total.
- **RN-02 Saldo previsto da conta** = saldo atual + lançamentos **pendentes até o último dia do mês corrente** (receitas somam, despesas subtraem). Conferido: Itaú 42,52 − 8.849,41 = −8.806,89 (igual ao print) e BV e PicPay iguais. Exceção em aberto: Nubank (ver D-02).
- **RN-03 Saldo previsto do dia** (linhas na tela de transações) = saldo corrente após cada dia, somando efetivados e pendentes em ordem de data, considerando o filtro de contas aplicado.
- **RN-04 Reajuste de saldo, modo "Criar transação de ajuste"**: diferença = saldo desejado − saldo atual. Cria uma transação efetivada, com a data da operação, na categoria **Reajuste**: receita se a diferença for positiva, despesa se negativa. Descrição padrão "Reajuste*". Os saldos de meses anteriores não mudam.
- **RN-05 Reajuste de saldo, modo "Modificar saldo inicial"**: novo saldo inicial = saldo inicial atual + diferença. Nenhuma transação é criada; saldos históricos mudam.
- **RN-06** A planilha mostra os dois usos: linhas `Reajuste*` com situação Paga e categoria "Reajuste" (PicPay +84,95, Nubank +239,90, Itaú −34,80, BV +366,92) são reajustes de saldo feitos em 03/10/2026.

**Cartões e faturas**

- **RN-07 Fatura** pertence a um cartão e a um mês de vencimento. Tem: valor (soma dos itens), status, dia de fechamento, data de vencimento e conta que a paga.
- **RN-08 Status da fatura**: *aberta* (antes do fechamento), *fechada* (passou do fechamento, ainda não paga) e *paga*. Hoje o sistema só distingue paga e não paga.
- **RN-09 Compra no cartão** entra na fatura pelo dia de fechamento do cartão, mas o usuário pode **escolher a fatura manualmente** (seletor "Fatura" do print). Compra não altera o saldo de nenhuma conta.
- **RN-10 Parcelado** divide o valor em N faturas consecutivas; **fixa/repetição** repete o mesmo valor por período.
- **RN-11 Reajuste de fatura** (a regra que você descreveu): o usuário informa o valor final da fatura. O sistema calcula a diferença e **cria ou atualiza um único item "Reajuste de fatura" dentro daquela fatura**, na categoria **Reajuste de fatura**, de modo que o total da fatura fique igual ao informado. Se a fatura já tem um reajuste, ele é recalculado, não duplicado.
- **RN-12** Se o valor informado for menor que a soma dos itens que não são reajuste, o item de reajuste ficaria negativo. **Proposta:** permitir reajuste negativo (como o Mobills, que mostra o item em outra cor), em vez de bloquear. Ver D-05.
- **RN-13 Pagar fatura** cria uma despesa efetivada na conta pagadora, na data do pagamento, e marca a fatura como paga. Já implementado.
- **RN-14 Agrupamento na conta**: na tela da conta, a fatura aparece como **uma linha "Agrupada cartão"** na data de vencimento, com o valor total da fatura, não item a item. Os itens só aparecem dentro do cartão. Isso explica por que a planilha traz cada fatura como uma única linha.
- **RN-15 No dashboard**, "Cartão de crédito" = soma das faturas do mês. Conferido: 5.679,96 + 128,58 + 369,90 + 3.986,83 = **10.165,27**, igual ao print.

**Receitas, despesas e totais**

- **RN-16** "Receitas do mês" e "Despesas do mês" somam todas as transações do mês pela data, efetivadas e pendentes, incluindo reajustes de saldo e as linhas de fatura na data de vencimento. Conferido: despesas de outubro 13.740,19 e receitas 15.746,13, ambos iguais aos prints.
- **RN-17 Balanço mensal** = receitas − despesas do filtro. Com filtro por conta, **transferências que entram na conta contam como receita e as que saem como despesa** (print: Itaú com receitas 0,00, despesas 8.891,61 e balanço −8.841,61, a diferença de 50,00 é a transferência BV → Itaú de 03/10).
- **RN-18 "Ignorar transação"**: a transação existe, mas não entra em saldos nem totais. Útil para movimentações que não devem contar (ex.: acerto entre contas). ❌ **Retirada** (a função foi removida na v1.11.x: toda transação conta); a coluna `lancamentos.ignorar` ficou sem uso.
- **RN-19 Despesa/receita fixa** é uma série sem data final (o Mobills projeta para frente); **repetir** é uma série com quantidade e período (dias, semanas, meses, anos). Hoje só temos meses.
- **RN-20 Situação visual**: ícone vermelho "!" = pendente vencido ou a vencer; ícone de cartão = linha agrupada de cartão; verde/check = efetivado.

**Importação e dados da planilha**

- **RN-21** A planilha traz cada fatura de cartão como um **item com Conta = conta que paga a fatura** (Itaú, Nubank) e data = **data de vencimento**. O nome do cartão se perde. Por isso o cartão precisa ser **inferido** (ver seção 4).
- **RN-22** Para linhas de fatura importadas, o **mês da fatura é o mês da data (vencimento)**, não calculado pelo fechamento. Se fosse calculado, uma fatura que vence dia 28 (fechamento dia 15) cairia na fatura seguinte.
- **RN-23** A planilha só tem outubro/2026 em diante (histórico anterior não veio). Os **saldos das contas** precisam ser fixados a partir dos prints: o saldo inicial de cada conta será calculado para que o saldo atual fique igual ao do Mobills (RN-05).
- **RN-24** Itens futuros "Pendente" são agendamentos: parcelas (Carro 7/48…, INATEL 8/11, notebook 10/10), recorrências (NIO FIBRA todo dia 20, Plano de Saúde dia 10, Salário e Vale Combustível dia 1) e faturas futuras projetadas. Entram como pendentes, não como efetivados.

---

## 3. Requisitos funcionais

### Contas
| ID | Requisito | Status | Prio |
|---|---|---|---|
| RF-01 | Cadastro de contas com saldo inicial, tipo, ativar/inativar | ✅ | — |
| RF-02 | Exibir saldo atual **e saldo previsto** por conta e no total | 🟡 (só atual) | P1 |
| RF-03 | **Reajuste de saldo** com as duas opções (RN-04/05) e descrição | ⬜ | P1 |
| RF-04 | Atalho "Adicionar despesa" direto no cartão da conta | ⬜ | P3 |
| RF-05 | Logo/ícone e cor por conta (instituição) | ⬜ | P3 |

### Cartões e faturas
| ID | Requisito | Status | Prio |
|---|---|---|---|
| RF-06 | Cadastro de cartões (limite, fechamento, vencimento, conta pagadora) | ✅ | — |
| RF-07 | Visão do cartão por mês de fatura, com seletor de cartão e navegação mês a mês | 🟡 (lista de faturas) | P1 |
| RF-08 | Painel da fatura: valor, **status (aberta/fechada/paga)**, fechamento e vencimento | 🟡 | P1 |
| RF-09 | **Ajustar fatura** (RN-11/12) | ⬜ | P1 |
| RF-10 | Escolher manualmente a fatura ao lançar compra no cartão | ⬜ | P1 |
| RF-11 | Pagar e reabrir fatura | ✅ | — |
| RF-12 | Linha agrupada da fatura na tela da conta (RN-14) | ⬜ | P2 |
| RF-13 | Compra parcelada e fixa no cartão | 🟡 (parcelada sim, fixa não separada) | P2 |

### Transações
| ID | Requisito | Status | Prio |
|---|---|---|---|
| RF-14 | Quatro atalhos de criação: despesa, receita, despesa cartão, transferência | 🟡 (um só formulário) | P2 |
| RF-15 | Campo observação, **ignorar transação**, anexos | ✅ observação e anexos; ❌ ignorar (retirado) | P2 |
| RF-16 | Fixa e repetir com período (dias, semanas, meses, anos) | 🟡 (meses) | P2 |
| RF-17 | "Salvar e criar nova" | ⬜ | P3 |
| RF-18 | Descrições favoritas (♥) para reutilizar | ⬜ | P3 |
| RF-19 | Seleção em lote com ações (efetivar, excluir, alterar categoria/conta) | ⬜ | P2 |
| RF-20 | Linhas "Saldo previsto final do dia" na lista (RN-03) | ⬜ | P2 |
| RF-21 | Opções de visualização (exibir categoria, contas, tags, saldo do dia) | ⬜ | P3 |
| RF-22 | Filtros: período, categorias, contas, tags, situações, tipos + **filtros salvos** | 🟡 (sem tags múltiplas nem salvos) | P2 |
| RF-23 | KPIs Receitas, Despesas, Balanço e Saldo atual coerentes com RN-16/17 | 🟡 | P1 |
| RF-24 | Ícones de situação (RN-20) | 🟡 | P3 |

### Dashboard
| ID | Requisito | Status | Prio |
|---|---|---|---|
| RF-25 | KPIs: saldo atual, receitas, despesas, **cartão de crédito** (RN-15) | 🟡 | P1 |
| RF-26 | Rosca de despesas e de **receitas** por categoria | 🟡 (só despesas) | P2 |
| RF-27 | "Meu desempenho" (evolução e metas) | ⬜ | P3 |

### Dados e importação
| ID | Requisito | Status | Prio |
|---|---|---|---|
| RF-28 | Importar `.xlsx` do Mobills | ✅ | — |
| RF-29 | **Mapa de cartões na importação** (RN-21/22): vincular linhas "conta + dia de vencimento" a um cartão e fixar a fatura pelo vencimento | ⬜ | P1 |
| RF-30 | Reconhecer "Reajuste*" e "Reajuste de fatura" e gravá-los como ajustes | ⬜ | P1 |
| RF-31 | Fixar saldos iniciais a partir dos saldos conhecidos (RN-23) | ⬜ | P1 |
| RF-32 | Categorias de sistema "Reajuste" e "Reajuste de fatura" (não removíveis) | ⬜ | P1 |

### Fora do escopo por enquanto
Várias moedas (seletor BRL), tema escuro e logos de bancos. O seletor de moeda aparece nos prints, mas não há necessidade informada.

---

## 4. Interpretação da planilha e plano de carga

**Cartões inferidos** (cruzando prints e planilha; **precisa da sua confirmação**):

| Cartão | Paga com | Vence dia | Evidência |
|---|---|---|---|
| AMAZON | Itaú | 28 (fecha 15) | Print do cartão e linha "Reajuste de fatura" 128,58 em 28/10 |
| Black Personalite | Itaú | 25 | Linha "Reajuste de fatura" 5.679,96 em 25/10 |
| LUIZA | Itaú | 25 | Linha "notebook (10/10)" 369,90 em 25/10 (o print mostra "LUIZA 369,90") |
| Nubank (cartão) | Nubank | 4 | Linha "Reajuste de fatura" 3.986,83 em 04/10 e o formulário de cartão com fatura "4 de nov" |

Regras de inferência propostas para as linhas importadas: conta Itaú + dia 28 → AMAZON; conta Itaú + dia 25 + descrição "Reajuste de fatura" → Black Personalite; conta Itaú + dia 25 + parcela de compra → LUIZA; conta Nubank + dia 4 → cartão Nubank. As demais linhas (salários, NIO FIBRA, Plano de Saúde, Carro, INATEL, DIVERSAS, imposto) são lançamentos normais de conta.

**Carga proposta (depois da aprovação):**
1. Criar contas BV, Itaú, Nubank, PicPay; cartões acima; categorias de sistema.
2. Importar as 129 linhas aplicando RN-21/22/24 (faturas pelo vencimento, pendentes como agendadas).
3. Calcular o saldo inicial de cada conta para o saldo atual bater com os prints (BV 63.817,22; Itaú 42,52; Nubank 20.345,12; PicPay 9.284,56; total 93.489,42).
4. Conferir: saldo previsto, receitas/despesas de outubro (15.746,13 / 13.740,19) e cartão de crédito (10.165,27).

---

## 5. Requisitos não funcionais

- **RNF-01** Mesmo padrão visual e arquitetura do ERP (`AGENTS.md`, `PADROES_INTERFACE.md`), PHP 7.4 e MariaDB 10.5.
- **RNF-02** Todo cálculo de saldo, previsto, fatura e reajuste fica em **uma única fonte** em `sistema/servicos/`, usada por contas, transações, dashboard e projeção (hoje há risco de divergência entre telas).
- **RNF-03** Valores monetários em `DECIMAL(14,2)`, arredondados a 2 casas; reajustes e parcelas nunca perdem centavos.
- **RNF-04** Operações de reajuste e pagamento em transação de banco (tudo ou nada) e registradas com data e usuário.
- **RNF-05** Importação sem duplicar (reimportar a mesma planilha não cria linhas) e com revisão antes de gravar.
- **RNF-06** Mudanças de estrutura do banco com backup e registro em `ALTERACOES_DE_BANCO.md`.
- **RNF-07** Testes automatizados para cada regra RN-01 a RN-24 que envolva cálculo, usando os valores conferidos dos prints como casos de teste.
- **RNF-08** Funcionar bem no celular, já que o Mobills é usado assim.

---

## 6. Decisões (respondidas em 26/10/2026)

| ID | Decisão |
|---|---|
| D-01 | Cartões confirmados pelos prints: **Nubank** (limite 29.900, vence dia 4, paga com Nubank), **Black Personalite** (38.000, vence 25, Itaú), **LUIZA** (4.301, vence 25, Itaú), **AMAZON** (6.000, fecha 15, vence 28, Itaú). A inferência bateu exatamente com o "limite utilizado" dos prints. Os dias de fechamento de Nubank, Black e LUIZA estão **provisórios** (28, 18 e 18) e devem ser corrigidos na tela de cartões. |
| D-02 | A diferença do Nubank (861,75) deixa de importar: **a carga começa em 26/10/2026**, ignorando tudo o que é anterior, e os saldos das contas correntes serão informados pelo Thiago (reajuste de saldo). |
| D-03 | Reajustes **contam** em receitas e despesas. |
| D-04 | O padrão do reajuste de saldo é **criar transação de ajuste**. |
| D-05 | Reajuste de fatura negativo é **permitido, com aviso**. |
| D-06 | Compra no cartão sem fatura escolhida usa o **fechamento** (o seletor sobrescreve). |
| D-07 | Implementar o P1 e carregar os dados: **feito na v1.1.0** (ver abaixo). |

### Status de implementação (v1.1.0)

✅ RF-02 saldo previsto · ✅ RF-03 reajuste de saldo · ✅ RF-08 painel da fatura com status · ✅ RF-09 ajustar fatura · ✅ RF-10 escolher a fatura · 🟡 RF-07 visão do cartão por mês (página `fatura.php`) · ✅ RF-23 KPIs com transferências no filtro de conta · ✅ RF-25 KPI "Cartão de crédito" · ✅ RF-29 mapa de cartões na importação · ✅ RF-30 reconhece reajustes · ✅ RF-31 saldos por reajuste · ✅ RF-32 categorias de sistema.
Tudo reproduzido em teste com os valores dos prints (`tools/ci/testes_reajustes_mobills.php`): limites utilizados, cartão de crédito de outubro (10.165,27), receitas e despesas de outubro, saldo atual (93.489,42) e saldo previsto das contas BV, Itaú e PicPay.
**v1.2.0 — fatura por PDF, CSV ou Excel:** leitura por IA (PDF, inclusive com senha) ou direta (CSV/XLSX), comparação de cada item com o banco (novo, possível duplicado, já existe) e **aprovação item a item ou de todos os novos** antes de gravar; créditos entram como itens negativos e a fatura pode ser ajustada ao total do arquivo. Testado com as faturas reais do Nubank (88 itens, total R$ 3.986,83) e do Itaú Black (39 itens, total R$ 5.676,85): em ambas a soma dos itens lidos fecha com o total.
**v1.3.0 — P2 concluído:** ✅ RF-12 linha agrupada da fatura · ✅ RF-15 observação e anexos (ignorar transação foi retirado depois) · ✅ RF-16 repetição por dias/semanas/meses/anos · ✅ RF-17 salvar e criar nova · ✅ RF-19 seleção em lote · ✅ RF-20 saldo previsto do dia · ✅ RF-21 opções de visualização · ✅ RF-22 filtros salvos.
Ainda não feitos (P3): RF-04 atalho "adicionar despesa" na conta, RF-05 logos/cores, RF-14 quatro atalhos de criação, RF-18 favoritos, RF-24 ícones de situação, RF-26 rosca de receitas, RF-27 "Meu desempenho".
**Fora do escopo (decisão de 26/10/2026):** importação de maquininhas (Cielo, PagBank) e conferência de vendas no cartão ficam **somente no Sistema ERP**; não serão implementadas aqui.

---

## 7. Ordem sugerida de implementação

1. **Fundação (P1):** campo "ajuste" e categorias de sistema, serviço único de saldo atual e previsto, reajuste de saldo (RF-02, RF-03, RF-32).
2. **Cartões (P1):** painel da fatura com status, ajustar fatura, escolher fatura ao lançar (RF-07 a RF-10).
3. **Carga (P1):** mapa de cartões na importação, saldos iniciais e carga dos seus dados, com conferência contra os prints (RF-29 a RF-31).
4. **Telas (P1/P2):** KPIs e dashboard coerentes com RN-15/16/17, saldo previsto do dia, seleção em lote, filtros salvos, campos extras do lançamento.
5. **P3:** favoritos, opções de visualização, desempenho, "salvar e criar nova".

## 8. Investimentos, CDI e transferências (v1.4.0)

### Como os produtos funcionam (resumo para o sistema)
- **Nubank (caixinhas/RDB), BV (CDB), PicPay (cofrinhos), Sicredi e Itaú (CDB/LCI/LCA/fundos):** em renda fixa atrelada ao CDI o app mostra o **saldo bruto** crescendo todo dia útil; o imposto só sai no **resgate**. Por isso o sistema guarda o bruto e calcula o líquido estimado.
- **Rendimento:** cada dia útil o saldo multiplica por `1 + CDI do dia × (% do CDI ÷ 100)`. Ex.: 110% do CDI com CDI de 13,65% a.a.
- **Imposto de renda (CDB, RDB, cofrinhos, fundos de renda fixa):** tabela regressiva sobre o rendimento, pelos dias corridos desde a aplicação: até 180 dias 22,5%; 181–360 dias 20%; 361–720 dias 17,5%; acima de 720 dias 15%.
- **IOF:** só nos primeiros 29 dias, sobre o rendimento, decrescente (96% no dia 1 até 3% no dia 29); é abatido antes do IR.
- **LCI/LCA e poupança:** isentos para pessoa física. Poupança: 0,5% ao mês enquanto a Selic estiver acima de 8,5% ao ano.
- **Resgate:** consome as aplicações da mais antiga para a mais nova (PEPS), e cada aplicação tem a sua faixa de IR.
- **Fonte do CDI:** Banco Central (séries SGS 12 CDI diário, 4389 CDI anualizado, 432 Selic), atualizada sozinha a cada 4 horas no máximo.

### Regras
- RN-I1: aplicação = transferência de conta corrente para investimento: debita a conta e aumenta o investimento, sem contar em receita/despesa.
- RN-I2: resgate = transferência do investimento para a conta: credita o **valor líquido** (após IR/IOF); o imposto fica registrado no movimento.
- RN-I3: transferência entre contas não é receita nem despesa.
- RN-I4: reajuste de investimento lança a diferença como rendimento (ou perda), sem mexer em conta corrente.
- RN-I5: aplicação ou resgate futuros ficam pendentes e entram na projeção.
- RN-I6: a projeção usa o CDI de hoje, ou o cenário informado, e mostra bruto e líquido.

## 9. Importação de extratos (v1.5.0)

- RN-E1: cada arquivo pertence a **uma conta ou um investimento** (sugerido pelo banco/produto; o usuário confirma). Valor positivo é entrada nesse destino; negativo, saída.
- RN-E2: a IA classifica cada linha: receita, despesa, transferência, aplicação, resgate, rendimento, imposto/tarifa ou pagamento de fatura. Linhas de saldo e totais são descartadas. Se o extrato traz saldo inicial e final, a soma das linhas é conferida e a diferença vira aviso.
- RN-E3: **pareamento**: uma saída de um destino e uma entrada em outro, de mesmo valor, com até 3 dias de diferença, e pelo menos uma das pontas classificada como transferência, aplicação ou resgate, viram **uma única transferência**. Receita e despesa de mesmo valor não são pareadas. Entre investimento e conta a entrada pode ser até 25% menor (IR/IOF).
- RN-E4: o que cada linha vira: receita/despesa (em conta); transferência entre contas; aplicação (conta para investimento); resgate (investimento para conta, líquido do imposto); rendimento (só em investimento de taxa manual; em investimento indexado ao CDI o sistema já calcula, então é ignorado); pagamento de fatura (quita a fatura em aberto de mesmo valor); ignorar.
- RN-E5: pagamento de fatura sem fatura correspondente é **ignorado por padrão**, para não contar duas vezes as compras do cartão.
- RN-E6: IR e IOF listados no extrato do investimento são ignorados (o sistema calcula no resgate).
- RN-E7: no resgate, o valor da conta é o líquido; o bruto é calculado pelo motor de renda fixa e a conta recebe exatamente o valor do extrato.
- RN-E8: **duplicidade**: "Já existe" (mesma linha já importada, por hash) ou "Possível duplicado" (lançamento ou movimento de investimento de mesmo valor, em data até 3 dias de diferença, na mesma conta/investimento). Só linhas novas e válidas vêm marcadas; o usuário aprova item por item ou todas as novas.
- RN-E9: nada é gravado antes da aprovação; o arquivo e a senha do PDF não ficam guardados (só o JSON da revisão, por até 24 h).

### Atualizações da v1.6.0

- RN-I7: **reajuste de investimento** informa o saldo **bruto** (padrão, como BV/Nubank mostram) ou o **líquido** (após IR e IOF de um resgate total na data). Em líquido, o sistema calcula o bruto equivalente. O movimento de reajuste é sempre sobre o bruto.
- RN-I8: aplicação e resgate usam **contas bancárias**; contas do tipo investimento não são origem nem destino.
- RN-I9: **posição inicial**: investimento que já existia entra com saldo bruto exato. Com o IR estimado do banco, a data da aplicação equivalente é a que reproduz esse IR; sem IR, usa 31 dias (sem IOF), ou hoje quando a aplicação foi feita hoje.
- RN-E10: **data de corte**: movimentações anteriores são descartadas; saldos atuais informados pelo usuário prevalecem (acertados por reajuste).
- RN-E11: **pareamento** só entre pontas compatíveis: conta e conta (as duas como transferência), conta para investimento (aplicação), investimento para conta (resgate). Resgate numa conta nunca pareia com outra conta.
- RN-E12: extrato de **custódia** (saldo por produto) vira posição: reajusta o investimento existente (saldo bruto) ou cria um novo.
- Removido: filtros salvos em lançamentos.

### Projeção (v1.6.1)

- RN-P1: tudo que sobra no mês (receitas − despesas − faturas, incluindo aplicações e resgates agendados) é **investido no fim do mês**, a 100% do CDI por padrão (cenário conservador, ajustável), com IR regressivo e IOF; os aportes rendem nos meses seguintes.
- RN-P2: mês de déficit sai do saldo da conta; a sobra só é investida depois que a conta volta a ficar positiva.
- RN-P3: o aviso de conta negativa só aparece no **dia exato** em que o saldo das contas fica abaixo de zero (simulação dia a dia, faturas no vencimento) e informa quanto há em investimentos (líquido) que poderia cobrir com resgate. Não se conclui saldo negativo pelo total do mês.

- RN-03 (v1.6.2): o **saldo previsto do dia** mostra contas, investimentos (bruto e líquido de IR/IOF se resgatasse tudo no dia) e o total (contas + investimentos), bruto e líquido de impostos.

- RN-I2 (v1.6.3): o **resgate** é informado pelo valor **líquido na conta**; o investimento é debitado de líquido + IR + IOF. IR e IOF podem ser editados (o banco pode cobrar mais). Resgate total debita o saldo inteiro.

- RN-18 (revogada na v1.7.0): não existe mais transação ignorada. Toda transação entra em saldos, faturas e totais.

- RN-16 (v1.8.0): receita/despesa **fixa não tem quantidade**: é sem fim e termina quando o usuário a exclui. O sistema mantém as ocorrências geradas até 24 meses à frente.
- RN-19 (v1.8.0): excluir lançamento de série (fixa, parcelada ou repetida) pergunta: somente este; este e os próximos ainda não confirmados; todos da série, inclusive confirmados.

## 9. Revisão geral de 27/10/2026 — regras ajustadas (v1.12.0)

Autorizadas pelo Thiago em 27/10/2026 ("corrija tudo"), depois de a revisão apresentar a regra anterior, a proposta e o impacto.

| Regra | Antes | Agora |
|---|---|---|
| **RN-25 Saldo restante** (pagamento parcial de fatura) | O item "Saldo restante" entrava como despesa de cartão em receitas × despesas, categorias e média da projeção, duplicando o que já fora contado nas compras (R$ 1.000 comprados e R$ 300 pagos davam R$ 1.700 de despesa) | O item continua na fatura seguinte (é cobrança real e entra em saldos, limite e projeção de caixa), mas **não conta como despesa nova** em totais, categorias e médias (`sql_sem_restante`) |
| **RN-26 Totais da lista de Lançamentos** | Somava as compras do cartão **e** o pagamento da fatura (dupla contagem) e divergia do Dashboard | Sem filtro de conta: o pagamento de fatura não entra (as compras já contam), igual ao Dashboard. Filtrando por uma conta: o pagamento entra, pois é a saída do dinheiro daquela conta (`lancamentos_totais`) |
| **RN-27 Leitura de valores** | "1.500" (sem vírgula) era 1,5 | Sem vírgula, ponto seguido de exatamente 3 dígitos (e sem zero à esquerda) é separador de milhar: "1.500" = 1500; "12.5", "0.500" e "1234.567" seguem decimais. Taxas e percentuais usam o ponto sempre como decimal |
| **RN-28 Média da projeção** | Subtraía só os avulsos pendentes e as compras de cartão de hoje em diante | Subtrai também o que já foi pago/recebido e comprado no cartão **neste mês** (já estão no saldo e nas faturas) |
| **RN-29 Categoria** | Qualquer categoria podia ir para qualquer lançamento (só a tela filtrava) | O servidor exige categoria do mesmo tipo do lançamento, inclusive no lote; categoria com lançamentos ou subcategorias não troca de tipo |
| **RN-30 Séries fixas** | Excluir em lote o fim de uma série fixa fazia as ocorrências voltarem | Excluir o fim da série a encerra, como o escopo "este e os próximos" |
| **RN-31 Administrador por espaço** | "Sempre ao menos um administrador" contava os de todos os espaços de dados | Conta só os do mesmo espaço (`dono_id`); não é possível inativar o próprio usuário editando |

## 10. Notificações push (v1.13.0)

Pedido do Thiago em 29/10/2026. Não altera regras de cálculo; amplia a entrega dos avisos do dia (RN dos avisos: contas a pagar/receber de hoje, lançamentos atrasados e fatura fechada no dia anterior).

- **RN-32** O aviso push só vai para quem ativou as notificações em um aparelho (Meu perfil → "Ativar notificações neste aparelho") e respeita as permissões do usuário: quem não pode ver lançamentos ou cartões não recebe esses avisos.
- **RN-33** Cada aviso é enviado uma única vez por usuário (mesma chave do sino), às 8h, a todos os aparelhos dele. Usuário inativo não recebe.
- **RN-34** O usuário pode enviar um teste e desativar qualquer aparelho dele; um aparelho é de quem entrou por último nele.
- **RN-35** O Financeiro e o ERP não compartilham chaves, assinaturas, origem nem agendamento (ver DOCUMENTACAO_TECNICA).

## 11. Histórico importado e média da projeção (v1.13.2)

Autorizada pelo Thiago em 29/10/2026, depois de a simulação da carga do histórico (planilha do Mobills, dez/2025 a set/2026) mostrar a distorção da projeção.

| Regra | Antes | Agora |
|---|---|---|
| **RN-32 Média da projeção e histórico importado** | A média dos 3 últimos meses completos somava todo lançamento avulso, inclusive os vindos de importação | Lançamentos de importações marcadas como histórico (`importacoes.tipo = 'historico'`) ficam fora da média (`sql_sem_historico`): o histórico não traz a marca de série (salário, faturas) e contaria em dobro o que a projeção já agenda. Continuam valendo em lançamentos, faturas, extrato e relatórios. |
