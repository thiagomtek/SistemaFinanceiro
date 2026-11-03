<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';

// Tudo da tela "Cenario e risco" numa resposta: indicadores publicos, leitura do dia, risco consolidado do perfil, inflacao
// percebida, historico de risco e fechamentos mensais.
$risco = risco_consolidar();
json_out([
    'indicadores' => inflacao_resumo(),
    'dia' => cenario_dia(),
    'risco' => $risco,
    'percebida' => inflacao_percebida(),
    'historico' => risco_historico(12),
    'mensais' => cenario_mensais(6),
    'rotulos' => [1 => risco_nivel_rotulo(1), 2 => risco_nivel_rotulo(2), 3 => risco_nivel_rotulo(3), 4 => risco_nivel_rotulo(4), 5 => risco_nivel_rotulo(5)],
]);
