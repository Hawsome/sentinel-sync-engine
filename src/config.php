<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Require credentials, never fall back to hardcoded values.
// Set these in .env (local) or as real environment variables (production).
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_SOURCE', 'DB_DEST']);

function env(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value !== false && $value !== null) ? (string)$value : $default;
}

define('DB_HOST',   env('DB_HOST',   '127.0.0.1'));
define('DB_PORT',   env('DB_PORT',   '3306'));
define('DB_USER',   env('DB_USER'));
define('DB_PASS',   env('DB_PASS'));
define('DB_SOURCE', env('DB_SOURCE'));
define('DB_DEST',   env('DB_DEST'));
