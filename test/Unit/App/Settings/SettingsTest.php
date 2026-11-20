<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Settings;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use OpenCCK\App\Agent\Tool\Self\SettingsTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\SettingsCommands;
use OpenCCK\App\Settings\Restarter;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemorySettingRepository;

final class SettingsTest extends AsyncTestCase {
    private InMemorySettingRepository $store;
    private Vault $vault;
    private Principal $owner;
    private SettingsService $settings;

    protected function setUp(): void {
        parent::setUp();
        $this->store = new InMemorySettingRepository();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $principals = new InMemoryPrincipalRepository();
        $this->owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $owner = $this->owner;
        $this->settings = new SettingsService(
            $this->store,
            $this->vault,
            ['OPENROUTER_API_KEY' => 'sk-env', 'QUIET_HOURS' => '23-8', 'TELEGRAM_BOT_TOKEN' => 't'],
            // the start checks: a bad zone does not start
            static fn(array $env): ?string => isset($env['OWNER_TIMEZONE']) && $env['OWNER_TIMEZONE'] === 'Mars/Base' ? 'OWNER_TIMEZONE is invalid' : null,
            static fn(): Principal => $owner,
        );
    }

    public function testStoredSettingsGoOverEnvAndSecretsLiveInTheVault(): void {
        $this->settings->set($this->owner, 'QUIET_HOURS', '0-7');
        $this->settings->set($this->owner, 'brave_search_api_key', 'brave-secret');

        $env = $this->settings->overlay();
        self::assertSame('0-7', $env['QUIET_HOURS']);
        self::assertSame('brave-secret', $env['BRAVE_SEARCH_API_KEY']);
        self::assertSame('sk-env', $env['OPENROUTER_API_KEY'], '.env stays the base');
        self::assertSame(['value' => null, 'secret' => true], $this->store->settings['BRAVE_SEARCH_API_KEY']);
        self::assertSame('brave-secret', $this->vault->reveal('setting.BRAVE_SEARCH_API_KEY', $this->owner));

        $text = $this->settings->text();
        self::assertStringContainsString('BRAVE_SEARCH_API_KEY = задан (диалог)', $text);
        self::assertStringContainsString('OPENROUTER_API_KEY = задан (.env)', $text);
        self::assertStringContainsString('QUIET_HOURS = 0-7 (диалог)', $text);
        self::assertStringNotContainsString('brave-secret', $text);
        self::assertStringNotContainsString('sk-env', $text);
    }

    public function testAnUnsetLimitShowsTheValueInEffect(): void {
        $text = $this->settings->text();
        // spec: brief/operations.md#трассировка-и-метрики — the agent sees the number it works with, so it can double it
        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — the default limits: 750 turns, 1 million tokens, 8 hours
        self::assertStringContainsString('AGENT_TOKEN_BUDGET = 1000000 (по умолчанию)', $text);
        self::assertStringContainsString('AGENT_MAX_TURNS = 750 (по умолчанию)', $text);
        self::assertStringContainsString('AGENT_RUN_TIMEOUT = 28800 (по умолчанию)', $text);
        self::assertStringContainsString('AGENT_WORKER_MAX_TURNS = 375 (по умолчанию)', $text);
        self::assertStringContainsString('SEARXNG_URL = — (по умолчанию)', $text, 'a setting without a default stays empty');
    }

    public function testTheDefaultsShownAreTheOnesTheAgentStartsWith(): void {
        $agent = \OpenCCK\Infrastructure\Config\AgentSettings::fromEnv([]);
        $defaults = \OpenCCK\App\Settings\SettingsCatalog::DEFAULTS;
        self::assertSame((string) $agent->maxTurns, $defaults['AGENT_MAX_TURNS']);
        self::assertSame((string) $agent->tokenBudget, $defaults['AGENT_TOKEN_BUDGET']);
        self::assertSame((string) $agent->runTimeout, $defaults['AGENT_RUN_TIMEOUT']);
        self::assertSame((string) $agent->toolTimeout, $defaults['AGENT_TOOL_TIMEOUT']);
        self::assertSame((string) $agent->approvalTimeout, $defaults['AGENT_APPROVAL_TIMEOUT']);
        self::assertSame((string) $agent->workerMaxTurns, $defaults['AGENT_WORKER_MAX_TURNS']);
        self::assertSame((string) $agent->workerTokenBudget, $defaults['AGENT_WORKER_TOKEN_BUDGET']);
        self::assertSame((string) $agent->maxWorkersPerRun, $defaults['AGENT_MAX_WORKERS_PER_RUN']);
        foreach (array_keys($defaults) as $key) {
            self::assertTrue(\OpenCCK\App\Settings\SettingsCatalog::has($key), $key);
        }
    }

    public function testAValueThatWouldStopTheStartIsRefusedAndEmptyGoesBackToEnv(): void {
        try {
            $this->settings->set($this->owner, 'OWNER_TIMEZONE', 'Mars/Base');
            self::fail('a bad zone is refused');
        } catch (DomainException $e) {
            self::assertStringContainsString('агент не запустится', $e->getMessage());
        }
        self::assertSame([], $this->store->settings);

        foreach ([['NO_SUCH', 'x'], ['AGENT_MAX_TURNS', 'много'], ['MEMORY_HRR_WEIGHT', 'half']] as [$key, $value]) {
            try {
                $this->settings->set($this->owner, $key, $value);
                self::fail($key);
            } catch (DomainException) {
            }
        }

        $this->settings->set($this->owner, 'QUIET_HOURS', '0-7');
        self::assertStringContainsString('сброшена', $this->settings->set($this->owner, 'QUIET_HOURS', ''));
        self::assertSame('23-8', $this->settings->overlay()['QUIET_HOURS']);
    }

    public function testTheToolTakesSecretsByNameAndWaitsForTheOwner(): void {
        $restarter = new Restarter();
        $tool = new SettingsTool($this->settings, $restarter);
        $context = new ToolContext(1, 1, $this->owner, new NullCancellation());

        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'list']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'set']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'restart']));

        self::assertTrue($tool->execute(['action' => 'set', 'key' => 'TAVILY_API_KEY', 'value' => 'tvly-in-dialog'], $context)->isError, 'a key never goes through the dialog');
        self::assertTrue($tool->execute(['action' => 'set', 'key' => 'TAVILY_API_KEY', 'secret' => 'missing'], $context)->isError);
        $this->vault->put('tavily', 'token', 'tvly-value', $this->owner);
        self::assertFalse($tool->execute(['action' => 'set', 'key' => 'TAVILY_API_KEY', 'secret' => 'tavily'], $context)->isError);
        self::assertSame('tvly-value', $this->settings->overlay()['TAVILY_API_KEY']);

        $guest = (new InMemoryPrincipalRepository())->ensureOwner('telegram', '43', 'x');
        $notOwner = new Principal($guest->id + 1, $guest->kind, \OpenCCK\Domain\Entity\Role::user(), 'Гость');
        self::assertTrue($tool->execute(['action' => 'list'], new ToolContext(1, 1, $notOwner, new NullCancellation()))->isError);

        $tool->execute(['action' => 'restart'], $context);
        \Amp\delay(1.1);
        self::assertTrue($restarter->requested()->isComplete(), 'the restart comes a second later');
    }

    public function testACommandWithAKeyIsKnownToCarryASecret(): void {
        self::assertTrue(SettingsCommands::carriesSecret(' openai_api_key sk-xyz'));
        self::assertFalse(SettingsCommands::carriesSecret('OPENAI_API_KEY'));
        self::assertFalse(SettingsCommands::carriesSecret('QUIET_HOURS 0-7'));
        $commands = new SettingsCommands($this->settings, new Restarter());
        self::assertStringContainsString('QUIET_HOURS сохранена', $commands->set($this->owner, 'QUIET_HOURS 0-7'));
        self::assertStringContainsString('/set <ключ> <значение>', $commands->show('время'));
    }

    public function testAStoredKeyNoLongerKnownIsNamedAndCanBeRemoved(): void {
        // spec: brief/operations.md#трассировка-и-метрики — a key renamed or removed in a release is not applied and not hidden
        $this->store->set('OLD_LIMIT', '5', false, null);
        $this->settings->set($this->owner, 'QUIET_HOURS', '0-7');

        self::assertSame(['OLD_LIMIT'], $this->settings->unknown());
        self::assertArrayNotHasKey('OLD_LIMIT', $this->settings->overlay(), 'an unknown key is not applied');

        self::assertSame(sprintf(\Mercurius\I18n\t('settings.unknown_removed'), 'OLD_LIMIT'), $this->settings->set($this->owner, 'old_limit', ''));
        self::assertSame([], $this->settings->unknown());
        self::assertArrayHasKey('QUIET_HOURS', $this->store->all(), 'the known ones stay');
    }
}
