<?php
declare(strict_types=1);

/**
 * Testes de seguranca do cadastro publico e do isolamento (RN-50 a RN-53): administrador unico do sistema, cadastro (modos, isca,
 * tempo, limites por IP, teto diario, senha, nao revelar e-mail existente, permissoes padrao), login com limite por IP, aprovacao,
 * isolamento entre usuarios (um usuario tentando tocar nos dados de outro), limites de recursos (IA, anexos, itens, datas, BCB) e
 * regressao estatica dos gatilhos de recurso. Nao usa internet. Uso: php tools/ci/testes_seguranca.php
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');

require __DIR__ . '/../../sistema/bootstrap.php';
require __DIR__ . '/sqlite_schema.php';
db()->exec(schema_sqlite());

$falhas = 0;
function ok(bool $cond, string $msg): void
{
    global $falhas;
    echo ($cond ? '  [ok] ' : '  [FALHA] ') . $msg . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function lanca(callable $f, string $msg, ?string $contem = null): void
{
    try {
        $f();
        ok(false, $msg . ' (nao lancou excecao)');
    } catch (InvalidArgumentException $e) {
        ok($contem === null || stripos($e->getMessage(), $contem) !== false, $msg . ($contem !== null && stripos($e->getMessage(), $contem) === false ? ' (mensagem: ' . $e->getMessage() . ')' : ''));
    }
}
function como(int $uid): void
{
    $_SESSION = ['usuario_id' => $uid];
    garantir_perfil();
}
function de_ip(string $ip): void
{
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $ip;
}
function cadastro(array $o = []): string
{
    static $n = 0;
    $n++;
    return cadastro_publico_registrar($o + ['nome' => 'Maria Souza', 'email' => 'maria' . $n . '@exemplo.com', 'senha' => 'Cavalo-Bateria-Grampo9', 'website' => '', 'inicio' => time() - 30]);
}

// administrador do sistema (o unico com privilegio administrativo)
$admin = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, sistema_admin) VALUES (?,?,?,?,?)', ['Thiago', 'thiago@exemplo.com', password_hash('senhaadmin1', PASSWORD_DEFAULT), 'admin', 1]);
como($admin);
$hoje = date('Y-m-d');

echo "== administrador unico (RN-50)\n";
ok(usuario_admin_sistema($admin) && permissao_pode('usuarios', 'editar'), 'o administrador do sistema administra usuários');
$tamper = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, sistema_admin) VALUES (?,?,?,?,?)', ['Falso Admin', 'falso@exemplo.com', 'x', 'admin', 0]);
$m = permissoes_do_usuario($tamper);
ok($m['usuarios'] === 'nenhum' && $m['lancamentos'] === 'editar', 'um usuário com papel "admin" no banco, mas sem a marca do sistema, é tratado como editor e não acessa usuários');
q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, sistema_admin) VALUES (?,?,?,?,?)', ['Custom', 'custom@exemplo.com', 'x', 'personalizado', 0]);
$custom = (int) q_val("SELECT id FROM usuarios WHERE email = 'custom@exemplo.com'");
q_insert('INSERT INTO usuario_permissoes (usuario_id, modulo, nivel) VALUES (?,?,?)', [$custom, 'usuarios', 'editar']);
q_insert('INSERT INTO usuario_permissoes (usuario_id, modulo, nivel) VALUES (?,?,?)', [$custom, 'lancamentos', 'ver']);
$m = permissoes_do_usuario($custom);
ok($m['usuarios'] === 'nenhum' && $m['lancamentos'] === 'ver', 'permissão "usuários" gravada à mão para um personalizado é ignorada');
lanca(function () use ($admin) { usuario_salvar(['nome' => 'Outro', 'email' => 'outro@exemplo.com', 'senha' => 'senhaoutro1', 'papel' => 'admin'], 0, $admin); }, 'o administrador não consegue criar outro administrador', 'administrador do sistema');
$sub = usuario_salvar(['nome' => 'Sub', 'email' => 'sub@exemplo.com', 'senha' => 'senhasub123', 'papel' => 'personalizado', 'permissoes' => ['usuarios' => 'editar', 'contas' => 'editar']], 0, $admin);
ok(!q_val("SELECT 1 FROM usuario_permissoes WHERE usuario_id = ? AND modulo = 'usuarios'", [$sub]), 'ao salvar, o módulo "usuários" nunca é concedido a outro usuário');
q('UPDATE usuarios SET ativo = 0 WHERE id = ?', [$admin]);
ok(!usuario_admin_sistema($admin), 'administrador inativo perde o privilégio');
q('UPDATE usuarios SET ativo = 1 WHERE id = ?', [$admin]);
ok(!usuario_admin_sistema($sub) && !usuario_admin_sistema(0) && !usuario_admin_sistema(999999), 'ninguém mais é administrador do sistema');
ok((int) q_val('SELECT COUNT(*) FROM usuarios WHERE sistema_admin = 1') === 1, 'há exatamente um administrador do sistema');

echo "== cadastro publico: modos e resposta igual (RN-51)\n";
$_SESSION = [];
parametro_set('cadastro_modo', 'fechado');
de_ip('203.0.113.10');
lanca(function () { cadastro(); }, 'cadastro fechado recusa', 'fechado');
parametro_set('cadastro_modo', 'aprovacao');
ok(cadastro_modo() === 'aprovacao', 'modo configurado');
$msg1 = cadastro(['email' => 'nova@exemplo.com']);
$u = q_one("SELECT * FROM usuarios WHERE email = 'nova@exemplo.com'");
ok($u && (int) $u['ativo'] === 0 && $u['aprovacao'] === 'pendente' && $u['cadastro'] === 'publico' && $u['papel'] === 'personalizado' && $u['dono_id'] === null && (int) $u['sistema_admin'] === 0, 'no modo com aprovação a conta nasce inativa, pendente, pública, sem dono e sem privilégio administrativo');
$perm = array_column(q_all('SELECT modulo, nivel FROM usuario_permissoes WHERE usuario_id = ?', [(int) $u['id']]), 'nivel', 'modulo');
ok(isset($perm['lancamentos'], $perm['investimentos']) && !isset($perm['usuarios']) && !isset($perm['importacoes']), 'permissões iniciais: dados próprios sim; usuários e importação por IA não');
ok(password_verify('Cavalo-Bateria-Grampo9', (string) $u['senha_hash']) && $u['senha_hash'] !== 'Cavalo-Bateria-Grampo9', 'a senha é guardada com hash');
de_ip('203.0.113.11');
$msg2 = cadastro_publico_registrar(['nome' => 'Outra Pessoa', 'email' => 'NOVA@exemplo.com', 'senha' => 'Zebra-Cinco-Verde-77', 'website' => '', 'inicio' => time() - 30]);
ok($msg2 === $msg1 && (int) q_val("SELECT COUNT(*) FROM usuarios WHERE email = 'nova@exemplo.com'") === 1, 'e-mail já cadastrado (mesmo em maiúsculas): mesma mensagem e nenhuma conta nova (não revela quem tem conta)');
ok(password_verify('Cavalo-Bateria-Grampo9', (string) q_val("SELECT senha_hash FROM usuarios WHERE email = 'nova@exemplo.com'")), 'e a conta existente não tem a senha trocada');
parametro_set('cadastro_modo', 'aberto');
de_ip('203.0.113.12');
cadastro(['email' => 'aberta@exemplo.com']);
$a = q_one("SELECT * FROM usuarios WHERE email = 'aberta@exemplo.com'");
ok((int) $a['ativo'] === 1 && $a['aprovacao'] === 'aprovado' && $a['papel'] === 'personalizado' && (int) $a['sistema_admin'] === 0, 'no modo aberto a conta já nasce ativa, mas sem privilégio administrativo');
parametro_set('cadastro_modo', 'aprovacao');

echo "== cadastro publico: isca, tempo, limites e validacao\n";
de_ip('203.0.113.20');
$n0 = (int) q_val('SELECT COUNT(*) FROM usuarios');
$r = cadastro(['website' => 'http://spam.exemplo']);
ok((int) q_val('SELECT COUNT(*) FROM usuarios') === $n0 && $r !== '', 'campo isca preenchido: parece sucesso, mas nada é criado');
lanca(function () { cadastro(['inicio' => time() - 1]); }, 'envio rápido demais é recusado', 'rápido demais');
lanca(function () { cadastro(['inicio' => time() - 90000]); }, 'formulário expirado é recusado', 'expirou');
lanca(function () { cadastro(['inicio' => 0]); }, 'sem o instante de exibição é recusado', 'expirou');
de_ip('203.0.113.30');
cadastro(); cadastro(); cadastro();
lanca(function () { cadastro(); }, '4º cadastro do mesmo IP na hora é barrado', 'Muitas tentativas');
de_ip('203.0.113.31');
cadastro();
ok(true, 'outro IP não é afetado pelo bloqueio do anterior');
parametro_set('cadastro_limite_dia', '2');
limite_consumir('cad:dia:' . date('Y-m-d'), 999, 86400); // zera a contagem de hoje para o teste do teto
parametro_set(limite_chave('cad:dia:' . date('Y-m-d')), time() . '|0|86400');
de_ip('203.0.113.40'); cadastro();
de_ip('203.0.113.41'); cadastro();
de_ip('203.0.113.42');
lanca(function () { cadastro(); }, 'teto diário global de cadastros', 'limite de cadastros');
parametro_set('cadastro_limite_dia', '500');
parametro_set(limite_chave('cad:dia:' . date('Y-m-d')), time() . '|0|86400');
de_ip('203.0.113.50');
lanca(function () { cadastro(['email' => 'sem-arroba']); }, 'e-mail inválido', 'e-mail');
limite_consumir('x', 99, 60); // (os 12 envios invalidos a seguir nao podem gastar a cota firme de cadastros do IP)
lanca(function () { cadastro(['email' => str_repeat('a', 160) . '@x.com']); }, 'e-mail longo demais', 'e-mail');
lanca(function () { cadastro(['nome' => '12345']); }, 'nome sem letras', 'nome');
lanca(function () { cadastro(['nome' => "Maria\x00Souza"]); }, 'nome com caractere de controle', 'nome');
lanca(function () { cadastro(['nome' => 'X']); }, 'nome curto demais', 'nome');
lanca(function () { cadastro(['senha' => 'curta1']); }, 'senha curta', '10 caracteres');
lanca(function () { cadastro(['senha' => str_repeat('a1', 40)]); }, 'senha longa demais (bcrypt corta em 72)', '72');
lanca(function () { cadastro(['senha' => '1234567890']); }, 'senha da lista de fracas', 'fraca');
lanca(function () { cadastro(['senha' => 'aaaaaaaaaaaa']); }, 'senha de um caractere só', 'fraca');
lanca(function () { cadastro(['senha' => 'abcabcabcabc']); }, 'senha que repete um padrão', 'fraca');
lanca(function () { cadastro(['email' => 'joaosilva@exemplo.com', 'senha' => 'minhajoaosilva99']); }, 'senha com o e-mail', 'e-mail');
lanca(function () { cadastro(['nome' => 'Fernanda Albuquerque', 'senha' => 'fernanda-gosta-de-cafe']); }, 'senha com o nome', 'nome');

echo "== login: pendente, bloqueio e conta recusada (RN-51)\n";
de_ip('198.51.100.7');
$r = login_autenticar('nova@exemplo.com', 'Cavalo-Bateria-Grampo9');
ok(!$r['ok'] && $r['motivo'] === 'pendente', 'senha certa de conta pendente: avisa que aguarda aprovação');
$r = login_autenticar('nova@exemplo.com', 'senha-errada-123');
ok(!$r['ok'] && $r['motivo'] === 'incorreto', 'senha errada de conta pendente: apenas "incorreto"');
$r = login_autenticar('ninguem@exemplo.com', 'qualquer-coisa-1');
ok(!$r['ok'] && $r['motivo'] === 'incorreto', 'e-mail inexistente: "incorreto" (igual a senha errada)');
$r = login_autenticar('thiago@exemplo.com', 'senhaadmin1');
ok($r['ok'] && (int) $r['usuario']['id'] === $admin, 'o administrador entra normalmente');
for ($i = 0; $i < 5; $i++) { login_autenticar('thiago@exemplo.com', 'errada-' . $i); }
$r = login_autenticar('thiago@exemplo.com', 'senhaadmin1');
ok(!$r['ok'] && $r['motivo'] === 'bloqueado' && $r['espera'] > 0, 'depois de 5 falhas o e-mail é bloqueado neste IP, até com a senha certa');
de_ip('198.51.100.8');
$r = login_autenticar('thiago@exemplo.com', 'senhaadmin1');
ok($r['ok'], 'o bloqueio é por e-mail e IP: outro IP consegue (o dono não fica trancado fora por um invasor)');
de_ip('198.51.100.9');
for ($i = 0; $i < LOGIN_MAX_FALHAS_IP; $i++) { limite_consumir('login-ip:198.51.100.9', LOGIN_MAX_FALHAS_IP, LOGIN_JANELA_S); }
$r = login_autenticar('qualquer@exemplo.com', 'x');
ok(!$r['ok'] && $r['motivo'] === 'bloqueado', 'um IP com 30 falhas em e-mails diferentes é bloqueado (barra a pulverização de senhas)');
$rec = (int) q_val("SELECT id FROM usuarios WHERE email = 'nova@exemplo.com'");

de_ip('198.51.100.20'); // IP limpo: o anterior ficou bloqueado de proposito
echo "== aprovacao so pelo administrador do sistema\n";
como($custom);
lanca(function () use ($rec) { cadastro_aprovar($rec, false); }, 'usuário comum não aprova cadastro', 'administrador do sistema');
lanca(function () { cadastro_config_salvar('aberto', 500); }, 'usuário comum não muda o modo', 'administrador do sistema');
lanca(function () { cadastro_lista('pendente'); }, 'usuário comum não lista cadastros', 'administrador do sistema');
como($admin);
lanca(function () { cadastro_config_salvar('qualquer', 5); }, 'modo inválido', 'inválido');
lanca(function () { cadastro_config_salvar('aprovacao', 0); }, 'limite diário inválido', 'limite');
lanca(function () use ($admin) { cadastro_aprovar($admin, false); }, 'não se aprova (nem se mexe em) conta que não é cadastro público', 'não encontrado');
lanca(function () use ($custom) { cadastro_recusar($custom); }, 'não recusa usuário comum', 'não encontrado');
cadastro_aprovar($rec, false);
$u = q_one('SELECT * FROM usuarios WHERE id = ?', [$rec]);
ok((int) $u['ativo'] === 1 && $u['aprovacao'] === 'aprovado' && $u['aprovado_em'] !== null, 'aprovação ativa a conta');
$m = permissoes_do_usuario($rec);
ok($m['usuarios'] === 'nenhum' && $m['importacoes'] === 'nenhum' && $m['lancamentos'] === 'editar', 'aprovada: sem usuários e sem importação por IA');
cadastro_definir_importacoes($rec, true);
ok(permissoes_do_usuario($rec)['importacoes'] === 'editar', 'o administrador pode liberar a importação por IA');
cadastro_definir_importacoes($rec, false);
$r = login_autenticar('nova@exemplo.com', 'Cavalo-Bateria-Grampo9');
ok($r['ok'], 'depois de aprovada a pessoa entra');
cadastro_alternar_ativo($rec);
$r = login_autenticar('nova@exemplo.com', 'Cavalo-Bateria-Grampo9');
ok(!$r['ok'] && $r['motivo'] === 'incorreto', 'conta inativada não entra e recebe a resposta genérica');
cadastro_alternar_ativo($rec);
de_ip('203.0.113.60');
cadastro(['email' => 'recusada@exemplo.com']);
$rid = (int) q_val("SELECT id FROM usuarios WHERE email = 'recusada@exemplo.com'");
cadastro_recusar($rid);
ok(permissoes_do_usuario($rid)['lancamentos'] === 'nenhum', 'conta recusada não tem nenhum acesso');
$r = login_autenticar('recusada@exemplo.com', 'Cavalo-Bateria-Grampo9');
ok(!$r['ok'] && $r['motivo'] === 'incorreto', 'conta recusada responde como senha incorreta (não revela a recusa)');
cadastro_excluir($rid);
ok(!q_val('SELECT 1 FROM usuarios WHERE id = ?', [$rid]), 'cadastro recusado que nunca entrou pode ser excluído');
$lista = cadastro_lista('aprovado');
ok(count($lista) >= 1 && !isset($lista[0]['senha_hash']) && !isset($lista[0]['saldo']), 'a lista do administrador traz só nome, e-mail e datas (sem hash e sem dado financeiro)');
lanca(function () use ($rec) { cadastro_excluir($rec); }, 'cadastro já aprovado não é excluído por aqui', 'não encontrado');

echo "== isolamento entre usuarios: um usuario nao toca nos dados de outro\n";
$ua = $rec; // A: conta publica aprovada
q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, cadastro, aprovacao, aprovado_em) VALUES (?,?,?,?,?,?,?)', ['Bruno', 'bruno@exemplo.com', 'x', 'personalizado', 'publico', 'aprovado', date('Y-m-d H:i:s')]);
$ub = (int) q_val("SELECT id FROM usuarios WHERE email = 'bruno@exemplo.com'");
cadastro_permissoes_padrao($ub, false);
como($ua);
$pa = perfil_id();
$contaA = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [$pa, 'Conta A', 'corrente', 1000]);
$cartaoA = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [$pa, 'Cartão A', $contaA, 5000, 1, 28]);
$catA = q_insert('INSERT INTO categorias (perfil_id, nome, tipo) VALUES (?,?,?)', [$pa, 'Cat A', 'despesa']);
$lancA = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Segredo de A', 'valor' => 50, 'data' => $hoje, 'efetivado' => 0, 'conta_id' => $contaA])[0];
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra no cartão', 'valor' => 120, 'data' => $hoje, 'cartao_id' => $cartaoA]);
$mesFat = (string) q_val('SELECT fatura_mes FROM lancamentos WHERE cartao_id = ? LIMIT 1', [$cartaoA]);
pagar_fatura($cartaoA, $mesFat, $contaA, $hoje);
$invA = q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, indexador, tributacao) VALUES (?,?,?,?,?,?)', [$pa, 'CDB de A', 'renda_fixa', 'Banco A', 'manual', 'nenhuma']);
rf_aplicar(0, $invA, 2000, $hoje, true);
$movA = (int) q_val('SELECT id FROM investimento_movs WHERE investimento_id = ?', [$invA]);
$anexoA = q_insert('INSERT INTO anexos (perfil_id, lancamento_id, nome, arquivo, mime, tamanho) VALUES (?,?,?,?,?,?)', [$pa, $lancA, 'nota.pdf', 'abc.pdf', 'application/pdf', 1000]);
q_insert('INSERT INTO notificacoes (usuario_id, chave, titulo, mensagem) VALUES (?,?,?,?)', [$ua, 'k1', 'Aviso de A', 'msg']);
$notifA = (int) q_val('SELECT id FROM notificacoes WHERE usuario_id = ?', [$ua]);
$endpointA = 'https://fcm.googleapis.com/fcm/send/abcdefghijklmnop';
q_insert('INSERT INTO push_assinaturas (usuario_id, endpoint, endpoint_hash, p256dh, auth) VALUES (?,?,?,?,?)', [$ua, $endpointA, hash('sha256', $endpointA), 'p', 'a']);
alocacao_perfil_salvar('arrojado', 12, []);

como($ub);
$pb = perfil_id();
ok($pb !== $pa, 'cada cadastro tem o seu próprio perfil de dados');
$contaB = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [$pb, 'Conta B', 'corrente', 10]);
$cartaoB = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [$pb, 'Cartão B', $contaB, 100, 1, 28]);
$base = ['tipo' => 'despesa', 'descricao' => 'invasão', 'valor' => 10, 'data' => $hoje, 'efetivado' => 0];
lanca(function () use ($base, $contaA) { criar_lancamento($base + ['conta_id' => $contaA]); }, 'B não lança na conta de A', 'inválida');
lanca(function () use ($base, $contaB, $contaA) { criar_lancamento(['tipo' => 'transferencia', 'conta_id' => $contaB, 'conta_destino_id' => $contaA] + $base); }, 'B não transfere para a conta de A', 'inválida');
lanca(function () use ($base, $cartaoA) { criar_lancamento($base + ['cartao_id' => $cartaoA]); }, 'B não compra no cartão de A', 'inválido');
lanca(function () use ($base, $contaB, $catA) { criar_lancamento($base + ['conta_id' => $contaB, 'categoria_id' => $catA]); }, 'B não usa a categoria de A', 'inválida');
lanca(function () use ($lancA, $base, $contaB) { atualizar_lancamento($lancA, $base + ['conta_id' => $contaB]); }, 'B não altera o lançamento de A', 'não encontrado');
ok(excluir_lancamento($lancA) === 0 && (int) q_val('SELECT COUNT(*) FROM lancamentos WHERE id = ?', [$lancA]) === 1, 'B não exclui o lançamento de A');
alternar_efetivado($lancA);
ok((int) q_val('SELECT efetivado FROM lancamentos WHERE id = ?', [$lancA]) === 0, 'B não confirma o lançamento de A');
lanca(function () use ($cartaoA, $mesFat, $contaB, $hoje) { pagar_fatura($cartaoA, $mesFat, $contaB, $hoje); }, 'B não paga a fatura do cartão de A');
lanca(function () use ($cartaoB, $mesFat, $contaA, $hoje) { pagar_fatura($cartaoB, $mesFat, $contaA, $hoje); }, 'B não paga a fatura com a conta de A');
reabrir_fatura($cartaoA, $mesFat);
ok((int) q_val('SELECT COUNT(*) FROM faturas WHERE cartao_id = ?', [$cartaoA]) === 1, 'B não reabre a fatura de A');
cartao_inativar($cartaoA);
ok((int) q_val('SELECT ativo FROM cartoes WHERE id = ?', [$cartaoA]) === 1, 'B não inativa o cartão de A');
lanca(function () use ($contaA) { reajustar_saldo($contaA, 5); }, 'B não reajusta a conta de A');
lanca(function () use ($cartaoA, $mesFat) { ajustar_fatura($cartaoA, $mesFat, 5); }, 'B não ajusta a fatura de A');
lanca(function () use ($invA, $contaB, $hoje) { rf_aplicar($contaB, $invA, 10, $hoje); }, 'B não aplica no investimento de A');
lanca(function () use ($invA, $contaB, $hoje) { rf_resgatar($invA, $contaB, 10, $hoje); }, 'B não resgata o investimento de A');
lanca(function () use ($invA, $hoje) { rf_reajustar($invA, 1, $hoje); }, 'B não reajusta o investimento de A');
lanca(function () use ($invA, $hoje) { rf_previa_resgate($invA, 10, $hoje); }, 'B não vê a prévia de resgate do investimento de A');
rf_excluir_mov($movA);
ok((int) q_val('SELECT COUNT(*) FROM investimento_movs WHERE id = ?', [$movA]) === 1, 'B não exclui movimento de A');
investimento_salvar(['nome' => 'sequestro', 'produto' => 'cdb'], $invA);
ok(q_val('SELECT nome FROM investimentos WHERE id = ?', [$invA]) === 'CDB de A', 'B não renomeia o investimento de A (um id alheio só cria um investimento novo de B)');
ok(anexo_dados($anexoA) === null && anexo_excluir($anexoA) === false && (int) q_val('SELECT COUNT(*) FROM anexos WHERE id = ?', [$anexoA]) === 1, 'B não lê nem exclui o anexo de A');
lanca(function () use ($lancA) { anexar_arquivo($lancA, ['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'x.pdf', 'tmp_name' => '/tmp/x']); }, 'B não anexa arquivo a lançamento de A', 'inválido');
notificacoes_marcar_lida($notifA);
ok(q_val('SELECT lida_em FROM notificacoes WHERE id = ?', [$notifA]) === null, 'B não marca como lida a notificação de A');
push_cancelar($ub, $endpointA);
ok((int) q_val('SELECT COUNT(*) FROM push_assinaturas WHERE usuario_id = ?', [$ua]) === 1, 'B não remove o aparelho de A');
lanca(function () use ($cartaoA) { importar_aplicar([], [], [], 'x.csv', $cartaoA); }, 'B não importa fatura no cartão de A', 'inválido');
lanca(function () use ($cartaoA, $mesFat) { fatura_importar_itens($cartaoA, $mesFat, [], 'x.pdf'); }, 'B não importa itens na fatura de A');
ok(alocacao_perfil()['tolerancia'] === 'moderado' && alocacao_perfil_salvar('conservador', 6, [])['tolerancia'] === 'conservador', 'o perfil de alocação é por perfil: B altera o seu');
como($ua);
ok(alocacao_perfil()['tolerancia'] === 'arrojado' && alocacao_perfil()['reserva_meses'] === 12, '... e o de A segue intacto');
como($ub);
ok(array_column(contas_do_perfil(), 'nome') === ['Conta B'] && array_column(cartoes_do_perfil(), 'nome') === ['Cartão B'] && array_column(categorias_do_perfil(), 'nome') !== [] && !in_array('Cat A', array_column(categorias_do_perfil(), 'nome'), true), 'as listas de B não trazem nada de A');
$invsB = investimentos_com_saldo();
ok(abs(saldo_total_contas() - 10) < 0.01 && !in_array('CDB de A', array_column($invsB, 'nome'), true) && abs(array_sum(array_column($invsB, 'saldo'))) < 0.01 && abs(risco_exposicao()['patrimonio'] - 10) < 0.01, 'saldo, investimentos e exposição de B não incluem os de A');
$proj = projetar(3, false);
ok(abs($proj['saldo_atual'] - 10) < 0.01 && abs($proj['investido_atual']) < 0.01, 'a projeção de B parte só do patrimônio de B');
ok((int) q_val('SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND descricao LIKE ?', [$pb, '%Segredo%']) === 0, 'nenhum lançamento de A está no perfil de B');
$m = permissoes_do_usuario($ub);
ok($m['usuarios'] === 'nenhum' && !permissao_pode('usuarios', 'ver') && !permissao_pode('importacoes', 'ver'), 'B (cadastro público) não vê usuários nem importações');
ok(dono_dos_dados($ub) === $ub && dono_dos_dados($ua) === $ua, 'cada cadastro público é dono dos próprios dados');

echo "== limites de recursos (RN-52)\n";
// contador por janela
ok(limite_consumir('t:a', 3, 60, 1000) && limite_consumir('t:a', 3, 60, 1001) && limite_consumir('t:a', 3, 60, 1002) && !limite_consumir('t:a', 3, 60, 1003), 'limite por janela: 3 usos passam, o 4º não');
ok(limite_bloqueado_por('t:a', 3, 60, 1004) > 0 && limite_bloqueado_por('t:a', 3, 60, 1100) === 0 && limite_consumir('t:a', 3, 60, 1100), 'o bloqueio informa o tempo restante e libera depois da janela');
parametro_set(limite_chave('t:velho'), '100|5|60');
limite_varrer(5000);
ok(parametro_get(limite_chave('t:velho')) === null, 'contadores vencidos são varridos');
ok(cliente_ip() === '198.51.100.9' || cliente_ip() !== '', 'o IP do cliente vem do X-Forwarded-For');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'lixo, 1.2.3.4';
ok(cliente_ip() !== 'lixo' && filter_var(cliente_ip(), FILTER_VALIDATE_IP), 'valor inválido no cabeçalho é descartado');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77, 10.0.0.1';
ok(cliente_ip() === '198.51.100.77', 'usa o primeiro endereço do X-Forwarded-For (o Caddy substitui o cabeçalho)');
// IA
como($ub);
for ($i = 0; $i < IA_COTA_USUARIO_DIA; $i++) { ia_cota_consumir(true); }
try { ia_cota_consumir(true); ok(false, 'a cota diária de IA por usuário barra o excesso'); } catch (RuntimeException $e) { ok(strpos($e->getMessage(), 'limite diário') !== false, 'a cota diária de IA por usuário barra o excesso'); }
como($admin);
ia_cota_consumir(true);
ok(true, 'o administrador do sistema não tem a cota por usuário');
parametro_set(limite_chave('ia:global:' . date('Y-m-d')), time() . '|' . IA_COTA_GLOBAL_DIA . '|86400');
try { ia_cota_consumir(true); ok(false, 'o teto global diário de IA vale até para o administrador'); } catch (RuntimeException $e) { ok(strpos($e->getMessage(), 'sistema') !== false, 'o teto global diário de IA vale até para o administrador'); }
ia_cota_consumir(false);
ok(true, 'rotina de linha de comando e IA simulada não gastam cota');
// anexos
como($ub);
$lancB = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'B', 'valor' => 1, 'data' => $hoje, 'conta_id' => $contaB])[0];
ok($lancB !== $lancA && (int) q_val('SELECT perfil_id FROM lancamentos WHERE id = ?', [$lancB]) === $pb, 'o lançamento de B é de B');
q_insert('INSERT INTO anexos (perfil_id, lancamento_id, nome, arquivo, mime, tamanho) VALUES (?,?,?,?,?,?)', [$pb, $lancB, 'grande.pdf', 'g.pdf', 'application/pdf', ANEXO_MAX_TOTAL_BYTES - 1000]);
lanca(function () use ($lancB) { anexar_arquivo($lancB, ['error' => UPLOAD_ERR_OK, 'size' => 2000, 'name' => 'a.pdf', 'tmp_name' => '/tmp/x']); }, 'a cota de anexos por perfil barra o excesso', 'limite');
lanca(function () use ($lancB) { anexar_arquivo($lancB, ['error' => UPLOAD_ERR_OK, 'size' => ANEXO_MAX_BYTES + 1, 'name' => 'a.pdf', 'tmp_name' => '/tmp/x']); }, 'arquivo acima de 5 MB', '5 MB');
// itens por perfil
for ($i = 0; $i < CADASTRO_LIMITES['investimentos'] - 0; $i++) {
    if ((int) q_val('SELECT COUNT(*) FROM investimentos WHERE perfil_id = ?', [$pb]) >= CADASTRO_LIMITES['investimentos']) { break; }
    q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, indexador, tributacao) VALUES (?,?,?,?,?)', [$pb, 'Inv ' . $i, 'renda_fixa', 'manual', 'nenhuma']);
}
lanca(function () { investimento_salvar(['nome' => 'Mais um', 'produto' => 'cdb']); }, 'teto de investimentos por perfil', 'Limite');
for ($i = (int) q_val('SELECT COUNT(*) FROM contas WHERE perfil_id = ?', [$pb]); $i < CADASTRO_LIMITES['contas']; $i++) { q_insert('INSERT INTO contas (perfil_id, nome, tipo) VALUES (?,?,?)', [$pb, 'C' . $i, 'corrente']); }
lanca(function () { limite_entidade_exigir('contas', 'contas'); }, 'teto de contas por perfil', 'Limite');
// lancamentos por perfil: enche o perfil de B ate o teto de uma vez (SQLite) e confere que o proximo nao entra
$faltam = LANCAMENTOS_MAX_POR_PERFIL - (int) q_val('SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ?', [$pb]) - 1;
q('WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < CAST(? AS INTEGER)) INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id) SELECT ?, \'despesa\', \'carga\', 1, ?, 0, ? FROM c', [$faltam, $pb, $hoje, $contaB]);
criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'o ultimo cabe', 'valor' => 1, 'data' => $hoje, 'conta_id' => $contaB]);
lanca(function () use ($contaB, $hoje) { criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'passou do teto', 'valor' => 1, 'data' => $hoje, 'conta_id' => $contaB]); }, 'teto de lançamentos por perfil', 'Limite');
lanca(function () use ($contaB, $hoje) { criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'serie', 'valor' => 1, 'data' => $hoje, 'conta_id' => $contaB, 'repeticao' => 'parcelado', 'qtd' => 12]); }, 'nem uma série nova entra com o perfil no teto', 'Limite');
q('DELETE FROM lancamentos WHERE perfil_id = ? AND descricao IN (?, ?)', [$pb, 'carga', 'o ultimo cabe']);
// movimentos por investimento
$invB = (int) q_val('SELECT id FROM investimentos WHERE perfil_id = ? LIMIT 1', [$pb]);
db()->beginTransaction();
for ($i = 0; $i < RF_MAX_MOVS; $i++) { q('INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)', [$invB, $hoje, 'aporte', 1]); }
db()->commit();
lanca(function () use ($invB, $hoje) { rf_aplicar(0, $invB, 10, $hoje); }, 'teto de movimentos por investimento', 'limite');
// datas
$invC = (int) q_val('SELECT id FROM investimentos WHERE perfil_id = ? ORDER BY id DESC LIMIT 1', [$pb]);
lanca(function () use ($invC) { rf_aplicar(0, $invC, 10, '1900-01-01'); }, 'data anterior a 1995 recusada', 'intervalo');
lanca(function () use ($invC) { rf_aplicar(0, $invC, 10, '2999-01-01'); }, 'data muito à frente recusada', 'intervalo');
lanca(function () use ($invC) { rf_reajustar($invC, 5, '1990-05-05'); }, 'reajuste com data antiga recusado', 'intervalo');
ok(indices_inicio_necessario() >= IDX_DATA_MINIMA, 'o início do histórico de índices nunca passa de 1995');
// BCB: uma data antiga nao faz cada pagina consultar o BCB
q('DELETE FROM indices');
q('DELETE FROM parametros WHERE chave LIKE \'idx_%\'');
q('UPDATE investimentos SET indexador = ? WHERE id = ?', ['cdi', $invC]);
q('DELETE FROM investimento_movs WHERE investimento_id = ?', [$invC]);
q('INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)', [$invC, '1900-01-01', 'aporte', 10]); // dado legado/adulterado
$chamadas = 0;
$GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim) use (&$chamadas): array { $chamadas++; throw new RuntimeException('BCB fora do ar'); };
$r1 = indices_atualizar(false);
$c1 = $chamadas;
$r2 = indices_atualizar(false);
$r3 = indices_atualizar(false);
ok($c1 > 0 && $chamadas === $c1 && !$r2['atualizado'] && !$r3['atualizado'], 'com histórico faltando e o BCB fora do ar, várias páginas seguidas fazem uma tentativa só (antes: uma por página)');
indices_garantir_desde('1900-01-01');
$c2 = $chamadas;
indices_garantir_desde('1900-01-01');
ok($chamadas === $c2, 'a busca por data antiga também respeita o intervalo');
unset($GLOBALS['FIN_BCB_MOCK']);

echo "== rotina diaria so para quem usa o sistema\n";
$recente = q_insert('INSERT INTO usuarios (nome, email, senha_hash, ultimo_acesso) VALUES (?,?,?,?)', ['Recente', 'recente@exemplo.com', 'x', date('Y-m-d H:i:s')]);
$parado = q_insert('INSERT INTO usuarios (nome, email, senha_hash, ultimo_acesso) VALUES (?,?,?,?)', ['Parado', 'parado@exemplo.com', 'x', date('Y-m-d H:i:s', strtotime('-90 day'))]);
$nunca = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['Nunca', 'nunca@exemplo.com', 'x']);
foreach ([$recente, $parado, $nunca] as $uid) { como($uid); }
como($admin);
$GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim): array { return []; };
$donos = [];
foreach (risco_snapshot_todos($hoje) as $s) { $donos[] = $s['perfil_id']; }
$perfilDe = function (int $uid) { return (int) q_val('SELECT id FROM perfis WHERE usuario_id = ? ORDER BY id LIMIT 1', [$uid]); };
ok(in_array($perfilDe($admin), $donos, true) && in_array($perfilDe($recente), $donos, true) && !in_array($perfilDe($parado), $donos, true) && !in_array($perfilDe($nunca), $donos, true), 'a foto de risco diária inclui o administrador e quem entrou nos últimos 45 dias, e não contas paradas ou que nunca entraram');
unset($GLOBALS['FIN_BCB_MOCK']);

echo "== regressao estatica: gatilhos de recurso so para o administrador do sistema\n";
$fonte = function (string $arq): string { return (string) file_get_contents(__DIR__ . '/../../sistema/' . $arq); };
$s = $fonte('api_cenario_atualizar.php');
ok(strpos($s, 'admin_sistema_exigir(true)') !== false && strpos($s, 'admin_sistema_exigir(true)') < strpos($s, 'cenario_atualizar_tudo'), '"Atualizar agora" exige o administrador do sistema antes de rodar qualquer coisa');
$s = $fonte('investimentos.php');
ok(preg_match("/'atualizar_indices'\)\s*\{\s*if \(!usuario_admin_sistema\(\)\)/", $s) === 1, 'a atualização manual de índices exige o administrador do sistema');
ok(preg_match('/if \(usuario_admin_sistema\(\)\): \?><form[^>]*><\?= csrf_field\(\) \?><input type="hidden" name="acao" value="atualizar_indices"/', $s) === 1, 'e o botão só aparece para ele');
foreach (['api_projecao.php', 'api_projecao_risco.php', 'api_cenario.php', 'api_investimento_painel.php', 'api_resgate_previa.php'] as $arq) {
    ok(strpos($fonte($arq), 'limite_exigir_api(') !== false, $arq . ' tem limite de uso por usuário');
}
ok(strpos($fonte('servicos/FaturaPdfService.php'), 'ia_cota_consumir()') !== false, 'toda chamada ao Gemini passa pela cota diária');
ok(PAGINA_MODULO['importar_fatura.php'] === 'importacoes' && PAGINA_MODULO['importar_extrato.php'] === 'importacoes' && PAGINA_MODULO['api_cenario_atualizar.php'] === 'projecao' && PAGINA_MODULO['api_projecao_risco.php'] === 'projecao', 'importações e as APIs novas estão sob permissão de módulo');
ok(strpos($fonte('cadastro.php'), '$PAGINA_PUBLICA = true') !== false && strpos($fonte('cadastro.php'), 'csrf_exigir()') !== false, 'a página de cadastro é pública e exige o token CSRF');
ok(substr_count($fonte('usuarios.php'), 'csrf_exigir()') >= 1 && strpos($fonte('usuarios.php'), "'usuarios.php' => 'usuarios'") === false, 'a gestão de usuários está atrás do módulo "usuarios" (só o administrador do sistema tem)');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
