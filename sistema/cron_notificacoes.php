<?php
// Rotina diaria de avisos por push (agendada no host: docker exec fin_app php /var/www/html/sistema/cron_notificacoes.php).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acesso permitido somente via CLI.\n");
}
require_once __DIR__ . '/bootstrap.php';

try {
    if (!push_disponivel()) {
        echo '[' . date('Y-m-d H:i:s') . "] push desligado (sem chaves VAPID ou sem a biblioteca); nada a fazer.\n";
        exit(0);
    }
    echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode(push_notificar_pendentes(), JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
