<?php
declare(strict_types=1);

/* Conexao PDO e atalhos de consulta (q, q_all, q_one, q_val, q_insert). MariaDB 10.5 em producao; SQLite so em testes locais. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $driver = getenv('DB_DRIVER') ?: 'mysql';
    if ($driver === 'sqlite') {
        $pdo = new PDO('sqlite:' . (getenv('DB_SQLITE') ?: APP_ROOT . '/financeiro.sqlite'));
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'db', getenv('DB_NAME') ?: 'financeiro');
        $pdo = new PDO($dsn, getenv('DB_USER') ?: 'financeiro', getenv('DB_PASS') ?: '');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db_driver(): string
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Expressao SQL "YYYY-MM" para uma coluna de data, portavel entre MySQL e SQLite (testes). */
function sql_mes(string $coluna): string
{
    return db_driver() === 'sqlite' ? "strftime('%Y-%m', $coluna)" : "DATE_FORMAT($coluna, '%Y-%m')";
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function q_val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function q_insert(string $sql, array $params = []): int
{
    q($sql, $params);
    return (int) db()->lastInsertId();
}
