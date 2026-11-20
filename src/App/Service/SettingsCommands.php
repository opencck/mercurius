<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Settings\Restarter;
use OpenCCK\App\Settings\SettingsCatalog;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\Domain\Entity\Principal;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * The owner's commands for settings, without a model: /settings, /set <key> <value>, /restart. They work even when
 * the models are not set up yet.
 *
 * spec: brief/operations.md#трассировка-и-метрики
 */
final class SettingsCommands {
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Restarter $restarter,
    ) {}

    public function show(string $argument): string {
        $group = trim($argument);
        return $this->settings->text($group === '' ? null : $group) . t('service.settings_commands.set_klyuch_znachenie_izmenit_pusto');
    }

    /** Whether the message carried a secret value, so it must leave the chat. */
    public static function carriesSecret(string $argument): bool {
        $parts = preg_split('~\s+~', trim($argument), 2) ?: [];
        return count($parts) === 2 && SettingsCatalog::secret(strtoupper($parts[0]));
    }

    public function set(Principal $who, #[SensitiveParameter] string $argument): string {
        $parts = preg_split('~\s+~', trim($argument), 2) ?: [];
        if (($parts[0] ?? '') === '') {
            return t('service.settings_commands.set_klyuch_znachenie_naprimer_set');
        }
        try {
            return $this->settings->set($who, $parts[0], $parts[1] ?? '');
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }

    public function restart(): string {
        $this->restarter->request();
        return t('service.settings_commands.perezapuskayus_nezakonchennye_zadachi_pr');
    }
}
