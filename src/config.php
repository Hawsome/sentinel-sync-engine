<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Require credentials, never fall back to hardcoded values.
// Set these in .env (local) or as real environment variables (production).
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_SOURCE', 'DB_DEST']);

function env(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return (is_string($value) && $value !== '') ? $value : $default;
}

define('DB_HOST',   env('DB_HOST'));
define('DB_PORT',   env('DB_PORT', '3306'));
define('DB_USER',   env('DB_USER'));
define('DB_PASS',   env('DB_PASS'));
define('DB_SOURCE', env('DB_SOURCE'));
define('DB_DEST',   env('DB_DEST'));

// Optional per-database hosts. The DLQ tier only earns its keep when the two
// databases can fail independently, which usually means separate servers.
define('DB_SOURCE_HOST', env('DB_SOURCE_HOST', DB_HOST));
define('DB_DEST_HOST',   env('DB_DEST_HOST',   DB_HOST));

function connect_source(): PDO {
    return connect(DB_SOURCE_HOST, DB_PORT, DB_SOURCE, DB_USER, DB_PASS);
}

function connect_dest(): PDO {
    return connect(DB_DEST_HOST, DB_PORT, DB_DEST, DB_USER, DB_PASS);
}
