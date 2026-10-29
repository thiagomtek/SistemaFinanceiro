# Instruções do projeto Sistema Financeiro

Sistema financeiro pessoal em PHP + MariaDB, irmão do Sistema ERP e seguindo a mesma arquitetura e os mesmos padrões. Em dúvida sobre um padrão, consulte o equivalente no repositório do ERP (somente leitura).

## Arquitetura e organização

- Código em `sistema/` (telas PHP na raiz, `api_*.php` para endpoints, `servicos/*Service.php` para regras de negócio). `index.php` na raiz apenas redireciona para `sistema/login.php`.
- Toda tela inclui `auth_check.php` no topo e usa `header.php` / `footer.php` (`$PAGINA = [titulo, icone, arquivo_ativo, subtitulo, cor]`). Endpoints `api_*.php` definem `$API_JSON = true` antes do include e respondem JSON.
- Regras de negócio têm **fonte única** em `servicos/`. Antes de criar código novo, procure uma implementação existente e reutilize; não duplique cálculos, consultas ou validações em telas.
- Todo dado pertence a um **perfil interno** (criado automaticamente, um por usuário; a tela não oferece mais PF/PJ nem troca de perfil) e toda consulta é escopada por `perfil_id()`. Nunca consulte sem esse escopo.
- Design: mesmo visual do ERP (`assets/css/admin_layout.css` copiado do ERP + `financeiro.css`). Siga `documentacao/PADROES_INTERFACE.md`.
- Código compatível com **PHP 7.4+** (CI do ERP usa `php:7.4-cli`) e **MariaDB 10.5**. Não use recursos exclusivos do PHP 8 nem SQL fora do MariaDB 10.5.

## Governança (mesmos guardrails do ERP)

- **Regra de negócio**: se uma mudança alterar, substituir ou contrariar uma regra existente, pare, apresente a regra atual, a proposta e os impactos, e peça autorização explícita.
- **Escopo**: altere só os arquivos da funcionalidade pedida. Achados fora do escopo são registrados e perguntados, não corrigidos por iniciativa própria.
- **Incerteza**: sem evidência suficiente sobre regra, formato ou intenção, pergunte antes de implementar.
- **Alterações de estrutura do banco** (nova coluna/tabela) em produção: backup antes, SQL em `sql/alteracoes/AAAAMMDD_descricao.sql` que possa rodar mais de uma vez, compatível com MariaDB 10.5, espelhado em `sql/schema.sql` e registrado em `documentacao/ALTERACOES_DE_BANCO.md`. Não há banco legado a preservar neste sistema.
- **Integração com o ERP é somente leitura**: nunca escrever nas tabelas do ERP (`tagcomercio`) nem no MySQL legado da loja. Usar usuário apenas com `SELECT` e nunca apontar para o MySQL legado.
- **Servidor**: a VM é produção compartilhada. O Thiago liberou atuar no servidor Docker (incluindo o Caddy) com **uma única regra: nunca derrubar serviço em funcionamento**. Use `caddy reload` (nunca restart), não use `docker compose down -v`, `prune`, nem reinicie Docker/VM, e trabalhe nos contêineres do próprio serviço (`fin_app`, `fin_db`). Edite o `Caddyfile` por append (é bind mount de arquivo único) e faça backup antes.
- **Segredos**: credenciais, chaves SSH e senhas nunca entram no repositório, em arquivos versionados, em documentação ou em respostas. Fatura em PDF com senha: a senha é usada em memória e nunca gravada.

## Ao concluir qualquer alteração

1. Incrementar `sistema/config_versao.php` (versão e build) e registrar a entrada no topo de `sistema/config_novidades.php`, com hora real (`date '+%d/%m/%Y %H:%M'`).
2. Registrar a validação em `documentacao/VALIDACOES_FUNCIONALIDADES.md` (arquivos, itens verificados, evidências, pendências e status: `validada`, `validada com ressalvas` ou `não validada`). Não marcar como validada sem evidência.
3. Rodar `bash tools/ci/validar_php.sh` e `php tools/ci/testes_dominio.php`.
4. Resposta ao usuário direta: o que mudou, o que foi validado e o que exige atenção.
