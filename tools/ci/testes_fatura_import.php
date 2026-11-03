<?php
declare(strict_types=1);

/**
 * Testes da importacao de fatura (PDF normalizado, CSV), checagem de itens existentes e importacao aprovada item a item.
 * Nao chama a IA: usa respostas de exemplo no formato do esquema. Uso: php tools/ci/testes_fatura_import.php
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

function lanca_ex(callable $f, string $msg): void
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
$itau = q_insert('INSERT INTO contas (perfil_id, nome, tipo) VALUES (?,?,?)', [perfil_id(), 'Itaú', 'corrente']);
$cartao = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Nubank', $itau, 29900, 28, 4]);
$alimentacao = (int) q_val("SELECT id FROM categorias WHERE perfil_id = ? AND nome = 'Alimentação'", [perfil_id()]);

echo "== resposta da IA (PDF) normalizada\n";
$resposta = [
    'emissor' => 'Nubank', 'produto' => null, 'final_cartao' => '6363', 'vencimento' => '2026-10-05', 'fechamento' => '2026-09-26', 'total_fatura' => 189.30,
    'lancamentos' => [
        ['data' => '2026-09-13', 'descricao' => 'Cooperrita', 'valor' => 94.46, 'parcela' => null, 'tipo' => 'compra', 'categoria' => 'Alimentação'],
        ['data' => '2026-09-13', 'descricao' => 'Cooperrita', 'valor' => 94.46, 'parcela' => null, 'tipo' => 'compra', 'categoria' => 'Alimentação'],
        ['data' => '2026-09-08', 'descricao' => 'Openai *Chatgpt', 'valor' => 103.99, 'parcela' => null, 'tipo' => 'compra', 'categoria' => null],
        ['data' => '2026-09-08', 'descricao' => 'IOF de "Openai *Chatgpt"', 'valor' => 3.64, 'parcela' => null, 'tipo' => 'encargo', 'categoria' => null],
        ['data' => '2026-08-27', 'descricao' => 'Nike - Parcela 3/4', 'valor' => 103.61, 'parcela' => '3/4', 'tipo' => 'compra', 'categoria' => null],
        ['data' => '2026-09-25', 'descricao' => 'Ajuste a crédito', 'valor' => 9.78, 'parcela' => null, 'tipo' => 'credito', 'categoria' => null],
        ['data' => '2026-09-25', 'descricao' => 'Item sem valor', 'valor' => 0, 'parcela' => null, 'tipo' => 'compra', 'categoria' => null],
    ],
];
$r = fatura_pdf_normalizar($resposta);
ok(count($r['linhas']) === 6, 'item sem valor é descartado, os demais ficam (6)');
$cred = array_values(array_filter($r['linhas'], fn($l) => $l['tags'] === 'credito'))[0];
igual($cred['valor'], -9.78, 'crédito vira item negativo');
igual($r['fatura']['soma_itens'], 94.46 * 2 + 103.99 + 3.64 + 103.61 - 9.78, 'soma dos itens inclui encargos e créditos');
ok($r['fatura']['vencimento'] === '2026-10-05' && $r['linhas'][0]['fatura_mes'] === '2026-10', 'fatura pelo mês do vencimento do PDF');
ok(fatura_pdf_sugerir_cartao($r['fatura']) === $cartao, 'cartão sugerido pelo emissor');
try {
    fatura_pdf_normalizar(['emissor' => 'X', 'vencimento' => '', 'total_fatura' => 0, 'lancamentos' => []]);
    ok(false, 'resposta sem vencimento/total deve ser recusada');
} catch (RuntimeException $e) {
    ok(true, 'resposta sem vencimento/total é recusada');
}

echo "== CSV\n";
$csv = tempnam(sys_get_temp_dir(), 'fat') . '.csv';
file_put_contents($csv, "Data;Descrição;Valor\n10/09/2026;Padaria;32,50\n11/09/2026;Estorno loja;-80,00\n12/09/2026;Notebook (3/10);450,00\n");
$c = fatura_arquivo_ler($csv, 'fatura.csv');
unlink($csv);
ok(count($c['linhas']) === 3 && $c['fatura']['origem'] === 'planilha' && $c['fatura']['total'] === null, 'CSV lido: 3 itens, sem total');
igual($c['linhas'][1]['valor'], -80, 'sinal oposto vira crédito (negativo)');
try {
    fatura_arquivo_ler('x.xls', 'fatura.xls');
    ok(false, '.xls antigo deve ser recusado');
} catch (InvalidArgumentException $e) {
    ok(true, '.xls antigo é recusado com orientação');
}

echo "== itens existentes e aprovação\n";
// lancamento manual parecido (mesmo valor, 1 dia de diferença) em Nubank
q_insert("INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes) VALUES (?,?,?,?,?,?,?,?)", [perfil_id(), 'despesa', 'Chatgpt assinatura', 103.99, '2026-09-09', 0, $cartao, '2026-10']);
$marcadas = fatura_marcar_existentes($cartao, $r['linhas']);
ok($marcadas[2]['status'] === 'provavel' && strpos($marcadas[2]['parecido'], 'Chatgpt assinatura') !== false, 'valor igual em data próxima: possível duplicado');
ok($marcadas[0]['status'] === 'novo' && $marcadas[1]['status'] === 'novo', 'itens sem correspondência: novos');
ok($marcadas[0]['hash'] !== $marcadas[1]['hash'], 'dois itens idênticos no arquivo têm hashes diferentes');

$aprovados = [];
foreach ([1, 4, 5] as $i) { // aprova só alguns itens (o 2º Cooperrita, a parcela e o crédito)
    $marcadas[$i]['categoria_id'] = $i === 1 ? $alimentacao : null;
    $aprovados[] = $marcadas[$i];
}
$st = fatura_importar_itens($cartao, '2026-10', $aprovados, 'teste.pdf', null);
igual($st['importados'], 3, 'só os 3 itens aprovados foram gravados');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE importacao_id IS NOT NULL"), 3, 'nenhum item não aprovado foi gravado');
ok((int) q_val("SELECT categoria_id FROM lancamentos WHERE descricao = 'Cooperrita'") === $alimentacao, 'categoria escolhida na aprovação é gravada');
$p = q_one("SELECT parcela_num, parcela_total, fatura_mes FROM lancamentos WHERE descricao LIKE 'Nike%'");
ok((int) $p['parcela_num'] === 3 && (int) $p['parcela_total'] === 4 && $p['fatura_mes'] === '2026-10', 'parcela reconhecida e fatura definida');

$de_novo = fatura_marcar_existentes($cartao, $r['linhas']);
ok($de_novo[1]['status'] === 'existe' && $de_novo[4]['status'] === 'existe' && $de_novo[5]['status'] === 'existe', 'itens importados passam a "já existe"');
ok($de_novo[0]['status'] === 'novo', 'o outro Cooperrita idêntico continua novo (hash por ocorrência)');
$st2 = fatura_importar_itens($cartao, '2026-10', [$de_novo[1]], 'teste.pdf', null);
igual($st2['importados'], 0, 'reimportar item aprovado não duplica');
igual($st2['duplicados'], 1, 'contado como duplicado');

echo "== ajuste ao total do PDF\n";
$todos = array_values(array_filter($de_novo, fn($l) => $l['status'] === 'novo'));
$st3 = fatura_importar_itens($cartao, '2026-10', $todos, 'teste.pdf', 189.30);
igual(fatura_total($cartao, '2026-10'), 189.30, 'fatura fecha exatamente no total do PDF');
ok($st3['ajuste'] !== null, 'ajuste calculado: ' . json_encode($st3['ajuste']));
pagar_fatura($cartao, '2026-10', $itau, '2026-10-05');
try {
    fatura_importar_itens($cartao, '2026-10', $todos, 'teste.pdf', null);
    ok(false, 'fatura paga não aceita importação');
} catch (InvalidArgumentException $e) {
    ok(true, 'fatura paga não aceita importação');
}

echo "== parcelas restantes nas faturas seguintes\n";
ok(fatura_descricao_parcela('SAMSUNG NO ITA 09/21', 10, 21) === 'SAMSUNG NO ITA 10/21', 'descrição mantém o zero à esquerda da parcela');
ok(fatura_descricao_parcela('Nike - Parcela 3/4', 4, 4) === 'Nike - Parcela 4/4', 'troca só o número da parcela');
ok(fatura_descricao_parcela('Loja sem numero', 2, 5) === 'Loja sem numero (2/5)', 'sem parcela no texto, acrescenta (n/m)');
q('DELETE FROM lancamentos');
q('DELETE FROM faturas');
$linhasP = [
    ['data' => '2026-08-27', 'descricao' => 'Nike - Parcela 3/4', 'valor' => 103.61, 'parcela' => '3/4', 'tags' => '', 'categoria' => ''],
    ['data' => '2025-12-12', 'descricao' => 'SAMSUNG NO ITA 09/21', 'valor' => 208.20, 'parcela' => '09/21', 'tags' => '', 'categoria' => ''],
    ['data' => '2025-12-13', 'descricao' => 'SAMSUNG NO ITA 09/21', 'valor' => 208.20, 'parcela' => '09/21', 'tags' => '', 'categoria' => ''],
    ['data' => '2026-09-10', 'descricao' => 'Padaria', 'valor' => 10.00, 'parcela' => '', 'tags' => '', 'categoria' => ''],
];
$m = fatura_marcar_existentes($cartao, $linhasP);
ok($m[0]['restantes_qtd'] === 1 && $m[1]['restantes_qtd'] === 12 && $m[3]['restantes_qtd'] === 0, 'parcelas restantes calculadas (1, 12, 12, 0)');
ok($m[1]['grupo'] !== $m[2]['grupo'], 'compras gêmeas (mesma descrição e valor) formam séries diferentes');
foreach ($m as $i => $x) {
    $m[$i]['restantes'] = $i < 3; // aprova lançar as restantes dos 3 itens parcelados
}
$st = fatura_importar_itens($cartao, '2026-10', $m, 'parc.pdf', null);
igual($st['importados'], 4, 'os 4 itens da fatura atual foram gravados');
igual($st['projetadas'], 1 + 12 + 12, 'parcelas futuras lançadas: 1 + 12 + 12');
$nike4 = q_one("SELECT * FROM lancamentos WHERE descricao = 'Nike - Parcela 4/4'");
ok($nike4 && $nike4['fatura_mes'] === '2026-11' && $nike4['data'] === '2026-11-04' && (int) $nike4['parcela_num'] === 4, 'parcela 4/4 na fatura seguinte, na data de vencimento');
$sam = q_all("SELECT fatura_mes, parcela_num FROM lancamentos WHERE descricao LIKE 'SAMSUNG NO ITA %' AND valor = 208.20 ORDER BY fatura_mes, parcela_num, id");
igual(count($sam), 2 + 24, 'duas séries Samsung: 2 da fatura atual + 24 futuras');
igual(q_val("SELECT MAX(parcela_num) FROM lancamentos WHERE descricao LIKE 'SAMSUNG NO ITA %'"), 21, 'a série vai até a parcela 21');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao LIKE 'SAMSUNG NO ITA %' AND fatura_mes = '2026-11'"), 2, 'duas parcelas Samsung na fatura de novembro (uma por série)');
igual(fatura_total($cartao, '2026-11'), 103.61 + 2 * 208.20, 'fatura de novembro soma as parcelas projetadas');

// no mes seguinte o arquivo traz a parcela 10/21: ja esta lancada
$proximo = [
    ['data' => '2025-12-12', 'descricao' => 'SAMSUNG NO ITA 10/21', 'valor' => 208.20, 'parcela' => '10/21', 'tags' => '', 'categoria' => ''],
    ['data' => '2025-12-13', 'descricao' => 'SAMSUNG NO ITA 10/21', 'valor' => 208.20, 'parcela' => '10/21', 'tags' => '', 'categoria' => ''],
    ['data' => '2026-10-20', 'descricao' => 'Compra nova', 'valor' => 55.00, 'parcela' => '', 'tags' => '', 'categoria' => ''],
];
$mp = fatura_marcar_existentes($cartao, $proximo);
ok($mp[0]['status'] === 'existe' && $mp[1]['status'] === 'existe' && strpos($mp[0]['parecido'], 'já lançada') !== false, 'parcela já projetada aparece como "já existe" ao ler a fatura do mês seguinte');
ok($mp[2]['status'] === 'novo', 'compra nova continua nova');

// sem marcar "restantes" nada e projetado; fatura paga e pulada
q('DELETE FROM lancamentos');
$m2 = fatura_marcar_existentes($cartao, [['data' => '2026-08-27', 'descricao' => 'Loja 1/3', 'valor' => 30.00, 'parcela' => '1/3', 'tags' => '', 'categoria' => '']]);
$st2 = fatura_importar_itens($cartao, '2026-10', $m2, 'x.pdf', null);
igual($st2['projetadas'], 0, 'sem a opção de parcelas restantes nada é projetado');
q('DELETE FROM lancamentos');
q_insert("INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes) VALUES (?,?,?,?,?,?,?,?)", [perfil_id(), 'despesa', 'Base', 10, '2026-11-01', 0, $cartao, '2026-11']);
pagar_fatura($cartao, '2026-11', $itau, '2026-11-04');
$m3 = fatura_marcar_existentes($cartao, [['data' => '2026-08-27', 'descricao' => 'Loja 1/3', 'valor' => 30.00, 'parcela' => '1/3', 'tags' => '', 'categoria' => '']]);
$m3[0]['restantes'] = true;
$st3 = fatura_importar_itens($cartao, '2026-10', $m3, 'x.pdf', null);
igual($st3['projetadas'], 1, 'parcela 3/3 projetada em dezembro');
igual($st3['puladas'], 1, 'parcela que cairia em fatura já paga é pulada');


echo "== fatura real manda: reajustes futuros saem e as parcelas passam a constar (RN-39)\n";
q('DELETE FROM lancamentos');
q('DELETE FROM faturas');
// faturas futuras ja tinham um "Reajuste de fatura" (ex.: projecao do Mobills) que simulava o gasto
ajustar_fatura($cartao, '2026-11', '300');
ajustar_fatura($cartao, '2026-12', '1000');
$linha = function (string $desc, float $valor, string $parc): array {
    return ['data' => '2026-08-27', 'descricao' => $desc, 'valor' => $valor, 'parcela' => $parc, 'tags' => '', 'categoria' => ''];
};
$mq = fatura_marcar_existentes($cartao, [$linha('Loja A 1/3', 100.00, '1/3'), $linha('Loja B 1/3', 100.00, '1/3'), $linha('Loja C 1/2', 50.00, '1/2')]);
foreach ($mq as $i => $x) {
    $mq[$i]['restantes'] = true;
}
$st4 = fatura_importar_itens($cartao, '2026-10', $mq, 'x.pdf', null, $mq);
igual($st4['reajustes_removidos'], 2, 'os dois reajustes manuais das faturas seguintes foram removidos');
igual($st4['reajustes_removidos_total'], 1300, 'total dos reajustes removidos informado (300 + 1000)');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE ajuste = 'fatura'"), 0, 'nenhum reajuste sobrou');
igual(fatura_total($cartao, '2026-11'), 250, 'novembro passa a ser só as parcelas (100 + 100 + 50)');
igual(fatura_total($cartao, '2026-12'), 200, 'dezembro passa a ser só as parcelas 3/3 (100 + 100)');

// reajuste manual feito depois e a fatura de novembro chega do banco sem a compra C (estornada): C 2/2 sai, A e B ficam
ajustar_fatura($cartao, '2026-12', '900');
$nov = fatura_marcar_existentes($cartao, [$linha('Loja A 2/3', 100.00, '2/3'), $linha('Loja B 2/3', 100.00, '2/3')]);
ok($nov[0]['status'] === 'existe' && $nov[1]['status'] === 'existe', 'parcelas 2/3 de A e B já constam (projetadas)');
$st5 = fatura_importar_itens($cartao, '2026-11', [], 'nov.pdf', null, $nov);
igual($st5['parcelas_removidas'], 1, 'a parcela 2/2 da compra C (que o banco não traz mais) foi removida');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao LIKE 'Loja C%'"), 1, 'da compra C só resta a parcela 1/2 de outubro, que veio da fatura');
igual(fatura_total($cartao, '2026-11'), 200, 'novembro espelha a fatura do banco (A + B)');
igual($st5['reajustes_removidos'], 1, 'o reajuste de dezembro foi removido');
igual(fatura_total($cartao, '2026-12'), 200, 'dezembro volta a só as parcelas');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao LIKE 'Loja A%'"), 3, 'a série da compra A continua inteira');

// dezembro: o banco só traz a parcela 3/3 de A; a de B (cancelada) sai
$dez = fatura_marcar_existentes($cartao, [$linha('Loja A 3/3', 100.00, '3/3')]);
$st6 = fatura_importar_itens($cartao, '2026-12', [], 'dez.pdf', null, $dez);
igual($st6['parcelas_removidas'], 1, 'a parcela 3/3 da compra B saiu');
igual(fatura_total($cartao, '2026-12'), 100, 'dezembro só com a parcela de A');

echo "== espelho: o que nao veio da projecao, serie em duvida e fatura paga ficam\n";
q('DELETE FROM lancamentos');
q('DELETE FROM faturas');
$mp2 = fatura_marcar_existentes($cartao, [$linha('Loja X 1/4', 80.00, '1/4')]);
$mp2[0]['restantes'] = true;
fatura_importar_itens($cartao, '2026-10', $mp2, 'x.pdf', null, $mp2); // projeta 2/4 (nov), 3/4 (dez), 4/4 (jan)
$grupoX = q_val("SELECT grupo_id FROM lancamentos WHERE descricao LIKE 'Loja X%' LIMIT 1");
// parcela lancada a mao em outro grupo e item comum do cartao em novembro
q_insert("INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes, grupo_id, parcela_num, parcela_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
    [perfil_id(), 'despesa', 'Sofá 2/5', 400, '2026-11-04', 0, $cartao, '2026-11', 'manual-sofa', 2, 5]);
q_insert("INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes) VALUES (?,?,?,?,?,?,?,?)",
    [perfil_id(), 'despesa', 'Mercado', 60, '2026-11-10', 0, $cartao, '2026-11']);
// importar uma fatura ANTERIOR (setembro) nao pode apagar a projecao da compra X, que so comeca em outubro
$st7 = fatura_importar_itens($cartao, '2026-09', [], 'set.pdf', null, fatura_marcar_existentes($cartao, [$linha('Padaria', 10.00, '')]));
igual($st7['parcelas_removidas'], 0, 'fatura anterior à série não remove a projeção dela');
// serie em duvida (IA indisponivel): nao e dada como cancelada
$st8 = fatura_importar_itens($cartao, '2026-11', [], 'nov.pdf', null, [['descricao' => 'Loja X parcela', 'status' => 'provavel', 'grupo' => 'outro', 'pn' => 2, 'pt' => 4, 'grupos_duvida' => [$grupoX]]]);
igual($st8['parcelas_removidas'], 0, 'série em dúvida (provável) mantém as parcelas gravadas');
// sem Loja X no arquivo de novembro: some a projeção dela, mas a parcela lancada a mao e o item comum ficam
$st9 = fatura_importar_itens($cartao, '2026-11', [], 'nov.pdf', null, fatura_marcar_existentes($cartao, [$linha('Mercado novo', 5.00, '')]));
igual($st9['parcelas_removidas'], 3, 'as parcelas 2/4, 3/4 e 4/4 da compra X saíram');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao = 'Sofá 2/5'"), 1, 'parcela lançada à mão não é removida');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao = 'Mercado'"), 1, 'item comum da fatura futura não é removido');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao LIKE 'Loja X%'"), 1, 'a parcela 1/4 (outubro, já na fatura) permanece');

// reajuste em fatura futura PAGA nao e removido
q('DELETE FROM lancamentos');
q('DELETE FROM faturas');
ajustar_fatura($cartao, '2026-12', '500');
pagar_fatura($cartao, '2026-12', $itau, '2026-12-04');
ajustar_fatura($cartao, '2026-11', '700');
$rp = remover_reajustes_futuros_fatura($cartao, '2026-10');
igual($rp['qtd'], 1, 'só o reajuste da fatura aberta (novembro) foi removido');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE ajuste = 'fatura' AND fatura_mes = '2026-12'"), 1, 'o reajuste da fatura paga permanece');

echo "== serie ja gravada sem 'restantes': ao reler a fatura, as parcelas que faltam passam a constar\n";
q('DELETE FROM lancamentos');
q('DELETE FROM faturas');
$me = fatura_marcar_existentes($cartao, [$linha('Loja E 2/4', 120.00, '2/4')]);
fatura_importar_itens($cartao, '2026-10', $me, 'e.pdf', null, $me); // sem marcar as restantes: nada projetado
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao LIKE 'Loja E%'"), 1, 'só a parcela da fatura foi gravada');
$me2 = fatura_marcar_existentes($cartao, [$linha('Loja E 2/4', 120.00, '2/4')]);
ok($me2[0]['status'] === 'existe', 'a parcela da fatura já existe');
$st10 = fatura_importar_itens($cartao, '2026-10', [], 'e.pdf', null, $me2);
igual($st10['projetadas'], 2, 'as parcelas 3/4 e 4/4 foram lançadas nas faturas seguintes');
igual(fatura_total($cartao, '2026-11'), 120, 'novembro com a parcela 3/4');
igual(fatura_total($cartao, '2026-12'), 120, 'dezembro com a parcela 4/4');
$st11 = fatura_importar_itens($cartao, '2026-10', [], 'e.pdf', null, $me2);
igual($st11['projetadas'], 0, 'reler a mesma fatura não duplica as parcelas');

echo "== fatura Bradesco/Bradescard (caso real: parcelas de uma compra cancelada na mesma fatura)\n";
$brad = ['emissor' => 'Bradescard', 'produto' => 'AMAZON MASTERCARD PLATINUM', 'final_cartao' => '5013', 'vencimento' => '2027-01-28', 'fechamento' => null, 'total_fatura' => 244.44, 'lancamentos' => []];
foreach ([[2, '02/10'], [3, '03/10'], [4, '04/10'], [10, '10/10']] as [$n, $p]) {
    $brad['lancamentos'][] = ['data' => '2026-09-03', 'descricao' => "AMAZONMKTPLC*AFCCOMERC ARUJA ($p)", 'valor' => 164.58, 'parcela' => $p, 'tipo' => 'compra', 'categoria' => null];
}
$brad['lancamentos'][] = ['data' => '2026-09-03', 'descricao' => 'AMAZONMKTPLC*AFCCOMERC ARUJA BRA', 'valor' => -(164.58 * 4 - 164.58 * 4 + 658.32 - 658.32 + 0) - 0, 'parcela' => null, 'tipo' => 'credito', 'categoria' => null];
$brad['lancamentos'][count($brad['lancamentos']) - 1]['valor'] = -414.14; // estorno parcial: so para a soma fechar em 244,44
$brad['lancamentos'][] = ['data' => '2026-09-28', 'descricao' => 'PAGAMENTO RECEBIDO - OBRIGADO', 'valor' => -761.58, 'parcela' => null, 'tipo' => 'credito', 'categoria' => null];
$brad['lancamentos'][] = ['data' => '2026-09-28', 'descricao' => 'AMAZON BR *Amazon BR Sao Paulo(01/03)', 'valor' => 0.01, 'parcela' => '01/03', 'tipo' => 'compra', 'categoria' => null];
$rb = fatura_pdf_normalizar($brad);
ok(count(array_filter($rb['linhas'], function ($l) { return stripos($l['descricao'], 'PAGAMENTO RECEBIDO') !== false; })) === 0, 'o "PAGAMENTO RECEBIDO" da fatura anterior não vira item');
igual($rb['fatura']['soma_itens'], 4 * 164.58 - 414.14 + 0.01, 'soma dos itens sem o pagamento');
$mb = fatura_marcar_existentes($cartao, $rb['linhas']);
$serie = array_values(array_filter($mb, function ($l) { return strpos($l['descricao'], 'ARUJA (') !== false; }));
ok(count($serie) === 4 && array_sum(array_column($serie, 'restantes_qtd')) === 0, 'parcelas até a 10/10 já estão na fatura: nenhuma parcela restante a lançar');
$un = array_values(array_filter($mb, function ($l) { return strpos($l['descricao'], '(01/03)') !== false; }))[0];
ok($un['restantes_qtd'] === 2, 'compra 01/03 sozinha na fatura: 2 parcelas restantes');

echo "== cartao: historico impede exclusao; inativar apaga o que ainda vence\n";
$cNovo = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Cartão Teste Regras', $itau, 1000, 10, 20]);
ok(!cartao_tem_historico($cNovo), 'cartão sem nada não tem histórico');
$futuro = date('Y-m-d', strtotime('+40 day'));
$lf = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Compra futura', 'valor' => '100', 'data' => $futuro, 'cartao_id' => $cNovo]);
ok(!cartao_tem_historico($cNovo), 'só compra futura: ainda sem histórico (pode excluir)');
$passado = date('Y-m-d', strtotime('-40 day'));
criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Compra antiga', 'valor' => '50', 'data' => $passado, 'cartao_id' => $cNovo]);
ok(cartao_tem_historico($cNovo), 'compra no passado: tem histórico (só inativar)');
$aV = cartao_itens_a_vencer($cNovo);
ok(in_array($lf[0], $aV['ids'], true), 'a compra futura está entre os itens a vencer');
$n = cartao_inativar($cNovo);
ok($n >= 1 && q_val('SELECT COUNT(*) FROM lancamentos WHERE id = ?', [$lf[0]]) == 0, 'inativar exclui os itens a vencer (' . $n . ')');
igual(q_val('SELECT ativo FROM cartoes WHERE id = ?', [$cNovo]), 0, 'cartão inativado', 0);
ok(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao = 'Compra antiga'") == 1, 'o histórico passado fica');

echo "== pagamento parcial da fatura\n";
$cP = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Cartão Parcial', $itau, 5000, 10, 20]);
criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Compra P', 'valor' => '1000', 'data' => '2027-03-05', 'cartao_id' => $cP, 'fatura_mes' => '2027-03']);
$saldoAntes = saldos_por_conta()[$itau];
lanca_ex(function () use ($cP, $itau) { pagar_fatura($cP, '2027-03', $itau, '2027-03-20', '1500'); }, 'valor maior que a fatura é recusado');
pagar_fatura($cP, '2027-03', $itau, '2027-03-20', '250,00');
igual(saldos_por_conta()[$itau] - $saldoAntes, -250, 'só os R$ 250 pagos saem da conta');
igual(fatura_total($cP, '2027-04'), 750, 'o restante (750) vai para a próxima fatura');
ok(q_val("SELECT COUNT(*) FROM lancamentos WHERE cartao_id = ? AND ajuste = 'restante'", [$cP]) == 1, 'item "Saldo restante" criado');
reabrir_fatura($cP, '2027-03');
igual(fatura_total($cP, '2027-04'), 0, 'reabrir a fatura remove o restante');
igual(saldos_por_conta()[$itau], $saldoAntes, 'e devolve o valor à conta');
pagar_fatura($cP, '2027-03', $itau, '2027-03-20');
igual(saldos_por_conta()[$itau] - $saldoAntes, -1000, 'pagamento total continua igual');
igual(fatura_total($cP, '2027-04'), 0, 'sem restante no pagamento total');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
