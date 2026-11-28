<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Llm\LlmException;
use Mercurius\Llm\Moonshot\KimiCodeUsage;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\Domain\Agent\ModelRef;

use function Mercurius\I18n\t;

/**
 * /kimi — the owner's Kimi Code subscription as a model provider. /kimi says whether a key of the console is set, the
 * address, how much of each window is used and when it reopens, the Extra Usage wallet and whether the subscription is
 * off the choice; /kimi resume puts it back on the choice before its window reopens (after the owner bought Extra
 * Usage, for instance). The key is a setting (`KIMI_CODE_API_KEY`), so there is no sign-in here.
 *
 * spec: brief/models.md#подписка-kimi-code-условия-и-лимиты
 */
final class KimiCommands {
    private const WINDOWS = ['5h', '7d', 'month', 'month_code'];

    public function __construct(
        private readonly ?KimiCodeUsage $usage,
        private readonly string $baseUrl,
        private readonly ProviderPauses $pauses,
    ) {}

    public function handle(string $argument): string {
        return strtolower(trim($argument)) === 'resume' ? $this->resume() : $this->show();
    }

    private function show(): string {
        if ($this->usage === null) {
            return t('kimi.no_key');
        }
        $lines = [sprintf(t('kimi.state'), $this->baseUrl)];
        try {
            $lines[] = self::quota($this->usage->fetch());
        } catch (LlmException $e) {
            $lines[] = sprintf(t('kimi.quota_unknown'), $e->getMessage());
        }
        $pause = $this->pauses->anyOf(ModelRef::KIMI);
        if ($pause !== null) {
            $until = gmdate('Y-m-d H:i', $pause['until']) . ' UTC';
            $lines[] = $pause['why'] === ProviderPauses::LIMIT ? sprintf(t('kimi.paused_limit'), $until) : sprintf(t('kimi.paused_key'), $until);
        }
        return implode("\n", $lines);
    }

    /** @param array{windows: array<string, array{used: float, resetsAt: ?int}>, extraUsageCents: ?array{left: int, total: int}} $quota */
    private static function quota(array $quota): string {
        $lines = [];
        foreach (self::WINDOWS as $name) {
            $window = $quota['windows'][$name] ?? null;
            if ($window === null) {
                continue;
            }
            $used = (int) round(max(0.0, min(1.0, $window['used'])) * 100.0);
            $lines[] = '• ' . t('kimi.window.' . $name) . ': ' . $used . '%'
                . ($window['resetsAt'] === null ? '' : ' (' . gmdate('Y-m-d H:i', $window['resetsAt']) . ' UTC)');
        }
        $wallet = $quota['extraUsageCents'];
        if ($wallet !== null) {
            $lines[] = sprintf(t('kimi.extra_usage'), $wallet['left'] / 100, $wallet['total'] / 100);
        }
        return $lines === [] ? t('kimi.quota_empty') : implode("\n", $lines);
    }

    private function resume(): string {
        $this->pauses->resume(ModelRef::KIMI);
        return t('kimi.resumed');
    }
}
