<?php
declare(strict_types=1);

/**
 * Carga do HISTORICO da planilha RELATORIO_TRANSACOES (Mobills): tudo antes de uma data de corte, somente para as contas e
 * os cartoes JA cadastrados. Nao cria contas nem cartoes (linhas de contas sem cadastro, como BV e PicPay, sao ignoradas).
 *
 *  - As movimentacoes das contas entram pelo ImportacaoService (mesmas regras da tela de importacao).
 *  - As linhas "Reajuste de fatura" listadas em FATURAS_HISTORICO viram uma fatura PAGA do cartao: um item unico de reajuste
 *    (ajuste = fatura) + o registro em faturas + o pagamento na conta pagadora, como pagar_fatura() faz.
 *    As demais linhas "Reajuste de fatura" (cartao nao habilitado) entram so como pagamento na conta.
 *  - O efeito do que foi importado nos saldos das contas e compensado em contas.saldo_inicial, para o saldo atual e o previsto
 *    ficarem exatamente como antes. Investimentos, cartoes e lancamentos a partir da data de corte nao sao tocados.
 *    Se alguma conferencia falhar, desfaz o que entrou.
 *
 * Uso:  php carga_historico.php <planilha.xlsx> <email-do-usuario> [--ate AAAA-MM-DD] [--aplicar]
 *  - sem --aplicar: mostra so o plano e nao grava nada
 *  - --ate: importa apenas lancamentos ANTERIORES a esta data (padrao 2026-10-01)
 * Variavel CARGA_BOOTSTRAP aponta para sistema/bootstrap.php (padrao: caminho do conteiner).
 */

/** Faturas confirmadas pelo Thiago em 29/10/2026: [data na planilha, conta pagadora, valor, cartao, mes (vencimento) da fatura]. */
const FATURAS_HISTORICO = [
    ['2025-12-22', 'Itaú', 4127.06, 'Nubank', '2025-12'],
    ['2026-01-21', 'Nubank', 3702.28, 'Nubank', '2026-01'],
    ['2026-02-23', 'Itaú', 3980.09, 'Nubank', '2026-02'],
    ['2026-03-23', 'Itaú', 4040.56, 'Nubank', '2026-03'],
    ['2026-05-04', 'Itaú', 4472.83, 'Nubank', '2026-05'],
    ['2026-06-03', 'Itaú', 4253.95, 'Nubank', '2026-06'],
    ['2026-07-03', 'Itaú', 4474.55, 'Nubank', '2026-07'],
    ['2026-08-03', 'Nubank', 4566.57, 'Nubank', '2026-08'],
    ['2026-09-03', 'Nubank', 3498.57, 'Nubank', '2026-09'],
    ['2026-06-26', 'Itaú', 10018.27, 'Black Personalite', '2026-06'],
    ['2026-07-27', 'Itaú', 3163.37, 'Black Personalite', '2026-07'],
    ['2026-09-25', 'Itaú', 7425.35, 'Black Personalite', '2026-09'],
    ['2026-01-28', 'Itaú', 500.79, 'AMAZON', '2026-01'],
    ['2026-03-02', 'Itaú', 659.67, 'AMAZON', '2026-02'],
    ['2026-03-28', 'Itaú', 667.48, 'AMAZON', '2026-03'],
    ['2026-04-28', 'Itaú', 337.12, 'AMAZON', '2026-04'],
    ['2026-06-29', 'Itaú', 900.65, 'AMAZON', '2026-06'],
    ['2026-07-28', 'Itaú', 775.24, 'AMAZON', '2026-07'],
    ['2026-08-28', 'Itaú', 544.70, 'AMAZON', '2026-08'],
    ['2026-09-28', 'Itaú', 761.58, 'AMAZON', '2026-09'],
];
/** Categoria da planilha (tipo|nome normalizado) que equivale a uma ja cadastrada; evita duplicar "Outros" de receita. */
const CATEGORIAS_EQUIVALENTES = ['receita|outros' => 'Outras receitas'];
/** Linhas "Reajuste de fatura" antes do corte que nao pertencem a um cartao habilitado (319,70 x3 e 76,20 x2). */
const FATURAS_SEM_CARTAO = 5;

function falha(string $msg, int $codigo = 3): void
{
    fwrite(STDERR, $msg . "\n");
    exit($codigo);
}

$boot = getenv('CARGA_BOOTSTRAP') ?: '/var/www/html/sistema/bootstrap.php';
require $boot;

$args = array_slice($argv, 1);
$aplicar = in_array('--aplicar', $args, true);
$ate = '2026-10-01';
foreach ($args as $i => $a) {
    if ($a === '--ate' && isset($args[$i + 1])) {
        $ate = $args[$i + 1];
        unset($args[$i], $args[$i + 1]);
    }
}
$args = array_values(array_filter($args, function ($a) { return $a !== '--aplicar'; }));
[$arquivo, $email] = [$args[0] ?? '', $args[1] ?? ''];
if (!is_file($arquivo) || $email === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
    falha("Uso: php carga_historico.php <planilha.xlsx> <email> [--ate AAAA-MM-DD] [--aplicar]", 2);
}

$uid = q_val('SELECT id FROM usuarios WHERE email = ?', [mb_strtolower($email)]);
if (!$uid) {
    falha("Usuário não encontrado: $email", 2);
}
$_SESSION['usuario_id'] = (int) $uid;
garantir_perfil();
$pid = perfil_id();
echo 'Perfil: ' . perfil_atual()['nome'] . " (#$pid) · histórico anterior a " . data_br($ate) . ($aplicar ? " · APLICANDO\n" : " · só plano (nada será gravado)\n");

// cadastros existentes (nada e criado aqui)
$contas = [];
$nomeConta = [];
foreach (q_all('SELECT id, nome FROM contas WHERE perfil_id = ?', [$pid]) as $c) {
    $contas[norm_texto($c['nome'])] = (int) $c['id'];
    $nomeConta[(int) $c['id']] = $c['nome'];
}
$cartoes = [];
foreach (q_all('SELECT * FROM cartoes WHERE perfil_id = ? AND ativo = 1', [$pid]) as $c) {
    $cartoes[norm_texto($c['nome'])] = $c;
}
$mapaFaturas = [];
foreach (FATURAS_HISTORICO as [$data, $conta, $valor, $cartao, $ym]) {
    if (!isset($contas[norm_texto($conta)]) || !isset($cartoes[norm_texto($cartao)])) {
        falha("Conta ou cartão habilitado não encontrado para a fatura $cartao $ym ($conta).");
    }
    $mapaFaturas[$data . '|' . norm_texto($conta) . '|' . number_format($valor, 2, '.', '')] = [$cartoes[norm_texto($cartao)], $contas[norm_texto($conta)], $valor, $data, $ym];
}

// leitura e separacao das linhas
$ler = importar_ler_arquivo($arquivo, basename($arquivo));
$lidas = count($ler['linhas']);
$posteriores = 0;
$transfFora = 0;
$foraContas = [];
$achadas = [];
$semCartao = 0;
$pendentes = [];
$outras = [];
foreach ($ler['linhas'] as $l) {
    if ($l['data'] >= $ate) {
        $posteriores++;
        continue;
    }
    $nc = norm_texto($l['conta']);
    if (!isset($contas[$nc])) {
        $foraContas[$l['conta']] = ($foraContas[$l['conta']] ?? 0) + 1;
        continue;
    }
    if ($l['tipo'] === 'transferencia' && !isset($contas[norm_texto($l['destino'])])) {
        $transfFora++;
        continue;
    }
    if (!$l['efetivado']) {
        $pendentes[] = sprintf('%s · %s · %s · %s', $l['descricao'], $l['conta'], data_br($l['data']), brl($l['valor']));
        continue; // conta em aberto nao e historico: mexeria no saldo previsto
    }
    if ($l['tipo'] === 'despesa' && importar_eh_reajuste_fatura($l)) {
        $k =$l['data'] . '|' . $nc . '|' . number_format($l['valor'], 2, '.', '');
        if (isset($mapaFaturas[$k])) {
            if (isset($achadas[$k])) {
                falha("Fatura duplicada na planilha: $k");
            }
            $achadas[$k] = true;
            continue;
        }
        $semCartao++;
        $l['descricao'] = 'Pagamento de fatura (cartão não habilitado)';
        $l['categoria'] = '';
        $l['subcategoria'] = '';
    }
    $outras[] = $l;
}
$faltam = array_diff_key($mapaFaturas, $achadas);
if ($faltam) {
    falha('Faturas confirmadas que não estão na planilha: ' . implode(', ', array_keys($faltam)));
}
if ($semCartao !== FATURAS_SEM_CARTAO) {
    falha("Esperava " . FATURAS_SEM_CARTAO . " linhas de fatura sem cartão habilitado e encontrei $semCartao. Revise o mapeamento antes de seguir.");
}

$an = importar_analisar($outras);
$mapC = [];
foreach ($an['contas'] as $k => $c) {
    if ($c['sugestao'] === '' || strpos($c['sugestao'], 'c:') !== 0) {
        falha("Conta do arquivo sem cadastro de conta correspondente: {$c['nome']}");
    }
    $mapC[$k] = $c['sugestao'];
}
$mapK = [];
$novas = [];
foreach ($an['categorias'] as $tipo => $lista) {
    foreach ($lista as $k => $c) {
        $sug = $c['sugestao'];
        if ($sug === '' && isset(CATEGORIAS_EQUIVALENTES["$tipo|$k"])) {
            $sug = (string) q_val('SELECT id FROM categorias WHERE perfil_id = ? AND tipo = ? AND nome = ? AND pai_id IS NULL', [$pid, $tipo, CATEGORIAS_EQUIVALENTES["$tipo|$k"]]);
        }
        $mapK["$tipo|$k"] = $sug !== '' ? $sug : 'novo';
        if ($sug === '') {
            $novas[] = "$tipo: {$c['nome']}";
        }
    }
}

// efeito esperado nos saldos (so efetivados) para conferir depois
$esperado = [];
foreach ($outras as $l) {
    if (!$l['efetivado']) {
        continue;
    }
    $ef = lancamento_efeito_contas(['tipo' => $l['tipo'], 'conta_id' => $contas[norm_texto($l['conta'])],
        'conta_destino_id' => $l['tipo'] === 'transferencia' ? $contas[norm_texto($l['destino'])] : null, 'sentido_invest' => null, 'total' => $l['valor']]);
    foreach ($ef as $cid => $d) {
        $esperado[$cid] = ($esperado[$cid] ?? 0.0) + $d;
    }
}
foreach ($mapaFaturas as [$cartao, $contaId, $valor]) {
    $esperado[$contaId] = ($esperado[$contaId] ?? 0.0) - $valor;
}

echo "\nPlano\n";
echo "  linhas lidas: $lidas · a partir do corte (ficam de fora): $posteriores\n";
foreach ($foraContas as $n => $q) {
    echo "  conta sem cadastro, ignorada: $n ($q linhas)\n";
}
echo "  transferências com conta sem cadastro, ignoradas: $transfFora\n";
foreach ($pendentes as $p) {
    echo "  pendente (em aberto no Mobills), NÃO importada: $p\n";
}
echo '  faturas pagas de cartão habilitado: ' . count($mapaFaturas) . " · pagamentos de fatura de cartão não habilitado (só na conta): $semCartao\n";
echo '  demais movimentações de conta: ' . count($outras) . ' (já importadas antes: ' . $an['duplicados'] . ")\n";
echo '  categorias novas: ' . ($novas ? implode(', ', $novas) : 'nenhuma') . "\n";
foreach ($esperado as $cid => $d) {
    echo sprintf("  efeito no saldo de %-8s %s (será compensado no saldo inicial)\n", $nomeConta[$cid], brl($d));
}
if (!$aplicar) {
    echo "\nSomente plano. Rode com --aplicar para gravar.\n";
    exit(0);
}

// ---- aplicacao ----
$instantaneo = function () use ($pid, $ate): array {
    return [
        'contas' => q_all('SELECT id, nome, tipo, ativa FROM contas WHERE perfil_id = ? ORDER BY id', [$pid]),
        'cartoes' => q_all('SELECT * FROM cartoes WHERE perfil_id = ? ORDER BY id', [$pid]),
        'invest' => q_one('SELECT (SELECT COUNT(*) FROM investimentos WHERE perfil_id = ?) AS n,
            (SELECT COUNT(*) FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE i.perfil_id = ?) AS movs,
            (SELECT COALESCE(SUM(m.valor), 0) FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE i.perfil_id = ?) AS soma', [$pid, $pid, $pid]),
        'depois_corte' => q_one('SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS soma FROM lancamentos WHERE perfil_id = ? AND data >= ?', [$pid, $ate]),
    ];
};
$snapAntes = $instantaneo();
$saldoAntes = saldos_por_conta();
$previstoAntes = saldos_previstos_por_conta();
$inicialAntes = [];
foreach (q_all('SELECT id, saldo_inicial FROM contas WHERE perfil_id = ?', [$pid]) as $c) {
    $inicialAntes[(int) $c['id']] = (float) $c['saldo_inicial'];
}

$nomeImp = 'RELATORIO_TRANSACOES (histórico até ' . data_br(date('Y-m-d', strtotime($ate . ' -1 day'))) . ')';
$st = importar_aplicar($outras, $mapC, $mapK, $nomeImp, null, false, [], null);
$impId = (int) q_val('SELECT id FROM importacoes WHERE perfil_id = ? AND arquivo = ? ORDER BY id DESC LIMIT 1', [$pid, $nomeImp]);
echo "\n  importação de contas: " . json_encode($st, JSON_UNESCAPED_UNICODE) . "\n";

$pdo = db();
$pdo->beginTransaction();
try {
    $catReajFatura = categoria_sistema_id('Reajuste de fatura', 'despesa');
    $novasFaturas = 0;
    foreach ($mapaFaturas as [$cartao, $contaId, $valor, $dataPg, $ym]) {
        $valorTxt = number_format($valor, 2, '.', '');
        $hashItem = sha1(implode('|', ['hist-fatura', $pid, $cartao['id'], $ym, $valorTxt]));
        $hashPg = sha1(implode('|', ['hist-pgto', $pid, $cartao['id'], $ym, $valorTxt]));
        if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND hash_unico = ?', [$pid, $hashItem])) {
            continue; // rodada anterior
        }
        if (q_val('SELECT id FROM faturas WHERE cartao_id = ? AND mes = ?', [$cartao['id'], $ym])) {
            throw new RuntimeException("A fatura {$cartao['nome']} $ym já existe; nada foi gravado.");
        }
        $dataItem = min($dataPg, date('Y-m-t', strtotime($ym . '-01')));
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes, categoria_id, ajuste, importacao_id, hash_unico)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$pid, 'despesa', 'Fatura ' . $cartao['nome'] . ' ' . mes_label($ym) . ' (histórico)', $valor, $dataItem, 0, $cartao['id'], $ym, $catReajFatura, 'fatura', $impId, $hashItem]
        );
        $fid = q_insert('INSERT INTO faturas (cartao_id, mes, valor_pago, data_pagamento, conta_id) VALUES (?,?,?,?,?)', [$cartao['id'], $ym, $valor, $dataPg, $contaId]);
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, fatura_id, importacao_id, hash_unico) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$pid, 'despesa', 'Fatura ' . $cartao['nome'] . ' ' . mes_label($ym), $valor, $dataPg, 1, $contaId, $fid, $impId, $hashPg]
        );
        $novasFaturas++;
    }
    echo "  faturas pagas gravadas: $novasFaturas\n";
    // RN-32: a Projecao ignora lancamentos de importacoes marcadas como historico na media
    q('UPDATE importacoes SET tipo = ? WHERE id = ? AND perfil_id = ?', ['historico', $impId, $pid]);

    // compensa o efeito no saldo inicial (mesma ideia do reajuste de saldo no modo "saldo inicial")
    $depois = saldos_por_conta();
    foreach ($saldoAntes as $cid => $s) {
        $dif = round($s - ($depois[$cid] ?? 0.0), 2);
        if (abs($dif) > 0.004) {
            q('UPDATE contas SET saldo_inicial = saldo_inicial + ? WHERE id = ? AND perfil_id = ?', [$dif, $cid, $pid]);
        }
    }

    // conferencias: qualquer falha desfaz
    $saldoDepois = saldos_por_conta();
    $previstoDepois = saldos_previstos_por_conta();
    foreach ($saldoAntes as $cid => $s) {
        if (abs($s - ($saldoDepois[$cid] ?? 0.0)) > 0.005 || abs($previstoAntes[$cid] - ($previstoDepois[$cid] ?? 0.0)) > 0.005) {
            throw new RuntimeException("O saldo da conta {$nomeConta[$cid]} mudou (atual " . brl($s) . ' → ' . brl($saldoDepois[$cid] ?? 0.0)
                . ', previsto ' . brl($previstoAntes[$cid]) . ' → ' . brl($previstoDepois[$cid] ?? 0.0) . '); nada foi mantido.');
        }
    }
    $snapDepois = $instantaneo();
    foreach (['contas', 'cartoes', 'invest', 'depois_corte'] as $chave) {
        if ($snapDepois[$chave] != $snapAntes[$chave]) {
            throw new RuntimeException("Conferência '$chave' mudou; nada foi mantido.");
        }
    }
    $pdo->commit();
} catch (Throwable $t) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    q('DELETE FROM lancamentos WHERE perfil_id = ? AND importacao_id = ?', [$pid, $impId]);
    q('DELETE FROM importacoes WHERE id = ? AND perfil_id = ?', [$impId, $pid]);
    fwrite(STDERR, 'ERRO, importação desfeita: ' . $t->getMessage() . "\n");
    exit(4);
}

echo "\nConferência (saldo atual · previsto · saldo inicial):\n";
$ini = [];
foreach (q_all('SELECT id, saldo_inicial FROM contas WHERE perfil_id = ?', [$pid]) as $c) {
    $ini[(int) $c['id']] = (float) $c['saldo_inicial'];
}
foreach ($saldoAntes as $cid => $s) {
    echo sprintf("  %-8s atual %s → %s · previsto %s → %s · saldo inicial %s → %s\n", $nomeConta[$cid],
        brl($s), brl($saldoDepois[$cid]), brl($previstoAntes[$cid]), brl($previstoDepois[$cid]), brl($inicialAntes[$cid]), brl($ini[$cid]));
}
foreach ($cartoes as $c) {
    $pagas = (int) q_val('SELECT COUNT(*) FROM faturas WHERE cartao_id = ?', [$c['id']]);
    echo sprintf("  %-18s faturas pagas %d · limite usado %s\n", $c['nome'], $pagas, brl(limite_utilizado($c)));
}
echo "Concluído.\n";
