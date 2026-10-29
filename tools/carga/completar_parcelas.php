<?php
declare(strict_types=1);

/**
 * Completa uma serie PARCELADA de conta (grupo_id) ate parcela_total, replicando a ultima parcela registrada:
 * mesma conta, categoria, valor e dia do mes, pendente, no mesmo grupo (editar/excluir "todas" alcanca a serie inteira).
 * So grava parcelas que faltam depois da ultima; nao altera nenhuma parcela existente nem saldos, investimentos ou cartoes.
 * Recusa series de cartao e series em que as parcelas registradas tenham valor, conta ou categoria diferentes.
 * As parcelas novas ficam ligadas a uma importacao (tipo "ajuste") para poderem ser desfeitas por ela.
 *
 * Uso:  php completar_parcelas.php <grupo_id> <email-do-usuario> [--aplicar]
 *  - sem --aplicar: mostra so o plano e nao grava nada
 * Variavel CARGA_BOOTSTRAP aponta para sistema/bootstrap.php (padrao: caminho do conteiner).
 */

function falha(string $msg, int $codigo = 3): void
{
    fwrite(STDERR, $msg . "\n");
    exit($codigo);
}

$boot = getenv('CARGA_BOOTSTRAP') ?: '/var/www/html/sistema/bootstrap.php';
require $boot;

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return $a !== '--aplicar'; }));
$aplicar = in_array('--aplicar', $argv, true);
[$grupo, $email] = [$args[0] ?? '', $args[1] ?? ''];
if ($grupo === '' || $email === '') {
    falha('Uso: php completar_parcelas.php <grupo_id> <email> [--aplicar]', 2);
}
$uid = q_val('SELECT id FROM usuarios WHERE email = ?', [mb_strtolower($email)]);
if (!$uid) {
    falha("Usuário não encontrado: $email", 2);
}
$_SESSION['usuario_id'] = (int) $uid;
garantir_perfil();
$pid = perfil_id();

$ult = q_one('SELECT * FROM lancamentos WHERE perfil_id = ? AND grupo_id = ? AND parcela_num IS NOT NULL ORDER BY parcela_num DESC, id DESC LIMIT 1', [$pid, $grupo]);
if (!$ult) {
    falha("Série não encontrada: $grupo");
}
if ($ult['cartao_id'] || $ult['tipo'] !== 'despesa' || !$ult['conta_id']) {
    falha('Só séries de despesa em conta (sem cartão) são suportadas.');
}
$ultimaNum = (int) $ult['parcela_num'];
$total = (int) $ult['parcela_total'];
if ($total <= $ultimaNum) {
    echo "A série já está completa ($ultimaNum/$total). Nada a fazer.\n";
    exit(0);
}
$cons = q_one('SELECT COUNT(DISTINCT valor) AS v, COUNT(DISTINCT conta_id) AS c, COUNT(DISTINCT COALESCE(categoria_id, 0)) AS k, COUNT(DISTINCT parcela_total) AS t
    FROM lancamentos WHERE perfil_id = ? AND grupo_id = ?', [$pid, $grupo]);
if ((int) $cons['v'] !== 1 || (int) $cons['c'] !== 1 || (int) $cons['k'] !== 1 || (int) $cons['t'] !== 1) {
    falha('As parcelas registradas não têm o mesmo valor, conta, categoria e total; não vou replicar.');
}

$dia = (int) date('j', strtotime($ult['data']));
$novas = [];
for ($n = $ultimaNum + 1; $n <= $total; $n++) {
    $ym = substr(add_meses(substr($ult['data'], 0, 7) . '-01', $n - $ultimaNum), 0, 7);
    $data = sprintf('%s-%02d', $ym, min($dia, (int) date('t', strtotime($ym . '-01'))));
    $desc = preg_replace('/\(\d+\s*\/\s*\d+\)/', sprintf('(%d/%d)', $n, $total), $ult['descricao'], 1, $subs);
    if ($subs !== 1) {
        falha('A descrição da última parcela não traz o padrão "(n/total)": ' . $ult['descricao']);
    }
    $novas[] = ['n' => $n, 'data' => $data, 'descricao' => $desc, 'hash' => sha1(implode('|', ['completar-parcelas', $pid, $grupo, $n]))];
}
$valor = (float) $ult['valor'];
echo 'Perfil: ' . perfil_atual()['nome'] . " (#$pid)\n";
echo "Série $grupo: {$ult['descricao']} · conta #{$ult['conta_id']} · categoria #{$ult['categoria_id']} · última registrada $ultimaNum/$total em " . data_br($ult['data']) . "\n";
echo '  a criar: ' . count($novas) . ' parcelas (' . $novas[0]['descricao'] . ' em ' . data_br($novas[0]['data']) . ' até ' . end($novas)['descricao'] . ' em ' . data_br(end($novas)['data'])
    . '), ' . brl($valor) . ' cada, total ' . brl($valor * count($novas)) . ", pendentes\n";
if (!$aplicar) {
    echo "\nSomente plano. Rode com --aplicar para gravar.\n";
    exit(0);
}

$instantaneo = function () use ($pid, $grupo, $ultimaNum): array {
    return [
        'lanc' => q_one('SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS soma FROM lancamentos WHERE perfil_id = ?', [$pid]),
        'serie_existente' => q_one('SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS soma, COALESCE(SUM(efetivado), 0) AS ef, MAX(data) AS ate FROM lancamentos WHERE perfil_id = ? AND grupo_id = ? AND parcela_num <= ?', [$pid, $grupo, $ultimaNum]),
        'invest' => q_one('SELECT (SELECT COUNT(*) FROM investimentos WHERE perfil_id = ?) AS n,
            (SELECT COUNT(*) FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE i.perfil_id = ?) AS movs,
            (SELECT COALESCE(SUM(m.valor), 0) FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE i.perfil_id = ?) AS soma', [$pid, $pid, $pid]),
        'faturas' => q_one('SELECT COUNT(*) AS n FROM faturas', []),
    ];
};
$antes = $instantaneo();
$saldoAntes = saldos_por_conta();
$previstoAntes = saldos_previstos_por_conta();

$pdo = db();
$pdo->beginTransaction();
try {
    $impId = q_insert('INSERT INTO importacoes (perfil_id, arquivo, tipo) VALUES (?,?,?)', [$pid, mb_substr('Complemento: parcelas ' . $novas[0]['n'] . ' a ' . $total . ' de ' . preg_replace('/\(\d+\s*\/\s*\d+\)/', '', $ult['descricao']), 0, 200), 'ajuste']);
    $criadas = 0;
    foreach ($novas as $p) {
        if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND hash_unico = ?', [$pid, $p['hash']])) {
            continue; // rodada anterior
        }
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, categoria_id, tags, observacao, grupo_id, parcela_num, parcela_total, importacao_id, hash_unico)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$pid, 'despesa', $p['descricao'], $ult['valor'], $p['data'], 0, $ult['conta_id'], $ult['categoria_id'], $ult['tags'], $ult['observacao'], $grupo, $p['n'], $total, $impId, $p['hash']]
        );
        $criadas++;
    }
    q('UPDATE importacoes SET qtd_importados = ? WHERE id = ?', [$criadas, $impId]);

    $depois = $instantaneo();
    if ($depois['lanc']['n'] - $antes['lanc']['n'] !== $criadas || abs($depois['lanc']['soma'] - $antes['lanc']['soma'] - $criadas * $valor) > 0.005) {
        throw new RuntimeException('A contagem ou a soma de lançamentos não fecha.');
    }
    foreach (['serie_existente', 'invest', 'faturas'] as $chave) {
        if ($depois[$chave] != $antes[$chave]) {
            throw new RuntimeException("Conferência '$chave' mudou.");
        }
    }
    $saldoDepois = saldos_por_conta();
    $previstoDepois = saldos_previstos_por_conta();
    foreach ($saldoAntes as $cid => $s) {
        if (abs($s - ($saldoDepois[$cid] ?? 0.0)) > 0.005 || abs($previstoAntes[$cid] - ($previstoDepois[$cid] ?? 0.0)) > 0.005) {
            throw new RuntimeException("O saldo da conta #$cid mudou.");
        }
    }
    $pdo->commit();
} catch (Throwable $t) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    falha('ERRO, nada foi gravado: ' . $t->getMessage(), 4);
}
echo "\nGravadas $criadas parcelas (importação #$impId). Lançamentos: {$antes['lanc']['n']} → {$depois['lanc']['n']}; saldos atual e previsto, investimentos, faturas e parcelas existentes sem alteração.\n";
$pend = q_one('SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS soma, MIN(data) AS de, MAX(data) AS ate FROM lancamentos WHERE perfil_id = ? AND grupo_id = ? AND efetivado = 0', [$pid, $grupo]);
echo "Pendentes da série: {$pend['n']} parcelas, " . brl((float) $pend['soma']) . ', ' . data_br($pend['de']) . ' a ' . data_br($pend['ate']) . "\n";
