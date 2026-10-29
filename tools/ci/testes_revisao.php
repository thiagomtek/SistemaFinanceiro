<?php
declare(strict_types=1);

/**
 * Testes da revisao geral de 27/10/2026: leitura de valores (parse_valor), mes valido, "Saldo restante" fora dos totais,
 * totais da lista de lancamentos, projecao sem dupla contagem, resgate pelo liquido, series fixas, categoria por tipo,
 * administradores por espaco, bloqueio de tentativas de login, pareamento de extratos e limites do leitor de planilhas.
 * Uso: php tools/ci/testes_revisao.php
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
function lanca(callable $f, string $msg, string $trecho = ''): void
{
    try {
        $f();
        ok(false, $msg);
    } catch (InvalidArgumentException $e) {
        ok($trecho === '' || mb_stripos($e->getMessage(), $trecho) !== false, $msg . ' [' . $e->getMessage() . ']');
    }
}

$GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim): array {
    $out = [];
    for ($d = $ini; $d <= $fim; $d = add_dias($d, 1)) {
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

/** Novo usuario com perfil proprio (os testes nao compartilham dados). */
function novo_usuario(string $email, string $papel = 'admin', ?int $dono = null): int
{
    $uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, dono_id) VALUES (?,?,?,?,?)', ['T', $email, 'x', $papel, $dono]);
    $_SESSION['usuario_id'] = $uid;
    unset($_SESSION['perfil_id']);
    garantir_perfil();
    return $uid;
}

$hoje = date('Y-m-d');
$mesIni = date('Y-m-01');

echo "== parse_valor: milhar brasileiro sem virgula e taxas\n";
igual(parse_valor('1.500'), 1500, '"1.500" é mil e quinhentos, não 1,5', 0);
igual(parse_valor('1.234.567'), 1234567, 'vários milhares', 0);
igual(parse_valor('R$ 2.000'), 2000, 'com R$', 0);
igual(parse_valor('-1.500'), -1500, 'negativo', 0);
igual(parse_valor('1.234,56'), 1234.56, 'formato brasileiro completo', 0.001);
igual(parse_valor('1,5'), 1.5, 'vírgula decimal', 0.001);
igual(parse_valor('12.5'), 12.5, 'um decimal com ponto segue decimal', 0.001);
igual(parse_valor('0.500'), 0.5, 'zero à esquerda segue decimal', 0.001);
igual(parse_valor('1234.567'), 1234.567, 'mais de 3 dígitos antes do ponto segue decimal', 0.0001);
igual(parse_valor('1234.56'), 1234.56, 'decimal com ponto', 0.001);
igual(parse_valor(13.875), 13.875, 'número já é número', 0.0001);
igual(parse_valor('13.875', false), 13.875, 'taxa: o ponto é sempre decimal', 0.0001);
igual(parse_valor('110.500', false), 110.5, 'percentual com 3 casas', 0.0001);

echo "== mes_valido\n";
ok(mes_valido('2026-12', '2000-01') === '2026-12', 'mês válido');
ok(mes_valido('2026-13', '2000-01') === '2000-01', 'mês 13 cai no padrão');
ok(mes_valido('2026-00', '2000-01') === '2000-01', 'mês 00 cai no padrão');
ok(mes_valido(null, 'x') === 'x' && mes_valido(['a'], 'x') === 'x', 'não texto cai no padrão');
ok(mes_valido('todos', '2000-01', ['todos']) === 'todos', 'valor extra permitido');
ok(receitas_despesas_meses(0, 0, '2026-13') !== [], 'base inválida não quebra o gráfico');

echo "== 'Saldo restante' do pagamento parcial nao e despesa nova; pagamento de fatura nao conta duas vezes\n";
novo_usuario('a@t');
$itau = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 5000]);
$cartao = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Visa', $itau, 9000, 25, 5]);
$cat = categorias_do_perfil('despesa')[0];
criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Compra grande', 'valor' => 1000, 'data' => $hoje, 'cartao_id' => $cartao, 'categoria_id' => $cat['id']]);
$ym = fatura_mes_da_compra(q_one('SELECT * FROM cartoes WHERE id = ?', [$cartao]), $hoje);
pagar_fatura($cartao, $ym, $itau, $hoje, '300');
$prox = substr(add_meses($ym . '-01', 1), 0, 7);
igual(fatura_total($cartao, $prox), 700, 'a fatura seguinte recebeu os 700 restantes (cobrança real do cartão)');
ok(faturas_do_cartao(q_one('SELECT * FROM cartoes WHERE id = ?', [$cartao]))[0]['parcial'] === true, 'a fatura paga é marcada como parcial');
$meses = [];
for ($k = 0; $k <= 3; $k++) {
    $meses[] = substr(add_meses($mesIni, $k), 0, 7);
}
$somaResumo = 0.0;
$somaCat = 0.0;
foreach ($meses as $m) {
    $somaResumo += resumo_mes($m)['desp'];
    foreach (despesas_por_categoria($m) as $c) {
        $somaCat += (float) $c['total'];
    }
}
igual($somaResumo, 1000, 'resumo do mês: despesas somam só os 1.000 da compra (sem os 700 de novo nem o pagamento)');
igual($somaCat, 1000, 'despesas por categoria: idem');
$serie = receitas_despesas_meses(0, 3);
igual(array_sum(array_column($serie, 'desp')), 1000, 'gráfico receitas × despesas: idem');
$todos = 'l.perfil_id = ?';
$t = lancamentos_totais($todos, [perfil_id()], 0);
igual($t['desp'], 1000, 'lista de lançamentos sem filtro de conta: 1.000 (compra), sem o pagamento da fatura e sem o restante');
$tc = lancamentos_totais($todos . ' AND (l.conta_id = ? OR l.conta_destino_id = ?)', [perfil_id(), $itau, $itau], $itau);
igual($tc['desp'], 300, 'filtrando pela conta: sai da conta o pagamento de 300');
igual(saldos_por_conta()[$itau], 5000 - 300, 'o saldo da conta continua descontando o que foi pago');
reabrir_fatura($cartao, $ym);
igual(fatura_total($cartao, $prox), 0, 'reabrir remove o saldo restante');

echo "== categoria precisa ser do tipo do lancamento\n";
$catRec = categorias_do_perfil('receita')[0];
lanca(function () use ($itau, $catRec) {
    criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'X', 'valor' => 10, 'data' => date('Y-m-d'), 'conta_id' => $itau, 'categoria_id' => $catRec['id']]);
}, 'despesa com categoria de receita é recusada', 'categoria');
$idRec = criar_lancamento_ids(['tipo' => 'receita', 'descricao' => 'R', 'valor' => 10, 'data' => $hoje, 'conta_id' => $itau])[0];
$idDesp = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'D', 'valor' => 10, 'data' => $hoje, 'conta_id' => $itau])[0];
igual(lote_aplicar([$idRec, $idDesp], 'categoria', (string) $cat['id']), 1, 'em lote, categoria de despesa só vai para a despesa', 0);
igual(lote_aplicar([$idRec, $idDesp], 'categoria', (string) $catRec['id']), 1, 'e a de receita só para a receita', 0);

echo "== excluir em lote o fim de uma serie fixa encerra a serie (nao volta sozinha)\n";
$ids = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Assinatura A', 'valor' => 50, 'data' => $hoje, 'conta_id' => $itau, 'repeticao' => 'fixa', 'periodo' => 'mes']);
ok(count($ids) >= 20, 'a série fixa foi gerada até o horizonte (' . count($ids) . ' ocorrências)');
$grupoA = q_val('SELECT grupo_id FROM lancamentos WHERE id = ?', [$ids[0]]);
$fimAntes = q_val('SELECT MAX(data) FROM lancamentos WHERE grupo_id = ?', [$grupoA]);
lote_aplicar(array_slice($ids, -3), 'excluir');
igual((int) q_val("SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ? AND recorrencia = 'fixa'", [$grupoA]), 0, 'a série deixou de ser "fixa" (acabou ali)', 0);
recorrencias_estender();
ok(q_val('SELECT MAX(data) FROM lancamentos WHERE grupo_id = ?', [$grupoA]) < $fimAntes, 'as ocorrências excluídas não foram recriadas');
$ids2 = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Assinatura B', 'valor' => 50, 'data' => $hoje, 'conta_id' => $itau, 'repeticao' => 'fixa', 'periodo' => 'mes']);
$grupoB = q_val('SELECT grupo_id FROM lancamentos WHERE id = ?', [$ids2[0]]);
$totalB = (int) q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ?', [$grupoB]);
lote_aplicar([$ids2[5]], 'excluir');
recorrencias_estender();
igual((int) q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ?', [$grupoB]), $totalB - 1, 'excluir uma do meio não encerra a série nem recria a ocorrência', 0);
ok((int) q_val("SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ? AND recorrencia = 'fixa'", [$grupoB]) === $totalB - 1, 'e a série continua fixa');

echo "== projecao: o que ja aconteceu neste mes nao entra de novo na media\n";
novo_usuario('p@t');
$cp = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Conta', 'corrente', 0]);
$cartaoP = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Cartao', $cp, 9000, 25, 5]);
for ($k = 1; $k <= 3; $k++) {
    criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Gasto mês -' . $k, 'valor' => 3000, 'data' => add_meses($mesIni, -$k), 'conta_id' => $cp, 'efetivado' => 1]);
}
$p0 = projetar(3, true);
igual($p0['media_despesas'], 3000, 'média de despesas avulsas dos 3 meses anteriores');
igual($p0['serie'][0]['despesas'], 3000, 'sem nada neste mês, o mês atual projeta a média inteira');
criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Já pago neste mês', 'valor' => 1000, 'data' => $mesIni, 'conta_id' => $cp, 'efetivado' => 1]);
$p1 = projetar(3, true);
igual($p1['serie'][0]['despesas'], 2000, 'o que já foi pago neste mês (1.000) sai da média do mês: restam 2.000');
igual($p1['serie'][1]['despesas'], 3000, 'o mês seguinte segue com a média inteira');
criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Compra no cartão hoje', 'valor' => 500, 'data' => $hoje, 'cartao_id' => $cartaoP]);
$p2 = projetar(3, true);
igual($p2['serie'][0]['despesas'], 1500, 'a compra de cartão feita antes de hoje também sai da média (já está na fatura)');

echo "== resgate pelo liquido: o bruto calculado devolve exatamente o liquido pedido\n";
novo_usuario('i@t');
$inv = investimento_salvar(['nome' => 'CDB teste', 'produto' => 'cdb', 'percentual_cdi' => '110']);
rf_aplicar(0, $inv, 5000, add_dias($hoje, -100));
rf_aplicar(0, $inv, 2000, add_dias($hoje, -12));
$total = rf_previa_resgate($inv, null, $hoje);
foreach ([0.2, 0.5, 0.9] as $f) {
    $alvo = round($total['liquido'] * $f, 2);
    $b = rf_bruto_do_liquido($inv, $alvo, $hoje);
    igual(rf_previa_resgate($inv, $b, $hoje)['liquido'], $alvo, 'líquido pedido ' . brl($alvo) . ' → bruto ' . brl((float) $b) . ' devolve o mesmo líquido', 0.02);
}
$inv2 = investimento_salvar(['nome' => 'CDB só ganho', 'produto' => 'cdb', 'percentual_cdi' => '100']);
rf_aplicar(0, $inv2, 100, add_dias($hoje, -5));
rf_reajustar($inv2, '2000', $hoje);
$tot2 = rf_previa_resgate($inv2, null, $hoje);
$alvo2 = round($tot2['liquido'] * 0.5, 2);
$b2 = rf_bruto_do_liquido($inv2, $alvo2, $hoje);
igual(rf_previa_resgate($inv2, $b2, $hoje)['liquido'], $alvo2, 'lote de poucos dias, quase todo ganho (IOF alto): o bruto também fecha com o líquido', 0.02);
ok($b2 > $alvo2 * 1.5 + 1, 'e passa do antigo teto de 1,5× o líquido (o cálculo antigo errava aqui)');
try {
    $rj = rf_reajustar($inv, (string) round($total['liquido'], 2), $hoje, '', 'liquido');
    ok(abs($rj['diferenca']) < 0.05, 'reajuste pelo líquido igual ao calculado quase não mexe no saldo (dif. ' . $rj['diferenca'] . ')');
} catch (InvalidArgumentException $e) {
    ok(strpos($e->getMessage(), 'já é o saldo') !== false, 'reajuste pelo líquido igual ao calculado: ' . $e->getMessage());
}
igual(dias_uteis('2018-02-14', '2018-02-21'), 5, 'dias úteis num período que atravessa o fim do horário de verão (fev/2018)', 0);

echo "== administradores por espaco de dados\n";
$a = novo_usuario('adm-a@t');
$b = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, dono_id) VALUES (?,?,?,?,?)', ['B', 'adm-b@t', 'x', 'admin', $a]);
$c = novo_usuario('adm-c@t');
igual(admins_ativos(null, $a), 2, 'o espaço A tem 2 administradores', 0);
igual(admins_ativos(null, $c), 1, 'o espaço C tem 1', 0);
igual(admins_ativos($a, $a), 1, 'sem contar o próprio A', 0);
lanca(function () use ($c) { usuario_salvar(['nome' => 'C', 'email' => 'adm-c@t.com', 'papel' => 'leitor', 'ativo' => 1], $c, $c); },
    'não rebaixa o único administrador do próprio espaço, mesmo havendo administradores em outros espaços', 'administrador');

echo "== bloqueio de tentativas de login\n";
$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
$t0 = 1000000;
for ($i = 0; $i < 4; $i++) {
    login_registrar_falha('Alvo@T.com', $t0 + $i);
}
igual(login_bloqueado_por('alvo@t.com', $t0 + 5), 0, '4 falhas ainda não bloqueiam', 0);
login_registrar_falha('alvo@t.com', $t0 + 5);
ok(login_bloqueado_por('alvo@t.com', $t0 + 6) > 0, 'a 5ª falha bloqueia (e-mail sem diferenciar maiúsculas)');
igual(login_bloqueado_por('outro@t.com', $t0 + 6), 0, 'outro e-mail não é afetado', 0);
igual(login_bloqueado_por('alvo@t.com', $t0 + LOGIN_JANELA_S + 1), 0, 'passada a janela, libera', 0);
login_limpar_falhas('alvo@t.com');
igual(login_bloqueado_por('alvo@t.com', $t0 + 6), 0, 'login correto zera as falhas', 0);
login_registrar_falha('velho@t.com', $t0);
login_registrar_falha('novo@t.com', $t0 + LOGIN_JANELA_S + 10);
igual((int) q_val("SELECT COUNT(*) FROM parametros WHERE chave = ?", [login_chave('velho@t.com')]), 0, 'registros fora da janela são apagados', 0);

echo "== pareamento de extratos (indexado por data, resultado previsivel)\n";
$it = [
    ['loc' => 'c:1', 'sentido' => 'out', 'valor' => 100.0, 'data' => '2026-03-10', 'natureza' => 'transferencia'],
    ['loc' => 'c:2', 'sentido' => 'in', 'valor' => 100.0, 'data' => '2026-03-12', 'natureza' => 'transferencia'],
    ['loc' => 'c:2', 'sentido' => 'in', 'valor' => 100.0, 'data' => '2026-03-15', 'natureza' => 'transferencia'],
    ['loc' => 'c:1', 'sentido' => 'out', 'valor' => 100.0, 'data' => '2026-03-11', 'natureza' => 'transferencia'],
];
$pares = extrato_parear($it);
ok($pares === [3 => 1], 'a saída mais próxima da entrada leva o par; a de 5 dias não pareia (' . json_encode($pares) . ')');
$it2 = [
    ['loc' => 'c:1', 'sentido' => 'out', 'valor' => 100.0, 'data' => '2026-03-10', 'natureza' => 'transferencia'],
    ['loc' => 'c:2', 'sentido' => 'in', 'valor' => 100.0, 'data' => '2026-03-13', 'natureza' => 'transferencia'],
    ['loc' => 'c:2', 'sentido' => 'in', 'valor' => 100.0, 'data' => '2026-03-14', 'natureza' => 'transferencia'],
];
ok(extrato_parear($it2) === [0 => 1], 'até 3 dias pareia; 4 dias não');
ok(extrato_parear([]) === [], 'lista vazia');

echo "== leitor de planilhas: coluna absurda\n";
if (!class_exists('ZipArchive')) {
    echo "  [pulado] extensao zip ausente neste PHP\n";
    echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
    exit($falhas ? 1 : 0);
}
$tmp = tempnam(sys_get_temp_dir(), 'xl');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="A" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>x</t></is></c><c r="XFD1"><v>9</v></c></row></sheetData></worksheet>');
$zip->close();
$lido = XlsxReader::ler($tmp);
ok(isset($lido['A'][0]) && count($lido['A'][0]) === 1 && $lido['A'][0][0] === 'x', 'a célula na coluna XFD é ignorada (a linha não vira 16.384 colunas)');
@unlink($tmp);

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
