<?php
declare(strict_types=1);

/* LancamentoService: Receitas, despesas e transferencias: validacao, repeticao/parcelas, edicao e exclusao. */

/**
 * Valida e normaliza os dados de um lancamento. Lanca InvalidArgumentException se algo for invalido.
 * Entrada: tipo, descricao, valor (positivo), data, efetivado, conta_id, conta_destino_id, cartao_id, categoria_id, tags.
 */
function validar_lancamento(array $d): array
{
    $tipo = (string) ($d['tipo'] ?? '');
    if (!in_array($tipo, ['receita', 'despesa', 'transferencia'], true)) {
        throw new InvalidArgumentException('Tipo de lançamento inválido.');
    }
    $desc = trim((string) ($d['descricao'] ?? ''));
    if ($desc === '') {
        throw new InvalidArgumentException('Informe a descrição.');
    }
    $valor = round(abs(parse_valor($d['valor'] ?? 0)), 2);
    if ($valor <= 0) {
        throw new InvalidArgumentException('Informe um valor maior que zero.');
    }
    $data = parse_data($d['data'] ?? '');
    if (!$data) {
        throw new InvalidArgumentException('Data inválida.');
    }

    $conta = !empty($d['conta_id']) ? (int) $d['conta_id'] : null;
    $destino = !empty($d['conta_destino_id']) ? (int) $d['conta_destino_id'] : null;
    $cartao = !empty($d['cartao_id']) ? (int) $d['cartao_id'] : null;
    $cat = !empty($d['categoria_id']) ? (int) $d['categoria_id'] : null;

    if ($conta && !pertence_ao_perfil('contas', $conta)) {
        throw new InvalidArgumentException('Conta inválida.');
    }
    if ($destino && !pertence_ao_perfil('contas', $destino)) {
        throw new InvalidArgumentException('Conta de destino inválida.');
    }
    $tipoCat = $cat ? q_val('SELECT tipo FROM categorias WHERE id = ? AND perfil_id = ?', [$cat, perfil_id()]) : null;
    if ($cat && !$tipoCat) {
        throw new InvalidArgumentException('Categoria inválida.');
    }
    $cartaoRow = null;
    if ($cartao) {
        $cartaoRow = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartao, perfil_id()]);
        if (!$cartaoRow) {
            throw new InvalidArgumentException('Cartão inválido.');
        }
    }

    if ($tipo === 'receita' && !$conta) {
        throw new InvalidArgumentException('Selecione a conta que recebe a receita.');
    }
    if ($tipo === 'despesa' && !$conta && !$cartao) {
        throw new InvalidArgumentException('Selecione a conta ou o cartão da despesa.');
    }
    if ($tipo === 'despesa' && $cartao) {
        $conta = null;
    }
    if ($tipo === 'transferencia') {
        if (!$conta || !$destino || $conta === $destino) {
            throw new InvalidArgumentException('Escolha duas contas diferentes para a transferência.');
        }
        $cartao = $cartaoRow = $cat = null;
    } else {
        $destino = null;
    }
    if ($tipo === 'receita') {
        $cartao = $cartaoRow = null;
    }
    if ($cat && $tipoCat !== $tipo) {
        throw new InvalidArgumentException('A categoria escolhida é de ' . $tipoCat . ' e o lançamento é de ' . $tipo . '.');
    }

    $faturaManual = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($d['fatura_mes'] ?? '')) ? (string) $d['fatura_mes'] : null;

    return [
        'fatura_mes' => $cartaoRow ? $faturaManual : null,
        'tipo' => $tipo, 'descricao' => $desc, 'valor' => $valor, 'data' => $data, 'conta_id' => $conta,
        'conta_destino_id' => $destino, 'cartao_id' => $cartao, 'cartao' => $cartaoRow, 'categoria_id' => $cat,
        'observacao' => mb_substr(trim((string) ($d['observacao'] ?? '')), 0, 255) ?: null,
        'tags' => trim((string) ($d['tags'] ?? '')) ?: null, 'efetivado' => !empty($d['efetivado']) && !$cartao ? 1 : 0,
    ];
}

/** Grava um lancamento (com repeticao fixa/parcelada). Retorna a quantidade criada. */
function criar_lancamento(array $d): int
{
    return count(criar_lancamento_ids($d));
}

/** Como criar_lancamento, mas devolve os IDs criados. Adicional: repeticao (unico|fixa|parcelado), qtd, periodo (dia|semana|mes|ano). */
function criar_lancamento_ids(array $d): array
{
    $v = validar_lancamento($d);
    ['tipo' => $tipo, 'descricao' => $desc, 'valor' => $valor, 'data' => $data, 'conta_id' => $conta,
        'conta_destino_id' => $destino, 'cartao_id' => $cartao, 'cartao' => $cartaoRow, 'categoria_id' => $cat,
        'tags' => $tags, 'efetivado' => $efetivado, 'fatura_mes' => $faturaManual, 'observacao' => $obs] = $v;
    $periodo = (string) ($d['periodo'] ?? 'mes');
    $periodo = in_array($periodo, ['dia', 'semana', 'mes', 'ano'], true) ? $periodo : 'mes';
    $ids = [];
    $rep = (string) ($d['repeticao'] ?? 'unico');
    $qtd = max(1, min(480, (int) ($d['qtd'] ?? 1)));
    if ($rep === 'unico') {
        $qtd = 1;
    }
    // fixa sem quantidade = sem fim: gera ate o horizonte e o sistema estende sozinho (ver recorrencias_estender)
    $infinita = $rep === 'fixa' && (int) ($d['qtd'] ?? 0) < 2;
    if ($infinita) {
        $limite = add_meses(date('Y-m-d'), RECORRENCIA_HORIZONTE_MESES);
        $qtd = 1;
        while ($qtd < RECORRENCIA_MAX_POR_SERIE && add_periodo($data, $qtd, $periodo) <= $limite) {
            $qtd++;
        }
    }
    $grupo = $qtd > 1 || $infinita ? bin2hex(random_bytes(8)) : null;

    $valores = array_fill(0, $qtd, $valor);
    if ($rep === 'parcelado' && $qtd > 1) {
        $centavos = (int) round($valor * 100);
        $base = intdiv($centavos, $qtd);
        $resto = $centavos - $base * $qtd;
        foreach ($valores as $i => $_) {
            $valores[$i] = ($base + ($i === 0 ? $resto : 0)) / 100;
        }
    }

    if ((int) q_val('SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ?', [perfil_id()]) + $qtd > LANCAMENTOS_MAX_POR_PERFIL) {
        throw new InvalidArgumentException('Limite de ' . number_format(LANCAMENTOS_MAX_POR_PERFIL, 0, ',', '.') . ' lançamentos atingido. Exclua lançamentos antigos para criar novos.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < $qtd; $i++) {
            $dt = add_periodo($data, $i, $periodo);
            $descI = $desc;
            if ($rep === 'parcelado' && $qtd > 1 && !preg_match('/\(\d+\/\d+\)\s*$/', $desc)) {
                $descI = sprintf('%s (%d/%d)', $desc, $i + 1, $qtd);
            }
            $ids[] = q_insert(
                'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, conta_destino_id, cartao_id,
                    fatura_mes, categoria_id, tags, grupo_id, parcela_num, parcela_total, recorrencia, periodo, observacao)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    perfil_id(), $tipo, $descI, $valores[$i], $dt, $i === 0 ? $efetivado : 0, $conta, $destino, $cartao,
                    $cartaoRow ? ($faturaManual ? substr(add_meses($faturaManual . '-01', $i), 0, 7) : fatura_mes_da_compra($cartaoRow, $dt)) : null, $cat, $tags, $grupo,
                    $rep === 'parcelado' && $qtd > 1 ? $i + 1 : null, $rep === 'parcelado' && $qtd > 1 ? $qtd : null,
                    $infinita ? 'fixa' : null, $infinita ? $periodo : null, $obs,
                ]
            );
        }
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return $ids;
}

const RECORRENCIA_HORIZONTE_MESES = 24;
const LANCAMENTOS_MAX_POR_PERFIL = 150000; // teto por perfil: criacao em massa e series fixas nao esgotam banco e CPU do servidor
const RECORRENCIA_MAX_POR_SERIE = 800;

/**
 * Receitas/despesas FIXAS sao sem fim: o sistema mantem as ocorrencias ate $meses a frente e gera as proximas conforme o
 * tempo passa. A serie acaba quando o usuario a exclui (ou exclui "este e os proximos").
 * @return int ocorrencias criadas
 */
function recorrencias_estender(int $meses = RECORRENCIA_HORIZONTE_MESES): int
{
    // duas requisicoes simultaneas nao podem gerar as mesmas ocorrencias: quem nao consegue a trava deixa para a outra (MariaDB)
    $trava = db_driver() === 'mysql' ? 'fin_rec_' . perfil_id() : null;
    if ($trava !== null && !(int) q_val('SELECT GET_LOCK(?, 0)', [$trava])) {
        return 0;
    }
    try {
        return recorrencias_gerar($meses);
    } finally {
        if ($trava !== null) {
            q('SELECT RELEASE_LOCK(?)', [$trava]);
        }
    }
}

/** Gera as ocorrencias que faltam ate o horizonte (use recorrencias_estender, que cuida da concorrencia). */
function recorrencias_gerar(int $meses): int
{
    $pid = perfil_id();
    $limite = add_meses(date('Y-m-d'), max(1, $meses));
    $criadas = 0;
    foreach (q_all(
        "SELECT grupo_id, MIN(data) AS ini, MAX(data) AS fim FROM lancamentos WHERE perfil_id = ? AND recorrencia = 'fixa' AND grupo_id IS NOT NULL
         GROUP BY grupo_id HAVING MAX(data) < ?",
        [$pid, $limite]
    ) as $g) {
        $ult = q_one('SELECT * FROM lancamentos WHERE perfil_id = ? AND grupo_id = ? ORDER BY data DESC, id DESC LIMIT 1', [$pid, $g['grupo_id']]);
        $periodo = in_array($ult['periodo'], ['dia', 'semana', 'mes', 'ano'], true) ? $ult['periodo'] : 'mes';
        $cartao = $ult['cartao_id'] ? q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$ult['cartao_id'], $pid]) : null;
        // datas ancoradas na primeira ocorrencia (31/01 -> 28/02 -> 31/03, sem "escorregar")
        $k = 0;
        while ($k < 5000 && add_periodo($g['ini'], $k, $periodo) <= $g['fim']) {
            $k++;
        }
        $n = 0;
        while ($n < RECORRENCIA_MAX_POR_SERIE) {
            $dt = add_periodo($g['ini'], $k++, $periodo);
            if ($dt > $limite) {
                break;
            }
            q_insert(
                'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, conta_destino_id, cartao_id, fatura_mes,
                    categoria_id, tags, grupo_id, recorrencia, periodo, observacao) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$pid, $ult['tipo'], $ult['descricao'], $ult['valor'], $dt, 0, $ult['conta_id'], $ult['conta_destino_id'], $ult['cartao_id'],
                    $cartao ? fatura_mes_da_compra($cartao, $dt) : null, $ult['categoria_id'], $ult['tags'], $g['grupo_id'], 'fixa', $periodo, $ult['observacao']]
            );
            $n++;
            $criadas++;
        }
    }
    return $criadas;
}

/** Atualiza um unico lancamento (mesmas validacoes de criar_lancamento, sem repeticao). */
function atualizar_lancamento(int $id, array $d): void
{
    $atual = q_one('SELECT * FROM lancamentos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
    if (!$atual) {
        throw new InvalidArgumentException('Lançamento não encontrado.');
    }
    if ($atual['fatura_id']) {
        throw new InvalidArgumentException('Este lançamento é o pagamento de uma fatura. Reabra a fatura para alterá-lo.');
    }
    if ($atual['investimento_id']) {
        throw new InvalidArgumentException('Esta transferência é uma aplicação/resgate de investimento. Exclua-a e refaça pela tela de Investimentos.');
    }
    $v = validar_lancamento($d);
    q(
        'UPDATE lancamentos SET tipo=?, descricao=?, valor=?, data=?, efetivado=?, conta_id=?, conta_destino_id=?, cartao_id=?,
            fatura_mes=?, categoria_id=?, tags=?, observacao=? WHERE id = ? AND perfil_id = ?',
        [$v['tipo'], $v['descricao'], $v['valor'], $v['data'], $v['efetivado'], $v['conta_id'], $v['conta_destino_id'], $v['cartao_id'],
            $v['cartao'] ? ($v['fatura_mes'] ?: fatura_mes_da_compra($v['cartao'], $v['data'])) : null, $v['categoria_id'], $v['tags'], $v['observacao'], $id, perfil_id()]
    );
}

/**
 * Exclui um lancamento. $escopo (so vale para series: fixa, parcelada ou repeticao):
 *  'um'      somente este;
 *  'futuras' este e os proximos AINDA NAO CONFIRMADOS (os confirmados ficam); numa serie fixa, encerra a serie;
 *  'todas'   toda a serie, inclusive os ja confirmados.
 * @return int quantidade excluida
 */
function excluir_lancamento(int $id, $escopo = 'um'): int
{
    $escopo = $escopo === true ? 'futuras' : ($escopo === false ? 'um' : (string) $escopo);
    $l = q_one('SELECT * FROM lancamentos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
    if (!$l) {
        return 0;
    }
    if ($l['fatura_id']) {
        $f = q_one('SELECT cartao_id, mes FROM faturas WHERE id = ?', [$l['fatura_id']]);
        if ($f) {
            reabrir_fatura((int) $f['cartao_id'], $f['mes']);
        }
        return 1;
    }
    $pid = perfil_id();
    if ($l['grupo_id'] && in_array($escopo, ['futuras', 'todas'], true)) {
        if ($escopo === 'todas') {
            $where = 'perfil_id = ? AND grupo_id = ?';
            $par = [$pid, $l['grupo_id']];
        } else {
            // este (confirmado ou nao) + os seguintes ainda nao confirmados; itens de cartao nao tem situacao
            $where = 'perfil_id = ? AND grupo_id = ? AND ((id = ?) OR (data >= ? AND efetivado = 0))';
            $par = [$pid, $l['grupo_id'], $id, $l['data']];
        }
        anexos_remover_arquivos(array_column(q_all("SELECT id FROM lancamentos WHERE $where", $par), 'id'));
        $n = q("DELETE FROM lancamentos WHERE $where", $par)->rowCount();
        if ($l['recorrencia'] === 'fixa' && $escopo === 'futuras') {
            // a serie terminou aqui: o que sobrou (passado/confirmado) nao deve ser estendido de novo
            q("UPDATE lancamentos SET recorrencia = NULL WHERE perfil_id = ? AND grupo_id = ?", [$pid, $l['grupo_id']]);
        }
        return $n;
    }
    anexos_remover_arquivos([$id]);
    return q('DELETE FROM lancamentos WHERE id = ? AND perfil_id = ?', [$id, $pid])->rowCount();
}

/**
 * Fim (ultima data) de cada serie fixa que tem algum dos $ids. Chame antes de excluir e passe o resultado a
 * series_fixas_encerrar_se_cortadas depois. @return array<string,string> grupo_id => ultima data
 */
function series_fixas_fim(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all("SELECT DISTINCT grupo_id FROM lancamentos WHERE perfil_id = ? AND recorrencia = 'fixa' AND grupo_id IS NOT NULL AND id IN ($marcas)", array_merge([perfil_id()], $ids)) as $g) {
        $out[(string) $g['grupo_id']] = (string) q_val('SELECT MAX(data) FROM lancamentos WHERE perfil_id = ? AND grupo_id = ?', [perfil_id(), $g['grupo_id']]);
    }
    return $out;
}

/** Se a exclusao tirou o fim de uma serie fixa, a serie acabou ali (sem isso o sistema recriaria as ocorrencias removidas). */
function series_fixas_encerrar_se_cortadas(array $fimAntes): void
{
    foreach ($fimAntes as $grupo => $fim) {
        $resta = q_val('SELECT MAX(data) FROM lancamentos WHERE perfil_id = ? AND grupo_id = ?', [perfil_id(), (string) $grupo]);
        if ($resta !== null && (string) $resta < $fim) {
            q('UPDATE lancamentos SET recorrencia = NULL WHERE perfil_id = ? AND grupo_id = ?', [perfil_id(), (string) $grupo]);
        }
    }
}

/**
 * Totais da lista de lancamentos (RN-17): com filtro de conta, transferencias que entram contam como receita e as que saem
 * como despesa. O pagamento de fatura so conta no filtro por conta (e a saida do dinheiro); nos demais casos as compras do
 * cartao ja contam. O "Saldo restante" nunca conta (sql_sem_restante). $where usa o alias "l".
 * @return array{n: int, rec: float, desp: float}
 */
function lancamentos_totais(string $where, array $params, int $contaId = 0): array
{
    $conta = max(0, $contaId);
    $conta_ok = sql_sem_restante('l') . ($conta ? '' : ' AND l.fatura_id IS NULL');
    $t = q_one(
        "SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN $conta_ok AND (l.tipo='receita' OR (l.tipo='transferencia' AND (l.conta_destino_id = ? OR (l.sentido_invest = 'resgate' AND l.conta_id = ?)))) THEN l.valor END),0) AS rec,
            COALESCE(SUM(CASE WHEN $conta_ok AND (l.tipo='despesa' OR (l.tipo='transferencia' AND l.conta_id = ? AND COALESCE(l.sentido_invest, '') <> 'resgate')) THEN l.valor END),0) AS desp
         FROM lancamentos l WHERE $where",
        array_merge([$conta, $conta, $conta], $params)
    );
    return ['n' => (int) $t['n'], 'rec' => (float) $t['rec'], 'desp' => (float) $t['desp']];
}

function alternar_efetivado(int $id): void
{
    q('UPDATE lancamentos SET efetivado = 1 - efetivado WHERE id = ? AND perfil_id = ? AND cartao_id IS NULL AND fatura_id IS NULL', [$id, perfil_id()]);
}
