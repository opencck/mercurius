<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\Cancellation;
use Amp\TimeoutCancellation;
use Closure;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * /export <password>: the owner's archive of the agent. The command answers at once; the archive is built in the
 * background and comes as a file, or, above the channel's file size, stays in storage/app/exports/ with its path said.
 * One export at a time.
 *
 * spec: BRIEF.md#экспорт-и-импорт-агента — the archive by the owner's command, sealed by the owner's password
 */
final class ExportCommands {
    /** Telegram's cloud Bot API takes files up to 50 MB; a margin for the form around the file */
    public const SEND_LIMIT = 45 * 1024 * 1024;
    private const TIMEOUT = 3600;

    private bool $busy = false;

    /**
     * @param Closure(string, Cancellation): string $build the path of the sealed archive (AgentExport::export)
     */
    public function __construct(
        private readonly Closure $build,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger,
        private readonly int $sendLimit = self::SEND_LIMIT,
    ) {}

    /** The answer now; the archive or the failure comes later to the same chat. */
    public function start(string $chatId, ?string $threadId, #[\SensitiveParameter] string $password): string {
        $password = trim($password);
        if ($password === '') {
            return t('export.usage');
        }
        if (mb_strlen($password) < ArchiveCrypto::PASSWORD_MIN) {
            return sprintf(t('export.password_short'), ArchiveCrypto::PASSWORD_MIN);
        }
        if ($this->busy) {
            return t('export.busy');
        }
        $this->busy = true;
        async(function () use ($chatId, $threadId, $password): void {
            try {
                $this->deliver($chatId, $threadId, ($this->build)($password, new TimeoutCancellation(self::TIMEOUT)));
            } catch (Throwable $e) {
                $this->logger->error('Export failed', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
                $this->say($chatId, $threadId, t('export.failed'));
            } finally {
                $this->busy = false;
            }
        })->ignore();
        return t('export.started');
    }

    private function deliver(string $chatId, ?string $threadId, string $path): void {
        $size = (int) filesize($path);
        $name = basename($path);
        $this->logger->info('Export ready', ['file' => $name, 'bytes' => $size]);
        $where = 'storage/app/exports/' . $name;
        if ($size <= $this->sendLimit) {
            $sent = $this->channel->sendFile($chatId, $name, (string) file_get_contents($path), sprintf(t('export.ready'), $where), $threadId);
            if ($sent->success) {
                return;
            }
        }
        $this->say($chatId, $threadId, sprintf(t('export.ready_path'), $where, round($size / 1048576, 1), $name));
    }

    private function say(string $chatId, ?string $threadId, string $text): void {
        $this->channel->send(new OutgoingMessage($chatId, $text, threadId: $threadId));
    }
}
