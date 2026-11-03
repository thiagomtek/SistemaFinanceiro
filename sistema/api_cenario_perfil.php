<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';

// Salva o perfil de alocacao (tolerancia, reserva de emergencia em meses e faixas por classe). POST com CSRF; campo "dados" em JSON.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['erro' => 'Use POST.'], 405);
}
csrf_exigir();
limite_exigir_api('cenario_perfil', 20, 60);
$d = json_decode((string) ($_POST['dados'] ?? ''), true);
if (!is_array($d)) {
    json_out(['erro' => 'Dados inválidos.'], 422);
}
try {
    $faixas = [];
    foreach ((array) ($d['faixas'] ?? []) as $k => $f) {
        if (is_array($f) && count($f) === 2 && is_numeric($f[0]) && is_numeric($f[1])) {
            $faixas[(string) $k] = [(float) $f[0], (float) $f[1]];
        }
    }
    json_out(['ok' => true, 'perfil' => alocacao_perfil_salvar((string) ($d['tolerancia'] ?? ''), (int) ($d['reserva_meses'] ?? 0), $faixas)]);
} catch (InvalidArgumentException $e) {
    json_out(['erro' => $e->getMessage()], 422);
}
