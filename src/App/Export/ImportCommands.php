<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\Cancellation;
use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * /import <password> with the archive attached, or with the name of an archive put into storage/app/import/: the
 * archive is opened and checked, and the owner is asked once more. /import yes seals the current state into exports/ by
 * the same password, marks the archive and restarts the agent, which restores it at the start; /import no drops it.
 *
 * spec: BRIEF.md#экспорт-и-импорт-агента — a full import replaces the agent, its state saved before
 */
final class ImportCommands {
    /** what the bot downloads from the chat; a larger archive is put into storage/app/import/ by hand */
    public const DOWNLOAD_LIMIT = 50 * 1024 * 1024;
    /** how long a checked archive waits for the yes */
    public const CONFIRM_SECONDS = 600;
    private const TIMEOUT = 3600;

    private bool $busy = false;
    /** @var array{password: string, at: int}|null */
    private ?array $prepared = null;

    /**
     * @param Closure(string, Cancellation): string $export seals the current state by the password, the archive's path
     * @param Closure(): void $restart stops the agent so that it starts again
     */
    public function __construct(
        private readonly AgentImport $import,
        private readonly Closure $export,
        private readonly Closure $restart,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger,
        /** storage/app/import: archives the owner put there and the downloaded one */
        private readonly string $folder,
        private readonly ?PartialImport $partial = null,
    ) {}

    /** @param int $owner who asks: the skills of a partial import go into this participant's folder */
    public function start(string $chatId, #[\SensitiveParameter] string $argument, ?Attachment $file, int $owner = 0): string {
        $argument = trim($argument);
        if ($this->busy) {
            return t('export.busy');
        }
        if (strtolower($argument) === 'no') {
            $this->prepared = null;
            $this->import->discard();
            return t('import.cancelled');
        }
        if (strtolower($argument) === 'yes') {
            return $this->confirm($chatId);
        }
        if (in_array(strtolower($argument), ['memory', 'skills'], true)) {
            return $this->part($chatId, strtolower($argument), $owner);
        }
        // a password may hold spaces: with a file attached it is the whole argument, otherwise the last word is the name
        $password = $argument;
        $name = '';
        if ($file === null && preg_match('~^(.*\S)\s+(\S+)$~su', $argument, $m) === 1) {
            [, $password, $name] = $m;
        }
        if ($password === '' || ($file === null && $name === '')) {
            return t('import.usage');
        }
        if (mb_strlen($password) < ArchiveCrypto::PASSWORD_MIN) {
            return sprintf(t('export.password_short'), ArchiveCrypto::PASSWORD_MIN);
        }
        $path = null;
        if ($file === null) {
            // a bare name in the import folder: no path of the host is reachable through it
            if ($name !== basename($name) || str_starts_with($name, '.') || !is_file($this->folder . '/' . $name)) {
                return t('import.no_file');
            }
            $path = $this->folder . '/' . $name;
        } elseif ($file->size !== null && $file->size > self::DOWNLOAD_LIMIT) {
            return t('import.too_large');
        }
        $this->busy = true;
        $this->prepared = null;
        async(function () use ($chatId, $password, $path, $file): void {
            try {
                $path ??= $this->download($file);
                if ($path === null) {
                    $this->say($chatId, t('import.too_large'));
                    return;
                }
                $info = $this->import->prepare($path, $password);
                $this->prepared = ['password' => $password, 'at' => time()];
                $this->say($chatId, sprintf(t('import.ready'), $info['commit'] === '' ? '?' : substr($info['commit'], 0, 12), round($info['bytes'] / 1048576, 1), intdiv(self::CONFIRM_SECONDS, 60)));
            } catch (InvalidArgumentException $e) {
                $this->say($chatId, $e->getMessage());
            } catch (Throwable $e) {
                $this->logger->error('Import not prepared', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
                $this->say($chatId, t('import.failed'));
            } finally {
                $this->busy = false;
            }
        })->ignore();
        return t('import.checking');
    }

    private function confirm(string $chatId): string {
        $prepared = $this->prepared;
        if ($prepared === null || !$this->import->prepared()) {
            return t('import.nothing');
        }
        if (time() - $prepared['at'] > self::CONFIRM_SECONDS) {
            $this->prepared = null;
            $this->import->discard();
            return t('import.expired');
        }
        $this->busy = true;
        $this->prepared = null;
        async(function () use ($chatId, $prepared): void {
            try {
                $saved = ($this->export)($prepared['password'], new TimeoutCancellation(self::TIMEOUT));
                $this->import->commit();
                $this->logger->notice('Import marked for the start', ['saved' => basename($saved)]);
                $this->say($chatId, sprintf(t('import.restarting'), 'storage/app/exports/' . basename($saved)));
                ($this->restart)();
            } catch (Throwable $e) {
                $this->logger->error('Import not started', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
                $this->say($chatId, t('import.failed'));
            } finally {
                $this->busy = false;
            }
        })->ignore();
        return t('import.saving');
    }

    /** A part of the checked archive into the running agent; the archive stays for the other part or the yes. */
    private function part(string $chatId, string $part, int $owner): string {
        if ($this->partial === null) {
            return t('import.usage');
        }
        $prepared = $this->prepared;
        if ($prepared === null || !$this->import->prepared()) {
            return t('import.nothing');
        }
        if (time() - $prepared['at'] > self::CONFIRM_SECONDS) {
            $this->prepared = null;
            $this->import->discard();
            return t('import.expired');
        }
        $this->busy = true;
        $partial = $this->partial;
        async(function () use ($chatId, $part, $owner, $partial): void {
            try {
                if ($part === 'memory') {
                    $this->say($chatId, sprintf(t('import.memory_done'), $partial->memory($this->import->staging())));
                } else {
                    $names = $partial->skills($this->import->staging(), $owner, new TimeoutCancellation(self::TIMEOUT));
                    $this->say($chatId, $names === [] ? t('import.skills_none') : sprintf(t('import.skills_done'), implode(', ', $names)));
                }
            } catch (Throwable $e) {
                $this->logger->error('Partial import failed', ['part' => $part, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
                $this->say($chatId, t('import.failed'));
            } finally {
                $this->busy = false;
            }
        })->ignore();
        return t('import.part_started');
    }

    private function download(?Attachment $file): ?string {
        if ($file === null) {
            return null;
        }
        $bytes = $this->channel->download($file->fileId, self::DOWNLOAD_LIMIT);
        if ($bytes === null) {
            return null;
        }
        if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new \RuntimeException('import folder');
        }
        $path = $this->folder . '/received' . AgentExport::EXTENSION;
        file_put_contents($path, $bytes);
        chmod($path, 0600);
        return $path;
    }

    private function say(string $chatId, string $text): void {
        $this->channel->send(new OutgoingMessage($chatId, $text));
    }
}
