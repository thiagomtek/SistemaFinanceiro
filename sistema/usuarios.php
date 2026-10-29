<?php
require_once __DIR__ . '/auth_check.php';

$eu = (int) usuario_id();
$dono = dono_dos_dados($eu);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($acao === 'salvar') {
            if ($id === $eu && empty($_POST['ativo'])) {
                throw new InvalidArgumentException('Você não pode inativar o próprio usuário.');
            }
            if ($id === $eu && ($_POST['papel'] ?? '') !== 'admin' && admins_ativos($eu, $dono) < 1) {
                throw new InvalidArgumentException('É preciso manter ao menos um administrador ativo.');
            }
            usuario_salvar($_POST + ['ativo' => !empty($_POST['ativo']) ? 1 : 0], $id, $dono);
            flash($id ? 'Usuário atualizado.' : 'Usuário criado. Ele acessa os mesmos dados financeiros, com as permissões definidas.');
        } elseif ($acao === 'alternar') {
            $u = q_one('SELECT * FROM usuarios WHERE id = ? AND (dono_id = ? OR (dono_id IS NULL AND id = ?))', [$id, $dono, $dono]);
            if (!$u) {
                throw new InvalidArgumentException('Usuário não encontrado.');
            }
            if ($id === $eu) {
                throw new InvalidArgumentException('Você não pode inativar o próprio usuário.');
            }
            if ($u['papel'] === 'admin' && (int) $u['ativo'] === 1 && admins_ativos($id, $dono) < 1) {
                throw new InvalidArgumentException('É preciso manter ao menos um administrador ativo.');
            }
            q('UPDATE usuarios SET ativo = 1 - ativo WHERE id = ?', [$id]);
            flash((int) $u['ativo'] ? 'Usuário inativado: não consegue mais entrar.' : 'Usuário reativado.');
        } elseif ($acao === 'excluir') {
            usuario_excluir($id, $dono, $eu);
            flash('Usuário excluído.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('usuarios.php');
}

$usuarios = q_all('SELECT * FROM usuarios WHERE (dono_id = ? OR (dono_id IS NULL AND id = ?)) ORDER BY ativo DESC, nome', [$dono, $dono]);
$ativos = array_values(array_filter($usuarios, function ($u) { return (int) $u['ativo'] === 1; }));
$inativos = array_values(array_filter($usuarios, function ($u) { return (int) $u['ativo'] !== 1; }));

$PAGINA = ['Usuários e permissões', 'fa-users', 'usuarios.php', 'Quem acessa os dados financeiros e o que cada um pode ver ou alterar', 'primary'];
require __DIR__ . '/header.php';

$linhaUsuario = function (array $u) use ($eu) {
    $perm = permissoes_do_usuario((int) $u['id']);
    $json = ['id' => $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel'], 'ativo' => $u['ativo']];
    foreach ($perm as $m => $n) {
        $json['permissoes[' . $m . ']'] = $n;
    }
    ?>
    <tr>
      <td><strong><?= e($u['nome']) ?></strong><?= (int) $u['id'] === $eu ? ' <span class="erp-badge erp-badge-info">você</span>' : '' ?><br><small class="text-muted"><?= e($u['email']) ?></small></td>
      <td><span class="erp-badge erp-badge-<?= $u['papel'] === 'admin' ? 'danger' : ($u['papel'] === 'editor' ? 'success' : ($u['papel'] === 'leitor' ? 'neutral' : 'warning')) ?>"><?= e(PAPEIS[$u['papel']] ?? $u['papel']) ?></span></td>
      <td class="col-perm"><?php foreach (MODULOS as $m => $rot): $n = $perm[$m]; if ($n === 'nenhum') { continue; } ?><span class="erp-badge erp-badge-<?= $n === 'editar' ? 'success' : 'neutral' ?>" title="<?= e($rot . ': ' . NIVEIS[$n]) ?>" style="margin:1px"><?= e($rot) ?><?= $n === 'ver' ? ' (ver)' : '' ?></span> <?php endforeach; ?></td>
      <td class="nowrap"><?= $u['ultimo_acesso'] ? e(date('d/m/Y H:i', strtotime($u['ultimo_acesso']))) : '<span class="text-muted">nunca</span>' ?></td>
      <td class="acoes">
        <a href="#" class="cli-btn-acao btn-acao-editar" title="Editar" data-modal="modal-usuario" data-preencher="<?= e(json_encode($json)) ?>"><i class="fa fa-pencil"></i></a>
        <?php if ((int) $u['id'] !== $eu): ?>
        <form method="post" style="display:inline" data-confirmar="<?= (int) $u['ativo'] ? 'Inativar ' . e($u['nome']) . '? Ele não conseguirá mais entrar.' : 'Reativar ' . e($u['nome']) . '?' ?>"><?= csrf_field() ?><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="cli-btn-acao btn-acao-reativar" title="<?= (int) $u['ativo'] ? 'Inativar' : 'Reativar' ?>"><i class="fa fa-<?= (int) $u['ativo'] ? 'eye-slash' : 'eye' ?>"></i></button></form>
        <form method="post" style="display:inline" data-confirmar="Excluir o usuário <?= e($u['nome']) ?>? Os dados financeiros não são afetados."><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="cli-btn-acao btn-acao-excluir" title="Excluir"><i class="fa fa-trash"></i></button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php
};
?>
<div class="kpi-grid">
  <div class="kpi azul"><i class="fa fa-users kpi-icone"></i><div class="kpi-label">Usuários ativos</div><div class="kpi-valor"><?= count($ativos) ?></div><div class="kpi-detalhe"><?= count($inativos) ?> inativo(s)</div></div>
  <div class="kpi vermelho"><i class="fa fa-shield kpi-icone"></i><div class="kpi-label">Administradores</div><div class="kpi-valor"><?= admins_ativos(null, $dono) ?></div><div class="kpi-detalhe">Sempre ao menos um</div></div>
</div>

<div class="card card-outline card-primary">
  <div class="card-header">
    <h3 class="card-title"><i class="fa fa-users text-primary"></i> Usuários <small class="text-muted" style="font-weight:400">(<?= count($ativos) ?>)</small></h3>
    <div class="card-tools"><a href="#" class="btn btn-primary btn-sm" data-modal="modal-usuario" data-novo><i class="fa fa-plus"></i> Novo usuário</a></div>
  </div>
  <div class="table-responsive">
    <table class="table table-striped table-hover m-0">
      <thead><tr><th>Usuário</th><th>Papel</th><th class="col-perm">Acessos</th><th>Último acesso</th><th class="text-center">Ações</th></tr></thead>
      <tbody><?php foreach ($ativos as $u) { $linhaUsuario($u); } ?></tbody>
    </table>
  </div>
</div>

<?php if ($inativos): ?>
<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:700;color:#475569;margin-bottom:10px"><i class="fa fa-archive"></i> Usuários inativos (<?= count($inativos) ?>)</summary>
<div class="card card-outline card-secondary"><div class="table-responsive">
  <table class="table table-striped table-hover m-0"><tbody><?php foreach ($inativos as $u) { $linhaUsuario($u); } ?></tbody></table>
</div></div></details>
<?php endif; ?>

<div class="painel" style="margin-top:16px">
  <h4><i class="fa fa-info-circle text-info"></i> Papéis</h4>
  <ul style="margin:0;padding-left:18px;line-height:1.7">
    <li><strong>Administrador:</strong> tudo, inclusive usuários e permissões.</li>
    <li><strong>Editor:</strong> vê e altera todas as telas, menos usuários.</li>
    <li><strong>Leitor:</strong> só visualiza (sem importações nem usuários).</li>
    <li><strong>Personalizado:</strong> você escolhe, tela por tela, entre sem acesso, só ver ou ver e editar.</li>
  </ul>
  <p class="text-muted" style="font-size:12px;margin:8px 0 0">Todos os usuários enxergam os mesmos dados financeiros; o que muda é o que cada um pode abrir e alterar.</p>
</div>

<div class="modal fin-modal erp-modal-lg" id="modal-usuario">
  <form class="modal-dialog" method="post" id="form-usuario" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-user"></i> Usuário</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group"><label>Nome</label><input class="form-control" name="nome" maxlength="120" required></div>
        <div class="form-group"><label>E-mail (login)</label><input class="form-control" type="email" name="email" maxlength="160" required></div>
        <div class="form-group"><label>Senha <small class="text-muted" id="us-senha-dica">(mínimo 8 caracteres)</small></label><input class="form-control" type="password" name="senha" autocomplete="new-password" minlength="8"></div>
        <div class="form-group"><label>Papel</label>
          <select class="form-control" name="papel" id="us-papel"><?php foreach (PAPEIS as $k => $rot): ?><option value="<?= e($k) ?>" <?= $k === 'leitor' ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px"><input type="checkbox" name="ativo" value="1" id="us-ativo" style="width:auto" checked><label for="us-ativo" style="margin:0">Usuário ativo</label></div>
      </div>
      <div id="us-matriz" class="hidden" style="margin-top:8px">
        <label style="font-weight:700">Permissões por tela</label>
        <table class="table table-striped m-0">
          <tbody>
          <?php foreach (MODULOS as $m => $rot): ?>
            <tr><td><?= e($rot) ?></td><td style="width:200px"><select class="form-control" name="permissoes[<?= e($m) ?>]" style="height:32px;padding:2px 8px">
              <?php foreach (NIVEIS as $k => $nr): ?><option value="<?= e($k) ?>"><?= e($nr) ?></option><?php endforeach; ?></select></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0" id="us-papel-desc"></p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>
<script>
(function () {
  var papel = document.getElementById('us-papel'), matriz = document.getElementById('us-matriz'), desc = document.getElementById('us-papel-desc');
  var textos = {
    admin: 'Acesso total, inclusive criar usuários e definir permissões.',
    editor: 'Vê e altera todas as telas, exceto usuários.',
    leitor: 'Só visualiza as telas (sem importações e sem usuários).',
    personalizado: 'Escolha abaixo o acesso a cada tela.'
  };
  function atualizar() {
    matriz.classList.toggle('hidden', papel.value !== 'personalizado');
    desc.textContent = textos[papel.value] || '';
  }
  papel.addEventListener('change', atualizar);
  document.getElementById('modal-usuario').addEventListener('click', function () {});
  new MutationObserver(function () {
    var novo = !document.querySelector('#form-usuario [name=id]').value;
    document.getElementById('us-senha-dica').textContent = novo ? '(mínimo 8 caracteres)' : '(deixe em branco para manter a atual)';
    document.querySelector('#form-usuario [name=senha]').required = novo;
    atualizar();
  }).observe(document.getElementById('modal-usuario'), { attributes: true, attributeFilter: ['class'] });
  atualizar();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
