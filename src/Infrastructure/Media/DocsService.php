<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use OpenCCK\Infrastructure\Services\ServiceModules;
use RuntimeException;
use Throwable;

/**
 * The media-docs module (services/media-docs): the text of office documents and PDF, and new docx, xlsx, pptx. An
 * untrusted file is parsed there, in a child process with limits, not in the app or the sandbox.
 *
 * spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — media-docs
 */
final class DocsService {
    public const MODULE = 'media-docs';
    public const KINDS = ['docx', 'pptx', 'xlsx', 'odt', 'pdf', 'rtf'];
    public const BUILDS = ['docx', 'xlsx', 'pptx'];

    public function __construct(
        private readonly HttpClient $http,
        private readonly ServiceModules $modules,
    ) {}

    public function up(): bool {
        return $this->modules->up(self::MODULE);
    }

    /**
     * The text of the document, at most $limit characters.
     *
     * @return array{int, string} [200, text] or [415 — the kind is not read | 422 — the file does not read, reason]
     * @throws RuntimeException the module did not answer
     */
    public function extract(string $bytes, string $name, int $limit, Cancellation $cancellation): array {
        [$status, $body] = $this->post('/extract?' . http_build_query(['name' => $name, 'limit' => $limit]), $bytes, 'application/octet-stream', $cancellation);
        $data = self::json($body);
        if ($status === 200) {
            /** @var mixed $text */
            $text = $data['text'] ?? null;
            return [200, is_string($text) ? $text : ''];
        }
        return [$status === 415 ? 415 : 422, self::error($data, $status)];
    }

    /**
     * A new document by its description (the format is in it).
     *
     * @param array<array-key, mixed> $description
     * @throws RuntimeException with the reason for the model
     */
    public function build(array $description, Cancellation $cancellation): string {
        [$status, $body] = $this->post('/build', json_encode($description, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'application/json', $cancellation);
        if ($status !== 200) {
            throw new RuntimeException(self::error(self::json($body), $status));
        }
        return $body;
    }

    /** @return array{int, string} */
    private function post(string $path, string $body, string $type, Cancellation $cancellation): array {
        $url = $this->modules->url(self::MODULE);
        $token = $this->modules->token(self::MODULE);
        if ($url === null || $token === null) {
            throw new RuntimeException('media-docs is not set up');
        }
        $request = new Request($url . $path, 'POST', $body);
        $request->setHeader('authorization', 'Bearer ' . $token);
        $request->setHeader('content-type', $type);
        $request->setTransferTimeout(90);
        $request->setInactivityTimeout(90);
        $request->setBodySizeLimit(60_000_000);
        try {
            $response = $this->http->request($request, $cancellation);
            return [$response->getStatus(), $response->getBody()->buffer($cancellation)];
        } catch (Throwable $e) {
            throw new RuntimeException('media-docs did not answer: ' . (new \ReflectionClass($e))->getShortName());
        }
    }

    /** @return array<array-key, mixed> */
    private static function json(string $body): array {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /** @param array<array-key, mixed> $data */
    private static function error(array $data, int $status): string {
        return isset($data['error']) && is_string($data['error']) ? mb_substr($data['error'], 0, 300) : sprintf('HTTP %d', $status);
    }
}
