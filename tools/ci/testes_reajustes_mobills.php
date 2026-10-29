<?php
declare(strict_types=1);

/**
 * Testes dos reajustes (saldo/fatura), status de fatura, saldo previsto e da carga da planilha do Mobills,
 * usando como casos de teste os valores conferidos nos prints (ver REQUISITOS_E_REGRAS_DE_NEGOCIO.md).
 * Uso: php tools/ci/testes_reajustes_mobills.php [planilha.xlsx]
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');

require __DIR__ . '/../../sistema/bootstrap.php';
require __DIR__ . '/sqlite_schema.php';
db()->exec(schema_sqlite());

$falhas = 0;
function ok(bool $cond, string $msg): void
{
    global $falhas;
    echo ($cond ? '  [ok] ' : '  [FALHA] ') . $msg . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function igual($a, $b, string $msg): void
{
    ok(abs((float) $a - (float) $b) < 0.005, $msg . " (esperado $b, obtido $a)");
}
function lanca(callable $f, string $msg): void
{
    try {
        $f();
        ok(false, $msg);
    } catch (InvalidArgumentException $e) {
        ok(true, $msg);
    }
}

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();

// ------------------------------------------------------------------ reajuste de saldo
echo "== reajuste de saldo (RN-04/05)\n";
$conta = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 100]);
$r = reajustar_saldo($conta, '1.000,00', 'transacao', '', '2026-10-26');
igual($r['diferenca'], 900, 'diferença calculada');
igual(saldos_por_conta()[$conta], 1000, 'saldo atual igual ao informado');
$aj = q_one("SELECT * FROM lancamentos WHERE ajuste = 'saldo'");
ok($aj && $aj['tipo'] === 'receita' && $aj['efetivado'] == 1 && $aj['descricao'] === 'Reajuste*', 'cria receita efetivada "Reajuste*"');
ok((int) $aj['categoria_id'] === categoria_sistema_id('Reajuste', 'receita'), 'usa a categoria de sistema Reajuste');
reajustar_saldo($conta, '800', 'transacao');
$aj2 = q_one("SELECT * FROM lancamentos WHERE ajuste = 'saldo' ORDER BY id DESC");
ok($aj2['tipo'] === 'despesa' && abs($aj2['valor'] - 200) < 0.005, 'saldo menor cria despesa de ajuste');
reajustar_saldo($conta, '1.500,50', 'saldo_inicial');
igual(saldos_por_conta()[$conta], 1500.50, 'modo saldo inicial ajusta o saldo atual');
igual(q_val('SELECT saldo_inicial FROM contas WHERE id = ?', [$conta]), 800.50, 'altera o saldo inicial');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE ajuste = 'saldo'"), 2, 'modo saldo inicial não cria transação');
lanca(fn() => reajustar_saldo($conta, '1500,50'), 'mesmo saldo é recusado');

// ------------------------------------------------------------------ status e fatura manual
echo "== status da fatura e fatura manual\n";
$amazon = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'AMAZON', $conta, 6000, 15, 28]);
$cAmazon = q_one('SELECT * FROM cartoes WHERE id = ?', [$amazon]);
ok(fatura_fechamento($cAmazon, '2026-10') === '2026-10-15', 'fechamento da fatura de outubro (fecha 15, vence 28)');
ok(fatura_status($cAmazon, '2026-10', false, '2026-10-26') === 'fechada', 'AMAZON em 26/10: fatura fechada (print)');
ok(fatura_status($cAmazon, '2026-10', false, '2026-10-10') === 'aberta', 'antes do fechamento: aberta');
ok(fatura_status($cAmazon, '2026-10', false, '2026-10-29') === 'vencida', 'depois do vencimento: vencida');
ok(fatura_status($cAmazon, '2026-10', true, '2026-10-29') === 'paga', 'paga');
$nubankCard = ['dia_fechamento' => 28, 'dia_vencimento' => 4];
ok(fatura_fechamento($nubankCard, '2026-10') === '2026-09-28', 'vencimento menor que fechamento: fecha no mês anterior');
ok(fatura_mes_da_compra($nubankCard, '2026-10-26') === '2026-11', 'compra em 26/10 no Nubank vai para a fatura de 4 de nov (print)');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra manual', 'valor' => '100', 'data' => '2026-10-10', 'cartao_id' => $amazon, 'fatura_mes' => '2027-01']);
ok(q_val("SELECT fatura_mes FROM lancamentos WHERE descricao = 'Compra manual'") === '2027-01', 'fatura escolhida manualmente vale sobre o fechamento');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Parcelada manual', 'valor' => '300', 'data' => '2026-10-10', 'cartao_id' => $amazon, 'repeticao' => 'parcelado', 'qtd' => 3, 'fatura_mes' => '2026-12']);
$fm = array_column(q_all("SELECT fatura_mes FROM lancamentos WHERE descricao LIKE 'Parcelada manual%' ORDER BY data"), 'fatura_mes');
ok($fm === ['2026-12', '2027-01', '2027-02'], 'parcelas seguem a partir da fatura escolhida');
q('DELETE FROM lancamentos WHERE cartao_id = ?', [$amazon]);

// ------------------------------------------------------------------ ajustar fatura
echo "== ajustar fatura (RN-11/12)\n";
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra A', 'valor' => '300', 'data' => '2026-10-05', 'cartao_id' => $amazon]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra B', 'valor' => '100', 'data' => '2026-10-06', 'cartao_id' => $amazon]);
igual(fatura_total($amazon, '2026-10'), 400, 'fatura de outubro soma as compras');
$r = ajustar_fatura($amazon, '2026-10', '600,00');
igual($r['ajuste'], 200, 'reajuste de 200');
igual(fatura_total($amazon, '2026-10'), 600, 'fatura passa a valer o informado');
$it = q_one("SELECT * FROM lancamentos WHERE ajuste = 'fatura'");
ok($it['descricao'] === 'Reajuste de fatura' && $it['data'] === '2026-10-28' && (int) $it['categoria_id'] === categoria_sistema_id('Reajuste de fatura', 'despesa'), 'item na data de vencimento e categoria de sistema');
ajustar_fatura($amazon, '2026-10', '450');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE ajuste = 'fatura'"), 1, 'novo ajuste atualiza o mesmo item (não duplica)');
igual(fatura_total($amazon, '2026-10'), 450, 'total após novo ajuste');
$r = ajustar_fatura($amazon, '2026-10', '350');
ok($r['negativo'] === true, 'valor menor que os itens gera ajuste negativo com aviso');
igual(fatura_total($amazon, '2026-10'), 350, 'total com ajuste negativo');
ajustar_fatura($amazon, '2026-10', '400');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE ajuste = 'fatura'"), 0, 'valor igual à soma dos itens remove o reajuste');
lanca(fn() => ajustar_fatura($amazon, '2026-10', '-5'), 'valor negativo é recusado');
q('DELETE FROM lancamentos WHERE cartao_id = ?', [$amazon]);
ajustar_fatura($amazon, '2026-10', '128,58');
igual(fatura_total($amazon, '2026-10'), 128.58, 'fatura só com reajuste (como no print da AMAZON)');
pagar_fatura($amazon, '2026-10', $conta, '2026-10-28');
lanca(fn() => ajustar_fatura($amazon, '2026-10', '10'), 'fatura paga não pode ser ajustada');

// ------------------------------------------------------------------ carga da planilha do Mobills
echo "== planilha do Mobills (prints)\n";
$arq = $argv[1] ?? 'C:/Users/thiago/Desktop/RELATORIO_TRANSACOES_47bb7992-f61e-478b-8a22-db3050e67e53.xlsx';
if (!is_file($arq)) {
    echo "  (planilha não encontrada, etapa ignorada: $arq)\n";
} else {
    $pid = criar_perfil($uid, 'Mobills', 'PF', null);
    $_SESSION['perfil_id'] = $pid;
    $c = [];
    foreach (['BV', 'Itaú', 'Nubank', 'PicPay'] as $n) {
        $c[$n] = q_insert('INSERT INTO contas (perfil_id, nome, tipo) VALUES (?,?,?)', [$pid, $n, 'corrente']);
    }
    $cartoes = [['Nubank', 29900, 28, 4, 'Nubank'], ['Black Personalite', 38000, 18, 25, 'Itaú'], ['LUIZA', 4301, 18, 25, 'Itaú'], ['AMAZON', 6000, 15, 28, 'Itaú']];
    foreach ($cartoes as [$n, $lim, $fe, $ve, $cp]) {
        q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [$pid, $n, $c[$cp], $lim, $fe, $ve]);
    }
    $ler = importar_ler_arquivo($arq, basename($arq));
    $an = importar_analisar($ler['linhas']);
    $mapF = [];
    foreach ($an['faturas'] as $k => $f) {
        $mapF[$k] = $f['sugestao'];
    }
    $nome = fn(string $id) => q_val('SELECT nome FROM cartoes WHERE id = ?', [$id]);
    $sug = [];
    foreach ($an['faturas'] as $f) {
        $sug[$f['conta'] . ' dia ' . $f['dia'] . ' ' . $f['tipo']] = $nome($f['sugestao']);
    }
    echo '  sugestões: ' . json_encode($sug, JSON_UNESCAPED_UNICODE) . "\n";
    ok(count($an['faturas']) === 4, 'quatro grupos de fatura detectados (Nubank, Black, LUIZA, AMAZON)');
    $mapC = [];
    foreach ($an['contas'] as $k => $x) {
        $mapC[$k] = $x['sugestao'] ?: 'novo';
    }
    $mapK = [];
    foreach ($an['categorias'] as $t => $l) {
        foreach ($l as $k => $x) {
            $mapK["$t|$k"] = $x['sugestao'] ?: 'novo';
        }
    }
    $st = importar_aplicar($ler['linhas'], $mapC, $mapK, 'teste', null, false, $mapF);
    echo '  resultado: ' . json_encode($st) . "\n";
    igual($st['importados'], 129, 'as 129 linhas importadas');
    ok($st['faturas'] > 20, 'itens de fatura reconhecidos: ' . $st['faturas'] . ', reajustes de saldo: ' . $st['reajustes']);

    $porNome = [];
    foreach (cartoes_do_perfil() as $cc) {
        $porNome[$cc['nome']] = $cc;
    }
    igual(limite_utilizado($porNome['Nubank']), 7652.09, 'limite utilizado Nubank = print');
    igual(limite_utilizado($porNome['Black Personalite']), 33172.51, 'limite utilizado Black Personalite = print');
    igual(limite_utilizado($porNome['LUIZA']), 369.90, 'limite utilizado LUIZA = print');
    igual(limite_utilizado($porNome['AMAZON']), 880.71, 'limite utilizado AMAZON = print');
    $cf = fatura_corrente($porNome['Nubank']);
    ok($cf && $cf['mes'] === '2026-10' && $cf['vencimento'] === '2026-10-04', 'fatura corrente do Nubank vence em 04/10/2026');
    ok($cf && fatura_status($porNome['Nubank'], $cf['mes'], false, '2026-10-26') === 'vencida', 'Nubank: fatura vencida (print)');
    igual(total_faturas_do_mes('2026-10'), 10165.27, 'dashboard "Cartão de crédito" de outubro = print');
    $rm = resumo_mes('2026-10');
    igual($rm['rec'], 15746.13, 'receitas de outubro = print');
    igual($rm['desp'], 13740.19, 'despesas de outubro = print');

    // saldos iniciais que fazem o saldo atual bater com os prints (RN-23)
    foreach (['BV' => 63817.22, 'Itaú' => 42.52, 'Nubank' => 20345.12, 'PicPay' => 9284.56] as $n => $alvo) {
        reajustar_saldo($c[$n], (string) $alvo, 'saldo_inicial');
    }
    igual(saldo_total_contas(), 93489.42, 'saldo atual total = print');
    $prev = saldos_previstos_por_conta('2026-10-31');
    igual($prev[$c['BV']], 63817.22, 'saldo previsto BV = print');
    igual($prev[$c['PicPay']], 9284.56, 'saldo previsto PicPay = print');
    igual($prev[$c['Itaú']], -8806.89, 'saldo previsto Itaú = print');
    igual($prev[$c['Nubank']], 15496.54, 'saldo previsto Nubank pela regra RN-02 (print mostra 14.634,79, diferença de 861,75 em aberto)');
}

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
