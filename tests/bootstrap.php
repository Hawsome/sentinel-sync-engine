<?php
// Loads the engine without config.php, so no .env is needed to run tests.
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/lib/sync.php';
require_once dirname(__DIR__) . '/src/lib/recovery.php';
require_once dirname(__DIR__) . '/src/lib/ingest.php';
require_once __DIR__ . '/Integration/DatabaseTestCase.php';
