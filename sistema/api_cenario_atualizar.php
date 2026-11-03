<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';
admin_sistema_exigir(true); // gasta rede e cota de IA do servidor: exclusivo do administrador do sistema (RN-52)

// "Atualizar agora" (so o administrador do sistema): roda a mesma rotina diaria (indicadores, leitura do dia, fechamento mensal, foto do risco). No maximo 1x a cada 30 minutos.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['erro' => 'Use POST.'], 405);
}
csrf_exigir();
$ult = (int) (parametro_get('cenario_manual_em') ?? 0);
if (time() - $ult < 1800) {
    json_out(['erro' => 'A atualização foi feita há menos de 30 minutos. Aguarde um pouco.'], 429);
}
parametro_set('cenario_manual_em', (string) time());
@set_time_limit(180);
json_out(cenario_atualizar_tudo(true) + ['ok' => true]);
