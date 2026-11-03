<?php
$PAGINA_PUBLICA = true;
require_once __DIR__ . '/auth_check.php';

// Cadastro de novos usuarios (RN-51). Pagina publica: o modo (fechado, com aprovacao ou aberto) e definido so pelo administrador do sistema.
header('Cache-Control: no-store');
if (usuario_id()) {
    redirect('index.php');
}
$modo = cadastro_modo();
$erro = '';
$sucesso = '';
$vals = ['nome' => '', 'email' => ''];

if ($modo === 'fechado') {
    $erro = 'O cadastro de novos usuários está fechado no momento.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $vals = ['nome' => trim((string) ($_POST['nome'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? ''))];
    try {
        if ((string) ($_POST['senha'] ?? '') !== (string) ($_POST['senha2'] ?? '')) {
            throw new InvalidArgumentException('A confirmação não confere com a senha.');
        }
        $sucesso = cadastro_publico_registrar([
            'nome' => $_POST['nome'] ?? '', 'email' => $_POST['email'] ?? '', 'senha' => $_POST['senha'] ?? '',
            'website' => $_POST['website'] ?? '', 'inicio' => $_SESSION['cad_inicio'] ?? 0,
        ]);
        unset($_SESSION['cad_inicio']); // um envio por exibicao do formulario
    } catch (InvalidArgumentException $e) {
        $erro = $e->getMessage();
    }
}
if ($sucesso === '' && $modo !== 'fechado') {
    $_SESSION['cad_inicio'] = time(); // instante da exibicao: o envio rapido demais e recusado
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0f172a">
<title>Criar conta · Sistema Financeiro</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin_layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/font-awesome.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/financeiro.css')) ?>">
<style>.campo-isca{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}</style>
</head>
<body class="login-page">
  <form class="login-box" method="post" autocomplete="off">
    <h1><i class="fa fa-user-plus text-success"></i> Criar conta</h1>
    <p>Você terá os seus próprios dados financeiros, separados dos de qualquer outra pessoa.</p>
    <?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?>
    <?php if ($sucesso): ?>
      <div class="alert alert-success"><?= e($sucesso) ?></div>
      <p style="text-align:center;margin:14px 0 0"><a href="login.php"><i class="fa fa-arrow-left"></i> Voltar para a entrada</a></p>
    <?php elseif ($modo !== 'fechado'): ?>
      <?= csrf_field() ?>
      <div class="campo-isca" aria-hidden="true"><label>Não preencha este campo<input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label></div>
      <div class="form-group"><label for="nome">Nome</label><input class="form-control" id="nome" name="nome" maxlength="80" required autofocus value="<?= e($vals['nome']) ?>"></div>
      <div class="form-group"><label for="email">E-mail</label><input class="form-control" id="email" name="email" type="email" maxlength="160" required value="<?= e($vals['email']) ?>"></div>
      <div class="form-group"><label for="senha">Senha</label><input class="form-control" id="senha" name="senha" type="password" minlength="10" maxlength="72" required autocomplete="new-password">
        <small class="text-muted">Mínimo de 10 caracteres, sem o seu nome ou e-mail e nada previsível.</small></div>
      <div class="form-group"><label for="senha2">Repita a senha</label><input class="form-control" id="senha2" name="senha2" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></div>
      <?php if ($modo === 'aprovacao'): ?><p class="text-muted" style="font-size:12.5px">O acesso é liberado depois que o administrador aprovar o seu cadastro.</p><?php endif; ?>
      <button class="btn btn-success w-100" type="submit"><i class="fa fa-user-plus"></i> Criar conta</button>
      <p style="text-align:center;margin:14px 0 0;font-size:13px"><a href="login.php">Já tenho conta</a></p>
    <?php else: ?>
      <p style="text-align:center;margin:14px 0 0"><a href="login.php"><i class="fa fa-arrow-left"></i> Voltar para a entrada</a></p>
    <?php endif; ?>
  </form>
</body>
</html>
