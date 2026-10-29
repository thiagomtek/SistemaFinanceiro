<?php
declare(strict_types=1);

/* LayoutService: cabecalho/rodape comuns (mesmo visual do Sistema ERP). Usado por header.php e footer.php. */

function pagina_inicio(string $titulo, string $icone, string $ativo, string $subtitulo = '', string $cor = 'success'): void
{
    $menu = [
        'VISÃO GERAL' => [
            ['index.php', 'fa-dashboard', '#38bdf8', 'Dashboard'],
            ['projecao.php', 'fa-line-chart', '#22c55e', 'Projeção'],
        ],
        'MOVIMENTAÇÕES' => [
            ['lancamentos.php', 'fa-exchange', '#34d399', 'Lançamentos'],
        ],
        'CADASTROS' => [
            ['contas.php', 'fa-university', '#60a5fa', 'Contas'],
            ['cartoes.php', 'fa-credit-card', '#f472b6', 'Cartões e faturas'],
            ['categorias.php', 'fa-tags', '#a78bfa', 'Categorias'],
            ['investimentos.php', 'fa-bar-chart', '#fb923c', 'Investimentos'],
        ],
    ];
    $menu['ADMINISTRAÇÃO'] = [['usuarios.php', 'fa-users', '#c084fc', 'Usuários e permissões']];
    // so mostra as telas que o usuario pode abrir
    foreach ($menu as $secao => $itens) {
        $menu[$secao] = array_values(array_filter($itens, function ($i) {
            $mod = PAGINA_MODULO[$i[0]] ?? null;
            return $mod === null || permissao_pode($mod, 'ver');
        }));
        if (!$menu[$secao]) {
            unset($menu[$secao]);
        }
    }
    $GLOBALS['FLASH_PAGINA'] = flash();
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
<title><?= e($titulo) ?> · Sistema Financeiro</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin_layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/font-awesome.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/financeiro.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/erp_design_system.css')) ?>">
<script>try{if(localStorage.getItem('finSidebarCollapsed')==='1'&&innerWidth>768)document.documentElement.classList.add('sidebar-collapsed-initial')}catch(e){}</script>
</head>
<?php $modPag = PAGINA_MODULO[basename($_SERVER['SCRIPT_NAME'] ?? '')] ?? null; $somenteLeitura = $modPag !== null && permissao_nivel($modPag) === 'ver'; ?>
<body class="hold-transition sidebar-mini layout-fixed<?= $somenteLeitura ? ' modo-leitura' : '' ?>">
<div class="wrapper">
  <nav class="main-header">
    <ul class="navbar-nav">
      <li><button class="sidebar-toggle-btn" id="btn-toggle-sidebar" type="button" aria-label="Alternar menu lateral"><i class="fa fa-bars fa-lg"></i></button></li>
      <li><a href="index.php" class="nav-link"><i class="fa fa-dashboard dashboard-menu-icon"></i> <span>Dashboard</span></a></li>
    </ul>
    <ul class="navbar-nav">
      <li style="position:relative;">
        <a href="#" class="nav-link" id="btn-notif-sino" aria-label="Notificações" title="Notificações"><i class="fa fa-bell fa-lg"></i><span class="notif-badge hidden" id="notif-badge">0</span></a>
        <div class="dropdown-menu notif-menu" id="notif-menu">
          <div class="notif-topo"><strong>Notificações</strong><a href="#" id="notif-ler-todas">Marcar todas como lidas</a></div>
          <div id="notif-lista"><div class="notif-vazio">Carregando…</div></div>
        </div>
      </li>
      <li style="position:relative;">
        <a href="#" class="nav-link" id="btn-user-dropdown"><i class="fa fa-user-circle fa-lg"></i> <span><?= e($_SESSION['usuario_nome'] ?? '') ?></span></a>
        <div class="dropdown-menu" id="user-dropdown-menu">
          <div class="user-header"><i class="fa fa-user-circle fa-3x mb-2"></i><div style="font-weight:700;font-size:16px;"><?= e($_SESSION['usuario_nome'] ?? '') ?></div></div>
          <div class="user-footer" style="display:flex;justify-content:space-between;gap:8px;">
            <a href="perfil.php" class="btn btn-sm btn-primary"><i class="fa fa-user"></i> Meu perfil</a>
            <a href="logout.php" class="btn btn-sm btn-danger"><i class="fa fa-power-off"></i> Sair</a>
          </div>
        </div>
      </li>
    </ul>
  </nav>

  <aside class="main-sidebar">
    <a href="index.php" class="brand-link"><i class="fa fa-line-chart text-warning" style="margin-right:8px;"></i><span>Sistema Financeiro</span></a>
    <div class="sidebar">
      <nav>
        <ul class="nav-sidebar">
          <?php foreach ($menu as $secao => $itens): ?>
            <li class="nav-header"><?= e($secao) ?></li>
            <?php foreach ($itens as [$href, $ic, $cor_ic, $nome]): ?>
              <li class="nav-item">
                <a href="<?= e($href) ?>" class="nav-link <?= $ativo === $href ? 'active' : '' ?>">
                  <i class="nav-icon fa <?= e($ic) ?>" style="color:<?= e($cor_ic) ?>;"></i><span><?= e($nome) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>
      </nav>
    </div>
  </aside>

  <div class="content-wrapper">
    <div class="content-header">
      <div>
        <h1><i class="fa <?= e($icone) ?> text-<?= e($cor) ?>" style="margin-right:8px;"></i> <?= e($titulo) ?></h1>
        <?php if ($subtitulo): ?><p class="text-muted mb-0" style="font-size:13px;margin-top:2px"><?= e($subtitulo) ?></p><?php endif; ?>
      </div>
      <div>
        <ul class="breadcrumb">
          <li class="breadcrumb-item"><a href="index.php">Início</a></li>
          <li class="breadcrumb-item active"><?= e($titulo) ?></li>
        </ul>
      </div>
    </div>
    <section class="content">
      <?php if ($somenteLeitura): ?><div class="alert alert-info" style="margin:0 0 12px"><i class="fa fa-eye"></i> Você tem acesso somente para visualizar esta tela.</div><?php endif; ?>
      <div class="container-fluid">
<?php
}

function pagina_fim(array $scripts = []): void
{
    $flash = $GLOBALS['FLASH_PAGINA'] ?? null;
    ?>
      </div>
    </section>
  </div>
  <footer class="main-footer"><small>Sistema Financeiro v<?= e(SISTEMA_VERSAO) ?> (build <?= e(SISTEMA_BUILD) ?>)</small></footer>
</div>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= e(asset_url('assets/js/jquery.min.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/bootstrap.min.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/erp_dialogs.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
<?php if ($flash): ?>
<script>ERP.toast.show(<?= json_encode($flash['msg'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(['success' => 'success', 'danger' => 'error', 'warning' => 'warning', 'info' => 'info'][$flash['tipo']] ?? 'info') ?>);</script>
<?php endif; ?>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset_url($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
