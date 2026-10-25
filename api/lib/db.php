<?php
declare(strict_types=1);

/** PDO access. MySQL/MariaDB in production, SQLite for local evaluation. */

require_once __DIR__ . '/prefix.php';

/**
 * Every statement passes through here, so a configured table prefix applies
 * to the whole application without any query having to know about it.
 */
function sql(string $statement): string
{
    return pb_apply_prefix($statement, (string) cfg('table_prefix', ''));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = (string) cfg('driver', 'mysql');
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($driver === 'sqlite') {
        $path = (string) cfg('sqlite_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) cfg('host', 'localhost'),
        (int) cfg('port', 3306),
        (string) cfg('database'),
        (string) cfg('charset', 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string) cfg('username'), (string) cfg('password'), $options);
    return $pdo;
}

/** @param array<string|int,mixed> $params */
function q(string $statement, array $params = []): PDOStatement
{
    $st = db()->prepare(sql($statement));
    $st->execute($params);
    return $st;
}

/**
 * @param array<string|int,mixed> $params
 * @return array<string,mixed>|null
 */
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * @param array<string|int,mixed> $params
 * @return list<array<string,mixed>>
 */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function last_id(): int
{
    return (int) db()->lastInsertId();
}

/** Runs a .sql file statement by statement (handles the simple DDL we ship). */
function run_sql_file(PDO $pdo, string $file, string $prefix = ''): void
{
    $content = (string) file_get_contents($file);
    $content = preg_replace('/^\s*--.*$/m', '', $content) ?? $content;
    $content = pb_apply_prefix($content, $prefix);
    foreach (array_filter(array_map('trim', explode(';', $content))) as $statement) {
        $pdo->exec($statement);
    }
}
