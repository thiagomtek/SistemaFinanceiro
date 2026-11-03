<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';
limite_exigir_api('projecao', 30, 60); // calculo pesado: limite por usuario (RN-52)

$meses = (int) ($_GET['meses'] ?? 12);
$media = ($_GET['media'] ?? '1') === '1';
$aporte = max(0.0, min(1e9, parse_valor($_GET['aporte'] ?? 0)));

$cdi = isset($_GET['cdi']) && $_GET['cdi'] !== '' ? max(0.0, min(100.0, parse_valor($_GET['cdi'], false))) : null;

$pct = max(0.0, min(1000.0, parse_valor($_GET['pct'] ?? 100, false)));

json_out(projetar($meses, $media, $aporte, $cdi, $pct));
