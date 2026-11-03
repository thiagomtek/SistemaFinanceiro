<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxReaderService.php';

/* =========================================================================
   Importacao de planilhas (.xlsx / .csv) no formato "RELATORIO_TRANSACOES" (Mobills)
   Colunas: Data, Descrição, Valor, Conta, Situação, Categoria, Subcategoria, Tags
   Transferências: Data, Conta origem, Conta destino, Valor, Tags
   Fluxo: ler -> analisar (mapear contas/categorias existentes) -> aplicar.
   Regra de integridade: contas e cartões do arquivo precisam ser vinculados a cadastros existentes
   (ou o usuário escolhe explicitamente criar/ignorar).
   ========================================================================= */

const IMPORT_ALIASES = [
    'data' => ['data', 'date', 'data da compra', 'data compra'],
    'descricao' => ['descricao', 'descricao da compra', 'historico', 'estabelecimento', 'description', 'lancamento'],
    'valor' => ['valor', 'valor r', 'value', 'amount'],
    'conta' => ['conta', 'cartao', 'conta cartao', 'account'],
    'situacao' => ['situacao', 'status'],
    'categoria' => ['categoria', 'category'],
    'subcategoria' => ['subcategoria', 'subcategory'],
    'tags' => ['tags', 'tag'],
    'origem' => ['conta origem', 'origem'],
    'destino' => ['conta destino', 'destino'],
    'parcela' => ['parcela', 'parcelas'],
];

function importar_ler_arquivo(string $caminho, string $nomeOriginal): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $abas = XlsxReader::ler($caminho);
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        $abas = ['CSV' => importar_ler_csv($caminho)];
    } else {
        throw new InvalidArgumentException('Formato não suportado. Envie um arquivo .xlsx ou .csv.');
    }
    return importar_extrair_linhas($abas);
}

function importar_ler_csv(string $caminho): array
{
    $texto = (string) file_get_contents($caminho);
    if (str_starts_with($texto, "\xEF\xBB\xBF")) {
        $texto = substr($texto, 3);
    }
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
    $primeira = strtok($texto, "\n") ?: '';
    $sep = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
    $linhas = [];
    $h = fopen('php://temp', 'r+');
    fwrite($h, $texto);
    rewind($h);
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        if ($r !== [null]) {
            $linhas[] = array_map(fn($c) => (string) $c, $r);
        }
    }
    fclose($h);
    return $linhas;
}

function importar_mapear_cabecalho(array $cab): array
{
    $mapa = [];
    foreach ($cab as $i => $nome) {
        $n = norm_texto((string) $nome);
        foreach (IMPORT_ALIASES as $campo => $aliases) {
            if (in_array($n, $aliases, true) && !isset($mapa[$campo])) {
                $mapa[$campo] = $i;
                break;
            }
        }
    }
    return $mapa;
}

/** @return array{linhas: array, avisos: array} linhas normalizadas */
function importar_extrair_linhas(array $abas): array
{
    $combinada = null;
    foreach (array_keys($abas) as $nome) {
        if (str_contains(norm_texto($nome), 'receitas e despesas')) {
            $combinada = $nome;
        }
    }
    $linhas = [];
    $avisos = [];
    foreach ($abas as $nomeAba => $rows) {
        if (!$rows) {
            continue;
        }
        $cab = importar_mapear_cabecalho($rows[0]);
        $ehTransf = isset($cab['origem'], $cab['destino']);
        $nomeN = norm_texto($nomeAba);
        // se existe a aba combinada, as abas "Despesas"/"Receitas" repetem o mesmo conteudo
        if ($combinada !== null && $nomeAba !== $combinada && !$ehTransf && in_array($nomeN, ['despesas', 'receitas'], true)) {
            continue;
        }
        if (!isset($cab['data'], $cab['valor']) || (!$ehTransf && !isset($cab['descricao']))) {
            $avisos[] = "Aba \"$nomeAba\" ignorada: cabeçalho fora do padrão (esperado: Data, Descrição, Valor, Conta...).";
            continue;
        }
        $dica = $nomeN === 'despesas' ? 'despesa' : ($nomeN === 'receitas' ? 'receita' : null);
        $col = fn(array $r, string $campo) => isset($cab[$campo]) ? trim((string) ($r[$cab[$campo]] ?? '')) : '';
        foreach (array_slice($rows, 1) as $n => $r) {
            $numero = $n + 2;
            if (!array_filter($r, fn($c) => trim((string) $c) !== '')) {
                continue;
            }
            $data = parse_data($col($r, 'data'));
            $bruto = $col($r, 'valor');
            if (!$data || $bruto === '') {
                $avisos[] = "Aba \"$nomeAba\", linha $numero: data ou valor inválido — ignorada.";
                continue;
            }
            $valor = parse_valor($bruto);
            if ($ehTransf) {
                $linhas[] = [
                    'tipo' => 'transferencia', 'data' => $data, 'descricao' => 'Transferência', 'valor' => abs($valor),
                    'conta' => $col($r, 'origem'), 'destino' => $col($r, 'destino'), 'efetivado' => 1,
                    'categoria' => '', 'subcategoria' => '', 'tags' => $col($r, 'tags'), 'aba' => $nomeAba, 'linha' => $numero,
                ];
                continue;
            }
            if ($valor == 0.0) {
                continue;
            }
            $tipo = $dica ?? ($valor < 0 ? 'despesa' : 'receita');
            $sit = norm_texto($col($r, 'situacao'));
            $linhas[] = [
                'tipo' => $tipo, 'data' => $data, 'descricao' => mb_substr($col($r, 'descricao'), 0, 160), 'valor' => abs($valor),
                'bruto' => $valor, 'conta' => $col($r, 'conta'), 'destino' => '',
                'efetivado' => in_array($sit, ['paga', 'pago', 'recebida', 'recebido', 'efetivada', 'efetivado', 'concluida', 'confirmada'], true) ? 1 : 0,
                'categoria' => $col($r, 'categoria'), 'subcategoria' => $col($r, 'subcategoria'), 'tags' => $col($r, 'tags'),
                'parcela' => $col($r, 'parcela'), 'aba' => $nomeAba, 'linha' => $numero,
            ];
        }
    }
    if (!$linhas) {
        throw new InvalidArgumentException('Nenhuma linha válida encontrada. Confira se o arquivo segue o modelo (Data, Descrição, Valor, Conta...).');
    }
    return ['linhas' => $linhas, 'avisos' => $avisos];
}

/**
 * Modo fatura de cartao: separa compras (sinal conforme $comprasNegativas) de creditos/estornos.
 * @return array{compras: array, descartadas: int} compras ja com tipo "despesa"
 */
function importar_separar_compras(array $linhas, bool $comprasNegativas): array
{
    $compras = [];
    $descartadas = 0;
    foreach ($linhas as $l) {
        $bruto = (float) ($l['bruto'] ?? 0);
        if ($l['tipo'] === 'transferencia' || ($comprasNegativas ? $bruto >= 0 : $bruto <= 0)) {
            $descartadas++;
            continue;
        }
        $l['tipo'] = 'despesa';
        $compras[] = $l;
    }
    return ['compras' => $compras, 'descartadas' => $descartadas];
}

/** Descarta linhas anteriores a $de (AAAA-MM-DD). @return array{linhas: array, descartadas: int} */
function importar_filtrar_data(array $linhas, ?string $de): array
{
    if (!$de) {
        return ['linhas' => $linhas, 'descartadas' => 0];
    }
    $ok = array_values(array_filter($linhas, fn($l) => $l['data'] >= $de));
    return ['linhas' => $ok, 'descartadas' => count($linhas) - count($ok)];
}

function importar_eh_reajuste_fatura(array $l): bool
{
    return norm_texto($l['descricao']) === 'reajuste de fatura' || norm_texto($l['categoria']) === 'reajuste de fatura';
}

function importar_eh_reajuste_saldo(array $l): bool
{
    return norm_texto($l['categoria']) === 'reajuste';
}

/** Chave do grupo de linhas que podem ser itens de fatura: conta pagadora + dia de vencimento + (reajuste|compra). */
function importar_chave_fatura(array $l): string
{
    return norm_texto($l['conta']) . '|' . (int) substr($l['data'], 8, 2) . '|' . (importar_eh_reajuste_fatura($l) ? 'aj' : 'cp');
}

function importar_hash(array $l, int $ocorrencia): string
{
    return sha1(implode('|', [perfil_id(), $l['tipo'], $l['data'], norm_texto($l['descricao']), number_format($l['valor'], 2, '.', ''),
        norm_texto($l['conta']), norm_texto($l['destino'] ?? ''), $ocorrencia]));
}

/**
 * Cruza nomes do arquivo com cadastros do perfil.
 * @return array{contas: array, categorias: array, duplicados: int}
 */
function importar_analisar(array $linhas): array
{
    $contas = [];
    foreach (contas_do_perfil() as $c) {
        $contas[norm_texto($c['nome'])] = 'c:' . $c['id'];
    }
    foreach (cartoes_do_perfil() as $c) {
        $contas[norm_texto($c['nome'])] ??= 'k:' . $c['id'];
    }
    $cats = ['receita' => [], 'despesa' => []];
    foreach (categorias_do_perfil() as $c) {
        if (!$c['pai_id']) {
            $cats[$c['tipo']][norm_texto($c['nome'])] = (int) $c['id'];
        }
    }

    $rc = [];
    $rk = ['receita' => [], 'despesa' => []];
    foreach ($linhas as $l) {
        foreach ([$l['conta'], $l['destino']] as $nome) {
            if ($nome === '') {
                continue;
            }
            $k = norm_texto($nome);
            $rc[$k] ??= ['nome' => $nome, 'sugestao' => $contas[$k] ?? '', 'qtd' => 0];
            $rc[$k]['qtd']++;
        }
        if ($l['categoria'] !== '' && $l['tipo'] !== 'transferencia') {
            $k = norm_texto($l['categoria']);
            $rk[$l['tipo']][$k] ??= ['nome' => $l['categoria'], 'sugestao' => isset($cats[$l['tipo']][$k]) ? (string) $cats[$l['tipo']][$k] : '', 'qtd' => 0];
            $rk[$l['tipo']][$k]['qtd']++;
        }
    }

    $ocorr = [];
    $dup = 0;
    foreach ($linhas as $l) {
        $base = importar_hash($l, 0);
        $ocorr[$base] = ($ocorr[$base] ?? -1) + 1;
        if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND hash_unico = ?', [perfil_id(), importar_hash($l, $ocorr[$base])])) {
            $dup++;
        }
    }
    // RN-21: linhas cuja conta e a pagadora de um cartao e cujo dia e o vencimento desse cartao podem ser itens de fatura
    $cartoes = cartoes_do_perfil();
    usort($cartoes, fn($a, $b) => $b['limite'] <=> $a['limite']);
    $faturas = [];
    foreach ($linhas as $l) {
        $m = $contas[norm_texto($l['conta'])] ?? '';
        if ($l['tipo'] !== 'despesa' || strpos($m, 'c:') !== 0) {
            continue;
        }
        $cands = array_values(array_filter($cartoes, fn($c) => (int) $c['conta_pagamento_id'] === (int) substr($m, 2) && (int) $c['dia_vencimento'] === (int) substr($l['data'], 8, 2)));
        if (!$cands) {
            continue;
        }
        $k = importar_chave_fatura($l);
        $faturas[$k] = $faturas[$k] ?? ['conta' => $l['conta'], 'dia' => (int) substr($l['data'], 8, 2),
            'tipo' => importar_eh_reajuste_fatura($l) ? 'aj' : 'cp', 'qtd' => 0, 'candidatos' => $cands, 'sugestao' => ''];
        $faturas[$k]['qtd']++;
    }
    foreach ($faturas as $k => &$f) {
        $irmao = preg_replace('/\|(aj|cp)$/', '', $k) . '|aj';
        $idx = ($f['tipo'] === 'cp' && isset($faturas[$irmao]) && count($f['candidatos']) > 1) ? 1 : 0;
        $f['sugestao'] = (string) $f['candidatos'][$idx]['id'];
    }
    unset($f);

    return ['contas' => $rc, 'categorias' => $rk, 'duplicados' => $dup, 'faturas' => $faturas];
}

/**
 * Grava as linhas conforme o mapeamento escolhido.
 * $mapContas: chave normalizada => "c:ID" | "k:ID" | "novo" | "ignorar"
 * $mapCats: "tipo|chave" => "ID" | "novo" | "nenhuma"
 * $cartaoForcado: ID de cartao para tratar todo o arquivo como fatura de cartao.
 * $mapFaturas: chave de importar_chave_fatura => ID do cartao (itens de fatura exportados com a conta pagadora).
 * $apartirDe: ignora linhas anteriores a esta data.
 * $comprasNegativas: no modo fatura, define se compras vem com sinal negativo (padrao Mobills) ou positivo (faturas de banco).
 */
function importar_aplicar(array $linhas, array $mapContas, array $mapCats, string $arquivo, ?int $cartaoForcado = null, bool $comprasNegativas = false, array $mapFaturas = [], ?string $apartirDe = null): array
{
    $pid = perfil_id();
    $cartaoForcadoRow = $cartaoForcado ? q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartaoForcado, $pid]) : null;
    if ($cartaoForcado && !$cartaoForcadoRow) {
        throw new InvalidArgumentException('Cartão inválido.');
    }
    $stats = ['importados' => 0, 'duplicados' => 0, 'ignorados' => 0, 'contas_criadas' => 0, 'categorias_criadas' => 0, 'anteriores' => 0, 'faturas' => 0, 'reajustes' => 0];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $impId = q_insert('INSERT INTO importacoes (perfil_id, arquivo, tipo) VALUES (?,?,?)', [$pid, mb_substr($arquivo, 0, 200), $cartaoForcado ? 'fatura_cartao' : 'planilha']);

        $resolverConta = function (string $nome) use ($mapContas, $pid, &$stats): ?array {
            $k = norm_texto($nome);
            $m = $mapContas[$k] ?? '';
            if ($m === 'novo') {
                $existente = q_val('SELECT id FROM contas WHERE perfil_id = ? AND nome = ?', [$pid, mb_substr($nome, 0, 80)]);
                if ($existente) {
                    return ['c', (int) $existente];
                }
                $id = q_insert('INSERT INTO contas (perfil_id, nome, tipo) VALUES (?,?,?)', [$pid, mb_substr($nome, 0, 80), 'corrente']);
                $stats['contas_criadas']++;
                return ['c', $id];
            }
            if (preg_match('/^([ck]):(\d+)$/', $m, $x)) {
                $ok = $x[1] === 'c' ? pertence_ao_perfil('contas', (int) $x[2]) : pertence_ao_perfil('cartoes', (int) $x[2]);
                return $ok ? [$x[1], (int) $x[2]] : null;
            }
            return null;
        };
        // memoiza contas novas para nao criar duas vezes
        $cacheConta = [];
        $conta = function (string $nome) use (&$cacheConta, $resolverConta): ?array {
            $k = norm_texto($nome);
            return $cacheConta[$k] ??= $resolverConta($nome);
        };
        $cacheCat = [];
        $categoria = function (string $tipo, string $nome, string $sub) use (&$cacheCat, $mapCats, $pid, &$stats): ?int {
            if ($nome === '') {
                return null;
            }
            $k = $tipo . '|' . norm_texto($nome);
            if (!array_key_exists($k, $cacheCat)) {
                $m = $mapCats[$k] ?? 'nenhuma';
                if ($m === 'novo') {
                    $cacheCat[$k] = q_insert('INSERT INTO categorias (perfil_id, nome, tipo) VALUES (?,?,?)', [$pid, mb_substr($nome, 0, 80), $tipo]);
                    $stats['categorias_criadas']++;
                } elseif (ctype_digit($m) && pertence_ao_perfil('categorias', (int) $m)) {
                    $cacheCat[$k] = (int) $m;
                } else {
                    $cacheCat[$k] = null;
                }
            }
            $pai = $cacheCat[$k];
            if ($pai && $sub !== '') {
                $ks = $k . '>' . norm_texto($sub);
                if (!array_key_exists($ks, $cacheCat)) {
                    $f = q_val('SELECT id FROM categorias WHERE perfil_id = ? AND pai_id = ? AND nome = ?', [$pid, $pai, $sub]);
                    $cacheCat[$ks] = $f ? (int) $f : q_insert('INSERT INTO categorias (perfil_id, pai_id, nome, tipo) VALUES (?,?,?,?)', [$pid, $pai, mb_substr($sub, 0, 80), $tipo]);
                }
                return $cacheCat[$ks];
            }
            return $pai;
        };

        $ocorr = [];
        foreach ($linhas as $l) {
            if ($apartirDe && $l['data'] < $apartirDe) {
                $stats['anteriores']++;
                continue;
            }
            $cartaoId = null;
            $contaId = null;
            $destinoId = null;
            $cartaoRow = null;
            $faturaMesFixa = null;
            $ajuste = null;
            $catSistema = null;

            if ($cartaoForcadoRow) {
                $bruto = (float) ($l['bruto'] ?? 0);
                if ($l['tipo'] === 'transferencia' || ($comprasNegativas ? $bruto >= 0 : $bruto <= 0)) {
                    $stats['ignorados']++; // credito, estorno ou pagamento da fatura
                    continue;
                }
                $l['conta'] = 'cartao#' . $cartaoForcadoRow['id'];
                $l['tipo'] = 'despesa';
                $cartaoRow = $cartaoForcadoRow;
                $cartaoId = (int) $cartaoRow['id'];
            } else {
                if ($l['conta'] === '') {
                    $stats['ignorados']++;
                    continue;
                }
                $r = $conta($l['conta']);
                if (!$r) {
                    $stats['ignorados']++;
                    continue;
                }
                if ($r[0] === 'k') {
                    if ($l['tipo'] !== 'despesa') {
                        $stats['ignorados']++;
                        continue;
                    }
                    $cartaoId = $r[1];
                    $cartaoRow = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartaoId, $pid]);
                } else {
                    $contaId = $r[1];
                    $mapa = $mapFaturas[importar_chave_fatura($l)] ?? '';
                    if ($l['tipo'] === 'despesa' && ctype_digit((string) $mapa) && pertence_ao_perfil('cartoes', (int) $mapa)) {
                        // RN-21/22: item de fatura; o cartao se perdeu na exportacao e o mes da fatura e o do vencimento
                        $cartaoId = (int) $mapa;
                        $cartaoRow = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartaoId, $pid]);
                        $contaId = null;
                        $faturaMesFixa = substr($l['data'], 0, 7);
                        if (importar_eh_reajuste_fatura($l)) {
                            $ajuste = 'fatura';
                            $catSistema = categoria_sistema_id('Reajuste de fatura', 'despesa');
                        }
                        $stats['faturas']++;
                    } elseif ($l['tipo'] !== 'transferencia' && importar_eh_reajuste_saldo($l)) {
                        $ajuste = 'saldo';
                        $catSistema = categoria_sistema_id('Reajuste', $l['tipo']);
                        $stats['reajustes']++;
                    }
                }
                if ($l['tipo'] === 'transferencia') {
                    $d = $conta($l['destino']);
                    if (!$d || $d[0] !== 'c' || $d[1] === $contaId || $r[0] !== 'c') {
                        $stats['ignorados']++;
                        continue;
                    }
                    $destinoId = $d[1];
                }
            }

            $base = importar_hash($l, 0);
            $ocorr[$base] = ($ocorr[$base] ?? -1) + 1;
            $hash = importar_hash($l, $ocorr[$base]);
            if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND hash_unico = ?', [$pid, $hash])) {
                $stats['duplicados']++;
                continue;
            }

            $pn = $pt = null;
            if (preg_match('/\((\d{1,3})\s*\/\s*(\d{1,3})\)/', $l['descricao'], $m) || preg_match('/^\s*(\d{1,3})\s*\/\s*(\d{1,3})\s*$/', $l['parcela'] ?? '', $m)) {
                $pn = (int) $m[1];
                $pt = (int) $m[2];
                if ($pn < 1 || $pt < $pn) {
                    $pn = $pt = null;
                }
            }
            $grupo = $pt ? substr(sha1($pid . norm_texto(preg_replace('/\(\d+\s*\/\s*\d+\)/', '', $l['descricao']) ?? '') . ($contaId ?? $cartaoId) . $pt), 0, 16) : null;
            $catId = $catSistema ?: ($l['tipo'] === 'transferencia' ? null : $categoria($l['tipo'], $l['categoria'], $l['subcategoria']));

            q_insert(
                'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, conta_destino_id, cartao_id, fatura_mes,
                    categoria_id, tags, grupo_id, parcela_num, parcela_total, importacao_id, hash_unico, ajuste)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$pid, $l['tipo'], $l['descricao'] ?: 'Sem descrição', $l['valor'], $l['data'], $cartaoId ? 0 : $l['efetivado'], $contaId, $destinoId,
                    $cartaoId, $cartaoRow ? ($faturaMesFixa ?: fatura_mes_da_compra($cartaoRow, $l['data'])) : null, $catId, $l['tags'] ?: null, $grupo, $pn, $pt, $impId, $hash, $ajuste]
            );
            $stats['importados']++;
        }
        q('UPDATE importacoes SET qtd_importados = ?, qtd_duplicados = ? WHERE id = ?', [$stats['importados'], $stats['duplicados'], $impId]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return $stats;
}
