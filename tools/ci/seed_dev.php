<?php
// Popula o banco de desenvolvimento com a planilha modelo: DB_DRIVER=sqlite DB_SQLITE=... php tests/seed_dev.php
declare(strict_types=1);
require __DIR__ . '/../../sistema/bootstrap.php';

$_SESSION['usuario_id'] = (int) q_val('SELECT id FROM usuarios ORDER BY id LIMIT 1');
garantir_perfil();
foreach (['Itaú' => 3500, 'Nubank' => 0, 'PicPay' => 0, 'BV' => 0] as $n => $s) {
    q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), $n, 'corrente', $s]);
}
$itau = (int) q_val("SELECT id FROM contas WHERE nome = 'Itaú'");
$cartao = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Nubank Visa', $itau, 8000, 25, 5]);
$arq = __DIR__ . '/../../RELATORIO_TRANSACOES_47bb7992-f61e-478b-8a22-db3050e67e53.xlsx';
$r = importar_ler_arquivo($arq, basename($arq));
$an = importar_analisar($r['linhas']);
$mc = [];
foreach ($an['contas'] as $k => $c) { $mc[$k] = $c['sugestao'] ?: 'novo'; }
$mk = [];
foreach ($an['categorias'] as $t => $l) { foreach ($l as $k => $c) { $mk["$t|$k"] = $c['sugestao'] ?: 'novo'; } }
print_r(importar_aplicar($r['linhas'], $mc, $mk, basename($arq)));
// historico recente para a media e graficos
$hoje = date('Y-m-d');
foreach ([1, 2, 3] as $i) {
    $d = add_meses(date('Y-m-05'), -$i);
    criar_lancamento(['tipo' => 'receita', 'descricao' => 'Salário', 'valor' => '9000', 'data' => $d, 'efetivado' => 1, 'conta_id' => $itau]);
    criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Mercado', 'valor' => (string) (800 + $i * 50), 'data' => add_meses(date('Y-m-12'), -$i), 'efetivado' => 1, 'conta_id' => $itau, 'categoria_id' => (int) q_val("SELECT id FROM categorias WHERE nome='Alimentação'")]);
    criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Restaurante', 'valor' => '230', 'data' => add_meses(date('Y-m-15'), -$i), 'cartao_id' => $cartao]);
}
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compras mês', 'valor' => '640', 'data' => date('Y-m-10'), 'cartao_id' => $cartao, 'categoria_id' => (int) q_val("SELECT id FROM categorias WHERE nome='Lazer'")]);
q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, taxa_anual) VALUES (?,?,?,?,?)', [perfil_id(), 'Tesouro Selic', 'renda_fixa', 'Tesouro Direto', 10.5]);
$iv = (int) q_val('SELECT MAX(id) FROM investimentos');
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,'aporte',?)", [$iv, add_meses($hoje, -4), 12000]);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,'rendimento',?)", [$iv, add_meses($hoje, -1), 320]);
echo "seed ok\n";
