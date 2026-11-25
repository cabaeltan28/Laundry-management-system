<?php
/**
 * Database connection (PDO). Every query in the app uses prepared statements.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        // LF_SQLITE is only used by the developers' automated test run.
        $sqlite = getenv('LF_SQLITE');
        if ($sqlite) {
            $pdo = new PDO('sqlite:' . $sqlite, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
    } catch (PDOException $ex) {
        http_response_code(500);
        echo '<div style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:24px;border:1px solid #f3c1c1;border-radius:12px;background:#fff5f5">';
        echo '<h2 style="margin-top:0">Cannot connect to the database</h2>';
        echo '<p>Check that MySQL is running in XAMPP and that you imported <b>database/laundryflow.sql</b> in phpMyAdmin.</p>';
        if (APP_DEBUG) {
            echo '<p style="color:#a00"><small>' . htmlspecialchars($ex->getMessage()) . '</small></p>';
        }
        echo '</div>';
        exit;
    }
    return $pdo;
}

/** Run a query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}
