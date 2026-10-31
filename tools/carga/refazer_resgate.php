<?php
declare(strict_types=1);

/**
 * Refaz um resgate de investimento que foi gravado SEM conta (e possivelmente em duplicidade), usando o motor de renda fixa:
 * apaga os movimentos de resgate informados (sem lancamento ligado) e grava UM resgate pelo valor LIQUIDO, na conta escolhida,
 * com IR e IOF calculados (lotes, prazo, IOF dos 30 primeiros dias). Os lancamentos de outros movimentos nao sao tocados.
 *
 * Uso:  php refazer_resgate.php <email> <investimento_id> <conta_id> <liquido> <AAAA-MM-DD> <mov_id[,mov_id...]> [--aplicar]
 *  - sem --aplicar: calcula e mostra, mas desfaz tudo (rollback)
 * Variavel CARGA_BOOTSTRAP aponta para sistema/bootstrap.php (padrao: caminho do conteiner).
 */

function falha(string $msg, int $codigo = 3): void
{
    fwrite(STDERR, $msg . "\n");
    exit($codigo);
}

$boot = getenv('CARGA_BOOTSTRAP') ?: '/var/www/html/sistema/bootstrap.php';
require $boot;

$aplicar = in_array('--aplicar', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return $a !== '--aplicar'; }));
if (count($args) < 6) {
    falha('Uso: php refazer_resgate.php <email> <investimento_id> <conta_id> <liquido> <data> <mov_ids> [--aplicar]', 2);
}
[$email, $invId, $contaId, $liquido, $data, $movIds] = [$args[0], (int) $args[1], (int) $args[2], (float) $args[3], $args[4], array_map('intval', explode(',', $args[5]))];
$uid = q_val('SELECT id FROM usuarios WHERE email = ?', [mb_strtolower($email)]);
if (!$uid) {
    falha("Usuário não encontrado: $email", 2);
}
$_SESSION['usuario_id'] = (int) $uid;
garantir_perfil();
$pid = perfil_id();

foreach ($movIds as $mid) {
    $m = q_one('SELECT m.* FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE m.id = ? AND i.perfil_id = ?', [$mid, $pid]);
    if (!$m || (int) $m['investimento_id'] !== $invId || $m['tipo'] !== 'resgate' || $m['lancamento_id']) {
        falha("Movimento $mid não é um resgate sem lançamento deste investimento; nada foi feito.");
    }
}

$pdo = db();
$salvos = [];
foreach ($movIds as $mid) {
    $salvos[] = q_one('SELECT * FROM investimento_movs WHERE id = ?', [$mid]);
}
function mostrar(array $r): void
{
    printf("Líquido na conta: %.2f | Bruto debitado: %.2f | IR: %.2f | IOF: %.2f | saldo bruto na data: %.2f
", $r['liquido'], $r['bruto'], $r['ir'], $r['iof'], $r['saldo_bruto']);
    foreach ($r['avisos'] as $a) {
        echo "Aviso: $a
";
    }
}

// calculo com os movimentos antigos fora, dentro de uma transacao que sempre e desfeita
$pdo->beginTransaction();
try {
    foreach ($movIds as $mid) {
        rf_excluir_mov($mid);
    }
    $p = rf_previa_resgate_liquido($invId, $liquido, $data);
    mostrar($p);
} catch (Throwable $t) {
    $pdo->rollBack();
    falha('Erro no cálculo (nada gravado): ' . $t->getMessage());
}
$pdo->rollBack();
if (!$aplicar) {
    echo "ENSAIO: nada foi gravado.
";
    exit(0);
}

// grava: apaga os antigos e refaz; se falhar, devolve os movimentos apagados
foreach ($movIds as $mid) {
    rf_excluir_mov($mid);
}
try {
    $r = rf_resgatar_liquido($invId, $contaId, $liquido, $data, $data <= date('Y-m-d'), 'Refeito: resgate gravado sem conta');
    echo "GRAVADO. Lançamento " . ($r['lancamento_id'] ?? '-') . "
";
} catch (Throwable $t) {
    foreach ($salvos as $m) {
        q_insert('INSERT INTO investimento_movs (id, investimento_id, data, tipo, valor, ir, iof, lancamento_id, obs) VALUES (?,?,?,?,?,?,?,?,?)',
            [$m['id'], $m['investimento_id'], $m['data'], $m['tipo'], $m['valor'], $m['ir'], $m['iof'], $m['lancamento_id'], $m['obs']]);
    }
    falha('Erro ao gravar; movimentos antigos restaurados: ' . $t->getMessage());
}
