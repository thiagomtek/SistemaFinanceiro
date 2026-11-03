<?php
$PAGINA_PUBLICA = true;
require_once __DIR__ . '/auth_check.php';

$primeiroAcesso = (int) q_val('SELECT COUNT(*) FROM usuarios') === 0;
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $manter = !empty($_POST['manter']);
    if ($primeiroAcesso) {
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 8) {
            $erro = 'Informe nome, e-mail válido e senha com ao menos 8 caracteres.';
        } else {
            $uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, sistema_admin) VALUES (?,?,?,?,?)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), 'admin', 1]); // o primeiro usuario e o administrador do sistema
            sessao_reiniciar($manter);
            $_SESSION['usuario_id'] = $uid;
            $_SESSION['usuario_nome'] = $nome;
            garantir_perfil();
            redirect('index.php');
        }
    } else {
        $r = login_autenticar($email, $senha);
        if ($r['ok']) {
            $u = $r['usuario'];
            q('UPDATE usuarios SET ultimo_acesso = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $u['id']]);
            sessao_reiniciar($manter);
            $_SESSION['usuario_id'] = (int) $u['id'];
            $_SESSION['usuario_nome'] = $u['nome'];
            garantir_perfil();
            redirect('index.php');
        } elseif ($r['motivo'] === 'bloqueado') {
            $erro = 'Muitas tentativas. Tente de novo em ' . (int) ceil($r['espera'] / 60) . ' minuto(s).';
        } elseif ($r['motivo'] === 'pendente') {
            $erro = 'Seu cadastro ainda aguarda a aprovação do administrador.';
        } else {
            $erro = 'E-mail ou senha incorretos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#0f172a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Financeiro">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<title>Entrar · Sistema Financeiro</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin_layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/font-awesome.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/financeiro.css')) ?>">
</head>
<body class="login-page">
  <form class="login-box" method="post" autocomplete="on">
    <h1><i class="fa fa-line-chart text-success"></i> Sistema Financeiro</h1>
    <p><?= $primeiroAcesso ? 'Primeiro acesso: crie o usuário administrador.' : 'Entre para acessar suas finanças.' ?></p>
    <?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <?php if ($primeiroAcesso): ?>
      <div class="form-group"><label for="nome">Nome</label><input class="form-control" id="nome" name="nome" required autofocus></div>
    <?php endif; ?>
    <div class="form-group"><label for="email">E-mail</label><input class="form-control" id="email" name="email" type="email" required <?= $primeiroAcesso ? '' : 'autofocus' ?>></div>
    <div class="form-group"><label for="senha">Senha</label><input class="form-control" id="senha" name="senha" type="password" required <?= $primeiroAcesso ? 'minlength="8"' : '' ?>></div>
    <div class="form-group">
      <label for="manter" style="font-weight:normal;cursor:pointer"><input id="manter" name="manter" type="checkbox" value="1" checked> Manter conectado</label>
      <small class="text-muted" style="display:block">A sessão não expira e dura para sempre neste aparelho, até você clicar em Sair. Não use em computador compartilhado.</small>
    </div>
    <button class="btn btn-success w-100" type="submit"><i class="fa fa-sign-in"></i> <?= $primeiroAcesso ? 'Criar acesso' : 'Entrar' ?></button>
    <?php if (!$primeiroAcesso && cadastro_modo() !== 'fechado'): ?>
      <p style="text-align:center;margin:14px 0 0;font-size:13px">Ainda não tem conta? <a href="cadastro.php"><strong>Criar conta</strong></a></p>
    <?php endif; ?>
  </form>
</body>
</html>
