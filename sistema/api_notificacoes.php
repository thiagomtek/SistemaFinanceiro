<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';

// Sino de notificacoes: GET = lista (e sincroniza os avisos do dia, no maximo 1x por hora por sessao); POST = marcar como lida.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    notificacoes_marcar_lida((int) ($_POST['id'] ?? 0));
    json_out(['ok' => true, 'nao_lidas' => notificacoes_nao_lidas()]);
}
if (empty($_SESSION['notif_sync']) || $_SESSION['notif_sync'] < time() - 3600 || isset($_GET['forcar'])) {
    notificacoes_sincronizar();
    $_SESSION['notif_sync'] = time();
}
json_out(['nao_lidas' => notificacoes_nao_lidas(), 'itens' => notificacoes_listar(15)]);
