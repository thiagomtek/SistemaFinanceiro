# Padrões de interface

Este sistema reproduz os padrões do ERP (`PADROES_INTERFACE_DASHBOARDS.md` e `vendas_vendedor.php` no repositório do ERP). Resumo do que está aplicado aqui:

- Layout: `admin_layout.css` do ERP (navbar branca, sidebar escura com seções `nav-header`, `content-header` com breadcrumb iniciado em `Início`, card externo `card-outline` com bloco de ícone, título e subtítulo).
- KPIs (`.kpi`): borda esquerda colorida, rótulo em caixa alta, valor em destaque, ícone suave e detalhe auxiliar. Cores semânticas: verde = receita/positivo, vermelho = despesa/risco, azul = informação, laranja = atenção, roxo = cartão.
- Cards internos (`.painel`) brancos, borda discreta, raio 8px. Tabelas com cabeçalho `#f8fafc`, números à direita, status e ações centralizados, estado vazio explícito.
- Botões `btn btn-sm`, ícone + rótulo; filtros em `.filtros`; pílulas para filtros equivalentes.
- Formato pt-BR (moeda BRL, datas dd/mm/aaaa). Texto/ícone branco só sobre fundo escuro.
- Modais com título contextual, fechar claro e confirmação explícita em ações destrutivas (`data-confirmar` ou modal próprio).
- Gráficos (Chart.js, local em `assets/js/`) com título e ícone; instâncias destruídas antes de recriar; textos da API escapados antes de `innerHTML`.
- Assets sempre via `asset_url()` (cache-busting por versão/build de `config_versao.php`).

Pendências de padrão em relação ao ERP: tooltips `data-toggle` nos KPIs, paginação tripartite com seletor de itens por página e ordenação dinâmica de colunas nas tabelas.

## Alinhamento ao ERP (v1.3.3)

Aplicado a partir da especificação do `PADROES_INTERFACE_VENDAS_VENDEDOR.md` e do `erp_design_system.css` (copiado do ERP):
- `.content-header` com título (ícone semântico) e subtítulo à esquerda e breadcrumb `Início / Tela` à direita; sem card externo repetindo o título.
- KPIs compactos no formato `erp-kpi-card` (cartão branco, borda esquerda colorida, rótulo em caixa alta, valor 20px, ícone circular); implementados com a classe `.kpi` do sistema, que reproduz esses valores.
- Listagens em `card card-outline` com a ação de criar no `card-header`; tabelas `table-striped table-hover` 13px com cabeçalho `#f8fafc`; botões de ação `cli-btn-acao` / `erp-btn-acao`; paginação tripartite (resumo, páginas e seletor 10/20/25/50/100/Todos) em lançamentos.
- Modais `fin-modal` (cabeçalho escuro, rodapé `#f8fafc`, tamanhos `erp-modal-md/lg`), confirmações por `ERP.confirm` e avisos por `ERP.toast` (`erp_dialogs.js`), badges `erp-badge`, estado vazio `erp-empty-state`, símbolos R$/% embutidos (`fin-field-prefix/suffix`).
- Ainda diferentes do ERP: tooltips nos KPIs, ordenação de colunas por clique, switches/checkboxes customizados (`erp-switch`/`erp-checkbox`) e paginação das demais listas (que são curtas).
