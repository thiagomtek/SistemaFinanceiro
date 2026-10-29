<?php
declare(strict_types=1);

/* =========================================================================
   RendaFixaService: motor de investimentos (CDB/RDB/LCI/LCA/cofrinhos/poupanca e manuais).

   Como os bancos mostram: o saldo exibido e BRUTO (rendimento diario em dias uteis, sem imposto);
   o imposto e retido na fonte somente no resgate (IR regressivo sobre o ganho + IOF nos 30 primeiros dias).
   - CDI: fator diario = 1 + CDI_dia x (percentual / 100), nos dias uteis; CDI_dia vem do Banco Central (serie 12);
     datas futuras usam o CDI anual atual (ou o cenario informado).
   - Cada aplicacao e um "lote" com data propria (o IR depende do prazo de cada lote); resgates consomem os lotes
     do mais antigo para o mais novo (PEPS).
   - Reajuste: o usuario informa o saldo bruto real; a diferenca vira um movimento "reajuste" distribuido nos lotes.
   ========================================================================= */

const RF_IOF_TABELA = [96, 93, 90, 86, 83, 80, 76, 73, 70, 66, 63, 60, 56, 53, 50, 46, 43, 40, 36, 33, 30, 26, 23, 20, 16, 13, 10, 6, 3];

/** Aliquota de IR (%) da tabela regressiva da renda fixa, por dias corridos desde a aplicacao. */
function rf_aliquota_ir(int $dias): float
{
    if ($dias <= 180) {
        return 22.5;
    }
    if ($dias <= 360) {
        return 20.0;
    }
    return $dias <= 720 ? 17.5 : 15.0;
}

/** IOF (% do rendimento) por dia corrido desde a aplicacao; zera a partir do 30o dia. */
function rf_iof_pct(int $dias): float
{
    if ($dias < 1) {
        return 96.0;
    }
    return $dias <= 29 ? (float) RF_IOF_TABELA[$dias - 1] : 0.0;
}

function rf_dias_corridos(string $de, string $ate): int
{
    return (int) round((strtotime($ate) - strtotime($de)) / 86400);
}

/**
 * Funcao g($a, $b) = fator de crescimento do saldo entre duas datas (AAAA-MM-DD), $b > $a.
 * @param float|null $cdiProj CDI anual (% a.a.) usado para dias sem taxa publicada (padrao: CDI atual)
 */
function rf_fator_fn(array $inv, string $inicio, string $fim, ?float $cdiProj = null): callable
{
    $ind = $inv['indexador'] ?? 'manual';
    if ($ind === 'manual') {
        return function (string $a, string $b): float {
            return 1.0;
        };
    }
    if ($ind === 'poupanca') {
        $selic = selic_atual();
        $m = $selic > 8.5 ? 0.005 : 0.7 * $selic / 100 / 12;
        return function (string $a, string $b) use ($m): float {
            return $b <= $a ? 1.0 : pow(1 + $m, rf_dias_corridos($a, $b) / 30);
        };
    }

    // calendario de dias uteis em [inicio, fim)
    $dias = [];
    $tab = $ind === 'cdi' ? cdi_diario_tabela($inicio, $fim) : [];
    $tdiProj = cdi_dia_de_anual($cdiProj ?? cdi_anual_atual());
    $p = ((float) ($inv['percentual_cdi'] ?? 100)) / 100;
    $diaPre = $ind === 'pre' ? pow(1 + ((float) $inv['taxa_anual']) / 100, 1 / 252) : 1.0;
    $cum = [1.0];
    $f = 1.0;
    for ($d = $inicio; $d < $fim; $d = add_dias($d, 1)) { // por data de calendario (somar 86400 s erra em dia de horario de verao)
        if (!eh_dia_util($d)) {
            continue;
        }
        $dias[] = $d;
        if ($ind === 'cdi') {
            $f *= 1 + (($tab[$d] ?? $tdiProj) / 100) * $p;
        } else {
            $f *= $diaPre;
        }
        $cum[] = $f;
    }
    $n = count($dias);
    $idx = function (string $d) use ($dias, $n): int { // dias uteis estritamente antes de $d
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($dias[$mid] < $d) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    };
    return function (string $a, string $b) use ($cum, $idx): float {
        return $b <= $a ? 1.0 : $cum[$idx($b)] / $cum[$idx($a)];
    };
}

/**
 * Imposto sobre um valor retirado dos lotes (PEPS). Altera $lotes.
 * @return array{bruto: float, ir: float, iof: float, liquido: float}
 */
function rf_consumir(array &$lotes, float $bruto, string $tributacao, string $data): array
{
    $resto = $bruto;
    $ir = $iof = 0.0;
    foreach ($lotes as &$l) {
        if ($resto <= 0.000001) {
            break;
        }
        if ($l['valor'] <= 0.000001) {
            continue;
        }
        $tira = min($resto, $l['valor']);
        $fr = $tira / $l['valor'];
        $principal = $l['principal'] * $fr;
        $ganho = max(0.0, $tira - $principal);
        if ($tributacao === 'ir_regressivo') {
            $dias = rf_dias_corridos($l['data'], $data);
            $i = $ganho * rf_iof_pct($dias) / 100;
            $iof += $i;
            $ir += ($ganho - $i) * rf_aliquota_ir($dias) / 100;
        }
        $l['valor'] -= $tira;
        $l['principal'] -= $principal;
        $resto -= $tira;
    }
    unset($l);
    return ['bruto' => $bruto - max(0.0, $resto), 'ir' => $ir, 'iof' => $iof, 'liquido' => $bruto - max(0.0, $resto) - $ir - $iof];
}

/** Quanto sobraria liquido se tudo fosse resgatado em $ate. @return array{bruto,ir,iof,liquido,principal,lotes} */
function rf_liquidar(array $lotes, string $tributacao, string $ate): array
{
    $copia = $lotes;
    $bruto = array_sum(array_column($copia, 'valor'));
    $principal = array_sum(array_column($copia, 'principal'));
    $r = rf_consumir($copia, $bruto, $tributacao, $ate);
    $detalhe = [];
    foreach ($lotes as $l) {
        $dias = rf_dias_corridos($l['data'], $ate);
        $ganho = max(0.0, $l['valor'] - $l['principal']);
        $detalhe[] = $l + ['dias' => $dias, 'ganho' => $ganho, 'aliquota_ir' => $tributacao === 'ir_regressivo' ? rf_aliquota_ir($dias) : 0.0,
            'iof_pct' => $tributacao === 'ir_regressivo' ? rf_iof_pct($dias) : 0.0];
    }
    return ['bruto' => $bruto, 'ir' => $r['ir'], 'iof' => $r['iof'], 'liquido' => $bruto - $r['ir'] - $r['iof'], 'principal' => $principal, 'lotes' => $detalhe];
}

function rf_movs(int $invId): array
{
    return q_all('SELECT * FROM investimento_movs WHERE investimento_id = ? ORDER BY data, id', [$invId]);
}

/**
 * Simula o investimento ate $ate considerando todos os movimentos com data <= $ate.
 * $cfg: 'fator' (callable ja construido), 'cdi_proj' (cenario de CDI anual).
 * @return array{lotes, saldo_bruto, principal, rendimento_bruto, aportes, resgates_bruto, resgates_liquido, ir_resgatado, iof_resgatado, liquidacao}
 */
function rf_simular(array $inv, array $movs, string $ate, array $cfg = []): array
{
    $eventos = array_values(array_filter($movs, function ($m) use ($ate) {
        return $m['data'] <= $ate;
    }));
    $trib = (string) ($inv['tributacao'] ?? 'nenhuma');
    $st = ['lotes' => [], 'saldo_bruto' => 0.0, 'principal' => 0.0, 'rendimento_bruto' => 0.0, 'aportes' => 0.0, 'resgates_bruto' => 0.0,
        'resgates_liquido' => 0.0, 'ir_resgatado' => 0.0, 'iof_resgatado' => 0.0];
    if (!$eventos) {
        $st['liquidacao'] = rf_liquidar([], $trib, $ate);
        return $st;
    }
    $inicio = $eventos[0]['data'];
    $g = $cfg['fator'] ?? rf_fator_fn($inv, $inicio, date('Y-m-d', strtotime($ate . ' +2 day')), $cfg['cdi_proj'] ?? null);
    $lotes = [];
    $prev = null;
    foreach ($eventos as $e) {
        if ($prev !== null && $e['data'] > $prev) {
            $f = $g($prev, $e['data']);
            foreach ($lotes as &$l) {
                $l['valor'] *= $f;
            }
            unset($l);
        }
        $prev = $e['data'];
        $v = (float) $e['valor'];
        if ($e['tipo'] === 'aporte') {
            $lotes[] = ['data' => $e['data'], 'principal' => $v, 'valor' => $v];
            $st['aportes'] += $v;
        } elseif ($e['tipo'] === 'resgate') {
            $r = rf_consumir($lotes, $v, $trib, $e['data']);
            $st['resgates_bruto'] += $r['bruto'];
            $st['resgates_liquido'] += $r['liquido'];
            $st['ir_resgatado'] += $r['ir'];
            $st['iof_resgatado'] += $r['iof'];
        } else { // reajuste / rendimento: diferenca de saldo distribuida proporcionalmente nos lotes
            $total = array_sum(array_column($lotes, 'valor'));
            if ($total > 0.000001) {
                foreach ($lotes as &$l) {
                    $l['valor'] += $v * $l['valor'] / $total;
                }
                unset($l);
            } elseif ($v > 0) {
                $lotes[] = ['data' => $e['data'], 'principal' => 0.0, 'valor' => $v];
            }
        }
    }
    if ($prev !== null && $ate > $prev) {
        $f = $g($prev, $ate);
        foreach ($lotes as &$l) {
            $l['valor'] *= $f;
        }
        unset($l);
    }
    $st['lotes'] = array_values(array_filter($lotes, function ($l) {
        return $l['valor'] > 0.004 || $l['principal'] > 0.004;
    }));
    $st['saldo_bruto'] = array_sum(array_column($st['lotes'], 'valor'));
    $st['principal'] = array_sum(array_column($st['lotes'], 'principal'));
    $st['rendimento_bruto'] = $st['saldo_bruto'] - $st['principal'];
    $st['liquidacao'] = rf_liquidar($st['lotes'], $trib, $ate);
    return $st;
}

/**
 * Serie de saldos (bruto e liquido) nas datas informadas, incluindo movimentos futuros ja agendados.
 * @param string[] $datas
 * @return array<string, array{bruto: float, liquido: float, ir: float, iof: float}>
 */
function rf_serie(array $inv, array $movs, array $datas, ?float $cdiProj = null): array
{
    if (!$datas) {
        return [];
    }
    $ini = $movs ? min($movs[0]['data'], min($datas)) : min($datas);
    $g = rf_fator_fn($inv, $ini, date('Y-m-d', strtotime(max($datas) . ' +2 day')), $cdiProj);
    $out = [];
    foreach ($datas as $d) {
        $s = rf_simular($inv, $movs, $d, ['fator' => $g]);
        $out[$d] = ['bruto' => $s['saldo_bruto'], 'liquido' => $s['liquidacao']['liquido'], 'ir' => $s['liquidacao']['ir'], 'iof' => $s['liquidacao']['iof']];
    }
    return $out;
}

/** Taxa efetiva anual aproximada (% a.a.) do investimento, para projetar aportes futuros sem movimento detalhado. */
function rf_taxa_anual_efetiva(array $inv, ?float $cdiProj = null): float
{
    switch ($inv['indexador'] ?? 'manual') {
        case 'cdi':
            $diaria = (cdi_dia_de_anual($cdiProj ?? cdi_anual_atual()) / 100) * ((float) $inv['percentual_cdi'] / 100);
            return (pow(1 + $diaria, 252) - 1) * 100;
        case 'poupanca':
            $s = selic_atual();
            return (pow(1 + ($s > 8.5 ? 0.005 : 0.7 * $s / 100 / 12), 12) - 1) * 100;
        default:
            return (float) ($inv['taxa_anual'] ?? 0);
    }
}

// ------------------------------------------------------------------- operacoes

/** Aplicacao e resgate usam contas bancarias: o investimento e cadastrado em Investimentos, nao como conta. */
function rf_exigir_conta_bancaria(int $contaId): void
{
    if ($contaId && q_val('SELECT tipo FROM contas WHERE id = ? AND perfil_id = ?', [$contaId, perfil_id()]) === 'investimento') {
        throw new InvalidArgumentException('Escolha uma conta bancária (as contas do tipo investimento não recebem aplicação nem resgate).');
    }
}

function rf_investimento(int $id, bool $exigirAtivo = false): array
{
    $inv = q_one('SELECT * FROM investimentos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
    if (!$inv || ($exigirAtivo && !$inv['ativo'])) {
        throw new InvalidArgumentException('Investimento inválido ou inativo.');
    }
    return $inv;
}

/** Aplicacao: transferencia da conta para o investimento (debita a conta e cria um aporte/lote). Retorna o ID do lancamento (null sem conta de origem). */
function rf_aplicar(int $contaId, int $invId, $valor, string $data, bool $efetivada = true, string $obs = ''): ?int
{
    if ($contaId && !pertence_ao_perfil('contas', $contaId)) {
        throw new InvalidArgumentException('Conta de origem inválida.');
    }
    rf_exigir_conta_bancaria($contaId);
    $inv = rf_investimento($invId, true);
    $v = round(abs(parse_valor($valor)), 2);
    $d = parse_data($data);
    if ($v <= 0 || !$d) {
        throw new InvalidArgumentException('Informe valor e data válidos.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // sem conta de origem = aplicacao que ja existia (historico): registra o lote sem mexer em saldo de conta
        $lid = $contaId ? q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, investimento_id, sentido_invest)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [perfil_id(), 'transferencia', mb_substr('Aplicação: ' . $inv['nome'], 0, 160), $v, $d, $efetivada ? 1 : 0, $contaId, $invId, 'aplicacao']
        ) : null;
        q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, lancamento_id, obs) VALUES (?,?,?,?,?,?)',
            [$invId, $d, 'aporte', $v, $lid, trim($obs) ?: (!$contaId ? 'Aplicação já existente (sem débito em conta)' : null)]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return $lid;
}

/**
 * Previa de resgate em $data. $valor = bruto desejado (null = resgate total).
 * @return array{saldo_bruto,bruto,ir,iof,liquido,total,avisos:string[],lotes}
 */
function rf_previa_resgate(int $invId, $valor, string $data): array
{
    $inv = rf_investimento($invId);
    $d = parse_data($data);
    if (!$d) {
        throw new InvalidArgumentException('Data inválida.');
    }
    indices_garantir();
    $s = rf_simular($inv, rf_movs($invId), $d);
    $saldo = round($s['saldo_bruto'], 2);
    $total = $valor === null || $valor === '';
    $bruto = $total ? $saldo : round(abs(parse_valor($valor)), 2);
    if ($bruto <= 0) {
        throw new InvalidArgumentException('Não há saldo para resgatar nesta data.');
    }
    if ($bruto > $saldo + 0.005) {
        throw new InvalidArgumentException('O valor do resgate (' . brl($bruto) . ') é maior que o saldo bruto na data (' . brl($saldo) . ').');
    }
    $lotes = $s['lotes'];
    $r = rf_consumir($lotes, $bruto, (string) $inv['tributacao'], $d);
    $avisos = [];
    if ($inv['liquidez'] === 'vencimento' && $inv['data_vencimento'] && $d < $inv['data_vencimento']) {
        $avisos[] = 'Este investimento tem liquidez no vencimento (' . data_br($inv['data_vencimento']) . '): o banco pode não permitir o resgate antes disso.';
    }
    if ($inv['tributacao'] === 'ir_regressivo' && $r['iof'] > 0.004) {
        $avisos[] = 'Há IOF porque parte do valor tem menos de 30 dias de aplicação.';
    }
    return ['saldo_bruto' => $saldo, 'bruto' => round($r['bruto'], 2), 'ir' => round($r['ir'], 2), 'iof' => round($r['iof'], 2),
        'liquido' => round($r['bruto'] - $r['ir'] - $r['iof'], 2), 'total' => $total, 'avisos' => $avisos, 'data' => $d];
}

/** Grava o resgate (lancamento na conta com o LIQUIDO + movimento do investimento com bruto, IR e IOF). */
function rf_gravar_resgate(array $inv, int $contaId, array $p, bool $efetivada, string $obs): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lid = $contaId ? q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, investimento_id, sentido_invest)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [perfil_id(), 'transferencia', mb_substr('Resgate: ' . $inv['nome'], 0, 160), $p['liquido'], $p['data'], $efetivada ? 1 : 0, $contaId, (int) $inv['id'], 'resgate']
        ) : null;
        q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, ir, iof, lancamento_id, obs) VALUES (?,?,?,?,?,?,?,?)',
            [(int) $inv['id'], $p['data'], 'resgate', $p['bruto'], $p['ir'], $p['iof'], $lid, trim($obs) ?: (!$contaId ? 'Resgate sem crédito em conta' : null)]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return $p + ['lancamento_id' => $lid];
}

/** Resgate informando o valor BRUTO: transferencia do investimento para a conta (credita o liquido). */
function rf_resgatar(int $invId, int $contaId, $valor, string $data, bool $efetivada = true, string $obs = ''): array
{
    if ($contaId && !pertence_ao_perfil('contas', $contaId)) {
        throw new InvalidArgumentException('Conta de destino inválida.');
    }
    rf_exigir_conta_bancaria($contaId);
    $inv = rf_investimento($invId, true);
    return rf_gravar_resgate($inv, $contaId, rf_previa_resgate($invId, $valor, $data), $efetivada, $obs);
}

/**
 * Bruto a resgatar para que o LIQUIDO (apos IR/IOF) seja $liquido na data. Devolve null quando e o resgate total.
 */
function rf_bruto_do_liquido(int $invId, float $liquido, string $data): ?float
{
    $tot = rf_previa_resgate($invId, null, $data);
    $tol = max(1.0, $liquido * 0.02);
    if ($liquido >= $tot['liquido'] - 0.01) {
        if ($liquido > $tot['liquido'] + $tol) {
            throw new InvalidArgumentException('O valor (' . brl($liquido) . ') é maior que o líquido calculado do investimento em ' . data_br($data)
                . ' (' . brl($tot['liquido']) . '). Faça o reajuste de saldo do investimento ou confira a data.');
        }
        return null;
    }
    // O liquido cresce com o bruto, entao a raiz esta entre o proprio liquido e o saldo bruto (nao ha teto menor garantido:
    // em lotes com menos de 30 dias o IOF pode consumir quase todo o ganho). Uma unica simulacao; a busca so reconsome os lotes.
    $inv = rf_investimento($invId);
    $lotes = rf_simular($inv, rf_movs($invId), $data)['lotes'];
    $trib = (string) $inv['tributacao'];
    $lo = $liquido;
    $hi = $tot['saldo_bruto'];
    for ($n = 0; $n < 40; $n++) {
        $mid = ($lo + $hi) / 2;
        $copia = $lotes;
        $r = rf_consumir($copia, round($mid, 2), $trib, $data);
        if (round($r['bruto'] - $r['ir'] - $r['iof'], 2) < $liquido) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return round($hi, 2);
}

/**
 * Previa do resgate pelo valor LIQUIDO que deve cair na conta ($liquido = null: resgate total). Os impostos saem do
 * proprio investimento: bruto = liquido + IR + IOF. $ir e $iof opcionais substituem o calculo (o banco pode cobrar mais).
 * @return array{saldo_bruto,bruto,ir,iof,liquido,total,editado,avisos:string[],data}
 */
function rf_previa_resgate_liquido(int $invId, $liquido, string $data, $ir = null, $iof = null): array
{
    $total = $liquido === null || $liquido === '';
    $liq = $total ? 0.0 : round(abs(parse_valor($liquido)), 2);
    if (!$total && $liq <= 0) {
        throw new InvalidArgumentException('Informe o valor que deve cair na conta.');
    }
    $bruto = $total ? null : rf_bruto_do_liquido($invId, $liq, $data);
    $p = rf_previa_resgate($invId, $bruto, $data);
    $p['editado'] = false;
    $irM = ($ir === null || $ir === '') ? null : round(abs(parse_valor($ir)), 2);
    $iofM = ($iof === null || $iof === '') ? null : round(abs(parse_valor($iof)), 2);
    if ($irM !== null || $iofM !== null) {
        $p['ir'] = $irM ?? $p['ir'];
        $p['iof'] = $iofM ?? $p['iof'];
        if ($total) {
            $p['liquido'] = round($p['bruto'] - $p['ir'] - $p['iof'], 2);
        } else {
            $p['liquido'] = $liq;
            $p['bruto'] = round($liq + $p['ir'] + $p['iof'], 2);
        }
        $p['editado'] = true;
        if ($p['bruto'] > $p['saldo_bruto'] + 0.005 || $p['liquido'] < 0) {
            throw new InvalidArgumentException('Com esses impostos o resgate (' . brl($p['bruto']) . ' bruto) passa do saldo do investimento (' . brl($p['saldo_bruto']) . ').');
        }
    } elseif (!$total) {
        $p['liquido'] = $liq; // a conta recebe exatamente o valor pedido (o bruto foi calculado para isso)
    }
    return $p;
}

/** Resgate pelo valor LIQUIDO na conta; IR e IOF debitados do proprio investimento (editaveis). */
function rf_resgatar_liquido(int $invId, int $contaId, $liquido, string $data, bool $efetivada = true, string $obs = '', $ir = null, $iof = null): array
{
    if ($contaId && !pertence_ao_perfil('contas', $contaId)) {
        throw new InvalidArgumentException('Conta de destino inválida.');
    }
    rf_exigir_conta_bancaria($contaId);
    $inv = rf_investimento($invId, true);
    return rf_gravar_resgate($inv, $contaId, rf_previa_resgate_liquido($invId, $liquido, $data, $ir, $iof), $efetivada, $obs);
}

/**
 * Saldo bruto que faz o liquido (apos IR/IOF de um resgate total em $data) ficar igual a $liquido.
 * Devolve a diferenca (positiva ou negativa) a lancar como reajuste.
 */
function rf_reajuste_para_liquido(array $inv, array $movs, string $data, float $liquido): float
{
    // o fator de crescimento (CDI dia a dia) e o mesmo em todas as tentativas: monta uma vez
    $fator = rf_fator_fn($inv, $movs ? min($movs[0]['data'], $data) : $data, date('Y-m-d', strtotime($data . ' +2 day')));
    $liq = function (float $delta) use ($inv, $movs, $data, $fator): float {
        $m = $movs;
        $m[] = ['data' => $data, 'tipo' => 'reajuste', 'valor' => $delta];
        return (float) rf_simular($inv, $m, $data, ['fator' => $fator])['liquidacao']['liquido'];
    };
    $atual = rf_simular($inv, $movs, $data, ['fator' => $fator])['saldo_bruto'];
    $lo = -$atual;
    $hi = max(1.0, $liquido - $liq(0.0)) * 2;
    for ($n = 0; $n < 40 && $liq($hi) < $liquido; $n++) {
        $hi *= 2;
    }
    for ($n = 0; $n < 50; $n++) {
        $mid = ($lo + $hi) / 2;
        if ($liq($mid) < $liquido) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return round($hi, 2);
}

/**
 * Reajuste de investimento: alinha o saldo ao informado. $base = 'bruto' (como o app do banco mostra; padrao) ou
 * 'liquido' (apos IR e IOF de um resgate total na data): neste caso o bruto equivalente e calculado.
 */
function rf_reajustar(int $invId, $saldoReal, string $data, string $obs = '', string $base = 'bruto'): array
{
    $inv = rf_investimento($invId, true);
    $d = parse_data($data);
    $real = round(parse_valor($saldoReal), 2);
    if (!$d || $real < 0) {
        throw new InvalidArgumentException('Informe um saldo e uma data válidos.');
    }
    if (!in_array($base, ['bruto', 'liquido'], true)) {
        throw new InvalidArgumentException('Informe se o saldo é bruto ou líquido.');
    }
    indices_garantir();
    $movs = rf_movs($invId);
    $atual = round(rf_simular($inv, $movs, $d)['saldo_bruto'], 2);
    if ($base === 'liquido') {
        $dif = rf_reajuste_para_liquido($inv, $movs, $d, $real);
        $real = round($atual + $dif, 2);
    }
    $dif = round($real - $atual, 2);
    if (abs($dif) < 0.005) {
        throw new InvalidArgumentException('O saldo informado já é o saldo calculado (' . brl($atual) . ').');
    }
    if ($atual <= 0.004 && $dif < 0) {
        throw new InvalidArgumentException('Não há saldo para reduzir nesta data.');
    }
    $obsPad = $base === 'liquido' ? 'Reajuste de saldo (informado líquido)' : 'Reajuste de saldo';
    q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, obs) VALUES (?,?,?,?,?)',
        [$invId, $d, 'reajuste', $dif, trim($obs) !== '' ? trim($obs) : $obsPad]);
    return ['diferenca' => $dif, 'anterior' => $atual, 'novo' => $real, 'base' => $base];
}

/**
 * Posicao inicial de um investimento que ja existia antes de usar o sistema: registra uma aplicacao (sem debito em conta)
 * cujo saldo bruto hoje e EXATAMENTE $bruto.
 * - Com $irEstimado (o app do banco mostra o IR estimado), escolhe a data da aplicacao equivalente cujo IR estimado pelo
 *   sistema fica mais proximo do informado; assim o saldo liquido bate com o do banco.
 * - Sem IR, usa a aplicacao $diasPadrao dias atras (31 = sem IOF); 0 = aplicada hoje (ex.: aplicacao feita hoje).
 * So vale para investimento sem movimentos.
 * @return array{data: string, principal: float, ajuste: float}
 */
function rf_posicao_inicial(int $invId, float $bruto, ?float $irEstimado = null, ?string $hoje = null, int $diasPadrao = 31): array
{
    $inv = rf_investimento($invId, true);
    $hoje = $hoje ?: date('Y-m-d');
    $bruto = round($bruto, 2);
    if ($bruto <= 0) {
        throw new InvalidArgumentException('Informe um saldo maior que zero.');
    }
    if (q_val('SELECT COUNT(*) FROM investimento_movs WHERE investimento_id = ?', [$invId])) {
        throw new InvalidArgumentException('Este investimento já tem movimentações: use o reajuste de saldo.');
    }
    $d0 = date('Y-m-d', strtotime($hoje . ' -' . max(0, $diasPadrao) . ' day'));
    $fim = date('Y-m-d', strtotime($hoje . ' +2 day'));
    if ($irEstimado !== null && $irEstimado > 0.004 && $inv['tributacao'] === 'ir_regressivo' && $inv['indexador'] !== 'manual') {
        $max = 720;
        $minData = date('Y-m-d', strtotime($hoje . ' -' . $max . ' day'));
        indices_garantir_desde($minData);
        $g = rf_fator_fn($inv, $minData, $fim);
        $melhor = null;
        for ($n = 31; $n <= $max; $n++) {
            $a = date('Y-m-d', strtotime($hoje . ' -' . $n . ' day'));
            $ganho = $bruto - $bruto / $g($a, $hoje);
            $erro = abs($ganho * rf_aliquota_ir($n) / 100 - $irEstimado);
            if ($melhor === null || $erro < $melhor[0] - 0.0001) {
                $melhor = [$erro, $a];
            }
        }
        $d0 = $melhor[1];
    } elseif ($diasPadrao > 0) {
        indices_garantir_desde($d0);
    }
    $f = rf_fator_fn($inv, $d0, $fim)($d0, $hoje);
    $principal = round($bruto / $f, 2);
    q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, obs) VALUES (?,?,?,?,?)',
        [$invId, $d0, 'aporte', $principal, 'Posição inicial (saldo que já existia)']);
    $calc = round(rf_simular($inv, rf_movs($invId), $hoje)['saldo_bruto'], 2);
    $dif = round($bruto - $calc, 2);
    if (abs($dif) >= 0.01) {
        q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, obs) VALUES (?,?,?,?,?)', [$invId, $hoje, 'reajuste', $dif, 'Ajuste de centavos da posição inicial']);
    }
    return ['data' => $d0, 'principal' => $principal, 'ajuste' => $dif];
}

/** Exclui um movimento (e a transferencia ligada a ele, quando houver). */
function rf_excluir_mov(int $movId): void
{
    $m = q_one('SELECT m.id, m.lancamento_id FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE m.id = ? AND i.perfil_id = ?', [$movId, perfil_id()]);
    if (!$m) {
        return;
    }
    if ($m['lancamento_id']) {
        q('DELETE FROM investimento_movs WHERE id = ?', [$movId]);
        q('DELETE FROM lancamentos WHERE id = ? AND perfil_id = ?', [$m['lancamento_id'], perfil_id()]);
    } else {
        q('DELETE FROM investimento_movs WHERE id = ?', [$movId]);
    }
}
