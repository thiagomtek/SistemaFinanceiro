<?php
declare(strict_types=1);

/** Testes de usuarios, papeis, permissoes por tela, espaco de dados compartilhado e meu perfil. Uso: php tools/ci/testes_usuarios.php */

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
function lanca(callable $f, string $msg): void
{
    try {
        $f();
        ok(false, $msg);
    } catch (InvalidArgumentException $e) {
        ok(true, $msg);
    }
}

$admin = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, sistema_admin) VALUES (?,?,?,?,?)', ['Admin', 'admin@t.t', password_hash('senhaadmin1', PASSWORD_DEFAULT), 'admin', 1]); // o administrador do sistema
$_SESSION['usuario_id'] = $admin;
garantir_perfil();
$perfilAdmin = perfil_id();

echo "== papeis\n";
$pa = permissoes_do_papel('admin');
ok($pa['usuarios'] === 'editar' && $pa['lancamentos'] === 'editar', 'administrador edita tudo, inclusive usuários');
$pe = permissoes_do_papel('editor');
ok($pe['lancamentos'] === 'editar' && $pe['usuarios'] === 'nenhum', 'editor edita tudo, menos usuários');
$pl = permissoes_do_papel('leitor');
ok($pl['lancamentos'] === 'ver' && $pl['importacoes'] === 'nenhum' && $pl['usuarios'] === 'nenhum', 'leitor só vê (sem importações e usuários)');
ok(permissao_pode('usuarios', 'editar'), 'o admin logado pode editar usuários');

echo "== usuarios do mesmo espaco de dados\n";
$leitor = usuario_salvar(['nome' => 'Leitora', 'email' => 'leitora@t.t', 'senha' => 'senhaleitor1', 'papel' => 'leitor'], 0, $admin);
$custom = usuario_salvar(['nome' => 'Custom', 'email' => 'custom@t.t', 'senha' => 'senhacustom1', 'papel' => 'personalizado', 'permissoes' => ['lancamentos' => 'ver', 'contas' => 'editar', 'cartoes' => 'nenhum']], 0, $admin);
ok(dono_dos_dados($leitor) === $admin, 'o novo usuário usa os dados do administrador');
$_SESSION['usuario_id'] = $leitor;
unset($_SESSION['perfil_id']);
garantir_perfil();
ok(perfil_id() === $perfilAdmin, 'e enxerga o mesmo perfil financeiro');
$mc = permissoes_do_usuario($custom);
ok($mc['lancamentos'] === 'ver' && $mc['contas'] === 'editar' && $mc['cartoes'] === 'nenhum' && $mc['usuarios'] === 'nenhum', 'personalizado: a matriz escolhida vale, o resto é sem acesso');
ok(permissoes_do_usuario($leitor)['contas'] === 'ver', 'leitor vê contas');

echo "== inativo, e-mail e senha\n";
q('UPDATE usuarios SET ativo = 0 WHERE id = ?', [$leitor]);
ok(permissoes_do_usuario($leitor)['lancamentos'] === 'nenhum', 'usuário inativo fica sem nenhuma permissão');
lanca(function () use ($admin) { usuario_salvar(['nome' => 'X', 'email' => 'admin@t.t', 'senha' => 'senhaxxxx1', 'papel' => 'leitor'], 0, $admin); }, 'e-mail repetido é recusado');
lanca(function () use ($admin) { usuario_salvar(['nome' => 'X', 'email' => 'x@t.t', 'senha' => 'curta', 'papel' => 'leitor'], 0, $admin); }, 'senha curta é recusada');
lanca(function () use ($admin) { usuario_salvar(['nome' => 'X', 'email' => 'invalido', 'senha' => 'senhaxxxx1', 'papel' => 'leitor'], 0, $admin); }, 'e-mail inválido é recusado');

echo "== sempre um administrador ativo\n";
lanca(function () use ($admin) { usuario_salvar(['nome' => 'Admin', 'email' => 'admin@t.t', 'papel' => 'leitor'], $admin, $admin); }, 'o único administrador não pode virar leitor');
lanca(function () use ($admin) { usuario_excluir($admin, $admin, $admin); }, 'não se exclui o próprio usuário');
lanca(function () use ($admin) { usuario_salvar(['nome' => 'Admin 2', 'email' => 'admin2@t.t', 'senha' => 'senhaadmin2', 'papel' => 'admin'], 0, $admin); }, 'RN-50: nenhum outro usuário pode ser criado como administrador');
$admin2 = usuario_salvar(['nome' => 'Admin 2', 'email' => 'admin2@t.t', 'senha' => 'senhaadmin2', 'papel' => 'editor'], 0, $admin);
lanca(function () use ($admin, $admin2) { usuario_salvar(['nome' => 'Admin 2', 'email' => 'admin2@t.t', 'papel' => 'admin'], $admin2, $admin); }, 'RN-50: promover um usuário existente a administrador também é recusado');
lanca(function () use ($admin, $admin2) { usuario_excluir($admin, $admin2, $admin2); }, 'o administrador do sistema (dono dos dados) não é excluído');
lanca(function () use ($admin) { usuario_salvar(['nome' => 'Admin', 'email' => 'admin@t.t', 'papel' => 'editor'], $admin, $admin); }, 'o administrador do sistema não perde o papel');
usuario_excluir($custom, $admin, $admin);
ok(!q_val('SELECT id FROM usuarios WHERE id = ?', [$custom]), 'usuário comum é excluído (e as permissões junto)');
ok((int) q_val('SELECT COUNT(*) FROM usuario_permissoes WHERE usuario_id = ?', [$custom]) === 0, 'sem permissões órfãs');

echo "== meu perfil\n";
perfil_salvar_dados($admin, 'Admin Novo', 'novo@t.t');
ok(q_val('SELECT nome FROM usuarios WHERE id = ?', [$admin]) === 'Admin Novo', 'nome e e-mail atualizados');
lanca(function () use ($admin) { perfil_salvar_dados($admin, 'A', 'admin2@t.t'); }, 'e-mail de outro usuário é recusado');
lanca(function () use ($admin) { perfil_trocar_senha($admin, 'errada', 'novasenha1', 'novasenha1'); }, 'senha atual errada é recusada');
lanca(function () use ($admin) { perfil_trocar_senha($admin, 'senhaadmin1', 'novasenha1', 'diferente1'); }, 'confirmação diferente é recusada');
perfil_trocar_senha($admin, 'senhaadmin1', 'novasenha1', 'novasenha1');
ok(password_verify('novasenha1', q_val('SELECT senha_hash FROM usuarios WHERE id = ?', [$admin])), 'senha trocada');

echo "== notificacoes do dia\n";
$_SESSION['usuario_id'] = $admin;
unset($_SESSION['perfil_id']);
garantir_perfil();
$ct = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 0]);
$hoje = date('Y-m-d');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Internet', 'valor' => '100', 'data' => $hoje, 'conta_id' => $ct]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Luz', 'valor' => '50', 'data' => $hoje, 'conta_id' => $ct]);
criar_lancamento(['tipo' => 'receita', 'descricao' => 'Freela', 'valor' => '900', 'data' => $hoje, 'conta_id' => $ct]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Antiga', 'valor' => '30', 'data' => date('Y-m-d', strtotime('-3 day')), 'conta_id' => $ct]);
$diaOntem = (int) date('d', strtotime('-1 day'));
q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Nubank', $ct, 1000, $diaOntem, 10]);
$ns = notificacoes_do_dia();
$chaves = array_column($ns, 'chave');
ok(in_array('dia:' . $hoje . ':despesa', $chaves, true) && in_array('dia:' . $hoje . ':receita', $chaves, true), 'avisa contas a pagar e a receber que vencem hoje');
$pagar = $ns[array_search('dia:' . $hoje . ':despesa', $chaves, true)];
ok(strpos($pagar['mensagem'], '2 lançamento(s)') !== false && strpos($pagar['mensagem'], '150,00') !== false, 'resume quantidade e total das contas a pagar hoje');
ok(in_array('atraso:' . $hoje, $chaves, true), 'avisa lançamentos atrasados');
$fecha = array_values(array_filter($ns, function ($n) { return strpos($n['chave'], 'fecha:') === 0; }));
ok(count($fecha) === 1 && strpos($fecha[0]['mensagem'], 'fechou ontem') !== false && $fecha[0]['url'] === 'importar_fatura.php', 'no dia seguinte ao fechamento avisa que a fatura fechou e pode ser importada');
$n1 = notificacoes_sincronizar();
ok($n1 === count($ns) && notificacoes_sincronizar() === 0, 'cada aviso é gravado uma única vez');
ok(notificacoes_nao_lidas() === count($ns), 'todos começam não lidos');
$primeiro = notificacoes_listar(1)[0];
notificacoes_marcar_lida((int) $primeiro['id']);
ok(notificacoes_nao_lidas() === count($ns) - 1, 'marcar uma como lida');
notificacoes_marcar_lida(0);
ok(notificacoes_nao_lidas() === 0, 'marcar todas como lidas');
$_SESSION['usuario_id'] = $custom ?? $admin;
$sem = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, dono_id) VALUES (?,?,?,?,?)', ['SemAcesso', 'sem@t.t', 'x', 'personalizado', $admin]);
$_SESSION['usuario_id'] = $sem;
unset($_SESSION['perfil_id']);
garantir_perfil();
ok(notificacoes_do_dia() === [], 'sem permissão de ver lançamentos e cartões, não recebe esses avisos');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
