<?php

declare(strict_types=1);

/**
 * Checks an installation without starting the agent: settings, the database and migrations, the disk, the sandbox
 * and the browser. Exit code 1 on an error.
 *
 *   docker compose run --rm --no-deps app php bin/doctor.php   (or exec app, when it runs)
 *
 * spec: brief/operations.md#как-сделано-поставка
 */

use Dotenv\Dotenv;
use OpenCCK\App\Service\Doctor;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Http\HttpClients;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = array_merge($_ENV, getenv());
try {
    $db = Database::connect(Config::fromEnv($env)->postgres);
} catch (Throwable $e) {
    fwrite(STDERR, 'База: ' . $e::class . "\n");
    $db = null;
}
$audit = $db === null ? null : new \OpenCCK\Infrastructure\Repository\PostgresCommandAuditRepository($db);
$unknown = $db === null ? null : static fn(): array => array_values(array_filter(
    array_map('strval', array_keys((new \OpenCCK\Infrastructure\Repository\PostgresSettingRepository($db))->all())),
    static fn(string $key): bool => !\OpenCCK\App\Settings\SettingsCatalog::has($key),
));
$results = (new Doctor($env, $db, $root . '/migrations', $root . '/storage', HttpClients::create(), $audit, $unknown))->run();
echo Doctor::render($results), "\n";
exit(array_filter($results, static fn(array $r): bool => $r[0] === Doctor::FAIL) === [] ? 0 : 1);
