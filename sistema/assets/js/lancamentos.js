// Comportamento da tela de lancamentos: formulario dinamico, anexos, lote, situacao rapida e opcoes de visualizacao.
(function () {
  var form = document.getElementById('form-lanc');
  if (!form) { return; }
  var tipo = document.getElementById('lanc-tipo');
  var cartao = document.getElementById('lanc-cartao');
  var rep = document.getElementById('lanc-repeticao');
  var cat = document.getElementById('lanc-categoria');
  var campo = function (n) { return form.querySelector('[data-campo="' + n + '"]'); };
  var show = function (n, v) { var el = campo(n); if (el) { el.classList.toggle('hidden', !v); } };

  function atualizar() {
    var t = tipo.value;
    var editando = !!form.querySelector('[name=id]').value;
    var noCartao = t === 'despesa' && cartao.value !== '';
    show('destino', t === 'transferencia');
    show('cartao', t === 'despesa');
    show('fatura', noCartao);
    show('categoria', t !== 'transferencia');
    show('efetivado', !noCartao);
    show('repeticao', !editando);
    show('qtd', !editando && (rep.value === 'parcelado'));
    var qin = campo('qtd') && campo('qtd').querySelector('input');
    if (qin) { qin.disabled = editando || rep.value !== 'parcelado'; } // fixa nao tem quantidade: e sem fim
    show('periodo', !editando && rep.value !== 'unico');
    document.getElementById('btn-salvar-nova').classList.toggle('hidden', editando);
    document.getElementById('lbl-conta').textContent = t === 'transferencia' ? 'Conta de origem' : (t === 'receita' ? 'Conta que recebe' : 'Conta');
    campo('origem').classList.toggle('hidden', noCartao);
    document.getElementById('lbl-efetivado').textContent = t === 'receita' ? 'Já foi recebido' : (t === 'transferencia' ? 'Já foi realizada' : 'Já foi pago');
    document.getElementById('lanc-titulo').textContent = editando ? 'Editar lançamento' : 'Novo lançamento';
    Array.prototype.forEach.call(cat.options, function (o) {
      if (!o.value) { return; }
      var ok = o.getAttribute('data-tipo') === t;
      o.hidden = !ok; o.disabled = !ok;
      if (!ok && o.selected) { cat.value = ''; }
    });
    if (t !== 'despesa') { cartao.value = ''; }
  }
  [tipo, cartao, rep].forEach(function (el) { el.addEventListener('change', atualizar); });
  form.addEventListener('reset', function () { setTimeout(atualizar, 0); });
  new MutationObserver(atualizar).observe(document.getElementById('modal-lanc'), { attributes: true, attributeFilter: ['class'] });
  atualizar();

  // anexos ja gravados do lancamento em edicao (cada um com botao para remover)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-modal="modal-lanc"]');
    if (!b) { return; }
    var box = document.getElementById('lanc-anexos');
    box.innerHTML = '';
    if (!b.dataset.preencher) { return; }
    try {
      var d = JSON.parse(b.dataset.preencher);
      (d.anexos || []).forEach(function (a) {
        var linha = document.createElement('div');
        linha.innerHTML = '<a href="anexo.php?id=' + Number(a.id) + '" target="_blank" rel="noopener"><i class="fa fa-paperclip"></i> ' + escapeHtml(a.nome) + '</a> ' +
          '<button type="submit" class="btn btn-outline-danger btn-sm btn-icone" name="anexo_excluir" value="' + Number(a.id) + '" formnovalidate title="Remover anexo"><i class="fa fa-trash"></i></button>';
        box.appendChild(linha);
      });
    } catch (err) {}
  });

  // modal de exclusao
  var ex = document.getElementById('modal-excluir');
  new MutationObserver(function () {
    var f = ex.querySelector('form');
    document.getElementById('ex-escopo').classList.toggle('hidden', f.elements.tem_grupo.value !== '1');
    document.getElementById('ex-fatura').classList.toggle('hidden', f.elements.fatura.value !== '1');
  }).observe(ex, { attributes: true, attributeFilter: ['class'] });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-modal="modal-excluir"]');
    if (b) { try { document.getElementById('ex-desc').textContent = JSON.parse(b.dataset.preencher).descricao_ex; } catch (err) {} }
  });

  // "Salvar e criar nova": a pagina recarrega com ?novo=1 e reabre o formulario
  var params = new URLSearchParams(window.location.search);
  if (params.get('novo') === '1') {
    params.delete('novo');
    var limpa = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    try { window.history.replaceState(null, '', limpa); } catch (err) {}
    var abrir = document.querySelector('[data-modal="modal-lanc"][data-novo]');
    if (abrir) { abrir.click(); }
  }

  // confirmar / reverter a situacao (recebido, pago): sempre com confirmacao em modal
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.btn-confirmar');
    if (!b) { return; }
    e.preventDefault();
    var reverter = b.dataset.efetivado === '1';
    var msg = reverter ? 'Reverter a confirmação de ' + b.dataset.desc + '? O lançamento volta a ficar pendente e o saldo da conta é recalculado.'
      : 'Confirmar o ' + b.dataset.verbo + ' de ' + b.dataset.desc + '? O saldo da conta será atualizado.';
    var enviar = function () {
      var f = document.getElementById('form-alternar');
      f.elements.id.value = b.dataset.id;
      f.submit();
    };
    if (window.ERP && ERP.confirm) { ERP.confirm(msg, enviar, reverter ? 'Reverter confirmação' : 'Confirmar', { perigo: false, btnConfirmar: reverter ? 'Reverter' : 'Confirmar' }); } else if (window.confirm(msg)) { enviar(); }
  });

  // confirmacao em botoes de submit
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirmar-click]');
    if (!b || b.dataset.confirmado === '1') { return; }
    e.preventDefault();
    var f = b.form;
    var enviar = function () {
      b.dataset.confirmado = '1';
      if (f.requestSubmit) { f.requestSubmit(b); } else { b.click(); }
    };
    if (window.ERP && ERP.confirm) { ERP.confirm(b.getAttribute('data-confirmar-click'), enviar, 'Confirmar ação'); } else if (window.confirm(b.getAttribute('data-confirmar-click'))) { enviar(); }
  });

  // acoes em lote
  var chks = Array.prototype.slice.call(document.querySelectorAll('.chk-lote'));
  var barra = document.getElementById('barra-lote');
  function atualizarLote() {
    var n = chks.filter(function (c) { return c.checked; }).length;
    if (barra) {
      barra.classList.toggle('hidden', n === 0);
      document.getElementById('lote-n').textContent = n + ' selecionado(s)';
    }
    var todos = document.getElementById('lote-todos');
    if (todos) { todos.checked = n > 0 && n === chks.length; }
  }
  chks.forEach(function (c) { c.addEventListener('change', atualizarLote); });
  var todos = document.getElementById('lote-todos');
  if (todos) { todos.addEventListener('change', function () { var v = this.checked; chks.forEach(function (c) { c.checked = v; }); atualizarLote(); }); }
  var aplicar = document.getElementById('lote-aplicar-valor');
  if (aplicar) {
    aplicar.addEventListener('click', function () {
      var sel = document.getElementById('lote-valor');
      var v = sel.value;
      if (!v) { window.alert('Escolha uma categoria ou uma conta.'); return; }
      var f = document.getElementById('form-lote');
      var partes = v.split(':');
      sel.removeAttribute('name');
      [['op', partes[0] === 'cat' ? 'categoria' : 'conta'], ['valor', partes[1]]].forEach(function (p) {
        var h = document.createElement('input'); h.type = 'hidden'; h.name = p[0]; h.value = p[1]; f.appendChild(h);
      });
      f.submit();
    });
  }

  // agrupar lancamentos do cartao: o servidor monta as linhas, entao grava um cookie e recarrega
  var agr = document.getElementById('viz-agrupar');
  if (agr) {
    agr.addEventListener('change', function () {
      document.cookie = 'finAgruparCartao=' + (agr.checked ? '1' : '0') + '; path=/; max-age=31536000; SameSite=Lax';
      window.location.reload();
    });
  }

  // opcoes de visualizacao (guardadas no navegador)
  var tabela = document.getElementById('t-lanc');
  var KEY = 'finLancViz';
  var estado = { cat: true, conta: true, tags: true, saldo: true };
  try { estado = Object.assign(estado, JSON.parse(localStorage.getItem(KEY) || '{}')); } catch (err) {}
  function aplicarViz() {
    if (tabela) {
      Object.keys(estado).forEach(function (k) { tabela.classList.toggle('hide-' + k, !estado[k]); });
    }
    document.querySelectorAll('[data-viz]').forEach(function (c) { c.checked = !!estado[c.dataset.viz]; });
  }
  document.querySelectorAll('[data-viz]').forEach(function (c) {
    c.addEventListener('change', function () {
      estado[c.dataset.viz] = c.checked;
      try { localStorage.setItem(KEY, JSON.stringify(estado)); } catch (err) {}
      aplicarViz();
    });
  });
  aplicarViz();
})();
