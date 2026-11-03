<?php
require_once __DIR__ . '/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($acao === 'salvar') {
            $nome = trim($_POST['nome'] ?? '');
            $tipo = ($_POST['tipo'] ?? '') === 'receita' ? 'receita' : 'despesa';
            $cor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cor'] ?? '') ? $_POST['cor'] : '#64748b';
            $pai = (int) ($_POST['pai_id'] ?? 0) ?: null;
            if ($nome === '') {
                throw new InvalidArgumentException('Informe o nome da categoria.');
            }
            if ($pai) {
                $paiRow = q_one('SELECT * FROM categorias WHERE id = ? AND perfil_id = ? AND pai_id IS NULL', [$pai, perfil_id()]);
                if (!$paiRow || $paiRow['id'] == $id) {
                    throw new InvalidArgumentException('Categoria principal inválida.');
                }
                $tipo = $paiRow['tipo'];
            }
            $dup = q_val('SELECT id FROM categorias WHERE perfil_id = ? AND tipo = ? AND nome = ? AND COALESCE(pai_id,0) = ? AND id <> ?', [perfil_id(), $tipo, $nome, $pai ?? 0, $id]);
            if ($dup) {
                throw new InvalidArgumentException('Já existe uma categoria com esse nome.');
            }
            if ($id && q_val('SELECT sistema FROM categorias WHERE id = ? AND perfil_id = ?', [$id, perfil_id()])) {
                throw new InvalidArgumentException('Categoria de sistema não pode ser alterada.');
            }
            if ($id && ($atual = q_one('SELECT tipo FROM categorias WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]))) {
                $filhas = (int) q_val('SELECT COUNT(*) FROM categorias WHERE pai_id = ? AND perfil_id = ?', [$id, perfil_id()]);
                if ($pai && $filhas) {
                    throw new InvalidArgumentException('Esta categoria tem subcategorias e não pode virar subcategoria de outra.');
                }
                if ($atual['tipo'] !== $tipo && ($filhas || q_val('SELECT COUNT(*) FROM lancamentos WHERE categoria_id = ? AND perfil_id = ?', [$id, perfil_id()]))) {
                    throw new InvalidArgumentException('Esta categoria já tem lançamentos ou subcategorias: não dá para trocar entre receita e despesa.');
                }
            }
            if ($id && pertence_ao_perfil('categorias', $id)) {
                q('UPDATE categorias SET nome=?, tipo=?, cor=?, pai_id=? WHERE id=? AND perfil_id=?', [$nome, $tipo, $cor, $pai, $id, perfil_id()]);
                flash('Categoria atualizada.');
            } else {
                limite_entidade_exigir('categorias', 'categorias');
                q_insert('INSERT INTO categorias (perfil_id, nome, tipo, cor, pai_id) VALUES (?,?,?,?,?)', [perfil_id(), $nome, $tipo, $cor, $pai]);
                flash('Categoria cadastrada.');
            }
        } elseif ($acao === 'excluir' && pertence_ao_perfil('categorias', $id)) {
            if (q_val('SELECT sistema FROM categorias WHERE id = ? AND perfil_id = ?', [$id, perfil_id()])) {
                throw new InvalidArgumentException('Categoria de sistema não pode ser excluída.');
            }
            // lancamentos ficam "sem categoria" (FK ON DELETE SET NULL); subcategorias sobem para sem pai? nao: bloqueia.
            if (q_val('SELECT COUNT(*) FROM categorias WHERE pai_id = ?', [$id])) {
                throw new InvalidArgumentException('Exclua primeiro as subcategorias desta categoria.');
            }
            q('DELETE FROM categorias WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            flash('Categoria excluída. Os lançamentos dela ficaram sem categoria.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('categorias.php');
}

$todas = categorias_do_perfil();
$usos = [];
foreach (q_all('SELECT categoria_id, COUNT(*) AS n FROM lancamentos WHERE perfil_id = ? AND categoria_id IS NOT NULL GROUP BY categoria_id', [perfil_id()]) as $u) {
    $usos[$u['categoria_id']] = (int) $u['n'];
}
$principais = array_filter($todas, fn($c) => !$c['pai_id']);

$PAGINA = ['Categorias', 'fa-tags', 'categorias.php', 'Classifique receitas e despesas (com subcategorias)', 'purple'];
require __DIR__ . '/header.php';
?>
<div class="grid-2">
<?php foreach (['despesa' => ['Categorias de despesa', 'danger'], 'receita' => ['Categorias de receita', 'success']] as $tipo => [$rotulo, $cls]): ?>
  <div class="card card-outline card-<?= $cls ?>">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-<?= $tipo === 'receita' ? 'arrow-circle-up' : 'arrow-circle-down' ?> text-<?= $cls ?>"></i> <?= $rotulo ?></h3>
      <a href="#" class="btn btn-primary btn-sm" data-modal="modal-cat" data-novo><i class="fa fa-plus"></i> Nova categoria</a></div>
    <div class="card-body p-0">
    <table class="table table-striped table-hover m-0">
      <tbody>
      <?php foreach ($todas as $c): if ($c['tipo'] !== $tipo) { continue; } ?>
        <tr>
          <td style="<?= $c['pai_id'] ? 'padding-left:34px' : '' ?>">
            <span class="cor-bolinha" style="background:<?= e($c['cor']) ?>"></span>
            <?= $c['pai_id'] ? '<i class="fa fa-level-up fa-rotate-90 text-muted"></i> ' : '<strong>' ?><?= e($c['nome']) ?><?= $c['pai_id'] ? '' : '</strong>' ?>
            <small class="text-muted"> · <?= (int) ($usos[$c['id']] ?? 0) ?> lanç.</small>
          </td>
          <td class="acoes"><?php if ($c['sistema']): ?><span class="erp-badge erp-badge-neutral" title="Categoria de sistema, usada nos reajustes"><i class="fa fa-lock"></i> Sistema</span><?php else: ?>
            <a href="#" class="cli-btn-acao btn-acao-editar" data-modal="modal-cat" data-preencher="<?= e(json_encode(['id' => $c['id'], 'nome' => $c['nome'], 'tipo' => $c['tipo'], 'cor' => $c['cor'], 'pai_id' => $c['pai_id']])) ?>"><i class="fa fa-pencil"></i></a>
            <form method="post" style="display:inline" data-confirmar="Excluir a categoria &quot;<?= e($c['nome']) ?>&quot;?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="cli-btn-acao btn-acao-excluir"><i class="fa fa-trash"></i></button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-cat">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-tags"></i> Categoria</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Nome</label><input class="form-control" name="nome" maxlength="80" required></div>
      <div class="form-group"><label>Tipo</label><select class="form-control" name="tipo"><option value="despesa">Despesa</option><option value="receita">Receita</option></select></div>
      <div class="form-group"><label>Subcategoria de (opcional)</label>
        <select class="form-control" name="pai_id"><option value="">— categoria principal —</option>
          <?php foreach ($principais as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nome']) ?> (<?= $p['tipo'] === 'receita' ? 'receita' : 'despesa' ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="form-group"><label>Cor</label><input type="color" name="cor" value="#64748b" style="height:38px;width:80px;padding:2px;"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>
<?php require __DIR__ . '/footer.php'; ?>
