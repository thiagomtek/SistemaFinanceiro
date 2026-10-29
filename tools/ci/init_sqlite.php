<?php
// Cria um banco SQLite de desenvolvimento: php tests/init_sqlite.php caminho.sqlite
declare(strict_types=1);
require __DIR__ . '/sqlite_schema.php';
$arq = $argv[1] ?? __DIR__ . '/../dev.sqlite';
@unlink($arq);
$pdo = new PDO('sqlite:' . $arq);
$pdo->exec(schema_sqlite());
echo "ok: $arq\n";
