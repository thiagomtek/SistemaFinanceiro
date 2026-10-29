<?php
declare(strict_types=1);

/** Testes do Web Push: assinaturas, endereços aceitos, corpo da notificação, envio (simulado) e rotina diária. Uso: php tools/ci/testes_push.php */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');
putenv('PUSH_VAPID_PUBLIC=');
putenv('PUSH_VAPID_PRIVATE=');
putenv('PUSH_VAPID_SUBJECT=');

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
function b64url(int $bytes): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
function assinatura(string $endpoint): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => b64url(65), 'auth' => b64url(16)]];
}

$admin = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['Admin', 'admin@t.t', 'x']);
$_SESSION['usuario_id'] = $admin;
garantir_perfil();
$outro = usuario_salvar(['nome' => 'Outra', 'email' => 'outra@t.t', 'senha' => 'senhaoutra1', 'papel' => 'leitor'], 0, $admin);
$FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

echo "== desligado sem chaves\n";
ok(!push_disponivel(), 'sem as chaves VAPID (ou sem a biblioteca) o push fica desligado');
q_insert('INSERT INTO push_assinaturas (usuario_id, endpoint, endpoint_hash, p256dh, auth) VALUES (?,?,?,?,?)', [$admin, $FCM, hash('sha256', $FCM), b64url(65), b64url(16)]);
ok(push_enviar_usuario($admin, ['titulo' => 'x', 'mensagem' => 'y', 'url' => 'index.php', 'chave' => 'k']) === ['enviadas' => 0, 'falhas' => 0, 'removidas' => 0], 'desligado: não envia nada nem falha');
ok(push_notificar_pendentes()['usuarios'] === 0, 'desligado: a rotina diária não faz nada');
q('DELETE FROM push_assinaturas');

echo "== enderecos aceitos\n";
foreach (['https://fcm.googleapis.com/fcm/send/x', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://sn3p.notify.windows.com/?token=x'] as $u) {
    ok(push_endpoint_valido($u), 'aceita ' . parse_url($u, PHP_URL_HOST));
}
foreach (['http://fcm.googleapis.com/x', 'https://fcm.googleapis.com.evil.com/x', 'https://evil.com/fcm.googleapis.com', 'https://169.254.169.254/x', 'https://fcm.googleapis.com:8443/x', 'https://user@fcm.googleapis.com/x', 'https://notgoogleapis.com/x', '', 'https://fcm.googleapis.com/' . str_repeat('a', 1000)] as $u) {
    ok(!push_endpoint_valido($u), 'recusa ' . (strlen($u) > 60 ? substr($u, 0, 40) . '…' : ($u === '' ? '(vazio)' : $u)));
}

echo "== assinar e cancelar\n";
push_registrar($admin, assinatura($FCM), 'Chrome/Android');
ok(push_dispositivos($admin) === 1, 'aparelho cadastrado');
$nova = assinatura($FCM);
push_registrar($admin, $nova, 'Chrome/Android 2');
ok(push_dispositivos($admin) === 1 && q_val('SELECT p256dh FROM push_assinaturas WHERE usuario_id = ?', [$admin]) === $nova['keys']['p256dh'], 'o mesmo aparelho não duplica e renova as chaves');
push_registrar($admin, assinatura('https://updates.push.services.mozilla.com/wpush/v2/zzz'), 'Firefox');
ok(push_dispositivos($admin) === 2, 'outro aparelho do mesmo usuário conta separado');
lanca(function () use ($admin) { push_registrar($admin, assinatura('https://evil.example/x')); }, 'endereço fora dos serviços de push é recusado');
lanca(function () use ($admin, $FCM) { push_registrar($admin, ['endpoint' => $FCM, 'keys' => ['p256dh' => 'curta', 'auth' => 'curta']]); }, 'chaves inválidas são recusadas');
lanca(function () use ($admin, $FCM) { push_registrar($admin, ['endpoint' => $FCM]); }, 'assinatura sem chaves é recusada');
push_cancelar($outro, $FCM);
ok(push_dispositivos($admin) === 2, 'outro usuário não cancela o aparelho alheio');
push_registrar($outro, assinatura($FCM), 'Chrome/Android');
ok(push_dispositivos($admin) === 1 && push_dispositivos($outro) === 1, 'o aparelho passa a ser de quem entrou por último');
push_cancelar($admin, 'https://updates.push.services.mozilla.com/wpush/v2/zzz');
ok(push_dispositivos($admin) === 0, 'o usuário cancela o próprio aparelho');
q('DELETE FROM push_assinaturas');

echo "== corpo da notificacao\n";
$p = json_decode(push_payload(['titulo' => 'Contas a pagar hoje', 'mensagem' => '2 lançamento(s)', 'url' => 'lancamentos.php?mes=2026-10&sit=pendente', 'chave' => 'dia:2026-10-29:despesa']), true);
ok($p['titulo'] === 'Financeiro · Contas a pagar hoje', 'o título leva o prefixo Financeiro (não se confunde com o ERP)');
ok($p['url'] === 'lancamentos.php?mes=2026-10&sit=pendente' && $p['tag'] === 'fin-dia:2026-10-29:despesa', 'mantém a tela de destino e usa a chave como tag (substitui o aviso local igual)');
$p2 = json_decode(push_payload(['titulo' => 'Financeiro: fatura', 'mensagem' => 'm', 'url' => 'x.php', 'chave' => 'a b/c']), true);
ok($p2['titulo'] === 'Financeiro: fatura' && $p2['tag'] === 'fin-abc', 'não repete o prefixo; a tag só leva caracteres seguros');
foreach (['https://evil.com/x.php', '//evil.com/x.php', 'javascript:alert(1)', '../x.php', 'a/b.php', '', 'login.html'] as $u) {
    ok(push_url_relativa($u) === 'index.php', 'destino inválido vira o início: ' . ($u === '' ? '(vazio)' : $u));
}

echo "== envio (simulado)\n";
$GLOBALS['FIN_PUSH_MOCK'] = function (array $subs, string $payload): array {
    $r = [];
    foreach ($subs as $s) {
        $e = $s['endpoint'];
        $r[] = ['endpoint' => $e, 'ok' => strpos($e, 'ok') !== false, 'expirada' => strpos($e, 'gone') !== false, 'motivo' => 'HTTP simulado'];
    }
    return $r;
};
ok(push_disponivel(), 'com o simulador o push fica disponível');
$aviso = ['titulo' => 'Teste', 'mensagem' => 'm', 'url' => 'index.php', 'chave' => 't'];
ok(push_enviar_usuario($admin, $aviso)['enviadas'] === 0, 'usuário sem aparelho não recebe');
foreach (['https://fcm.googleapis.com/ok1', 'https://fcm.googleapis.com/gone1', 'https://fcm.googleapis.com/fail1'] as $e) {
    push_registrar($admin, assinatura($e));
}
$r = push_enviar_usuario($admin, $aviso);
ok($r === ['enviadas' => 1, 'falhas' => 2, 'removidas' => 1], 'conta entregues, falhas e removidos');
ok(push_dispositivos($admin) === 2 && !q_val('SELECT id FROM push_assinaturas WHERE endpoint_hash = ?', [hash('sha256', 'https://fcm.googleapis.com/gone1')]), 'só o aparelho expirado (404/410) é removido; falha comum mantém');
$GLOBALS['FIN_PUSH_MOCK'] = function (array $subs, string $payload): array { throw new RuntimeException('rede fora'); };
$r = push_enviar_usuario($admin, $aviso);
ok($r['falhas'] === 2 && $r['enviadas'] === 0 && push_dispositivos($admin) === 2, 'erro do serviço de push não derruba o chamador e não apaga aparelhos');
q('DELETE FROM push_assinaturas');

echo "== rotina diaria\n";
$_SESSION['usuario_id'] = $admin;
unset($_SESSION['perfil_id']);
garantir_perfil();
$ct = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 0]);
$hoje = date('Y-m-d');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Internet', 'valor' => '100', 'data' => $hoje, 'conta_id' => $ct]);
criar_lancamento(['tipo' => 'receita', 'descricao' => 'Freela', 'valor' => '900', 'data' => $hoje, 'conta_id' => $ct]);
$esperados = count(notificacoes_do_dia());
$sem = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, dono_id) VALUES (?,?,?,?,?)', ['SemAcesso', 'sem@t.t', 'x', 'personalizado', $admin]);
$inativo = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, dono_id, ativo) VALUES (?,?,?,?,?,0)', ['Inativo', 'inativo@t.t', 'x', 'admin', $admin]);
$chamadas = [];
$GLOBALS['FIN_PUSH_MOCK'] = function (array $subs, string $payload) use (&$chamadas): array {
    $chamadas[] = ['n' => count($subs), 'p' => json_decode($payload, true)];
    return array_map(function ($s) { return ['endpoint' => $s['endpoint'], 'ok' => true, 'expirada' => false, 'motivo' => '']; }, $subs);
};
push_registrar($admin, assinatura('https://fcm.googleapis.com/ok-admin-1'));
push_registrar($admin, assinatura('https://web.push.apple.com/ok-admin-2'));
push_registrar($sem, assinatura('https://fcm.googleapis.com/ok-sem'));
push_registrar($inativo, assinatura('https://fcm.googleapis.com/ok-inativo'));
ok($esperados === 2, 'há dois avisos hoje (contas a pagar e a receber)');
$res = push_notificar_pendentes();
ok($res['usuarios'] === 2, 'só usuários ativos com aparelho entram (o inativo e quem não tem aparelho ficam de fora)');
ok($res['avisos'] === $esperados && $res['enviadas'] === 2 * $esperados, 'o admin recebe todos os avisos nos 2 aparelhos; quem não tem permissão não recebe nada');
ok(count($chamadas) === $esperados && $chamadas[0]['n'] === 2 && strpos($chamadas[0]['p']['titulo'], 'Financeiro · ') === 0, 'um envio por aviso, para todos os aparelhos do usuário');
ok((int) q_val('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ?', [$admin]) === $esperados, 'os avisos também ficam gravados no sino');
ok((int) q_val('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ?', [$outro]) === 0 && (int) q_val('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ?', [$inativo]) === 0, 'quem não tem aparelho (ou está inativo) não é sincronizado');
ok($_SESSION['usuario_id'] === $admin, 'a sessão original é restaurada');
$chamadas = [];
$res2 = push_notificar_pendentes();
ok($res2['avisos'] === 0 && $res2['enviadas'] === 0 && $chamadas === [], 'rodar de novo no mesmo dia não repete os avisos');
$amanha = date('Y-m-d', strtotime('+1 day'));
$res3 = push_notificar_pendentes($amanha);
ok($res3['avisos'] >= 1, 'no dia seguinte há avisos novos (chave inclui a data)');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
