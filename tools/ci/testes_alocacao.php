<?php
declare(strict_types=1);

/**
 * Testes das sugestoes por classe de ativo (RN-49): taxas do Tesouro (IPCA+ e prefixado), classificacao dos investimentos, reserva
 * de emergencia, perfil e faixas editaveis (com validacao), sinais por classe, travas pela reserva e retorno minimo do aluguel.
 * Nao usa internet nem a IA de verdade. Uso: php tools/ci/testes_alocacao.php
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');

require __DIR__ . '/../../sistema/bootstrap.php';
require __DIR__ . '/sqlite_schema.php';
require __DIR__ . '/mocks_cenario.php';
db()->exec(schema_sqlite());
cenario_instalar_mocks();

$falhas = 0;
function ok(bool $cond, string $msg): void
{
    global $falhas;
    echo ($cond ? '  [ok] ' : '  [FALHA] ') . $msg . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function igual($a, $b, string $msg, float $tol = 0.01): void
{
    ok(abs((float) $a - (float) $b) <= $tol, $msg . " (esperado $b, obtido $a)");
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
$conta = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 3000]);
$novo = function (string $nome, string $tipo, string $idx, string $moeda = 'BRL', string $liq = 'diaria') {
    return q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, indexador, percentual_cdi, taxa_anual, tributacao, liquidez, moeda) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [perfil_id(), $nome, $tipo, 'Banco', $idx, 100, $idx === 'pre' ? 14 : 0, 'nenhuma', $liq, $moeda]);
};
$antigo = date('Y-m-d', strtotime('-60 day'));

echo "== taxas do Tesouro (CSV aberto)\n";
$r = inflacao_atualizar(true);
ok(count($r['erros']) === 0, 'todas as fontes atualizam, inclusive o Tesouro');
$t = tesouro_taxas();
igual($t['ipca_3']['taxa'], 7.80, 'IPCA+ curto: o vencimento mais próximo de 3 anos (taxa de compra, vírgula decimal)');
igual($t['ipca_6']['taxa'], 7.89, 'IPCA+ longo: o vencimento mais próximo de 6 anos');
igual($t['pre_3']['taxa'], 14.89, 'prefixado curto');
igual($t['pre_6']['taxa'], 15.38, 'prefixado longo');
ok($t['data_base'] === date('Y-m-d', strtotime('-1 day')), 'a data base é a mais recente do arquivo (a linha mais antiga com taxa 9,99 e o título vencido foram ignorados)');
ok($t['ipca_6']['vencimento'] === date('Y-m-d', strtotime(date('Y-m-d', strtotime('-1 day')) . ' +5 year +9 month')), 'o vencimento escolhido é informado (' . $t['ipca_6']['vencimento'] . ')');
igual($t['breakeven_6'], ((1.1538 / 1.0789) - 1) * 100, 'inflação implícita = (1 + prefixado) ÷ (1 + IPCA+) − 1', 0.02);

$raiz = __DIR__ . '/../../sistema/certs/globalsign_root_r46.pem';
ok(is_file($raiz) && strtoupper((string) openssl_x509_fingerprint((string) file_get_contents($raiz), 'sha256')) === '4FA3126D8D3A11D1C4855A4F807CBAD6CF919D3A5A88B03BEA2C6372D93C40C9', 'a raiz de confiança do Tesouro é a GlobalSign Root R46 (impressão digital SHA-256 conferida com a loja do sistema e o site da GlobalSign)');

echo "== classificacao dos investimentos\n";
$ids = [
    'pos' => $novo('CDB DI', 'renda_fixa', 'cdi'), 'prazo' => $novo('CDB pré', 'renda_fixa', 'pre', 'BRL', 'vencimento'), 'acoes' => $novo('Ações BR', 'acoes', 'manual'),
    'imobiliario' => $novo('FII', 'fii', 'manual'), 'exterior' => $novo('ETF exterior', 'fundos', 'manual', 'USD'), 'outros' => $novo('Cripto', 'cripto', 'manual'),
];
foreach ($ids as $k => $id) {
    $inv = q_one('SELECT * FROM investimentos WHERE id = ?', [$id]);
    ok(alocacao_classe($inv) === $k, 'classe de "' . $inv['nome'] . '" = ' . $k);
}
ok(alocacao_classe(['tipo' => 'acoes', 'indexador' => 'manual', 'moeda' => 'USD']) === 'exterior', 'exposição ao dólar manda sobre o tipo (ações em dólar = exterior)');
ok(alocacao_classe(['tipo' => 'renda_fixa', 'indexador' => 'poupanca']) === 'pos' && alocacao_classe(['tipo' => 'previdencia', 'indexador' => 'manual']) === 'outros', 'poupança é pós-fixado; previdência é outros');

echo "== perfil e faixas editaveis\n";
$p = alocacao_perfil();
ok($p['tolerancia'] === 'moderado' && $p['reserva_meses'] === 6 && !$p['personalizado'] && $p['faixas']['pos'] === [25, 50], 'sem configuração: moderado, reserva de 6 meses e faixas padrão');
lanca(function () { alocacao_perfil_salvar('ousado', 6, []); }, 'tolerância inválida é recusada');
lanca(function () { alocacao_perfil_salvar('moderado', 0, []); }, 'reserva de 0 meses é recusada');
lanca(function () { alocacao_perfil_salvar('moderado', 6, ['acoes' => [30, 10]]); }, 'mínimo maior que o máximo é recusado');
lanca(function () { alocacao_perfil_salvar('moderado', 6, ['acoes' => [0, 120]]); }, 'máximo acima de 100% é recusado');
lanca(function () { alocacao_perfil_salvar('moderado', 6, ['pos' => [60, 80], 'prazo' => [30, 40], 'acoes' => [20, 30]]); }, 'soma dos mínimos acima de 100% é recusada');
$p = alocacao_perfil_salvar('arrojado', 12, ['acoes' => [30, 50]]);
ok($p['tolerancia'] === 'arrojado' && $p['reserva_meses'] === 12 && $p['faixas']['acoes'] === [30.0, 50.0] && $p['faixas']['pos'] === [10, 30] && $p['faixas_editadas'] === ['acoes'], 'salva tolerância, reserva e só a faixa editada; as demais seguem o padrão do perfil');
$p = alocacao_perfil_salvar('moderado', 6, []);
ok(!$p['faixas_editadas'] && $p['faixas']['acoes'] === [10, 25], 'enviar sem faixas volta ao padrão da tolerância');

echo "== reserva de emergencia\n";
for ($m = 1; $m <= 6; $m++) {
    q_insert('INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id) VALUES (?,?,?,?,?,?,?)',
        [perfil_id(), 'despesa', 'Gasto do mês', 1000, date('Y-m-15', strtotime("first day of -$m month")), 1, $conta]);
}
rf_aplicar(0, $ids['pos'], 12000, $antigo, true);
rf_aplicar(0, $ids['prazo'], 20000, $antigo, true);
rf_aplicar(0, $ids['acoes'], 4000, $antigo, true);
rf_aplicar(0, $ids['imobiliario'], 3000, $antigo, true);
rf_aplicar(0, $ids['exterior'], 6000, $antigo, true);
rf_aplicar(0, $ids['outros'], 1000, $antigo, true);
$rv = alocacao_reserva();
igual($rv['media_despesas'], 1000, 'média mensal de despesas dos 6 meses completos');
$posSaldo = (float) array_values(array_filter(investimentos_com_saldo(), function ($i) use ($ids) { return (int) $i['id'] === $ids['pos']; }))[0]['saldo'];
igual($rv['liquido'], max(0.0, saldo_total_contas()) + $posSaldo, 'dinheiro de liquidez diária = contas (nunca negativas) + renda fixa pós-fixada de resgate diário; o pré com liquidez no vencimento e as ações ficam de fora');
igual($rv['meses'], $rv['liquido'] / 1000, 'meses de reserva = liquidez ÷ média mensal de despesas (cerca de 12)', 0.06);
ok($rv['meses'] > 11 && $rv['meses'] < 14, 'reserva em torno de 12 meses de despesas (obtido ' . $rv['meses'] . ')');
cenario_gerar_dia(); // leitura do dia (mock): fiscal 4/5, bolsa 3/5

echo "== sugestoes por classe\n";
$a = alocacao_sugestoes();
ok(count($a['classes']) === 6 && !$a['reserva']['baixa'], 'seis classes; reserva de 12 meses acima do alvo de 6');
igual(array_sum(array_column($a['classes'], 'valor')), $a['total_investido'], 'a soma das classes fecha com o total investido', 0.02);
igual(array_sum(array_column($a['classes'], 'pct')), 100, 'os percentuais somam 100%', 0.3);
$c = [];
foreach ($a['classes'] as $x) {
    $c[$x['chave']] = $x;
}
ok($c['prazo']['pct'] > 40 && $c['prazo']['sinal'] === 'reduzir', 'renda fixa de prazo com mais de 40% (faixa 15 a 35%): "acima da faixa, avaliar reduzir"');
ok($c['pos']['pct'] > 25 && $c['pos']['pct'] <= 50 && $c['pos']['sinal'] === 'manter', 'pós-fixado dentro da faixa 25 a 50%, sem fator forte: manter');
$textos = implode(' | ', array_column($c['prazo']['fatores'], 'texto'));
ok(strpos($textos, 'Selic esperada em queda') !== false && strpos($textos, 'Tesouro IPCA+') !== false && strpos($textos, 'risco fiscal alto') !== false, 'fatores da renda fixa de prazo: Selic em queda, IPCA+ pagando juro real e risco fiscal alto nas manchetes');
igual($c['prazo']['pontos'], 1 + 1 - 1, 'pontos de prazo = +1 (queda da Selic) +1 (IPCA+) −1 (fiscal)');
ok(strpos(implode(' ', array_column($c['acoes']['fatores'], 'texto')), 'juro real líquido alto') !== false, 'ações: o juro real alto da renda fixa pesa contra');
$ext = $c['exterior'];
ok(strpos(implode(' ', array_column($ext['fatores'], 'texto')), 'Focus projeta o dólar') !== false && $ext['pontos'] === array_sum(array_column($ext['fatores'], 'pontos')), 'exterior usa os fatores da dolarização (Focus do câmbio acima do dólar de hoje) e os pontos são a soma deles');
ok(isset($a['padroes']['moderado']) && isset($a['tesouro']['ipca_6']), 'a resposta traz os padrões das faixas e as taxas do Tesouro');

echo "== a reserva abaixo do alvo trava sugestoes de risco\n";
alocacao_perfil_salvar('moderado', 36, []); // 36 meses: a reserva de 12 meses fica abaixo do alvo
$b = alocacao_sugestoes();
$cb = [];
foreach ($b['classes'] as $x) {
    $cb[$x['chave']] = $x;
}
ok($b['reserva']['baixa'], 'reserva de 12 meses fica abaixo do alvo de 36');
ok($cb['acoes']['sinal'] === 'priorizar_reserva' && $cb['imobiliario']['sinal'] === 'priorizar_reserva' && $cb['exterior']['sinal'] === 'priorizar_reserva', 'ações, imobiliário e exterior: "antes, reforçar a reserva"');
ok($cb['pos']['sinal'] !== 'priorizar_reserva' && $cb['pos']['pontos'] >= $c['pos']['pontos'] + 2, 'o pós-fixado ganha 2 pontos pela reserva baixa e não é travado');
ok(strpos(implode(' ', array_column($cb['acoes']['fatores'], 'texto')), 'reserva abaixo do alvo') !== false, 'o motivo da trava aparece nos fatores');
alocacao_perfil_salvar('moderado', 6, []);

echo "== sinal: faixas, momento e posicao\n";
$f = [10, 30];
ok(alocacao_sinal(40, $f, 0, false, 'acoes')['sinal'] === 'reduzir', 'acima da faixa: reduzir');
ok(alocacao_sinal(5, $f, 1, false, 'acoes')['sinal'] === 'aumentar', 'abaixo da faixa com momento neutro ou favorável: aumentar');
ok(alocacao_sinal(5, $f, -1, false, 'acoes')['sinal'] === 'aguardar', 'abaixo da faixa com momento desfavorável: aguardar');
ok(alocacao_sinal(20, $f, 2, false, 'acoes')['sinal'] === 'aumentar' && alocacao_sinal(20, $f, -2, false, 'acoes')['sinal'] === 'aguardar' && alocacao_sinal(20, $f, 1, false, 'acoes')['sinal'] === 'manter', 'dentro da faixa: 2 pontos ou mais aumentar, −2 ou menos aguardar, senão manter');
ok(alocacao_sinal(5, $f, 3, true, 'acoes')['sinal'] === 'priorizar_reserva' && alocacao_sinal(5, $f, 3, true, 'pos')['sinal'] === 'aumentar', 'reserva baixa trava as classes de risco, mas não o pós-fixado');
ok(alocacao_sinal(40, $f, 3, true, 'acoes')['sinal'] === 'reduzir', 'com reserva baixa e classe acima da faixa, continua valendo reduzir');

echo "== imovel para aluguel\n";
$im = alocacao_imovel_minimo(inflacao_resumo());
igual($im['cdi_liquido'], 13.65 * 0.85, 'CDI líquido de IR de 15%');
igual($im['aluguel_minimo_ano'], $im['cdi_liquido'] - $im['valorizacao'], 'aluguel mínimo = CDI líquido − valorização esperada (a inflação)');
igual($im['aluguel_minimo_mes'], $im['aluguel_minimo_ano'] / 12, 'e o equivalente por mês', 0.01);
ok(alocacao_imovel_minimo(['cdi' => 13.65, 'ipca_esperado_12m' => null]) === null, 'sem IPCA esperado não calcula');

echo "== foto de risco guarda os sinais\n";
cenario_gerar_dia();
risco_snapshot_salvar();
$exp = json_decode((string) q_val('SELECT exposicao_json FROM risco_snapshots WHERE perfil_id = ?', [perfil_id()]), true);
ok(isset($exp['sugestoes']) && count($exp['sugestoes']) === 6 && $exp['sugestoes']['prazo'] === 'reduzir', 'a foto diária guarda o sinal de cada classe para comparar mês a mês');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
