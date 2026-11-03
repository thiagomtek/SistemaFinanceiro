<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';

$meses = (int) ($_GET['meses'] ?? 12);
$media = ($_GET['media'] ?? '1') === '1';
$aporte = max(0.0, min(1e9, parse_valor($_GET['aporte'] ?? 0)));
$cdi = isset($_GET['cdi']) && $_GET['cdi'] !== '' ? max(0.0, min(100.0, parse_valor($_GET['cdi'], false))) : null;
$pct = max(0.0, min(1000.0, parse_valor($_GET['pct'] ?? 100, false)));

json_out(risco_selic_resumo($meses, $media, $aporte, $cdi, $pct));
