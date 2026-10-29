<?php
require_once __DIR__ . '/auth_check.php';

$eu = (int) usuario_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'dados') {
            perfil_salvar_dados($eu, (string) ($_POST['nome'] ?? ''), (string) ($_POST['email'] ?? ''));
            $_SESSION['usuario_nome'] = trim((string) $_POST['nome']);
            flash('Seus dados foram atualizados.');
        } elseif ($acao === 'senha') {
            perfil_trocar_senha($eu, (string) ($_POST['atual'] ?? ''), (string) ($_POST['nova'] ?? ''), (string) ($_POST['confirma'] ?? ''));
            flash('Senha alterada.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('perfil.php');
}

$u = q_one('SELECT * FROM usuarios WHERE id = ?', [$eu]);
$perm = permissoes_do_usuario($eu);

$PAGINA = ['Meu perfil', 'fa-user-circle', 'perfil.php', 'Seus dados de acesso, senha e permissões', 'info'];
require __DIR__ . '/header.php';
?>
<div class="grid-2">
  <div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-user text-primary"></i> Meus dados</h3></div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="acao" value="dados">
        <div class="form-group"><label>Nome</label><input class="form-control" name="nome" value="<?= e($u['nome']) ?>" maxlength="120" required></div>
        <div class="form-group"><label>E-mail (login)</label><input class="form-control" type="email" name="email" value="<?= e($u['email']) ?>" maxlength="160" required></div>
        <div class="form-group"><label>Papel</label><div><span class="erp-badge erp-badge-info"><?= e(PAPEIS[$u['papel']] ?? $u['papel']) ?></span>
          <small class="text-muted" style="margin-left:6px">Definido por um administrador</small></div></div>
        <button class="btn btn-primary"><i class="fa fa-check"></i> Salvar dados</button>
      </form>
    </div>
  </div>

  <div class="card card-outline card-warning">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-lock text-warning"></i> Trocar senha</h3></div>
    <div class="card-body">
      <form method="post" autocomplete="off" data-confirmar="Alterar a sua senha?">
        <?= csrf_field() ?><input type="hidden" name="acao" value="senha">
        <div class="form-group"><label>Senha atual</label><input class="form-control" type="password" name="atual" autocomplete="current-password" required></div>
        <div class="form-group"><label>Nova senha <small class="text-muted">(mínimo 8 caracteres)</small></label><input class="form-control" type="password" name="nova" autocomplete="new-password" minlength="8" required></div>
        <div class="form-group"><label>Confirmar nova senha</label><input class="form-control" type="password" name="confirma" autocomplete="new-password" minlength="8" required></div>
        <button class="btn btn-warning"><i class="fa fa-key"></i> Alterar senha</button>
      </form>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card card-outline card-info">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-shield text-info"></i> Minhas permissões</h3></div>
    <div class="table-responsive"><table class="table table-striped m-0"><tbody>
      <?php foreach (MODULOS as $m => $rot): $n = $perm[$m]; ?>
        <tr><td><?= e($rot) ?></td><td class="text-right"><span class="erp-badge erp-badge-<?= $n === 'editar' ? 'success' : ($n === 'ver' ? 'neutral' : 'danger') ?>"><?= e(NIVEIS[$n]) ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="card card-outline card-success">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-bell text-success"></i> Notificações e aplicativo</h3></div>
    <div class="card-body">
      <p style="margin-top:0">Receba um aviso no dia das contas a pagar e a receber e quando uma fatura de cartão fechar, no celular ou no navegador, mesmo com o aplicativo fechado. Ative em cada aparelho que quiser avisar.</p>
      <div id="notif-estado" class="text-muted" role="status" aria-live="polite" style="margin-bottom:8px"></div>
      <button type="button" class="btn btn-success btn-sm" id="btn-notif"><i class="fa fa-bell"></i> Ativar notificações neste aparelho</button>
      <button type="button" class="btn btn-default btn-sm hidden" id="btn-notif-teste"><i class="fa fa-paper-plane"></i> Enviar teste</button>
      <button type="button" class="btn btn-default btn-sm hidden" id="btn-notif-off"><i class="fa fa-bell-slash"></i> Desativar neste aparelho</button>
      <p class="text-muted" style="font-size:12px;margin:8px 0 0">No iPhone e no iPad, as notificações só funcionam com o aplicativo instalado na tela inicial (iOS 16.4 ou mais novo).</p>
      <hr>
      <p style="margin:0 0 6px"><strong>Instalar como aplicativo</strong></p>
      <p class="text-muted" style="font-size:13px;margin:0">No celular ou tablet, abra o menu do navegador e escolha "Instalar aplicativo" ou "Adicionar à tela inicial". Funciona pela rede Tailscale.</p>
      <?php if (!empty($_SESSION['usuario_id'])): ?><p class="text-muted" style="font-size:12px;margin:10px 0 0">Último acesso: <?= $u['ultimo_acesso'] ? e(date('d/m/Y H:i', strtotime($u['ultimo_acesso']))) : '—' ?></p><?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
