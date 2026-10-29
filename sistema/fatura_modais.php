<?php
/* Modais compartilhados de fatura (usados por cartoes.php e fatura.php). Requer $contas (contas ativas). */
?>
<div class="modal fin-modal erp-modal-md" id="modal-pagar">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="pagar"><input type="hidden" name="cartao_id"><input type="hidden" name="mes">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-check-circle"></i> Pagar fatura</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Debitar da conta</label><select class="form-control" name="conta_id" required><?= opcoes($contas, 'id', 'nome', null, true, 'Selecione…') ?></select></div>
      <div class="form-group"><label>Data do pagamento</label><input class="form-control" type="date" name="data" required></div>
      <div class="form-group"><label>Valor a pagar</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor" inputmode="decimal" placeholder="Total da fatura" style="font-weight:700"></div>
        <small class="text-muted">Total da fatura: <strong id="pg-total">—</strong>. Pagando <strong>menos</strong> (por exemplo o mínimo), o que sobrar vai para a próxima fatura como "Saldo restante" — lembre que o banco cobra juros sobre ele. Para <strong>antecipar</strong>, pague antes do vencimento, quando quiser reduzir o uso do limite.</small></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-success"><i class="fa fa-check"></i> Confirmar pagamento</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-ajustar">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="ajustar"><input type="hidden" name="cartao_id"><input type="hidden" name="mes">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-sliders"></i> Ajustar fatura</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <p style="margin-top:0"><strong id="aj-cartao"></strong><br><span class="text-muted" id="aj-fatura"></span></p>
      <div class="form-group"><label>Valor atual</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor_atual" readonly></div></div>
      <div class="form-group"><label>O valor da sua fatura é de:</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor_final" inputmode="decimal" required placeholder="0,00" style="font-size:16px;font-weight:700"></div></div>
      <p class="text-muted" style="font-size:12px;margin-bottom:0">O sistema cria (ou atualiza) um único item <em>Reajuste de fatura</em> com a diferença, de modo que a fatura fique com este valor. Se o valor for menor que a soma das compras, o reajuste ficará negativo.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Ajustar fatura</button></div>
  </form>
</div>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-modal="modal-ajustar"]');
  if (!b) { return; }
  try {
    var d = JSON.parse(b.dataset.preencher);
    document.getElementById('aj-cartao').textContent = 'Cartão: ' + d.cartao_nome;
    document.getElementById('aj-fatura').textContent = 'Fatura com vencimento em ' + d.vencimento_txt;
  } catch (err) {}
});
</script>
