<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads the autoloader and minimal Cake bootstrap (without DB connection).
 * Tests must remain pure-unit — no DB queries, no fixtures.
 */

use Cake\Core\Configure;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
if (!defined('APP')) {
    define('APP', ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG')) {
    define('CONFIG', ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
}
if (!defined('TESTS')) {
    define('TESTS', ROOT . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR);
}
if (!defined('TMP')) {
    define('TMP', ROOT . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR);
}
if (!defined('LOGS')) {
    define('LOGS', ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);
}
if (!defined('CACHE')) {
    define('CACHE', TMP . 'cache' . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'App',
    'paths' => [
        'templates' => [ROOT . DS . 'templates' . DS],
    ],
]);
