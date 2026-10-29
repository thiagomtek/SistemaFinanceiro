<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';

// Previa de resgate pelo valor LIQUIDO na conta (vazio = total): bruto, IR, IOF (editaveis) e liquido.
try {
    $valor = isset($_GET['valor']) && $_GET['valor'] !== '' ? $_GET['valor'] : null;
    json_out(rf_previa_resgate_liquido((int) ($_GET['inv'] ?? 0), $valor, (string) ($_GET['data'] ?? date('Y-m-d')), $_GET['ir'] ?? null, $_GET['iof'] ?? null));
} catch (InvalidArgumentException $e) {
    json_out(['erro' => $e->getMessage()], 422);
}
