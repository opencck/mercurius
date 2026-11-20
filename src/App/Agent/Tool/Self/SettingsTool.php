<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Settings\Restarter;
use OpenCCK\App\Settings\SettingsCatalog;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent reads and changes its settings in the dialog with the owner. A change and a restart wait for the owner's
 * card; a secret setting takes a vault secret by name, so its value never enters the dialog.
 *
 * spec: brief/operations.md#настройки-агента
 */
final class SettingsTool implements Tool, GrantKeyed {
    public const NAME = 'settings';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Restarter $restarter,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.settings.nastroyki_agenta_modeli_i_klyuchi')
            . t('tool.settings.group_odna_gruppa_set_key')
            . t('tool.settings.vmesto_value_secret_imya_sekreta')
            . t('tool.settings.restart_perezapustit_agenta_chtoby_izmen'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'set', 'restart']],
                'group' => ['type' => 'string', 'maxLength' => 40],
                'key' => ['type' => 'string', 'enum' => array_keys(SettingsCatalog::ALL)],
                'value' => ['type' => 'string', 'maxLength' => 1000],
                'secret' => ['type' => 'string', 'maxLength' => 64],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'list' ? DangerClass::Read : DangerClass::Irreversible;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target
     */
    public function grantKey(array $args): ?string {
        return match (Args::string($args, 'action')) {
            'set' => ($key = Args::string($args, 'key')) === null ? null : 'settings:set:' . $key,
            'restart' => 'settings:restart',
            default => null,
        };
    }

    public function describe(array $args): string {
        $secret = Args::string($args, 'secret');
        return trim(sprintf('settings %s %s %s', Args::string($args, 'action') ?? '', Args::string($args, 'key') ?? '', $secret !== null ? '← ' . $secret : mb_substr(Args::string($args, 'value') ?? '', 0, 200)));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.settings.nastroyki_menyaet_tolko_vladelets'));
        }
        $action = Args::string($args, 'action') ?? 'list';
        if ($action === 'list') {
            return ToolResult::ok($this->settings->text(Args::string($args, 'group')));
        }
        if ($action === 'restart') {
            $this->restarter->request();
            return ToolResult::ok(t('tool.settings.perezapuskayus_nezakonchennye_zadachi_pr'));
        }
        $key = Args::string($args, 'key') ?? '';
        $secret = Args::string($args, 'secret');
        try {
            if (SettingsCatalog::secret($key)) {
                if (Args::string($args, 'value') !== null && Args::string($args, 'value') !== '') {
                    return ToolResult::error(sprintf(t('tool.settings.sekret_vladelets_kladet_ego_komandoy'), $key, $key));
                }
                return ToolResult::ok($secret === null ? $this->settings->set($context->principal, $key, '') : $this->settings->setFromSecret($context->principal, $key, $secret));
            }
            return ToolResult::ok($this->settings->set($context->principal, $key, Args::string($args, 'value') ?? ''));
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
