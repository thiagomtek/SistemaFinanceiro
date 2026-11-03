<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';
limite_exigir_api('inv_painel', 60, 60); // calculo pesado: limite por usuario (RN-52)

// KPIs e series de um investimento para o modal da tela Investimentos (somente leitura, escopado pelo perfil).
try {
    json_out(investimento_painel((int) ($_GET['id'] ?? 0)));
} catch (InvalidArgumentException $e) {
    json_out(['erro' => $e->getMessage()], 422);
}
