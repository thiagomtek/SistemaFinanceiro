# Sistema Financeiro

Gerenciador financeiro pessoal, inspirado no Mobills, na mesma arquitetura do Sistema ERP: PHP (compatível com 7.4+) + MariaDB 10.5, Docker, visual `admin_layout.css` do ERP.

- Instruções para desenvolvimento e governança: [AGENTS.md](AGENTS.md)
- Arquitetura, estrutura e deploy: [sistema/documentacao/DOCUMENTACAO_TECNICA.md](sistema/documentacao/DOCUMENTACAO_TECNICA.md)
- Requisitos e regras de negócio (inferidos do Mobills): [sistema/documentacao/REQUISITOS_E_REGRAS_DE_NEGOCIO.md](sistema/documentacao/REQUISITOS_E_REGRAS_DE_NEGOCIO.md)
- Validações registradas: [sistema/documentacao/VALIDACOES_FUNCIONALIDADES.md](sistema/documentacao/VALIDACOES_FUNCIONALIDADES.md)

## Funcionalidades (v1.0.0)

Importar fatura do cartão por **PDF (lido por IA), CSV ou Excel** com aprovação item a item e checagem de itens já existentes · reajuste de saldo e de fatura · lançamentos com lote, filtros salvos, saldo previsto do dia, ignorar, observação e anexos · contas · categorias/subcategorias · receitas, despesas e transferências (fixas e parceladas) · cartões e faturas · investimentos · importação `.xlsx`/`.csv` (padrão Mobills e faturas de cartão) com vínculo a cadastros existentes e sem duplicar · projeção de saldo/patrimônio · dashboard.

## Executar localmente (sem MySQL)

```bash
php tools/ci/init_sqlite.php dev.sqlite
DB_DRIVER=sqlite DB_SQLITE=dev.sqlite php -S 127.0.0.1:8765 -t .   # http://127.0.0.1:8765/sistema/login.php
php tools/ci/testes_dominio.php                                   # regras de negócio + importação
bash tools/ci/validar_php.sh                                      # sintaxe
```

## Subir com Docker (VM do homelab)

```bash
cp .env.example .env      # defina as senhas
docker compose up -d --build   # fin_app em 172.17.0.1:8097 e fin_db (MariaDB 10.5)
```

O HTTPS fica a cargo do Caddy (bloco novo, manual). Veja os cuidados de produção em `AGENTS.md`.

## Fora do escopo

Importação de maquininhas (Cielo, PagBank) e conferência de vendas no cartão: ficam apenas no Sistema ERP.

## Pendências (P3)

Atalho "adicionar despesa" na conta, logos/cores das contas, favoritos de descrição, rosca de receitas por categoria e "Meu desempenho".
