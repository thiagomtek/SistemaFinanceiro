# PWA e notificacoes — 01/11/2026 — validada com ressalvas

# Isolamento dos PWAs por porta — 01/11/2026

URLs e portas preservados: Instagram Tracker8450, ERP8452, Financeiro8453 e Hub8787.
AdGuard8446 e Homepage443 sao paginas independentes. Nenhum DNS/rota/servico de acesso remoto alterado.
Identidades dos manifests preservadas: caminhos id agora absolutos equivalem aos ids anteriores.
Nomes, simbolos monocromaticos, tags, registro do worker e destinos das notificacoes sao separados.
Clique valida origem COMPLETA (protocolo, hostname e porta) e escopo antes de reutilizar janela.
Notificacoes locais de ERP/Financeiro passam pela mesma identificacao explicita.
Nao foram alterados bancos, assinaturas ou chaves VAPID, nem enviados pushes aos usuarios como teste.

Validacao: 56 cenarios de service workers em sandbox (porta errada inclusive AdGuard, dominio externo,
URL invalida, credencial embutida, janela correta/errada/fechada); dois testes pytest do manifesto real
Instagram, compilacao Python, sintaxe PHP, testes de dominio Financeiro e tres testes PushService Hub.
Verificar tambem scripts publicados via HTTPS e navegacao real em Chromium independente apos deploy.

Limitacao Android comprovada no template oficial WebAPK:
https://raw.githubusercontent.com/chromium/chromium/main/chrome/android/webapk/shell_apk/AndroidManifest.xml
O filtro usa scheme, host e path, sem android:port. Manifest com porta nao corrige esse filtro do SO.
Mantendo o mesmo hostname e os caminhos atuais, desativar Abrir links compativeis nos quatro PWAs:
Samsung Configuracoes > Aplicativos > [app] > Definir como padrao > Abrir links compativeis: desligar.
Repetir para Instagram Tracker, ERP Elba Enxovais, Sistema Financeiro e Hub de Acesso Remoto.
Isso impede captura de links externos; o icone instalado continua abrindo o proprio app.
Nomes/icones ja instalados podem depender de atualizacao WebAPK ou reinstalacao individual do PWA.
Nao limpar todos os dados do Chrome nem apagar assinaturas/bancos para tentar resolver essa preferencia.

Status: validada com ressalvas. Fluxos de codigo e HTTPS testaveis no servidor;
identificacao de canal, nome exibido pelo Android e captura de links devem ser conferidos no celular.
Backup e rollback de codigo em /home/thiago/pwa-correcao-originais.
Backup consistente do volume Tracker antes de publicar: tracker-antes-pwa-20261101.tar.gz.
Nao restaurar banco para rollback de codigo.


# Validacoes de funcionalidades


## Publicacao e evidencia final

Publicado em01/11/2026, sem alterar portas ou rotas. PHP e arquivos estaticos instalados atomicamente.
Instagram recarregado por HUP do Gunicorn, sem reiniciar container/worker/rede/acesso remoto.
Houve1 resposta HTTP falha em38 sondagens durante a recarga; as seguintes voltaram a200.
Nao afirmar zero indisponibilidade. Servico saudavel depois; login/Painel/Historico autenticados HTTP200.

Chromium real carregou os quatro workers publicados e executou eventos push sinteticos locais.
A chamada showNotification foi capturada no teste porque o ambiente headless nao concedeu permissao
nativa de notificacoes. Confirmados titulo, icone, badge e URL resultante de payload tentando abrir8446.
Isso NAO valida entrega FCM, canal/nome/icone do Android nem clique fisico no celular.
Cliques e janelas de portas diferentes foram verificados pelos56 cenarios em sandbox.
Login ERP mobile inspecionado, versao2.100.13/build20261101.1; Financeiro1.13.8/build20261101.1.
Login/Painel/Historico Instagram inspecionados no viewport movel.
Novidades e base de suporte ERP atualizadas. Sem alteracoes de regras de negocio ou de schema/dados.

Imagens locais derivadas de instagram-tracker-instagram-tracker, remote-hub-remote-hub e
sistema-financeiro-fin_app atualizadas com os arquivos corrigidos para futuras recriacoes.
Tags antes-pwa-20261101 preservam as imagens anteriores. Codigo ERP persistente atualizado no volume.
Fontes espelhadas em /mnt/d/Servidores/Servicos, patchERP e testes preservados junto deste relatorio.
Para rollback de codigo, restaurar somente os arquivos originais revisados e, no Instagram,
recarregar trabalhadores. Nao restaurar banco nem tocar em assinaturas para rollback de PWA.

Pendencia do usuario: desativar Abrir links compativeis nos quatro PWAs nas configuracoes Android;
abrir novamente cada app para atualizar seus workers; conferir AdGuard a partir da Homepage.
Se nome/icone antigo persistir no Android, atualizar ou reinstalar somente o PWA afetado.
Manter os mesmos enderecos/portas na reinstalacao. Nao limpar todo o Chrome.
