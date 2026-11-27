<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Infrastructure\Llm\Antigravity\AntigravitySubscription;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

use function Mercurius\I18n\t;

/**
 * /antigravity — the owner's Google Antigravity plan as a model provider. /antigravity says whether the plan is
 * signed in, the quota by groups and what is off the choice; /antigravity login first tells what Google's terms say
 * about such use and goes on only after `/antigravity login accept`; the link it gives is opened by the owner, and
 * the code from the page comes back as /antigravity code <code> — that message leaves the chat;
 * /antigravity resume puts the models back on the choice after the owner did what Google asked. The CLI has no
 * command to sign out. The models then go by antigravity:<model>, once the owner puts them into the lists of the
 * categories.
 *
 * spec: brief/models.md#google-по-подписке
 */
final class AntigravityCommands {
    /** Where the terms that forbid this use are written. */
    public const TERMS = 'https://antigravity.google/terms';

    /**
     * @param ?Closure(): void $signedIn called once the sign-in succeeds: the models of the plan load at once
     */
    public function __construct(
        private readonly AntigravitySubscription $subscription,
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
            'login' => $this->login(strtolower(trim(substr($argument, 5))) === 'accept'),
            'code' => $this->code(trim(substr($argument, 4))),
            'resume' => $this->resume(),
            'logout' => $this->logout(),
            default => $this->show(),
        };
    }

    private function show(): string {
        $status = $this->subscription->status(true);
        if ($status === null) {
            return t('antigravity.module_down');
        }
        if (!$status['usable']) {
            return sprintf(t('antigravity.cli_unusable'), $status['detail']);
        }
        if ($status['auth'] !== 'login') {
            return $status['pending'] ? t('antigravity.auth_pending') : t('antigravity.auth_none');
        }
        $lines = [sprintf(t('antigravity.auth_login'), $status['cli'] ?: '—')];
        $quota = $this->subscription->quota();
        $lines[] = $quota === null ? t('antigravity.quota_unknown') : self::quota($quota);
        $pause = $this->pauses->anyOf(ModelRef::ANTIGRAVITY);
        if ($pause !== null) {
            $until = gmdate('Y-m-d H:i', $pause['until']) . ' UTC';
            $lines[] = match ($pause['why']) {
                ProviderPauses::LIMIT => sprintf(t('antigravity.paused_limit'), $until),
                ProviderPauses::ACCOUNT => t('antigravity.paused_account'),
                default => sprintf(t('antigravity.paused_sign_in'), $until),
            };
        }
        return implode("\n", $lines);
    }

    /** @param array{groups: list<array{name: string, buckets: list<array{window: string, remaining: float, resets_at: ?int}>}>, stale: bool} $quota */
    private static function quota(array $quota): string {
        $lines = [];
        foreach ($quota['groups'] as $group) {
            $parts = [];
            foreach ($group['buckets'] as $bucket) {
                $used = (int) round((1.0 - max(0.0, min(1.0, $bucket['remaining']))) * 100.0);
                $parts[] = sprintf('%s %d%%', $bucket['window'] === '' ? '—' : $bucket['window'], $used)
                    . ($bucket['resets_at'] === null ? '' : ' (' . gmdate('Y-m-d H:i', $bucket['resets_at']) . ' UTC)');
            }
            // the name of a group is the CLI's own word: only its plain characters are shown
            $name = trim(preg_replace('~[^\p{L}\p{N} ._-]~u', '', $group['name']) ?? '');
            $lines[] = '• ' . ($name === '' ? '—' : $name) . ': ' . ($parts === [] ? '—' : implode(', ', $parts));
        }
        return t('antigravity.quota') . "\n" . ($lines === [] ? '—' : implode("\n", $lines)) . ($quota['stale'] ? "\n" . t('antigravity.quota_stale') : '');
    }

    private function login(bool $accepted): string {
        if (!$accepted) {
            // spec: brief/models.md#google-по-подписке — the owner reads what the terms say before the first link
            return sprintf(t('antigravity.warning'), self::TERMS);
        }
        // spec: brief/models.md#google-по-подписке — a plan signed in already gives no link: the owner signs out first
        if ($this->subscription->signedIn()) {
            return t('antigravity.login_again');
        }
        try {
            [$url, $wait] = $this->subscription->login();
        } catch (Throwable $e) {
            $this->logger->warning('Antigravity sign-in not started', ['error' => $e::class]);
            return $this->subscription->status(true) === null ? t('antigravity.module_down') : t('antigravity.failed');
        }
        return sprintf(t('antigravity.login'), $url, $wait);
    }

    /** The sign-in is removed so the owner can sign in again, with this account or another one. */
    private function logout(): string {
        if (!$this->subscription->logout()) {
            return $this->subscription->status(true) === null ? t('antigravity.module_down') : t('antigravity.logout_failed');
        }
        // a pause belongs to the account that is gone
        $this->pauses->resume(ModelRef::ANTIGRAVITY);
        return t('antigravity.logged_out');
    }

    private function code(#[SensitiveParameter] string $code): string {
        if ($code === '') {
            return t('antigravity.code_empty');
        }
        if (!$this->subscription->code($code)) {
            return t('antigravity.code_refused');
        }
        $this->pauses->resume(ModelRef::ANTIGRAVITY);
        if ($this->signedIn !== null) {
            try {
                ($this->signedIn)();
            } catch (Throwable $e) {
                $this->logger->warning('The models of the Antigravity plan did not load', ['error' => $e::class]);
            }
        }
        return t('antigravity.done');
    }

    private function resume(): string {
        $this->pauses->resume(ModelRef::ANTIGRAVITY);
        $this->subscription->forget();
        return t('antigravity.resumed');
    }
}
