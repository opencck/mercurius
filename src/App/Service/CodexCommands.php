<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Infrastructure\Llm\ChatGpt\CodexAccount;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * /codex — the owner's ChatGPT subscription as a model provider: /codex login gives a page and a code to type there
 * (the agent waits up to 15 minutes and says how it went), /codex — whether it is signed in, /codex logout — the tokens
 * leave the vault. The models then go by chatgpt:<model>, for example in /models or the roles.
 *
 * spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — Codex through the owner's ChatGPT subscription
 */
final class CodexCommands {
    private bool $waiting = false;

    public function __construct(
        private readonly CodexAccount $account,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(string $chatId, string $argument): string {
        return match (strtolower(trim($argument))) {
            'login' => $this->login($chatId),
            'logout' => $this->account->signOut() ? t('codex.signed_out') : t('codex.not_signed_in'),
            default => $this->account->signedIn() ? t('codex.signed_in') : t('codex.not_signed_in'),
        };
    }

    private function login(string $chatId): string {
        if ($this->waiting) {
            return t('codex.waiting');
        }
        try {
            $device = $this->account->start();
        } catch (Throwable $e) {
            $this->logger->warning('ChatGPT sign-in not started', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
            return t('codex.failed');
        }
        $this->waiting = true;
        async(function () use ($chatId, $device): void {
            try {
                $this->account->finish($device['id'], $device['code'], $device['interval']);
                $this->channel->send(new OutgoingMessage($chatId, t('codex.done')));
            } catch (Throwable $e) {
                $this->logger->warning('ChatGPT sign-in not finished', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
                $this->channel->send(new OutgoingMessage($chatId, t('codex.failed')));
            } finally {
                $this->waiting = false;
            }
        })->ignore();
        return sprintf(t('codex.login'), CodexAccount::VERIFY_URL, $device['code']);
    }
}
