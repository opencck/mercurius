<?php

declare(strict_types=1);

/**
 * The process: .env, the start checks of the config, Telegram and the vault key, the log, the version.
 *
 * A part of the start of the agent: index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Dotenv\Dotenv;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use OpenCCK\Infrastructure\Config\VaultSettings;
use OpenCCK\Infrastructure\Logger\LoggerFactory;
use Revolt\EventLoop;

Dotenv::createImmutable(PATH_ROOT)->safeLoad();
// spec: brief/operations.md#решено-в-фазе-4 — the memory package takes its texts from the agent's catalogs
\Mercurius\Memory\Texts::translateWith(static fn(string $key): string => \Mercurius\I18n\t($key));
\Mercurius\Peer\Texts::translateWith(static fn(string $key): string => \Mercurius\I18n\t($key));

// What the start needs before the database: the process, Telegram, the vault key. The rest may come from the
// settings the owner keeps in the dialog (over .env).
try {
    $config = Config::fromEnv($_ENV);
    $telegram = TelegramSettings::fromEnv($_ENV, $config->appUrl);
    $vaultSettings = VaultSettings::fromEnv($_ENV);
} catch (ConfigException $e) {
    fwrite(STDERR, json_encode(['level' => 'CRITICAL', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(78); // EX_CONFIG
}

date_default_timezone_set($config->timezone);
// spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — the secrets of the settings are masked by their values everywhere
foreach ($_ENV as $name => $value) {
    if (is_string($value) && preg_match('~(TOKEN|_KEY|SECRET|PASSWORD|_HASH|_URI)$~', $name) === 1) {
        SecretMasker::remember($value);
    }
}
$logger = LoggerFactory::create($config->logLevel);
// An error escaping a fiber is logged instead of stopping the whole process.
EventLoop::setErrorHandler(static fn(Throwable $e) => $logger->error('Unhandled error in the event loop', ['error' => $e::class]));
$version = getenv('APP_VERSION');
$version = is_string($version) && $version !== '' ? $version : 'dev';
