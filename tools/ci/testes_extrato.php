<?php
declare(strict_types=1);

/**
 * Testes da importacao de extratos: OFX, normalizacao da resposta da IA (mock), destino sugerido, pareamento de
 * transferencias entre extratos, duplicidade e aplicacao (receita, despesa, transferencia, aplicacao, resgate com
 * bruto calculado, pagamento de fatura). Nao chama a IA nem a internet. Uso: php tools/ci/testes_extrato.php
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
function igual($a, $b, string $msg, float $tol = 0.01): void
{
    ok(abs((float) $a - (float) $b) <= $tol, $msg . " (esperado $b, obtido $a)");
}
function linha_por(array $linhas, string $trecho): ?array
{
    foreach ($linhas as $l) {
        if (stripos($l['descricao'], $trecho) !== false) {
            return $l;
        }
    }
    return null;
}

$GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim): array {
    $out = [];
    for ($t = strtotime($ini); $t <= strtotime($fim); $t += 86400) {
        $d = date('Y-m-d', $t);
        if ($serie === IDX_CDI_DIA && eh_dia_util($d)) {
            $out[] = [$d, 0.05];
        } elseif ($serie === IDX_CDI_ANUAL && eh_dia_util($d)) {
            $out[] = [$d, 13.65];
        } elseif ($serie === IDX_SELIC) {
            $out[] = [$d, 13.75];
        }
    }
    return $out;
};

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();
$bv = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'BV', 'corrente', 1000]);
$itau = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 5000]);
investimento_salvar(['nome' => 'CDB BV 110%', 'produto' => 'cdb', 'instituicao' => 'BV', 'indexador' => 'cdi', 'percentual_cdi' => 110, 'tributacao' => 'ir_regressivo', 'liquidez' => 'diaria'], 0);
$cdb = (int) q_val("SELECT id FROM investimentos WHERE nome = 'CDB BV 110%'");
$hoje = date('Y-m-d');
$d = function (int $n) use ($hoje): string {
    return date('Y-m-d', strtotime($n . ' day', strtotime($hoje)));
};
$alimentacao = (int) q_val("SELECT id FROM categorias WHERE perfil_id = ? AND nome = 'Alimentação'", [perfil_id()]);

echo "== aplicacao sem conta de origem (historico) nao quebra\n";
$lid = rf_aplicar(0, $cdb, 20000, $d(-60), true, 'historico');
ok($lid === null, 'aplicação sem conta devolve null e grava o lote');
igual(q_val('SELECT COUNT(*) FROM investimento_movs WHERE investimento_id = ?', [$cdb]), 1, 'um aporte registrado', 0);

echo "== OFX\n";
$ofx = "OFXHEADER:100\r\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><BANKID>0341<ACCTID>12345-6</BANKACCTFROM>"
    . "<BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261020120000[-3:BRT]<TRNAMT>-45,90<MEMO>PIX ENVIADO MERCADO LTDA</STMTTRN>"
    . "<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261021<TRNAMT>3000.00<NAME>SALARIO EMPRESA</STMTTRN></BANKTRANLIST>"
    . "<LEDGERBAL><BALAMT>8954.10<DTASOF>20261021</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
$o = extrato_parse_ofx($ofx);
ok(count($o['itens']) === 2, 'duas transações lidas');
igual($o['itens'][0]['valor'], -45.90, 'valor negativo com vírgula decimal');
ok($o['itens'][0]['data'] === '2026-10-20' && $o['itens'][1]['descricao'] === 'SALARIO EMPRESA', 'data e descrição (NAME na falta de MEMO)');
igual($o['meta']['saldo_final'], 8954.10, 'saldo final do OFX');
ok($o['meta']['conta'] === '12345-6', 'número da conta');
$GLOBALS['FIN_IA_MOCK'] = function () { throw new RuntimeException('IA fora do ar'); };
$tmpOfx = tempnam(sys_get_temp_dir(), 'ofx');
file_put_contents($tmpOfx, $ofx);
$r = extrato_ler_arquivo($tmpOfx, 'extrato.ofx');
unlink($tmpOfx);
ok(count($r['itens']) === 2 && $r['itens'][1]['natureza'] === 'receita', 'OFX sem a IA: classificação pelo sinal');
ok(count($r['avisos']) === 1, 'aviso de que a IA falhou');

echo "== normalizacao da resposta da IA\n";
$resp = [
    'instituicao' => 'Itaú', 'tipo_extrato' => 'conta_corrente', 'produto' => null, 'agencia' => '1234', 'conta' => '56789-0',
    'periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-25', 'saldo_inicial' => 5000.00, 'saldo_final' => 4900.00,
    'lancamentos' => [
        ['data' => $d(-20), 'descricao' => 'SALDO ANTERIOR', 'valor' => 5000, 'natureza' => 'receita'],
        ['data' => $d(-10), 'descricao' => 'Supermercado Extra', 'valor' => -120.50, 'natureza' => 'despesa', 'contraparte' => null, 'categoria' => 'Alimentação'],
        ['data' => $d(-5), 'descricao' => 'Item sem valor', 'valor' => 0, 'natureza' => 'despesa'],
        ['data' => $d(-3), 'descricao' => 'Pix recebido', 'valor' => 20.50, 'natureza' => 'receita'],
    ],
];
$n = extrato_normalizar($resp);
ok(count($n['itens']) === 2, 'saldo anterior e item sem valor são descartados');
igual($n['meta']['soma'], -100.00, 'soma das movimentações');
ok(count($n['avisos']) === 1, 'só avisa do item incompleto: a soma (-100) fecha com os saldos (5000 -> 4900)');
$resp2 = $resp;
$resp2['saldo_final'] = 4800.00;
ok(strpos(implode(' ', extrato_normalizar($resp2)['avisos']), 'difere da variação de saldo') !== false, 'aviso quando a soma não fecha com os saldos');
try {
    extrato_normalizar(['instituicao' => 'X', 'tipo_extrato' => 'conta_corrente', 'lancamentos' => []]);
    ok(false, 'extrato vazio deve ser recusado');
} catch (RuntimeException $e) {
    ok(true, 'extrato vazio é recusado');
}

$nIr = extrato_normalizar(['instituicao' => 'BV', 'tipo_extrato' => 'investimento', 'lancamentos' => [
    ['data' => $d(-2), 'descricao' => 'IR sobre resgate', 'valor' => -0.80, 'natureza' => 'resgate'],
    ['data' => $d(-2), 'descricao' => 'Irmão pix', 'valor' => -10, 'natureza' => 'despesa']]]);
ok($nIr['itens'][0]['natureza'] === 'imposto_tarifa' && $nIr['itens'][1]['natureza'] === 'despesa', 'linha de IR/IOF vira imposto; "Irmão" continua despesa');

echo "== destino sugerido\n";
ok(extrato_sugerir_destino(['instituicao' => 'Itaú Unibanco', 'tipo_extrato' => 'conta_corrente', 'produto' => '']) === 'c:' . $itau, 'extrato do Itaú vai para a conta Itaú');
ok(extrato_sugerir_destino(['instituicao' => 'BV', 'tipo_extrato' => 'investimento', 'produto' => 'CDB']) === 'i:' . $cdb, 'extrato de investimento do BV vai para o CDB BV');
ok(extrato_sugerir_destino(['instituicao' => 'BV', 'tipo_extrato' => 'conta_corrente', 'produto' => '']) === 'c:' . $bv, 'extrato de conta do BV vai para a conta BV');
ok(extrato_sugerir_destino(['instituicao' => 'Sicredi', 'tipo_extrato' => 'conta_corrente', 'produto' => '']) === '', 'banco desconhecido: sem sugestão');

echo "== pareamento: resgate do investimento BV para a conta Itaú\n";
$arqBv = ['nome' => 'bv_invest.pdf', 'meta' => ['instituicao' => 'BV', 'tipo_extrato' => 'investimento', 'produto' => 'CDB'], 'itens' => [
    ['data' => $d(-2), 'descricao' => 'RESGATE CDB', 'valor' => -52.00, 'natureza' => 'resgate', 'contraparte' => 'Itaú', 'categoria' => null],
    ['data' => $d(-2), 'descricao' => 'IR sobre resgate', 'valor' => -0.80, 'natureza' => 'imposto_tarifa', 'contraparte' => null, 'categoria' => null],
]];
$arqItau = ['nome' => 'itau.csv', 'meta' => ['instituicao' => 'Itaú', 'tipo_extrato' => 'conta_corrente', 'produto' => ''], 'itens' => [
    ['data' => $d(-1), 'descricao' => 'TED RECEBIDA BV', 'valor' => 51.20, 'natureza' => 'transferencia', 'contraparte' => 'BV', 'categoria' => null],
    ['data' => $d(-4), 'descricao' => 'Supermercado Extra', 'valor' => -120.50, 'natureza' => 'despesa', 'contraparte' => null, 'categoria' => 'Alimentação'],
    ['data' => $d(-3), 'descricao' => 'Salario', 'valor' => 3000.00, 'natureza' => 'receita', 'contraparte' => 'Empresa X', 'categoria' => null],
    ['data' => $d(-3), 'descricao' => 'Compra coincidente', 'valor' => -3000.00, 'natureza' => 'despesa', 'contraparte' => null, 'categoria' => null],
]];
$arqs = [$arqBv, $arqItau];
$dest = [0 => 'i:' . $cdb, 1 => 'c:' . $itau];
$linhas = extrato_montar($arqs, $dest);
$res = linha_por($linhas, 'RESGATE CDB');
ok($res !== null && $res['par_loc'] === 'c:' . $itau, 'saída do CDB (R$ 52,00) pareada com a entrada no Itaú (R$ 51,20, após IR)');
ok(linha_por($linhas, 'TED RECEBIDA') === null, 'a entrada pareada não aparece como linha separada');
ok($res['acao'] === 'mover' && $res['op']['op'] === 'resgatar', 'ação sugerida: resgate do investimento para a conta');
igual($res['op']['liquido'], 51.20, 'líquido do resgate = valor creditado no Itaú');
$ir = linha_por($linhas, 'IR sobre resgate');
ok($ir['acao'] === 'ignorar', 'IR do extrato do investimento é ignorado (o sistema calcula)');
$sal = linha_por($linhas, 'Salario');
$coin = linha_por($linhas, 'Compra coincidente');
ok($sal['par_loc'] === '' && $coin['par_loc'] === '', 'receita e despesa de mesmo valor NÃO são pareadas');
ok($sal['acao'] === 'receita' && $coin['acao'] === 'despesa', 'ações sugeridas: receita e despesa');
$mer = linha_por($linhas, 'Supermercado');
ok($mer['categoria_id'] === $alimentacao, 'categoria sugerida pela IA casa com o cadastro');
ok($res['status'] === 'novo' && $mer['status'] === 'novo', 'nada existe ainda no banco');

echo "== aplicar as linhas aprovadas\n";
$saldoAntes = saldos_por_conta();
$aprov = array_values(array_filter($linhas, function ($l) { return $l['acao'] !== 'ignorar'; }));
$out = extrato_aplicar($aprov);
ok($out['erros'] === [], 'sem erros' . ($out['erros'] ? ': ' . implode(' | ', $out['erros']) : ''));
ok($out['feitos'] === 4, '4 operações: resgate, despesa, receita e a despesa coincidente');
$saldo = saldos_por_conta();
igual($saldo[$itau] - $saldoAntes[$itau], 51.20 - 120.50 + 3000 - 3000, 'saldo do Itaú: resgate líquido, despesa, receita e despesa');
$mov = q_one("SELECT * FROM investimento_movs WHERE investimento_id = ? AND tipo = 'resgate'", [$cdb]);
ok($mov && $mov['valor'] > 51.20 && $mov['valor'] < 52.5, 'o resgate usa o bruto calculado para sobrar R$ 51,20 líquidos (bruto ' . ($mov['valor'] ?? '?') . ')');
igual((float) $mov['ir'] + (float) $mov['iof'] + 51.20, (float) $mov['valor'], 'bruto = líquido + IR + IOF', 0.02);
igual(q_val("SELECT valor FROM lancamentos WHERE investimento_id = ? AND sentido_invest = 'resgate'", [$cdb]), 51.20, 'crédito na conta exatamente como o extrato', 0.0001);

echo "== reimportar o mesmo extrato\n";
$linhas2 = extrato_montar($arqs, $dest);
$res2 = linha_por($linhas2, 'RESGATE CDB');
ok($res2['status'] === 'existe', 'resgate já importado é reconhecido pelo hash');
ok(linha_por($linhas2, 'Salario')['status'] === 'existe' && linha_por($linhas2, 'Supermercado')['status'] === 'existe', 'receitas e despesas já importadas são reconhecidas');
// mesmo valor lançado à mão, sem hash
$lancMao = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Café digitado à mão', 'valor' => '18.90', 'data' => $d(-8), 'efetivado' => 1, 'conta_id' => $itau]);
$arqMao = ['nome' => 'x.csv', 'meta' => ['instituicao' => 'Itaú', 'tipo_extrato' => 'conta_corrente'], 'itens' => [
    ['data' => $d(-9), 'descricao' => 'CAFETERIA CENTRO', 'valor' => -18.90, 'natureza' => 'despesa', 'contraparte' => null, 'categoria' => null]]];
$lm = extrato_montar([$arqMao], [0 => 'c:' . $itau]);
ok($lm[0]['status'] === 'provavel' && strpos($lm[0]['parecido'], 'Café') !== false, 'lançamento manual de mesmo valor e data próxima vira "possível duplicado"');

echo "== aplicacao conta -> investimento, transferencia entre contas e destino faltando\n";
$arq3 = ['nome' => 'itau2.csv', 'meta' => ['instituicao' => 'Itaú', 'tipo_extrato' => 'conta_corrente'], 'itens' => [
    ['data' => $d(-1), 'descricao' => 'APLICACAO CDB BV', 'valor' => -1000.00, 'natureza' => 'aplicacao', 'contraparte' => 'BV', 'categoria' => null],
    ['data' => $d(-1), 'descricao' => 'TED PARA BV', 'valor' => -250.00, 'natureza' => 'transferencia', 'contraparte' => 'BV', 'categoria' => null],
    ['data' => $d(-1), 'descricao' => 'APLICACAO FUNDO XPTO', 'valor' => -70.00, 'natureza' => 'aplicacao', 'contraparte' => 'XPTO', 'categoria' => null],
    ['data' => $d(-1), 'descricao' => 'PIX FULANO', 'valor' => -33.00, 'natureza' => 'transferencia', 'contraparte' => 'Fulano', 'categoria' => null],
]];
$l3 = extrato_montar([$arq3], [0 => 'c:' . $itau]);
$ap = linha_por($l3, 'APLICACAO CDB');
ok($ap['acao'] === 'mover' && $ap['cp'] === 'i:' . $cdb && $ap['op']['op'] === 'aplicar', 'aplicação: contraparte BV casa com o CDB BV');
$ted = linha_por($l3, 'TED PARA BV');
ok($ted['cp'] === 'c:' . $bv && $ted['op']['op'] === 'transferir', 'TED para o BV: transferência entre contas (prefere conta a investimento)');
$xp = linha_por($l3, 'XPTO');
ok($xp['cp'] === '' && isset($xp['op']['erro']), 'aplicação sem investimento identificado: pede para escolher (não importa sozinha)');
$fu = linha_por($l3, 'PIX FULANO');
ok($fu['acao'] === 'despesa', 'pix para pessoa desconhecida vira despesa');
$bvAntes = saldos_por_conta();
$o3 = extrato_aplicar($l3);
ok(count($o3['erros']) === 1 && $o3['feitos'] === 3, '3 feitas e 1 recusada com motivo (' . implode(' | ', $o3['erros']) . ')');
$bvDepois = saldos_por_conta();
igual($bvDepois[$bv] - $bvAntes[$bv], 250, 'BV recebe a transferência');
igual($bvDepois[$itau] - $bvAntes[$itau], -1000 - 250 - 33, 'Itaú: aplicação, TED e pix');
igual(q_val("SELECT COALESCE(SUM(valor),0) FROM investimento_movs WHERE investimento_id = ? AND tipo = 'aporte'", [$cdb]), 21000, 'aporte de R$ 1.000 somado aos R$ 20.000', 0.001);

echo "== resgate maior que o saldo e resgate total\n";
$arq4 = ['nome' => 'bv.pdf', 'meta' => ['instituicao' => 'BV', 'tipo_extrato' => 'investimento'], 'itens' => [
    ['data' => $d(0), 'descricao' => 'RESGATE ENORME', 'valor' => -90000.00, 'natureza' => 'resgate', 'contraparte' => 'BV', 'categoria' => null]]];
$l4 = extrato_montar([$arq4], [0 => 'i:' . $cdb]);
$l4[0]['cp'] = 'c:' . $bv;
$l4[0]['op'] = extrato_resolver($l4[0], 'mover', 'c:' . $bv);
$o4 = extrato_aplicar($l4);
ok($o4['feitos'] === 0 && count($o4['erros']) === 1, 'resgate maior que o saldo é recusado com explicação: ' . ($o4['erros'][0] ?? ''));

echo "== pagamento de fatura\n";
$cartao = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Nubank', $bv, 5000, 28, 4]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra A', 'valor' => '300', 'data' => $d(-12), 'cartao_id' => $cartao, 'fatura_mes' => date('Y-m', strtotime('+1 month'))]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra B', 'valor' => '124.30', 'data' => $d(-11), 'cartao_id' => $cartao, 'fatura_mes' => date('Y-m', strtotime('+1 month'))]);
$arq5 = ['nome' => 'bv_cc.csv', 'meta' => ['instituicao' => 'BV', 'tipo_extrato' => 'conta_corrente'], 'itens' => [
    ['data' => $d(0), 'descricao' => 'PAGTO FATURA NUBANK', 'valor' => -424.30, 'natureza' => 'pagamento_fatura', 'contraparte' => 'Nubank', 'categoria' => null],
    ['data' => $d(0), 'descricao' => 'PAGTO FATURA OUTRO', 'valor' => -777.00, 'natureza' => 'pagamento_fatura', 'contraparte' => null, 'categoria' => null]]];
$l5 = extrato_montar([$arq5], [0 => 'c:' . $bv]);
$pf = linha_por($l5, 'NUBANK');
ok($pf['acao'] === 'fatura' && preg_match('/^f:' . $cartao . ':\d{4}-\d{2}$/', $pf['cp']) === 1, 'pagamento casa com a fatura em aberto de mesmo valor');
ok(linha_por($l5, 'OUTRO')['acao'] === 'ignorar', 'pagamento sem fatura correspondente é ignorado por padrão (evita contar duas vezes)');
$o5 = extrato_aplicar($l5);
ok($o5['feitos'] === 1 && $o5['erros'] === [], 'fatura paga' . ($o5['erros'] ? ' - erro: ' . implode('|', $o5['erros']) : ''));
ok((int) q_val('SELECT COUNT(*) FROM faturas WHERE cartao_id = ?', [$cartao]) === 1, 'fatura marcada como paga');

echo "== leitura de PDF com a IA (mock)\n";
$GLOBALS['FIN_IA_MOCK'] = function (array $partes, array $esquema) use ($resp) {
    return $resp;
};
$tmpPdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($tmpPdf, "%PDF-1.4\nfake");
$rp = extrato_ler_arquivo($tmpPdf, 'itau.pdf');
unlink($tmpPdf);
ok(count($rp['itens']) === 2 && $rp['nome'] === 'itau.pdf' && $rp['meta']['instituicao'] === 'Itaú', 'PDF lido via IA e normalizado');
$tmpTxt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($tmpTxt, "Data;Historico;Valor\n10/10/2026;Supermercado;-120,50\n");
$rc = extrato_ler_arquivo($tmpTxt, 'extrato.csv');
unlink($tmpTxt);
ok(count($rc['itens']) === 2, 'CSV enviado como texto para a IA');
try {
    extrato_ler_arquivo(__FILE__, 'x.docx');
    ok(false, 'formato inválido');
} catch (InvalidArgumentException $e) {
    ok(true, 'formato não suportado é recusado');
}

echo "== pareamento so entre pontas compativeis (caso real: BV x Itaú)\n";
$cdb110 = $cdb;
investimento_salvar(['nome' => 'CDB BV 130% CDI', 'produto' => 'cdb', 'instituicao' => 'BV', 'percentual_cdi' => 130], 0);
$cdb130 = (int) q_val("SELECT id FROM investimentos WHERE nome = 'CDB BV 130% CDI'");
investimento_salvar(['nome' => 'Itaú CDB DI', 'produto' => 'cdb', 'instituicao' => 'Itaú', 'percentual_cdi' => 100], 0);
$cdbItau = (int) q_val("SELECT id FROM investimentos WHERE nome = 'Itaú CDB DI'");
q_insert('INSERT INTO contas (perfil_id, nome, tipo) VALUES (?,?,?)', [perfil_id(), 'Itaú - Investimentos', 'investimento']); // conta do tipo investimento nao atrai aplicacoes
$bvCc = ['nome' => 'bv_cc.pdf', 'meta' => ['instituicao' => 'BV', 'tipo_extrato' => 'conta_corrente'], 'itens' => [
    ['data' => $d(-9), 'descricao' => 'Resgate CDB CDB 110 CDI', 'valor' => 50.00, 'natureza' => 'resgate', 'contraparte' => null, 'categoria' => null],
    ['data' => $d(-9), 'descricao' => 'Transferência enviada via Pix THIAGO', 'valor' => -50.00, 'natureza' => 'transferencia', 'contraparte' => null, 'categoria' => null],
    ['data' => $d(-9), 'descricao' => 'Resgate CDB CDB 130 CDI', 'valor' => 77.00, 'natureza' => 'resgate', 'contraparte' => null, 'categoria' => null],
    ['data' => $d(-30), 'descricao' => 'Transferência antiga', 'valor' => -5.00, 'natureza' => 'transferencia', 'contraparte' => null, 'categoria' => null]]];
$itCc = ['nome' => 'itau.pdf', 'meta' => ['instituicao' => 'Itaú', 'tipo_extrato' => 'conta_corrente'], 'itens' => [
    ['data' => $d(-9), 'descricao' => 'PIX TRANSF THIAGO', 'valor' => 50.00, 'natureza' => 'transferencia', 'contraparte' => 'Itaú', 'categoria' => null],
    ['data' => $d(-9), 'descricao' => 'PIX QRS 69.129.532', 'valor' => -50.00, 'natureza' => 'despesa', 'contraparte' => null, 'categoria' => null],
    ['data' => $d(-9), 'descricao' => 'APLICACAO CDB DI', 'valor' => -130.00, 'natureza' => 'aplicacao', 'contraparte' => null, 'categoria' => null]]];
$lr = extrato_montar([$bvCc, $itCc], [0 => 'c:' . $bv, 1 => 'c:' . $itau]);
$p50 = linha_por($lr, 'Transferência enviada');
ok($p50['par_loc'] === 'c:' . $itau, 'Pix do BV pareia com o PIX TRANSF recebido no Itaú');
$r50 = linha_por($lr, 'CDB 110');
ok($r50['par_loc'] === '' && $r50['cp'] === 'i:' . $cdb110, 'resgate do BV NÃO pareia com a despesa de mesmo valor do Itaú; o investimento certo (110%) é identificado');
ok(linha_por($lr, 'CDB 130')['cp'] === 'i:' . $cdb130, 'resgate "CDB 130 CDI" aponta para o CDB de 130%');
ok(linha_por($lr, 'PIX QRS')['acao'] === 'despesa' && linha_por($lr, 'PIX QRS')['par_loc'] === '', 'a despesa de R$ 50 continua despesa');
ok(linha_por($lr, 'APLICACAO CDB DI')['cp'] === 'i:' . $cdbItau && linha_por($lr, 'APLICACAO CDB DI')['op']['op'] === 'aplicar', 'aplicação "CDB DI" no Itaú aponta para o CDB do Itaú');
$lc = extrato_montar([$bvCc, $itCc], [0 => 'c:' . $bv, 1 => 'c:' . $itau], [], $d(-20));
ok(linha_por($lc, 'Transferência antiga') === null && count($lc) === count($lr) - 1 + 0, 'data de corte descarta o que é anterior (linhas: ' . count($lc) . ')');
ok(count(extrato_montar([$bvCc, $itCc], [0 => 'c:' . $bv, 1 => 'c:' . $itau], [], '')) === count($lr), 'sem corte nada é descartado');

echo "== a mesma transferencia marcada nos dois extratos nao e lancada duas vezes\n";
$dupA = ['nome' => 'a', 'meta' => ['instituicao' => 'BV', 'tipo_extrato' => 'conta_corrente'], 'itens' => [['data' => $d(-9), 'descricao' => 'envio', 'valor' => -61.00, 'natureza' => 'despesa']]];
$dupB = ['nome' => 'b', 'meta' => ['instituicao' => 'Itaú', 'tipo_extrato' => 'conta_corrente'], 'itens' => [['data' => $d(-9), 'descricao' => 'recebido', 'valor' => 61.00, 'natureza' => 'receita']]];
$ld = extrato_montar([$dupA, $dupB], [0 => 'c:' . $bv, 1 => 'c:' . $itau]);
ok(count($ld) === 2 && $ld[0]['par_loc'] === '', 'classificadas só como despesa/receita não pareiam sozinhas');
foreach ($ld as $k => $l) {
    $ld[$k]['acao'] = 'mover';
    $ld[$k]['cp'] = $l['loc'] === 'c:' . $bv ? 'c:' . $itau : 'c:' . $bv;
    $ld[$k]['op'] = extrato_resolver($ld[$k], 'mover', $ld[$k]['cp']);
}
$od = extrato_aplicar($ld);
ok($od['feitos'] === 1 && count($od['erros']) === 1 && strpos($od['erros'][0], 'Já lançado nesta importação') !== false, 'a segunda ponta é barrada (uma transferência só)');

echo "== extrato de custodia (posicoes) e posicao inicial\n";
$cust = extrato_normalizar(['instituicao' => 'Nubank', 'tipo_extrato' => 'investimento', 'data_posicao' => $hoje, 'lancamentos' => [], 'posicoes' => [
    ['nome' => 'Turbo Croma', 'tipo_ativo' => 'RDB', 'emissor' => 'Nubank', 'saldo_bruto' => 10184.31, 'ir' => 92.79, 'iof' => 0, 'saldo_liquido' => 10091.52],
    ['nome' => 'Turbo NuCel', 'tipo_ativo' => 'RDB', 'emissor' => 'Nubank', 'saldo_bruto' => 10335.10, 'ir' => 78.72, 'iof' => 0, 'saldo_liquido' => 10256.38],
    ['nome' => 'Zerada', 'saldo_bruto' => 0]]]);
ok($cust['itens'] === [] && count($cust['meta']['posicoes']) === 2, 'extrato só de posições é aceito (sem movimentações); posição zerada descartada');
$cust['nome'] = 'custodia.pdf';
indices_garantir_desde($d(-800)); // historico do CDI completo antes de montar as posicoes (a producao faz isso ao ler cada investimento)
$cdbCroma = investimento_salvar(['nome' => 'Nubank · Turbo Croma', 'produto' => 'rdb', 'instituicao' => 'Nubank', 'percentual_cdi' => 120], 0);
$cdbCroma = (int) q_val("SELECT id FROM investimentos WHERE nome = 'Nubank · Turbo Croma'");
rf_aplicar(0, $cdbCroma, 9000, $d(-100), true, 'antigo');
$pos = extrato_posicoes([$cust]);
ok(count($pos) === 2 && $pos[0]['sugestao'] === $cdbCroma && $pos[1]['sugestao'] === 0, 'posição "Turbo Croma" casa com o investimento existente; "Turbo NuCel" não casa (novo)');
$ap = extrato_aplicar_posicoes([$pos[0] + ['inv' => $cdbCroma, 'pct' => 120], $pos[1] + ['inv' => 'novo', 'pct' => 120.0]]);
ok($ap['feitos'] === 2 && $ap['erros'] === [], 'posições aplicadas' . ($ap['erros'] ? ': ' . implode('|', $ap['erros']) : ''));
$iC = q_one('SELECT * FROM investimentos WHERE id = ?', [$cdbCroma]);
igual(rf_simular($iC, rf_movs($cdbCroma), $hoje)['saldo_bruto'], 10184.31, 'investimento existente: saldo bruto reajustado para o do extrato', 0.011);
$idNu = (int) q_val("SELECT id FROM investimentos WHERE nome = 'Nubank · Turbo NuCel'");
ok($idNu > 0, 'investimento novo criado com o nome do produto');
$iN = q_one('SELECT * FROM investimentos WHERE id = ?', [$idNu]);
$sN = rf_simular($iN, rf_movs($idNu), $hoje);
igual($sN['saldo_bruto'], 10335.10, 'investimento novo: saldo bruto exatamente o do extrato', 0.011);
igual($sN['liquidacao']['ir'], 78.72, 'IR estimado pelo sistema fica próximo ao do banco', 1.5);
ok($sN['liquidacao']['iof'] < 0.005, 'sem IOF (aplicação equivalente com mais de 30 dias)');
$cInv = investimento_salvar(['nome' => 'Itaú sem histórico', 'produto' => 'cdb', 'percentual_cdi' => 100], 0);
$cInv = (int) q_val("SELECT id FROM investimentos WHERE nome = 'Itaú sem histórico'");
$pp = rf_posicao_inicial($cInv, 9502.00, null, null, 0);
igual(rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$cInv]), rf_movs($cInv), $hoje)['saldo_bruto'], 9502.00, 'aplicação feita hoje: saldo = principal', 0.001);
ok($pp['data'] === $hoje, 'sem IR informado e diasPadrao 0, a data é hoje');
try {
    rf_posicao_inicial($cInv, 100);
    ok(false, 'posição inicial em investimento com movimentos deve ser recusada');
} catch (InvalidArgumentException $e) {
    ok(true, 'posição inicial só vale para investimento sem movimentações');
}

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
