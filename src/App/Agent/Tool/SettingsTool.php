<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Settings\Restarter;
use OpenCCK\App\Settings\SettingsCatalog;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * The agent reads and changes its settings in the dialog with the owner. A change and a restart wait for the owner's
 * card; a secret setting takes a vault secret by name, so its value never enters the dialog.
 *
 * spec: BRIEF.md#настройки-агента
 */
final class SettingsTool implements Tool {
    public const NAME = 'settings';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Restarter $restarter,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Настройки агента: модели и ключи, лимиты, поиск, время, память. action: list — показать '
            . '(group — одна группа); set — key и value (пустое value — вернуть значение из .env); для секретных настроек (ключи) '
            . 'вместо value — secret: имя секрета, который владелец сохранил командой /secret <имя> <значение>, сами ключи в диалог не пиши; '
            . 'restart — перезапустить агента, чтобы изменения вступили в силу. set и restart ждут подтверждения владельца.', [
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

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error('Настройки меняет только владелец.');
        }
        $action = Args::string($args, 'action') ?? 'list';
        if ($action === 'list') {
            return ToolResult::ok($this->settings->text(Args::string($args, 'group')));
        }
        if ($action === 'restart') {
            $this->restarter->request();
            return ToolResult::ok('Перезапускаюсь: незаконченные задачи продолжатся после старта.');
        }
        $key = Args::string($args, 'key') ?? '';
        $secret = Args::string($args, 'secret');
        try {
            if (SettingsCatalog::secret($key)) {
                if (Args::string($args, 'value') !== null && Args::string($args, 'value') !== '') {
                    return ToolResult::error(sprintf('%s — секрет: владелец кладёт его командой /secret <имя> <значение> или /set %s <значение>, а сюда передай имя в secret.', $key, $key));
                }
                return ToolResult::ok($secret === null ? $this->settings->set($context->principal, $key, '') : $this->settings->setFromSecret($context->principal, $key, $secret));
            }
            return ToolResult::ok($this->settings->set($context->principal, $key, Args::string($args, 'value') ?? ''));
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
