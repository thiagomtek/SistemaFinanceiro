<?php
declare(strict_types=1);

/**
 * Carga inicial dos dados do Thiago a partir da planilha RELATORIO_TRANSACOES do Mobills.
 *
 * Usa os mesmos serviços da importação pela tela (ImportacaoService), com as contas e cartões dos prints.
 * Pode rodar mais de uma vez: contas e cartões existentes são reaproveitados e linhas já importadas são ignoradas.
 *
 * Uso:  php carga_inicial.php <planilha.xlsx> <email-do-usuario> [AAAA-MM-DD] [--saldos "BV=1000,Itaú=50"]
 *  - AAAA-MM-DD: ignora lançamentos anteriores (padrão: hoje)
 *  - --saldos: reajusta o saldo atual das contas pelo modo "saldo inicial" depois da carga
 * Variável CARGA_BOOTSTRAP aponta para sistema/bootstrap.php (padrão: caminho do contêiner).
 */

$boot = getenv('CARGA_BOOTSTRAP') ?: '/var/www/html/sistema/bootstrap.php';
require $boot;

$args = array_slice($argv, 1);
$saldosArg = null;
foreach ($args as $i => $a) {
    if ($a === '--saldos' && isset($args[$i + 1])) {
        $saldosArg = $args[$i + 1];
        unset($args[$i], $args[$i + 1]);
    }
}
$args = array_values($args);
[$arquivo, $email] = [$args[0] ?? '', $args[1] ?? ''];
$apartir = $args[2] ?? date('Y-m-d');
if (!is_file($arquivo) || $email === '') {
    fwrite(STDERR, "Uso: php carga_inicial.php <planilha.xlsx> <email> [AAAA-MM-DD] [--saldos \"BV=1000,Itaú=50\"]\n");
    exit(2);
}

$uid = q_val('SELECT id FROM usuarios WHERE email = ?', [mb_strtolower($email)]);
if (!$uid) {
    fwrite(STDERR, "Usuário não encontrado: $email\n");
    exit(2);
}
$_SESSION['usuario_id'] = (int) $uid;
garantir_perfil();
$pid = perfil_id();
echo 'Perfil: ' . perfil_atual()['nome'] . " (#$pid), a partir de $apartir\n";

// contas (saldo inicial 0; o saldo real e definido depois por reajuste)
$contas = [];
foreach (['BV', 'Itaú', 'Nubank', 'PicPay'] as $n) {
    $id = q_val('SELECT id FROM contas WHERE perfil_id = ? AND nome = ?', [$pid, $n]);
    if (!$id) {
        $id = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,0)', [$pid, $n, 'corrente']);
        echo "  conta criada: $n\n";
    }
    $contas[$n] = (int) $id;
}

// cartoes: limites, vencimentos e conta pagadora dos prints; fechamento do Nubank/Black/LUIZA e PROVISORIO
// (AMAZON: fecha dia 15, confirmado no print). Ajuste pela tela de cartoes.
$cartoes = [
    ['Nubank', 29900, 28, 4, 'Nubank'],
    ['Black Personalite', 38000, 18, 25, 'Itaú'],
    ['LUIZA', 4301, 18, 25, 'Itaú'],
    ['AMAZON', 6000, 15, 28, 'Itaú'],
];
foreach ($cartoes as list($nome, $limite, $fech, $venc, $paga)) {
    if (!q_val('SELECT id FROM cartoes WHERE perfil_id = ? AND nome = ?', [$pid, $nome])) {
        q_insert(
            'INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)',
            [$pid, $nome, $contas[$paga], $limite, $fech, $venc]
        );
        echo "  cartão criado: $nome (limite $limite, fecha $fech, vence $venc, paga com $paga)\n";
    }
}

$ler = importar_ler_arquivo($arquivo, basename($arquivo));
$f = importar_filtrar_data($ler['linhas'], $apartir);
echo '  linhas na planilha: ' . count($ler['linhas']) . ', a partir de ' . data_br($apartir) . ': ' . count($f['linhas']) . ", descartadas: {$f['descartadas']}\n";
$an = importar_analisar($f['linhas']);

$mapC = [];
foreach ($an['contas'] as $k => $c) {
    if ($c['sugestao'] === '') {
        fwrite(STDERR, "Conta do arquivo sem cadastro correspondente: {$c['nome']}\n");
        exit(3);
    }
    $mapC[$k] = $c['sugestao'];
}
$mapK = [];
foreach ($an['categorias'] as $tipo => $lista) {
    foreach ($lista as $k => $c) {
        $mapK["$tipo|$k"] = $c['sugestao'] !== '' ? $c['sugestao'] : 'novo';
    }
}
$mapF = [];
foreach ($an['faturas'] as $k => $fx) {
    $mapF[$k] = $fx['sugestao'];
    $cartaoNome = q_val('SELECT nome FROM cartoes WHERE id = ?', [$fx['sugestao']]);
    echo "  fatura: {$fx['conta']} dia {$fx['dia']} " . ($fx['tipo'] === 'aj' ? 'reajuste' : 'compras') . " -> $cartaoNome ({$fx['qtd']} linhas)\n";
}

$st = importar_aplicar($f['linhas'], $mapC, $mapK, basename($arquivo), null, false, $mapF, $apartir);
echo '  resultado: ' . json_encode($st, JSON_UNESCAPED_UNICODE) . "\n";

if ($saldosArg) {
    foreach (explode(',', $saldosArg) as $par) {
        [$nome, $valor] = array_pad(explode('=', $par, 2), 2, '');
        $nome = trim($nome);
        if (!isset($contas[$nome]) || $valor === '') {
            fwrite(STDERR, "Saldo ignorado (formato inválido): $par\n");
            continue;
        }
        try {
            $r = reajustar_saldo($contas[$nome], $valor, 'saldo_inicial');
            echo "  saldo de $nome reajustado (diferença " . brl($r['diferenca']) . ")\n";
        } catch (InvalidArgumentException $e) {
            echo "  saldo de $nome: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nConferência:\n";
foreach (cartoes_do_perfil() as $c) {
    $fc = fatura_corrente($c);
    echo sprintf("  %-18s limite usado %s de %s%s\n", $c['nome'], brl(limite_utilizado($c)), brl($c['limite']),
        $fc ? " · fatura corrente {$fc['mes']}: " . brl($fc['total']) . " ({$fc['status']})" : '');
}
$prev = saldos_previstos_por_conta();
$atual = saldos_por_conta();
foreach ($contas as $n => $id) {
    echo sprintf("  %-8s saldo atual %s · previsto até o fim do mês %s\n", $n, brl($atual[$id] ?? 0), brl($prev[$id] ?? 0));
}
$mes = date('Y-m');
$rm = resumo_mes($mes);
echo '  mês ' . $mes . ': receitas ' . brl($rm['rec']) . ' · despesas ' . brl($rm['desp']) . ' · cartão de crédito ' . brl(total_faturas_do_mes($mes)) . "\n";
