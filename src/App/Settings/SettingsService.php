<?php

declare(strict_types=1);

namespace OpenCCK\App\Settings;

use Closure;
use DomainException;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\SettingRepository;
use SensitiveParameter;

/**
 * The settings of the agent: stored values over .env. A value is checked by the same checks as at the start before it
 * is kept, so a bad one cannot stop the agent; it takes effect after a restart. Secret values live in the vault.
 *
 * spec: BRIEF.md#настройки-агента
 */
final class SettingsService {
    public const VAULT_PREFIX = 'setting.';

    /**
     * @param array<array-key, mixed> $env .env as read at the start
     * @param Closure(array<array-key, mixed>): ?string $validate the start checks; a message when the settings would not start
     * @param Closure(): ?Principal $owner the reader of secret values
     */
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly Vault $vault,
        private readonly array $env,
        private readonly Closure $validate,
        private readonly Closure $owner,
    ) {}

    /**
     * .env with the stored settings over it.
     *
     * @return array<array-key, mixed>
     */
    public function overlay(): array {
        $env = $this->env;
        $owner = ($this->owner)();
        foreach ($this->settings->all() as $key => $setting) {
            if (!SettingsCatalog::has($key)) {
                continue;
            }
            if ($setting['secret']) {
                $value = $owner === null ? null : $this->vault->reveal(self::VAULT_PREFIX . $key, $owner);
                if ($value !== null) {
                    $env[$key] = $value;
                }
            } else {
                $env[$key] = $setting['value'] ?? '';
            }
        }
        return $env;
    }

    /** @return list<array{key: string, group: string, about: string, value: string, source: string}> secrets are never shown */
    public function list(): array {
        $stored = $this->settings->all();
        $out = [];
        foreach (SettingsCatalog::ALL as $key => [$group, $kind, $about]) {
            $raw = isset($stored[$key]) ? ($stored[$key]['secret'] ? '***' : ($stored[$key]['value'] ?? '')) : (isset($this->env[$key]) && is_string($this->env[$key]) ? $this->env[$key] : '');
            $shown = $raw === '' ? '—' : ($kind === SettingsCatalog::SECRET ? 'задан' : $raw);
            $out[] = ['key' => $key, 'group' => $group, 'about' => $about, 'value' => $shown, 'source' => isset($stored[$key]) ? 'диалог' : ($raw === '' ? 'по умолчанию' : '.env')];
        }
        return $out;
    }

    public function text(?string $group = null): string {
        $lines = [];
        $last = null;
        foreach ($this->list() as $s) {
            if ($group !== null && $s['group'] !== $group) {
                continue;
            }
            if ($s['group'] !== $last) {
                $lines[] = ($last === null ? '' : "\n") . mb_strtoupper(mb_substr($s['group'], 0, 1)) . mb_substr($s['group'], 1) . ':';
                $last = $s['group'];
            }
            $lines[] = sprintf('%s = %s (%s) — %s', $s['key'], $s['value'], $s['source'], $s['about']);
        }
        return $lines === [] ? 'Таких настроек нет.' : implode("\n", $lines);
    }

    /**
     * Keeps a value (empty — back to .env) after the start checks pass with it.
     *
     * @throws DomainException with what is wrong
     */
    public function set(Principal $who, string $key, #[SensitiveParameter] string $value): string {
        $key = strtoupper(trim($key));
        $value = trim($value);
        $problem = SettingsCatalog::problem($key, $value);
        if ($problem !== null) {
            throw new DomainException($problem);
        }
        $candidate = $this->overlay();
        if ($value === '') {
            unset($candidate[$key]);
            if (isset($this->env[$key]) && is_string($this->env[$key])) {
                $candidate[$key] = $this->env[$key];
            }
        } else {
            $candidate[$key] = $value;
        }
        $failure = ($this->validate)($candidate);
        if ($failure !== null) {
            throw new DomainException('Не сохранено: с этим значением агент не запустится — ' . $failure);
        }
        if ($value === '') {
            $this->settings->delete($key);
            $this->vault->remove(self::VAULT_PREFIX . $key, $who);
            return sprintf('%s сброшена к значению из .env. Применится после перезапуска (/restart).', $key);
        }
        if (SettingsCatalog::secret($key)) {
            if (!$this->vault->enabled()) {
                throw new DomainException('Хранилище секретов выключено: в .env нет SECRETS_KEY.');
            }
            $this->vault->remove(self::VAULT_PREFIX . $key, $who);
            $this->vault->put(self::VAULT_PREFIX . $key, 'token', $value, $who);
            $this->settings->set($key, null, true, $who->id);
        } else {
            $this->settings->set($key, $value, false, $who->id);
        }
        return sprintf('%s сохранена. Применится после перезапуска (/restart).', $key);
    }

    /** A secret setting takes the value of a vault secret the owner put with /secret: the value never enters the dialog. */
    public function setFromSecret(Principal $who, string $key, string $secretName): string {
        $value = $this->vault->reveal($secretName, $who);
        if ($value === null) {
            throw new DomainException(sprintf('Секрета «%s» нет: владелец кладёт его командой /secret %s <значение>.', $secretName, $secretName));
        }
        return $this->set($who, $key, $value);
    }
}
