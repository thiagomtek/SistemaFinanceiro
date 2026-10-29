<?php
require_once __DIR__ . '/auth_check.php';

// Download de anexo (comprovante) do perfil ativo. Arquivos ficam fora do DocumentRoot.
$a = anexo_dados((int) ($_GET['id'] ?? 0));
$arq = $a ? anexos_dir() . '/' . $a['arquivo'] : '';
if (!$a || !is_file($arq)) {
    http_response_code(404);
    exit('Anexo não encontrado.');
}
$inline = in_array($a['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . filesize($arq));
header('X-Content-Type-Options: nosniff');
if ($a['mime'] !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($a['nome']) . '"');
readfile($arq);
