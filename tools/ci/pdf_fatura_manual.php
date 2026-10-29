<?php
declare(strict_types=1);

/**
 * Teste MANUAL (chama a IA de verdade; nao roda no CI): php tools/ci/pdf_fatura_manual.php fatura.pdf [senha]
 * Requer GEMINI_API_KEY no ambiente. Usa um banco SQLite em memoria so para as categorias padrao.
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');
require __DIR__ . '/../../sistema/bootstrap.php';
require __DIR__ . '/sqlite_schema.php';
db()->exec(schema_sqlite());
$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();

$r = fatura_pdf_ler($argv[1] ?? '', $argv[2] ?? '');
$f = $r['fatura'];
echo "Emissor: {$f['emissor']} {$f['produto']} final {$f['final_cartao']} | vencimento {$f['vencimento']} | fechamento " . ($f['fechamento'] ?: '-') . " | modelo {$f['modelo']}\n";
echo 'Total da fatura: ' . brl($f['total']) . ' | soma dos itens: ' . brl($f['soma_itens']) . ' | diferença: ' . brl($f['diferenca']) . "\n";
foreach ($f['tentativas'] as $t) {
    echo "  tentativa falhou: $t
";
}
echo count($r['linhas']) . " itens:\n";
foreach ($r['linhas'] as $l) {
    printf("  %s  %-40s %10s  %-6s %s\n", $l['data'], mb_substr($l['descricao'], 0, 40), number_format($l['valor'], 2, ',', '.'), $l['parcela'], $l['categoria']);
}
foreach ($r['avisos'] as $a) {
    echo "  aviso: $a\n";
}
