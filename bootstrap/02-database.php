<?php

declare(strict_types=1);

/**
 * An import the owner confirmed, the database and its migrations, participants, the vault, settings over .env, the language.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\Telegram\TelegramChannel;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Infrastructure\Config\AgentSettings;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Config\MemorySettings;
use OpenCCK\Infrastructure\Config\SandboxSettings;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use OpenCCK\Infrastructure\Config\TraceSettings;
use OpenCCK\Infrastructure\Config\VaultSettings;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\Migrator;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Repository\PostgresMemberRepository;
use OpenCCK\Infrastructure\Repository\PostgresSecretRepository;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\Infrastructure\Config\Config $config
 * @var Monolog\Logger $logger
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\Infrastructure\Config\VaultSettings $vaultSettings
 */

// spec: brief/operations.md#экспорт-и-импорт-агента — an import the owner confirmed is applied before the database is used
$postgresAccess = ['host' => $config->postgres->host, 'port' => $config->postgres->port, 'user' => $config->postgres->user, 'password' => $config->postgres->password(), 'name' => $config->postgres->database];
$agentImport = new \OpenCCK\App\Export\AgentImport(\OpenCCK\App\Export\ProcessRunner::closure(), $postgresAccess, PATH_ROOT . '/storage');
$imported = null;
$importFailed = false;
try {
    $imported = $agentImport->apply();
    if ($imported !== null) {
        $logger->notice('Import applied', ['commit' => $imported['commit']]);
    }
} catch (Throwable $e) {
    $importFailed = true;
    $logger->critical('Import not applied', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
}
// the vault key an import brought: the secrets of its dump are sealed by it
$importedKey = \OpenCCK\App\Export\AgentImport::secretsKey(PATH_ROOT . '/storage');
if ($importedKey !== null && $importedKey !== ($_ENV['SECRETS_KEY'] ?? null)) {
    SecretMasker::remember($importedKey);
    try {
        $vaultSettings = VaultSettings::fromEnv(['SECRETS_KEY' => $importedKey] + $_ENV);
        $logger->notice('The vault key of an imported archive is used instead of SECRETS_KEY');
    } catch (ConfigException $e) {
        $logger->error('The vault key of an imported archive is not valid', ['error' => $e->getMessage()]);
    }
}
$db = Database::pool($config->postgres);
$applied = (new Migrator($db, $logger))->migrate(Migrator::loadDirectories(...\OpenCCK\Infrastructure\Database\MigrationFolders::of(PATH_ROOT)));
$logger->info('Migrations checked', ['applied' => $applied]);

// spec: brief/operations.md#настройки-агента — settings from the dialog over .env; a stored value is checked by the start checks
// when it is kept, and once more here: if they fail, the agent starts on .env and says why.
$principals = new PostgresMemberRepository($db, TelegramChannel::NAME);
// spec: brief/participants.md#несколько-пользователей — TELEGRAM_ADMIN_ID is the first owner; ownership may pass on, the owner is asked here
$ownerDirectory = new \OpenCCK\App\Service\OwnerDirectory($principals, TelegramChannel::NAME, $telegram->ownerId);
// spec: brief/participants.md#несколько-пользователей — the owner reads every secret but a former owner's
$vault = new Vault(new PostgresSecretRepository($db), $vaultSettings->key(), hidden: $ownerDirectory->isFormerOwner(...));
$restarter = new \OpenCCK\App\Settings\Restarter();
$validateSettings = static function (array $env): ?string {
    try {
        $checked = Config::fromEnv($env);
        TelegramSettings::fromEnv($env, $checked->appUrl);
        LlmSettings::fromEnv($env);
        AgentSettings::fromEnv($env);
        SandboxSettings::fromEnv($env);
        TraceSettings::fromEnv($env);
        MemorySettings::fromEnv($env);
        VaultSettings::fromEnv($env);
        \OpenCCK\Infrastructure\Config\MediaSettings::fromEnv($env);
        \OpenCCK\Infrastructure\Config\SearchSettings::build($env, HttpClients::create(), \OpenCCK\Infrastructure\Config\Env::raw($env, 'OPENROUTER_API_KEY'));
        try {
            new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($env, 'OWNER_TIMEZONE') ?? '+03:00');
        } catch (Throwable) {
            return 'OWNER_TIMEZONE: неизвестный часовой пояс — пример: Europe/Moscow или +03:00';
        }
        $quiet = \OpenCCK\Infrastructure\Config\Env::raw($env, 'QUIET_HOURS');
        if ($quiet !== null && preg_match('~^([01]?\d|2[0-3])-([01]?\d|2[0-3])$~', $quiet) !== 1) {
            return 'QUIET_HOURS: «с-до» в часах, например 23-8';
        }
    } catch (ConfigException $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return $e::class;
    }
    return null;
};
$settingsService = new \OpenCCK\App\Settings\SettingsService(
    new \OpenCCK\Infrastructure\Repository\PostgresSettingRepository($db),
    $vault,
    $_ENV,
    $validateSettings,
    static fn(): ?Member => $ownerDirectory->principal(),
);
$overlay = $settingsService->overlay();
// spec: brief/operations.md#настройки-агента — a stored key the release no longer has is not applied; it is named
if (($outdated = $settingsService->unknown()) !== []) {
    $logger->warning('Stored settings this version does not know', ['keys' => $outdated]);
}
$settingsProblem = $validateSettings($overlay);
if ($settingsProblem === null) {
    $_ENV = $overlay;
} else {
    $logger->error('Stored settings ignored: the agent starts on .env', ['problem' => $settingsProblem]);
}
// spec: brief/operations.md#язык-агента — the language of everything the agent writes itself; Russian by default
/** @var mixed $agentLanguage */
$agentLanguage = $_ENV['AGENT_LANGUAGE'] ?? 'ru';
\Mercurius\I18n\Lang::use(new \Mercurius\I18n\CatalogTranslator(is_string($agentLanguage) ? $agentLanguage : 'ru', \Mercurius\I18n\Catalogs::bundled()));
$logger->info('Agent language', ['language' => \Mercurius\I18n\Lang::current()->language()]);
try {
    $llmSettings = LlmSettings::fromEnv($_ENV);
    $agentSettings = AgentSettings::fromEnv($_ENV);
    $sandboxSettings = SandboxSettings::fromEnv($_ENV);
    $traceSettings = TraceSettings::fromEnv($_ENV);
    $memorySettings = MemorySettings::fromEnv($_ENV);
} catch (ConfigException $e) {
    $logger->critical('Configuration error', ['message' => $e->getMessage()]);
    exit(78); // EX_CONFIG
}
