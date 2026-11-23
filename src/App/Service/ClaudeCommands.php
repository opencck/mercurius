<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Infrastructure\Llm\Claude\ClaudeSubscription;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

use function Mercurius\I18n\t;

/**
 * /claude — the owner's Claude subscription as a model provider. /claude says how the work goes in (a sign-in or a
 * token of `claude setup-token`), the plan, the windows of the plan, paid use beyond it and the owner's word on it;
 * /claude login gives a link, the owner opens it and sends the code from the page as /claude code <code> — that
 * message leaves the chat; /claude logout removes the sign-in. The models then go by claude:<model>, once the owner
 * puts them into the lists of the categories.
 *
 * spec: brief/models.md#вход-в-подписку-claude
 */
final class ClaudeCommands {
    /**
     * @param Closure(): bool $tokenSet whether a token of `claude setup-token` is set: then the sign-in is not used
     * @param Closure(): string $extraUsage the owner's word about paid use beyond the plan
     * @param ?Closure(): void $signedIn called once the sign-in succeeds: the models of the subscription load at once
     */
    public function __construct(
        private readonly ClaudeSubscription $subscription,
        private readonly Closure $tokenSet,
        private readonly Closure $extraUsage,
        private readonly ProviderPauses $pauses,
        private readonly LoggerInterface $logger,
        private readonly ?Closure $signedIn = null,
    ) {}

    /** Whether the argument carries a secret: the message is removed from the chat. */
    public static function carriesSecret(string $argument): bool {
        return str_starts_with(strtolower(trim($argument)), 'code');
    }

    public function handle(#[SensitiveParameter] string $argument): string {
        $argument = trim($argument);
        $word = strtolower((string) strtok($argument, " \t\n"));
        return match ($word) {
            'login' => $this->login(),
            'code' => $this->code(trim(substr($argument, 4))),
            'logout' => $this->logout(),
            default => $this->show(),
        };
    }

    private function show(): string {
        $status = $this->subscription->status(true);
        if ($status === null) {
            return t('claude.module_down');
        }
        if (!$status['usable']) {
            return sprintf(t('claude.cli_unusable'), $status['detail']);
        }
        $lines = [match ($status['auth']) {
            'token' => sprintf(t('claude.auth_token'), $status['plan'] ?: '—'),
            'login' => sprintf(t('claude.auth_login'), $status['plan'] ?: '—'),
            'metered' => t('claude.auth_metered'),
            default => $status['pending'] ? t('claude.auth_pending') : t('claude.auth_none'),
        }];
        if ($status['auth'] === 'token') {
            $lines[] = t('claude.token_wins');
        }
        if (in_array($status['auth'], ['login', 'token'], true)) {
            $usage = $this->subscription->usage();
            $lines[] = $usage === null ? t('claude.usage_unknown') : self::usage($usage);
        }
        $lines[] = sprintf(t('claude.extra_usage_policy'), ($this->extraUsage)());
        $pause = $this->pauses->of(ModelRef::CLAUDE);
        if ($pause !== null) {
            $lines[] = sprintf(t($pause['why'] === ProviderPauses::LIMIT ? 'claude.paused_limit' : 'claude.paused_sign_in'), gmdate('Y-m-d H:i', $pause['until']) . ' UTC');
        }
        return implode("\n", $lines);
    }

    /** @param array{limits: array<array-key, mixed>, using: bool, resetsAt: ?int} $usage */
    private static function usage(array $usage): string {
        $parts = [];
        /** @var mixed $entries */
        $entries = $usage['limits']['limits'] ?? null;
        /** @var mixed $entry */
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            /** @var mixed $kind */
            $kind = $entry['kind'] ?? null;
            /** @var mixed $percent */
            $percent = $entry['percent'] ?? null;
            /** @var mixed $resets */
            $resets = $entry['resets_at'] ?? null;
            if (is_string($kind) && (is_int($percent) || is_float($percent))) {
                $parts[] = sprintf('%s %d%%', $kind, (int) round((float) $percent)) . (is_string($resets) && $resets !== '' ? ' (' . $resets . ')' : '');
            }
        }
        // the older answer names the windows of the account with a fraction of 0–1
        foreach ($parts === [] ? ['five_hour', 'seven_day'] : [] as $window) {
            /** @var mixed $data */
            $data = $usage['limits'][$window] ?? null;
            /** @var mixed $used */
            $used = is_array($data) ? ($data['utilization'] ?? null) : null;
            if (is_int($used) || is_float($used)) {
                $used = (float) $used;
                $parts[] = sprintf('%s %d%%', $window, (int) round($used <= 1.0 ? $used * 100.0 : $used));
            }
        }
        $line = sprintf(t('claude.usage'), $parts === [] ? '—' : implode(', ', $parts));
        if ($usage['using']) {
            $line .= "\n" . sprintf(t('claude.extra_usage_on'), $usage['resetsAt'] === null ? '—' : gmdate('Y-m-d H:i', $usage['resetsAt']) . ' UTC');
        }
        return $line;
    }

    private function login(): string {
        try {
            $url = $this->subscription->login();
        } catch (Throwable $e) {
            $this->logger->warning('Claude sign-in not started', ['error' => $e::class]);
            return $this->subscription->status(true) === null ? t('claude.module_down') : t('claude.failed');
        }
        $hint = ($this->tokenSet)() ? "\n" . t('claude.token_wins') : '';
        return sprintf(t('claude.login'), $url) . $hint;
    }

    private function code(#[SensitiveParameter] string $code): string {
        if ($code === '') {
            return t('claude.code_empty');
        }
        if (!$this->subscription->code($code)) {
            return t('claude.code_refused');
        }
        $this->pauses->resume(ModelRef::CLAUDE);
        if ($this->signedIn !== null) {
            try {
                ($this->signedIn)();
            } catch (Throwable $e) {
                $this->logger->warning('The models of the Claude subscription did not load', ['error' => $e::class]);
            }
        }
        return t('claude.done');
    }

    private function logout(): string {
        $this->subscription->logout();
        return t('claude.signed_out');
    }
}
