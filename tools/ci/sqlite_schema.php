<?php
declare(strict_types=1);

/** Converte sql/schema.sql (MySQL) para SQLite, usado apenas em testes locais sem MySQL. */
function schema_sqlite(): string
{
    $sql = (string) file_get_contents(__DIR__ . '/../../sql/schema.sql');
    $sql = preg_replace('/^SET NAMES.*$/m', '', $sql);
    $sql = str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/ENUM\([^)]*\)/', 'TEXT', $sql);
    $sql = preg_replace('/\s+UNSIGNED/', '', $sql);
    $sql = preg_replace('/\)\s*ENGINE=[^;]*;/', ');', $sql);
    $sql = preg_replace("/\s+COMMENT\s+'[^']*'/", '', $sql);
    $sql = preg_replace('/^\s*KEY\s+\w+\s*\([^)]*\),?\s*$/m', '', $sql);
    $sql = preg_replace('/UNIQUE KEY (\w+)\s*\(/', 'UNIQUE (', $sql);
    $sql = preg_replace('/,(\s*)\)\s*;/', '$1);', $sql);
    return $sql;
}
