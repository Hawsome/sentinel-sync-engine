<?php
// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad(); // safeLoad won't throw exception if .env is missing (e.g. in prod using actual env vars)

define('DB_HOST', isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : (getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1'));
define('DB_PORT', isset($_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : (getenv('DB_PORT') !== false ? getenv('DB_PORT') : '10016'));
define('DB_USER', isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : (getenv('DB_USER') !== false ? getenv('DB_USER') : 'root'));
define('DB_PASS', isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root'));

define('DB_SOURCE', isset($_ENV['DB_SOURCE']) ? $_ENV['DB_SOURCE'] : (getenv('DB_SOURCE') !== false ? getenv('DB_SOURCE') : 'wp_source_db'));
define('DB_DEST',   isset($_ENV['DB_DEST'])   ? $_ENV['DB_DEST']   : (getenv('DB_DEST')   !== false ? getenv('DB_DEST')   : 'accounting_dest_db'));