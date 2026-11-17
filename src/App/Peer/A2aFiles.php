<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Amp\Http\Server\FormParser\FormParser;
use DateTimeImmutable;
use OpenCCK\App\Agent\Media\MediaNames;
use OpenCCK\Domain\Repository\A2aFileRepository;

use function Amp\Http\Server\FormParser\parseContentBoundary;

/**
 * Files between agents: an upload ahead of a message (multipart/form-data), parts that refer to an upload
 * (`url: a2a-file:<number>`) or carry the bytes (`raw`), files of an answer.
 *
 * spec: BRIEF.md#между-агентами
 */
final class A2aFiles {
    /** a file and all files of one message, as Telegram */
    public const FILE_MAX = 20_000_000;
    public const COUNT_MAX = 10;
    /** the body of an upload: the files and the multipart overhead */
    public const BODY_MAX = 21_000_000;
    public const SCHEME = 'a2a-file:';

    public function __construct(private readonly A2aFileRepository $files) {}

    /**
     * The files of a multipart body, kept for the agent who sent them.
     *
     * @return list<array{id: string, filename: string, mediaType: string, size: int}>
     * @throws \DomainException with the reason for the sender
     */
    public function upload(int $agentId, string $contentType, string $body): array {
        $boundary = parseContentBoundary($contentType);
        if ($boundary === null || $boundary === '') {
            throw new \DomainException('multipart/form-data with a boundary was expected');
        }
        $form = (new FormParser(self::COUNT_MAX + 5))->parseMultipartBody($body, $boundary);
        $list = [];
        foreach ($form->getFiles() as $files) {
            foreach ($files as $file) {
                $list[] = $file;
            }
        }
        if ($list === [] || count($list) > self::COUNT_MAX) {
            throw new \DomainException(sprintf('from 1 to %d files', self::COUNT_MAX));
        }
        $total = 0;
        foreach ($list as $file) {
            $total += strlen($file->getContents());
        }
        if ($total > self::FILE_MAX) {
            throw new \DomainException(sprintf('files of one message are up to %d MB', intdiv(self::FILE_MAX, 1_000_000)));
        }
        $kept = [];
        foreach ($list as $file) {
            $name = MediaNames::clean($file->getName() === '' ? 'file' : $file->getName());
            $type = self::mediaType($file->getMimeType(), $name);
            $bytes = $file->getContents();
            $kept[] = ['id' => $this->files->add($agentId, $name, $type, $bytes), 'filename' => $name, 'mediaType' => $type, 'size' => strlen($bytes)];
        }
        return $kept;
    }

    /**
     * The files of a message from the agent: parts that refer to its uploads and parts with bytes. A reference to a
     * file of someone else is not found.
     *
     * @param array<array-key, mixed> $message
     * @return list<array{name: string, bytes: string, mediaType: string}>
     */
    public function ofMessage(int $agentId, array $message): array {
        $found = [];
        $total = 0;
        /** @var mixed $part */
        foreach (isset($message['parts']) && is_array($message['parts']) ? $message['parts'] : [] as $part) {
            if (!is_array($part) || count($found) >= self::COUNT_MAX) {
                continue;
            }
            /** @var mixed $url */
            $url = $part['url'] ?? null;
            /** @var mixed $raw */
            $raw = $part['raw'] ?? null;
            /** @var mixed $named */
            $named = $part['filename'] ?? null;
            /** @var mixed $typed */
            $typed = $part['mediaType'] ?? null;
            $file = null;
            if (is_string($url) && str_starts_with($url, self::SCHEME)) {
                $taken = $this->files->take($agentId, substr($url, strlen(self::SCHEME)));
                $file = $taken === null ? null : ['name' => $taken['name'], 'bytes' => $taken['bytes'], 'mediaType' => $taken['mediaType']];
            } elseif (is_string($raw)) {
                $bytes = base64_decode($raw, true);
                $name = MediaNames::clean(is_string($named) ? $named : 'file');
                $file = $bytes === false ? null : ['name' => $name, 'bytes' => $bytes, 'mediaType' => self::mediaType(is_string($typed) ? $typed : '', $name)];
            }
            if ($file === null || $total + strlen($file['bytes']) > self::FILE_MAX) {
                continue;
            }
            $total += strlen($file['bytes']);
            $found[] = $file;
        }
        return $found;
    }

    /** Keeps a file the agent sent into a task as part of its answer to the agent $requesterId. */
    public function answer(int $requesterId, string $taskId, string $name, string $bytes): string {
        $name = MediaNames::clean($name);
        return $this->files->add($requesterId, $name, self::mediaType('', $name), $bytes, $taskId);
    }

    /**
     * Parts of the answer of a task that refer to its files.
     *
     * @return list<array{url: string, filename: string, mediaType: string}>
     */
    public function parts(string $taskId): array {
        return array_map(static fn(array $f): array => ['url' => self::SCHEME . $f['id'], 'filename' => $f['name'], 'mediaType' => $f['mediaType']], $this->files->ofTask($taskId));
    }

    /** @return array{name: string, mediaType: string, bytes: string}|null */
    public function download(int $agentId, string $id): ?array {
        return $this->files->take($agentId, $id);
    }

    public function sweep(DateTimeImmutable $now): int {
        return $this->files->sweep($now->modify('-1 hour'), $now->modify('-1 day'));
    }

    /** The type the sender gave when it is a plain media type, else by the extension. */
    public static function mediaType(string $given, string $name): string {
        $given = strtolower(trim($given));
        if ($given !== '' && $given !== 'application/octet-stream' && preg_match('~^[a-z]+/[a-z0-9.+-]{1,100}$~', $given) === 1) {
            return $given;
        }
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
            'mp3' => 'audio/mpeg', 'ogg', 'oga', 'opus' => 'audio/ogg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'pdf' => 'application/pdf', 'txt', 'log' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
            'json' => 'application/json', 'html', 'htm' => 'text/html', 'xml' => 'application/xml',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    /**
     * A multipart/form-data body of files, for an upload to a neighbour.
     *
     * @param list<array{name: string, bytes: string, mediaType?: string}> $files
     * @return array{string, string} [content type, body]
     */
    public static function multipart(array $files): array {
        $boundary = '----mercurius' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($files as $index => $file) {
            $name = str_replace(['"', "\r", "\n", '\\'], '_', $file['name']);
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="file' . $index . '"; filename="' . $name . '"' . "\r\n"
                . 'Content-Type: ' . self::mediaType($file['mediaType'] ?? '', $file['name']) . "\r\n\r\n"
                . $file['bytes'] . "\r\n";
        }
        return ['multipart/form-data; boundary=' . $boundary, $body . '--' . $boundary . "--\r\n"];
    }
}
