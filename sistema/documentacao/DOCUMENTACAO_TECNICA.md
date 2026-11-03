# Documentação técnica

## Arquitetura (espelha o ERP)

```
Usuário → Tailscale → Caddy (HTTPS) → fin_app (PHP/Apache, 172.17.0.1:8097) → fin_db (MariaDB 10.5, banco "financeiro")
```

- Instalado em 26/10/2026 na VM Ubuntu (VirtualBox no PC Windows), na pasta própria `/mnt/d/Servidores/Servicos/SistemaFinanceiro/`, com Docker Compose (`docker-compose.yml`, projeto `sistema-financeiro`, contêineres `fin_app` e `fin_db`). Imagem baseada em `php:7.4-apache` com o mesmo ajuste de apt/instalador de extensões do ERP.
- Banco **próprio** (`fin_db`), sem sincronização e separado do `erp_db`. Mesma versão (MariaDB 10.5).
- Integração futura com o ERP: somente leitura, via usuário `SELECT` no `erp_db`/`tagcomercio`. Nunca o MySQL legado da loja.
- Acesso apenas via Tailscale, como o ERP. URL: `https://servidor.tail074692.ts.net:8453/sistema/login.php`. O bloco `servidor.tail074692.ts.net:8453` foi anexado ao `Caddyfile` (porta já publicada no range 8444-8453; backup em `Caddyfile.bak-pre-financeiro`) e aplicado com `caddy reload`, sem reiniciar o contêiner. A porta do app é publicada só em `172.17.0.1:8097` (docker0), como o remote-hub; o Caddy a alcança por `host.docker.internal`.
- Login separado do ERP (tabela `usuarios` própria, `password_hash`).

## Estrutura de código (`sistema/`)

| Arquivo/pasta | Papel |
|---|---|
| `auth_check.php` | Guarda de login (`$PAGINA_PUBLICA`, `$API_JSON`) |
| `bootstrap.php` | Sessão, timezone, carregamento dos serviços |
| `bd.php` | Conexão PDO e atalhos `q*` |
| `header.php` / `footer.php` | Layout (via `servicos/LayoutService.php`) |
| `servicos/CadastroService.php` | Contas, cartões, categorias, saldos |
| `servicos/CartaoService.php` | Faturas, pagamento, reabertura |
| `servicos/LancamentoService.php` | Validação, repetição/parcelas, edição, exclusão |
| `servicos/InvestimentoService.php` | Aportes, resgates, rendimentos |
| `servicos/ProjecaoService.php` | Projeção de saldo e patrimônio |
| `servicos/RiscoService.php` | Risco do patrimônio frente à Selic: cenários (projeção com o CDI deslocado) e exposição por tipo de rendimento e por instituição (RN-44); API `api_projecao_risco.php` |
| `servicos/InflacaoService.php` | Inflação real e percebida: IPCA/INPC/dólar (BCB), IPCA por grupo e item (IBGE/SIDRA), Focus, juro real, reajustes recorrentes e cesta do usuário (RN-45, RN-46) |
| `servicos/CenarioService.php` | Cenário econômico: manchetes RSS, leitura por IA (Gemini, só manchetes e indicadores públicos), dia, fechamento mensal e rotina `cenario_atualizar_tudo` (RN-48) |
| `servicos/LimiteService.php` | Limites por janela (IP, usuário, dia), IP do cliente e cota de IA (RN-52) |
| `servicos/CadastroPublicoService.php` | Cadastro público, modos, aprovação e permissões padrão (RN-51); `cadastro.php` é a página pública |
| `servicos/CenarioFiltroService.php` | Filtros das manchetes (fonte confiável, ruído, país, tema) e a lista editável de fontes (RN-48a) |
| `servicos/AlocacaoService.php` | Sugestões por classe de ativo: classificação, perfil e faixas, reserva de emergência, fatores do momento, Tesouro e retorno mínimo do aluguel (RN-49); API `api_cenario_perfil.php` (POST com CSRF) salva o perfil |
| `servicos/DashboardService.php` | Agregações do dashboard |
| `servicos/ImportacaoService.php` / `XlsxReaderService.php` | Leitura e aplicação de planilhas |
| `servicos/PerfilService.php` | Usuário e perfil interno (um por usuário) |
| `config_versao.php` / `config_novidades.php` | Versão e changelog |

Nota: os serviços ainda são funções (como `pdv_utils.php` no ERP), não classes estáticas. Novos serviços (PDF com IA, maquininhas, integração com ERP) devem seguir o estilo de classe `XxxService`, como `AjudaIaService`.

## Modelo de dados (`sql/schema.sql`)

`usuarios` → `perfis` (interno, um por usuário; sem PF/PJ na interface) → `contas`, `categorias` (com `pai_id`), `cartoes` → `faturas`, `lancamentos` (tipo receita/despesa/transferencia, `valor` sempre positivo), `investimentos` → `investimento_movs`, `importacoes`.

Regras principais: compra no cartão não mexe no saldo e entra na fatura (`fatura_mes` = mês do vencimento) conforme o fechamento; pagar a fatura cria uma despesa na conta (`lancamentos.fatura_id`); a importação gera `hash_unico` por linha para não duplicar.

## Deploy

1. Copiar `.env.example` para `.env` na pasta do serviço e definir senhas.
2. `docker compose up -d --build` somente dentro da pasta do serviço.
3. `sql/schema.sql` roda só na primeira criação do volume `fin_db_data`. Mudanças de estrutura posteriores: `sql/alteracoes/` (veja `ALTERACOES_DE_BANCO.md`), com backup.
4. Validar `curl http://172.17.0.1:8097/sistema/login.php` e o acesso pelo Caddy.
5. Atualização de código: enviar os arquivos para a pasta do serviço e rodar `docker compose up -d --build` nela (recria só `fin_app`; `fin_db` e seus dados são mantidos). Nunca usar `down -v`.

O `.env` (senhas aleatórias) existe só no servidor e não é versionado.

## Leitura de fatura por IA (v1.2.0)

- Tela `importar_fatura.php` (PDF, CSV ou XLSX) → `servicos/FaturaImportService.php` (fluxo e checagem de itens existentes) → `servicos/FaturaPdfService.php` (PDF + Gemini).
- O PDF é enviado como arquivo ao Gemini (`generativelanguage.googleapis.com`) com saída JSON estruturada; faturas de banco têm colunas lado a lado e texto puro embaralha valores. Modelos tentados em ordem: gemini-3.5-flash, gemini-flash-latest, gemini-3.5-flash-lite, gemini-flash-lite-latest (com nova tentativa em caso de alta demanda).
- **Chave:** variável de ambiente `GEMINI_API_KEY` no `.env` do servidor (mesma conta usada pelo ERP); nunca no repositório.
- **Privacidade:** o conteúdo do PDF vai ao Google só para extração; o arquivo e a senha não são guardados (a senha só existe durante a requisição; `qpdf` remove a proteção em arquivo temporário 0600 apagado em seguida). Só o JSON dos itens fica em `uploads/tmp` até a confirmação (apagado ao confirmar/cancelar ou em 24 h).
- A IA pode errar: por isso há a tela de aprovação, o total do PDF contra a soma dos itens e o ajuste opcional da fatura.
- Itens já existentes: mesmo hash (perfil, data, descrição, valor, cartão) = "já existe"; mesmo valor em data ±3 dias no cartão = "possível duplicado".

## Investimentos e CDI (v1.4.0)

- `IndiceService.php`: calendário de dias úteis (feriados nacionais, Páscoa), busca no Banco Central (`bcb_buscar`, simulável em teste), tabelas `indices` e `parametros`, atualização preguiçosa (`indices_garantir`).
- `RendaFixaService.php`: motor de rendimento por lote (aplicação), IR regressivo, IOF, PEPS, aplicar, resgatar, reajustar e série de projeção.
- `InvestimentoService.php`: catálogo de produtos com predefinições de indexador e tributação; saldos bruto e líquido.
- Aplicação e resgate são `lancamentos` com `tipo='transferencia'`, `investimento_id` e `sentido_invest`, e uma linha em `investimento_movs` ligada por `lancamento_id` (exclusão em cascata).
- Testes: `tools/ci/testes_investimentos.php`.

## Importação de extratos (v1.5.0)

- `ExtratoService.php`: leitura (`extrato_ler_arquivo`: PDF via IA, OFX local, CSV/Excel como texto para a IA), normalização, destinos, `extrato_montar` (pareamento, status, ação sugerida), `extrato_resolver` (valida a ação e devolve a operação) e `extrato_aplicar` (cada linha em sua transação; erro de uma não impede as outras).
- `ia_gemini_json` (em `FaturaPdfService.php`) é a chamada comum ao Gemini (modelos em cascata, nova tentativa). Em teste, `$GLOBALS['FIN_IA_MOCK']` substitui a rede.
- `importar_extrato.php`: envio de vários arquivos, estado em `uploads/tmp/<token>.json`, revisão e confirmação. O servidor recalcula tudo na confirmação.
- Hash de linha (`hash_unico`) guardado no lançamento criado; movimentos de investimento sem lançamento são detectados por valor e data.
- Testes: `tools/ci/testes_extrato.php`.

## Segurança, sessão e servidor (v1.12.0)

- **Apache** (`docker/apache-financeiro.conf`, ativado no `Dockerfile` como `zz-financeiro` para valer depois do `security.conf`): sem listagem de diretórios; `sistema/documentacao/`, `sistema/servicos/`, `*.md`, `*.sql` e os arquivos de apoio (`bootstrap`, `bd`, `auth_check`, `config_*`, `header`, `footer`, `fatura_modais`) respondem 403; `X-Content-Type-Options`, `X-Frame-Options` e `Referrer-Policy`; `ServerTokens Prod`. O `.htaccess` não vale na imagem `php:apache` (`AllowOverride None`). **Só vale depois de reconstruir o `fin_app`** (na pasta do serviço: `docker compose build fin_app` e depois `docker compose up -d --no-deps fin_app`, que troca só esse contêiner em cerca de 5 s). Em produção isso vale desde a publicação de 29/10/2026; a imagem anterior ficou com a tag `sistema-financeiro-fin_app:rollback-1.11.5`.
- **PHP** (`docker/php.ini`): `display_errors = Off`, `log_errors = On` (erros vão para o log do contêiner), `session.gc_maxlifetime = 28800`, `session.cookie_samesite = Lax`.
- **Sessão** (`bootstrap.php`): cookie `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição veio por HTTPS (`X-Forwarded-Proto` do Caddy). `sw.php` e `manifest.php` não abrem sessão (`FIN_SEM_SESSAO`).
- **Login**: 5 falhas em 15 minutos para o mesmo e-mail e IP bloqueiam por 15 minutos (`login_bloqueado_por`, guardado em `parametros` com a chave `lf:...`); o e-mail inexistente também confere um hash falso, para o tempo de resposta ser igual. O IP vem do `X-Forwarded-For` (o Caddy o substitui, não o repassa do cliente).
- **Séries fixas**: `recorrencias_estender` usa `GET_LOCK` por perfil (MariaDB) e a checagem por página roda no máximo 1 vez por hora por sessão; `projetar` ainda grava as ocorrências até o horizonte pedido (até 61 meses), porque a projeção as lê como lançamentos pendentes.
- **Arquivos**: a chave do Gemini vai em `x-goog-api-key`; `XlsxReader` recusa XML acima de 20 MB, mais de 50.000 linhas e colunas depois da 200.
- **Primeiro acesso**: com a tabela `usuarios` vazia, qualquer pessoa que alcance `login.php` cria o administrador. Em produção isso só existe antes do primeiro cadastro (e o acesso é só pela Tailscale).
- **Dados temporários**: o JSON da importação de extrato fica em `uploads/tmp` por até 24 h (apagado quando alguém abre a tela de importação); a senha do PDF nunca é gravada.
- **Gemini**: o PDF vai ao Google para extração; confirme no plano da chave que os dados não são usados para treino.

## Desempenho (v1.12.0)

- `totais_investidos()` simula os investimentos uma vez; `projetar` reaproveita `investimentos_com_saldo()`.
- `rf_bruto_do_liquido` simula uma vez e só reconsome os lotes na busca; `rf_reajuste_para_liquido` monta o fator do CDI uma vez.
- `extrato_montar` guarda destinos e faturas em aberto durante a montagem (`extrato_memo`), consulta os hashes em lote e pareia transferências por data (`extrato_parear`).
- A atualização automática do CDI usa uma tentativa de 6 s (`bcb_buscar(..., $rapido)`); o botão "Atualizar CDI" mantém 3 tentativas de 25 s.
- Índices novos em `lancamentos`: `sql/alteracoes/20261027_indices_lancamentos.sql` (aplicado em produção em 29/10/2026).

## Notificações push (v1.13.0)

Aviso no celular e no navegador com o aplicativo fechado, no mesmo modelo do ERP (biblioteca `minishlink/web-push` 7.0.0 com o mesmo `composer.lock`), mas **sem nenhum compartilhamento** com ele:

| Ponto | ERP | Financeiro |
|---|---|---|
| Origem (o navegador separa assinaturas por origem) | `:8452` | `:8453` |
| Service worker e escopo | `/sistema/service-worker.js` | `/sistema/sw.php` (cache `sistema-financeiro-*`) |
| Chaves VAPID | `push_vapid.php` do ERP | variáveis `PUSH_VAPID_PUBLIC/PRIVATE/SUBJECT` do `.env` do Financeiro (próprias; nunca no repositório) |
| Assinaturas | `sistema_push_assinaturas` (banco do ERP) | `push_assinaturas` (`fin_db`) |
| Agendamento | `crontab` do host, `erp_app` | `crontab` do host, `fin_app` (`cron_notificacoes.php`, log próprio) |
| Texto | título do ERP | título começa com "Financeiro ·"; `tag` = `fin-<chave do aviso>` |

- **Código**: `servicos/PushService.php` (registro, envio, rotina diária), `api_push.php` (GET = estado e chave pública; POST com CSRF = `assinar`, `cancelar`, `teste`), `cron_notificacoes.php` (só CLI; o Apache o bloqueia), evento `push` em `sw.php` e botões em Meu perfil (`assets/js/app.js`).
- **Envio**: a rotina diária (8h, `0 8 * * * docker exec fin_app php /var/www/html/sistema/cron_notificacoes.php`) grava os avisos novos de cada usuário ativo que tem aparelho cadastrado (`notificacoes_sincronizar_itens`) e os envia a todos os aparelhos dele. Cada aviso vai uma vez (chave única por usuário), então rodar de novo não repete. O sino e o aviso local do navegador continuam como antes; a `tag` igual faz o aviso push e o local se substituírem em vez de duplicar.
- **Formato**: `aes128gcm` (RFC 8291) pedido explicitamente, porque o padrão da biblioteca é o antigo `aesgcm` e o Safari/iOS só aceita o novo; TTL de 1 dia. Aparelho que responde 404/410 é removido; outras falhas só vão para o log.
- **Segurança**: o servidor só aceita endereços `https` dos serviços de push dos navegadores (`googleapis.com`, `push.services.mozilla.com`, `push.apple.com`, `notify.windows.com`), sem usuário e sem porta, para não ser usado para chamar outros endereços; o destino do clique é sempre uma tela de `/sistema/` (o service worker descarta qualquer outro); a chave privada só existe no ambiente do servidor.
- **Biblioteca**: instalada em estágio próprio do `Dockerfile` (`composer:2.2`) e copiada para `/var/www/vendor`, fora do DocumentRoot; a imagem final não leva o Composer. Sem a biblioteca ou sem as chaves, `push_disponivel()` é falso: o push some da tela e o resto do sistema segue igual (CI em `php:7.4-cli` não tem a biblioteca e usa o simulador `FIN_PUSH_MOCK`).
- **Gerar as chaves** (uma vez; colocar no `.env` da VM sem imprimir): `docker compose run --rm --no-deps --entrypoint php fin_app -r 'require "/var/www/vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'`. Trocar as chaves invalida os aparelhos já cadastrados (eles voltam a assinar no próximo acesso, no máximo 1 vez por dia).
- **iPhone/iPad**: só recebem push com o aplicativo instalado na tela inicial (iOS 16.4+).

## Cenário e risco (v1.16.0)

- **Telas e APIs:** `cenario.php` (módulo de permissão *Projeção*), `api_cenario.php` (GET, tudo da tela), `api_cenario_atualizar.php` (POST com CSRF, no máximo 1 vez a cada 30 minutos), `assets/js/cenario.js`.
- **Rotina diária:** `cron_cenario.php` (só CLI; `--forcar` refaz a leitura do dia). Agendamento no `crontab` do host, no mesmo padrão dos avisos: `0 7 * * * docker exec fin_app php /var/www/html/sistema/cron_cenario.php >> /home/thiago/cron_cenario_financeiro.log 2>&1` e uma segunda tentativa `0 12 * * *` (refaz a IA só se o dia ficou sem ela). O arquivo `cron_*.php` já é bloqueado na web pelo Apache.
- **Tabelas (globais, como `indices`):** `cenario_noticias`, `cenario_diario`, `cenario_mensal`; **por perfil:** `risco_snapshots`; coluna nova `investimentos.moeda`. SQL em `sql/alteracoes/20261103_cenario_risco.sql`.
- **IA e privacidade:** usa a chave `GEMINI_API_KEY` já existente no `fin_app` (nunca no repositório). O prompt contém só manchetes públicas e indicadores públicos. Em testes, `$GLOBALS['FIN_IA_MOCK']`, `FIN_HTTP_MOCK` e `FIN_BCB_MOCK` substituem a rede e a IA.
- **Testes:** `tools/ci/testes_cenario.php` e `testes_risco.php` (no CI).


- **Certificado do Tesouro:** o arquivo aberto do Tesouro Transparente usa uma cadeia que termina na GlobalSign Root R46, mais nova que o pacote de certificados da imagem PHP. A raiz pública está em `sistema/certs/globalsign_root_r46.pem` (SHA-256 `4F:A3:12:6D:8D:3A:11:D1:C4:85:5A:4F:80:7C:BA:D6:CF:91:9D:3A:5A:88:B0:3B:EA:2C:63:72:D9:3C:40:C9`, igual à da loja do sistema e à publicada pela GlobalSign) e vale **só** para esse endereço (`CURLOPT_CAINFO`); a verificação do certificado continua ligada em todas as buscas.

## Segurança do cadastro público (v1.18.0)

- **Administrador único:** `usuarios.sistema_admin`; `usuario_admin_sistema()` e `admin_sistema_exigir()` (PermissaoService). Contas públicas: `cadastro = 'publico'`, `aprovacao` pendente, aprovado ou recusado.
- **Limites:** contadores em `parametros` (`rl:<sha1>`), sem tabela nova; `limite_consumir`, `limite_exigir_api`, `ia_cota_consumir`. O IP vem de `cliente_ip()` (primeiro valor do `X-Forwarded-For`; o `fin_app` só é alcançável pelo Caddy, em `172.17.0.1:8097`).
- **Testes:** `tools/ci/testes_seguranca.php` (no CI) cobre administrador único, cadastro, login, aprovação, isolamento entre usuários, limites e regressão estática dos gatilhos. Para revalidar uma trava, remova-a (por exemplo o `AND perfil_id = ?` de uma consulta) e confirme que o teste falha.
- **Migração:** `sql/alteracoes/20261103_cadastro_publico.sql`, validada no MariaDB 10.5 com um segundo `admin` antigo, segunda execução e banco vazio.

