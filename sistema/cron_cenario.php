<?php
// Rotina diaria do cenario economico e do risco (agendada no host: docker exec fin_app php /var/www/html/sistema/cron_cenario.php).
// Atualiza indicadores oficiais (BCB, IBGE, Focus), le as manchetes do dia com a IA, fecha o mes anterior quando preciso e grava a
// foto de risco de cada perfil. Uso: php cron_cenario.php [--forcar]  (--forcar refaz a leitura do dia mesmo que ela ja exista)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acesso permitido somente via CLI.\n");
}
require_once __DIR__ . '/bootstrap.php';

try {
    $r = cenario_atualizar_tudo(in_array('--forcar', $argv ?? [], true));
    echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
