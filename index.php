<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use OpenCCK\App\Service\HealthService;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Server;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\DatabaseHealthCheck;
use OpenCCK\Infrastructure\Database\Migrator;
use OpenCCK\Infrastructure\Logger\LoggerFactory;

require __DIR__ . '/vendor/autoload.php';

const PATH_ROOT = __DIR__;

Dotenv::createImmutable(PATH_ROOT)->safeLoad();

try {
    $config = Config::fromEnv($_ENV);
} catch (ConfigException $e) {
    fwrite(STDERR, json_encode(['level' => 'CRITICAL', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(78); // EX_CONFIG
}

date_default_timezone_set($config->timezone);
$logger = LoggerFactory::create($config->logLevel);
$version = getenv('APP_VERSION');
$version = is_string($version) && $version !== '' ? $version : 'dev';

$db = Database::pool($config->postgres);
$applied = (new Migrator($db, $logger))->migrate(Migrator::loadDirectory(PATH_ROOT . '/migrations'));
$logger->info('Migrations checked', ['applied' => $applied]);

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);

(new App($logger))
    ->addHandler(fn(App $app): Server => new Server($app->getLogger(), $config->httpHost, $config->httpPort, $health, $version))
    ->run();

$db->close();
