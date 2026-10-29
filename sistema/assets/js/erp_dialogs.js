/**
 * ERP DIALOGS & MODAL ENGINE
 * Motor Canônico de Diálogos Globais do ERP (Alert, Confirm, Prompt, Success, Error)
 * 
 * Fornece API unificada para substituir modais fragmentados:
 * - ERP.alert(mensagem, tipo, titulo)
 * - ERP.confirm(mensagem, onConfirm, titulo, opcoes)
 * - ERP.modal({ id, title, size, body, footer, onOpen, onClose })
 */

window.ERP = window.ERP || {};

(function() {
  function esc(str) {
    return String(str || '').replace(/[&<>"']/g, function(m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
  }

  /**
   * Abre um modal dinâmico adaptativo
   * @param {Object} opts { id, title, icon, size ('sm'|'md'|'lg'|'xl'|'auto'), body, footer, onOpen, onClose }
   */
  ERP.modal = function(opts) {
    opts = opts || {};
    var id = opts.id || 'erpModalDinamico';
    var sizeClass = 'modal-dialog-' + (opts.size || 'md');
    var existing = document.getElementById(id);
    if (existing) existing.remove();

    var iconHtml = opts.icon ? '<i class="fa ' + esc(opts.icon) + ' mr-2" style="color:#38bdf8;"></i>' : '';
    var titleHtml = opts.title ? (iconHtml + esc(opts.title)) : '';

    // z-index acima de qualquer modal customizado do sistema (ex.: .pdv-modal do PDV, z-index 2600)
    // - o motor de dialogos e a camada mais alta por definicao (alerta/confirmacao respondem a
    // algo que aconteceu DENTRO de outro modal), entao precisa sempre renderizar por cima dele.
    // Sem isso, um ERP.alert aberto enquanto um .pdv-modal estiver na tela fica completamente
    // invisivel atras dele (mesmo tendo sido criado com sucesso e "aberto").
    var modalHtml = '<div class="modal fade erp-modal" id="' + esc(id) + '" style="z-index:2700;" tabindex="-1" role="dialog" aria-hidden="true">' +
      '<div class="modal-dialog ' + sizeClass + '" role="document">' +
        '<div class="modal-content">' +
          '<div class="modal-header">' +
            '<h4 class="modal-title">' + titleHtml + '</h4>' +
            '<button type="button" class="close" data-dismiss="modal" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>' +
          '</div>' +
          '<div class="modal-body">' + (opts.body || '') + '</div>' +
          (opts.footer ? ('<div class="modal-footer">' + opts.footer + '</div>') : '') +
        '</div>' +
      '</div>' +
    '</div>';

    var div = document.createElement('div');
    div.innerHTML = modalHtml;
    var modalEl = div.firstElementChild;
    document.body.appendChild(modalEl);

    if (window.jQuery && window.jQuery.fn.modal) {
      window.jQuery(modalEl).modal('show');
      window.jQuery(modalEl).on('hidden.bs.modal', function() {
        if (typeof opts.onClose === 'function') opts.onClose();
        modalEl.remove();
      });
      if (typeof opts.onOpen === 'function') opts.onOpen(modalEl);
    }

    return modalEl;
  };

  /**
   * Diálogo Unificado de Alerta
   */
  ERP.alert = function(msg, tipo, titulo) {
    tipo = tipo || 'info';
    var iconMap = {
      success: 'fa-check-circle text-success',
      error: 'fa-times-circle text-danger',
      warning: 'fa-exclamation-triangle text-warning',
      info: 'fa-info-circle text-info'
    };
    var titleMap = {
      success: 'Sucesso',
      error: 'Atenção / Erro',
      warning: 'Aviso',
      info: 'Informação'
    };

    var icon = iconMap[tipo] || iconMap.info;
    var defaultTitle = titleMap[tipo] || 'Notificação';

    return new Promise(function(resolve) {
      ERP.modal({
        id: 'erpAlertModal',
        size: 'sm',
        title: titulo || defaultTitle,
        body: '<div class="d-flex align-items-center gap-3 py-2" style="display:flex;gap:14px;align-items:center;">' +
                '<i class="fa ' + icon + ' fa-2x"></i>' +
                '<div style="font-size:13.5px;color:#1e293b;line-height:1.4;">' + esc(msg) + '</div>' +
              '</div>',
        footer: '<button type="button" class="btn btn-primary font-weight-bold" data-dismiss="modal" style="padding:6px 16px;">OK</button>',
        onClose: function() { resolve(true); }
      });
    });
  };

  /**
   * Diálogo Unificado de Confirmação
   */
  ERP.confirm = function(msg, onConfirm, titulo, opts) {
    opts = opts || {};
    var btnConfirmar = opts.btnConfirmar || 'Confirmar';
    var btnCancelar = opts.btnCancelar || 'Cancelar';
    var perigo = opts.perigo !== false;

    return new Promise(function(resolve) {
      var btnClasse = perigo ? 'btn-danger' : 'btn-primary';
      var footerHtml = '<button type="button" class="btn btn-secondary" data-dismiss="modal">' + esc(btnCancelar) + '</button>' +
                       '<button type="button" class="btn ' + btnClasse + ' font-weight-bold" id="erpConfirmBtnAction">' + esc(btnConfirmar) + '</button>';

      ERP.modal({
        id: 'erpConfirmModal',
        size: 'sm',
        title: titulo || 'Confirmar Ação',
        icon: 'fa-question-circle',
        body: '<div style="font-size:13.5px;color:#1e293b;line-height:1.4;padding:8px 0;">' + esc(msg) + '</div>',
        footer: footerHtml,
        onOpen: function(el) {
          var btn = el.querySelector('#erpConfirmBtnAction');
          if (btn) {
            btn.onclick = function() {
              if (window.jQuery) window.jQuery(el).modal('hide');
              if (typeof onConfirm === 'function') onConfirm();
              resolve(true);
            };
          }
        },
        onClose: function() {
          resolve(false);
        }
      });
    });
  };


  /**
   * SISTEMA DE TOASTS FLUTUANTES (Notificações Não Bloqueantes)
   */
  ERP.toast = {
    _getContainer: function() {
      var c = document.getElementById('erpToastContainer');
      if (!c) {
        c = document.createElement('div');
        c.id = 'erpToastContainer';
        c.className = 'erp-toast-container';
        document.body.appendChild(c);
      }
      return c;
    },
    show: function(msg, tipo, titulo, duracaoMs) {
      if (typeof titulo === 'number') {
        duracaoMs = titulo;
        titulo = null;
      }
      tipo = tipo || 'info';
      duracaoMs = 0; // Fica visível permanentemente até o usuário clicar
      var c = this._getContainer();

      var iconMap = {
        success: 'fa-check-circle',
        error: 'fa-times-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
      };
      var titleMap = {
        success: 'Sucesso',
        error: 'Erro',
        warning: 'Atenção',
        info: 'Aviso'
      };

      var icon = iconMap[tipo] || iconMap.info;
      var title = titulo || titleMap[tipo] || 'Notificação';

      var toast = document.createElement('div');
      toast.className = 'erp-toast erp-toast-' + (tipo === 'error' ? 'danger' : tipo);
      toast.setAttribute('title', 'Clique para fechar');
      toast.innerHTML = 
        '<i class="fa ' + icon + ' erp-toast-icon"></i>' +
        '<div class="erp-toast-body">' +
          '<div class="erp-toast-title">' + esc(title) + '</div>' +
          '<div class="erp-toast-message">' + esc(msg) + '</div>' +
        '</div>' +
        '<button type="button" class="erp-toast-close" aria-label="Fechar" title="Fechar">&times;</button>';

      var closeBtn = toast.querySelector('.erp-toast-close');
      var dismissed = false;

      function dismiss() {
        if (dismissed) return;
        dismissed = true;
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(function() { toast.remove(); }, 200);
      }

      if (closeBtn) {
        closeBtn.onclick = function(e) {
          if (e && e.stopPropagation) e.stopPropagation();
          dismiss();
        };
      }

      toast.onclick = function() {
        dismiss();
      };

      c.appendChild(toast);
      return toast;
    },
    success: function(msg, titulo, duracao) { return this.show(msg, 'success', titulo, duracao); },
    error: function(msg, titulo, duracao) { return this.show(msg, 'error', titulo, duracao); },
    warning: function(msg, titulo, duracao) { return this.show(msg, 'warning', titulo, duracao); },
    info: function(msg, titulo, duracao) { return this.show(msg, 'info', titulo, duracao); }
  };

  /**
   * CONTROLE DE LOADING EM BOTÕES (Anti-Duplo Clique)
   * @param {HTMLElement|string} btn Seletor ou elemento do botão
   * @param {boolean} isLoading true para carregar, false para restaurar
   * @param {string} loadingText Texto temporário durante loading
   */
  ERP.btnLoading = function(btn, isLoading, loadingText) {
    if (typeof btn === 'string') btn = document.querySelector(btn);
    if (!btn) return;

    if (isLoading) {
      if (btn.classList.contains('erp-btn-loading')) return;
      btn.dataset.erpOriginalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.classList.add('erp-btn-loading');
      var txt = loadingText || 'Aguarde...';
      btn.innerHTML = '<span class="erp-btn-spinner"></span> ' + esc(txt);
    } else {
      btn.disabled = false;
      btn.classList.remove('erp-btn-loading');
      if (btn.dataset.erpOriginalHtml) {
        btn.innerHTML = btn.dataset.erpOriginalHtml;
        delete btn.dataset.erpOriginalHtml;
      }
    }
  };

})();

