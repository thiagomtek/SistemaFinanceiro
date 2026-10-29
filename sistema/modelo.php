<?php
require_once __DIR__ . '/auth_check.php';

$tipo = ($_GET['tipo'] ?? '') === 'fatura' ? 'fatura' : 'lancamentos';
if ($tipo === 'fatura') {
    $linhas = [
        ['Data', 'Descrição', 'Valor', 'Categoria', 'Parcela'],
        ['10/11/2026', 'Supermercado Exemplo', '152,30', 'Alimentação', ''],
        ['12/11/2026', 'Notebook', '450,00', 'Eletrônicos', '3/10'],
    ];
} else {
    $linhas = [
        ['Data', 'Descrição', 'Valor', 'Conta', 'Situação', 'Categoria', 'Subcategoria', 'Tags'],
        ['05/11/2026', 'Salário', '5000,00', 'Itaú', 'Paga', 'Salário', '', ''],
        ['10/11/2026', 'Plano de Saúde', '-636,35', 'Itaú', 'Pendente', 'Saúde', '', ''],
    ];
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="modelo_' . $tipo . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
foreach ($linhas as $l) {
    fputcsv($out, $l, ';', '"', '');
}
