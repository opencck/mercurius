<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\TimeoutCancellation;
use InvalidArgumentException;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * "/project export <name> <password>" — the archive of a project comes as a file; "/project import <password>" with the
 * file attached — the project from it. In a private chat only; the message with the password leaves the chat.
 *
 * spec: brief/participants.md#экспорт-и-импорт-проекта
 */
final class ProjectArchiveCommands {
    private const TIMEOUT = 900;

    private bool $busy = false;

    public function __construct(
        private readonly ProjectArchive $archive,
        private readonly ProjectRepository $projects,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger,
        /** storage/app/import: where a received archive waits */
        private readonly string $folder,
        private readonly int $sendLimit = ExportCommands::SEND_LIMIT,
    ) {}

    /** The answer now; the archive or the outcome comes later to the same chat. */
    public function export(Principal $who, string $chatId, #[\SensitiveParameter] string $argument): string {
        if (preg_match('~^(.+?)\s+(\S+)$~su', trim($argument), $m) !== 1) {
            return t('project.archive.export_usage');
        }
        [, $name, $password] = $m;
        if (mb_strlen($password) < ArchiveCrypto::PASSWORD_MIN) {
            return sprintf(t('export.password_short'), ArchiveCrypto::PASSWORD_MIN);
        }
        $project = $this->projects->byName(trim($name), Project::scopeOf($who));
        if ($project === null || !$project->visibleTo($who)) {
            return sprintf(t('project.archive.owner_only'), trim($name));
        }
        if ($this->busy) {
            return t('export.busy');
        }
        $this->busy = true;
        async(function () use ($project, $password, $chatId): void {
            try {
                $path = $this->archive->export($project, $password, new TimeoutCancellation(self::TIMEOUT));
                $size = (int) filesize($path);
                $sent = $size <= $this->sendLimit
                    ? $this->channel->sendFile($chatId, basename($path), (string) file_get_contents($path), sprintf(t('project.archive.exported'), $project->name))
                    : null;
                if ($sent === null || !$sent->success) {
                    $this->say($chatId, sprintf(t('export.ready_path'), 'storage/app/exports/' . basename($path), round($size / 1048576, 1), basename($path)));
                }
                @unlink($path);
            } catch (Throwable $e) {
                $this->logger->error('Project export failed', ['project' => $project->id, 'error' => $e::class]);
                $this->say($chatId, $e instanceof \RuntimeException ? $e->getMessage() : t('export.failed'));
            } finally {
                $this->busy = false;
            }
        })->ignore();
        return t('export.started');
    }

    public function import(Principal $who, string $chatId, #[\SensitiveParameter] string $password, ?Attachment $file): string {
        $password = trim($password);
        if (!$who->role->may(Power::Projects)) {
            return t('project.archive.no_power');
        }
        if ($file === null || $password === '') {
            return t('project.archive.import_usage');
        }
        if ($file->size !== null && $file->size > ImportCommands::DOWNLOAD_LIMIT) {
            return t('import.too_large');
        }
        if ($this->busy) {
            return t('export.busy');
        }
        $this->busy = true;
        async(function () use ($who, $chatId, $password, $file): void {
            $path = null;
            try {
                $bytes = $this->channel->download($file->fileId, ImportCommands::DOWNLOAD_LIMIT);
                if ($bytes === null) {
                    $this->say($chatId, t('import.too_large'));
                    return;
                }
                if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
                    throw new \RuntimeException('import folder');
                }
                $path = $this->folder . '/project-received-' . $who->id . ProjectArchive::EXTENSION;
                file_put_contents($path, $bytes);
                $this->say($chatId, $this->archive->import($path, $password, $who, new TimeoutCancellation(self::TIMEOUT)));
            } catch (InvalidArgumentException $e) {
                $this->say($chatId, $e->getMessage());
            } catch (Throwable $e) {
                $this->logger->error('Project import failed', ['error' => $e::class]);
                $this->say($chatId, t('import.failed'));
            } finally {
                if ($path !== null) {
                    @unlink($path);
                }
                $this->busy = false;
            }
        })->ignore();
        return t('import.checking');
    }

    private function say(string $chatId, string $text): void {
        $this->channel->send(new OutgoingMessage($chatId, $text));
    }
}
