<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Amp\CancelledException;
use DomainException;
use Mercurius\Mcp\Models\McpModel;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\App\Mcp\McpModelProviders;
use OpenCCK\Domain\Agent\ModelRef;
use Throwable;

use function Amp\delay;
use function Mercurius\I18n\t;

/**
 * The models of the providers MCP servers offer, called by the tools of their servers with the fixed arguments of their
 * kind: a picture, speech, a video from a description; reading a picture, a video or a sound. A quota a server reports
 * takes its model off the choice for an hour; other failures hand over to the next model of the list.
 *
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
 */
final class McpMedia {
    /** a video is made in minutes: its result is read this long, then the agent is told to ask again later */
    private const FILM_SECONDS = 600.0;
    private const FILM_STEP = 15.0;
    private const QUOTA_PAUSE = 3600;
    private const FILES_MAX = 20 * 1024 * 1024;

    public function __construct(
        private readonly McpModelProviders $providers,
        private readonly ?ProviderPauses $pauses = null,
    ) {}

    public static function owns(string $ref): bool {
        return str_starts_with($ref, McpModelProviders::PREFIX);
    }

    /**
     * Calls a model of a kind and gives its answer: text, files, and the estimate of the price of the call.
     *
     * @param array<string, mixed> $args the arguments of the kind; the model is added
     * @return array{text: string, files: list<array{name: string, type: string, bytes: string}>, cost: ?float}
     * @throws DomainException with why the model did not answer
     */
    public function call(string $ref, string $kind, array $args, \Amp\Cancellation $cancellation): array {
        [$provider, $model] = $this->model($ref, $kind);
        $pause = $this->pauses?->blocking(new ModelRef($provider, $model->id));
        if ($pause !== null) {
            throw new DomainException(sprintf(t('media.mcp.paused'), $ref, gmdate('Y-m-d H:i', $pause['until']) . ' UTC'));
        }
        [$text, $error, $structured, $files] = $this->providers->call($provider, $model->tool, ['model' => $model->id] + $args, $cancellation);
        if ($error) {
            $this->failed($provider, $model, $text, $structured);
            throw new DomainException($text === '' ? t('media.models.empty') : mb_substr($text, 0, 500));
        }
        if ($model->resultTool !== null) {
            [$text, $files] = $this->result($provider, $model, $text, $structured, $cancellation);
        }
        return ['text' => $text, 'files' => $files, 'cost' => $model->price !== null && $model->price['unit'] === 'call' ? $model->price['usd'] : null];
    }

    /**
     * A file of an attachment as the contract passes it: a name, a type and the bytes in base64.
     *
     * @return array{name: string, mime_type: string, content_base64: string}
     */
    public static function file(string $name, string $mime, string $bytes): array {
        if (strlen($bytes) > self::FILES_MAX) {
            throw new DomainException(sprintf(t('media.mcp.too_large'), intdiv(self::FILES_MAX, 1_048_576)));
        }
        return ['name' => $name, 'mime_type' => $mime, 'content_base64' => base64_encode($bytes)];
    }

    /** @return array{string, McpModel} */
    private function model(string $ref, string $kind): array {
        [$provider, $id] = explode(':', $ref, 2) + [1 => ''];
        $model = $this->providers->model($provider, $id, $kind) ?? $this->providers->model($provider, $id);
        if ($model === null) {
            throw new DomainException(sprintf(t('model.lists.mcp_unknown'), $ref, '—'));
        }
        if ($model->kind !== $kind) {
            throw new DomainException(sprintf(t('model.lists.mcp_kind'), $ref, $model->kind, $kind, '—'));
        }
        return [$provider, $model];
    }

    /**
     * Reads the result of a long call until it is ready or the time is over.
     *
     * @param array<array-key, mixed>|null $structured
     * @return array{string, list<array{name: string, type: string, bytes: string}>}
     */
    private function result(string $provider, McpModel $model, string $text, ?array $structured, \Amp\Cancellation $cancellation): array {
        $id = $structured['id'] ?? $structured['operation'] ?? null;
        if (!is_string($id) && preg_match('~^id:\s*(\S+)~m', $text, $m) === 1) {
            $id = $m[1];
        }
        if (!is_string($id) || $id === '' || $model->resultTool === null) {
            throw new DomainException(t('media.mcp.no_operation'));
        }
        $until = microtime(true) + self::FILM_SECONDS;
        do {
            [$said, $error, $state, $files] = $this->providers->call($provider, $model->resultTool, ['id' => $id], $cancellation);
            if ($error) {
                throw new DomainException(mb_substr($said, 0, 500));
            }
            if ($files !== []) {
                return [$said, $files];
            }
            if (($state['done'] ?? null) === true) {
                throw new DomainException(t('media.mcp.no_file'));
            }
            try {
                delay(self::FILM_STEP, cancellation: $cancellation);
            } catch (CancelledException $e) {
                throw $e;
            }
        } while (microtime(true) < $until);
        throw new DomainException(sprintf(t('media.mcp.still_making'), $id));
    }

    /**
     * A quota the server reports takes the model off the choice for a while.
     *
     * @param array<array-key, mixed>|null $structured
     */
    private function failed(string $provider, McpModel $model, string $text, ?array $structured): void {
        $said = isset($structured['error']) && is_array($structured['error']) ? $structured['error'] : [];
        $kind = isset($said['kind']) && is_string($said['kind']) ? $said['kind'] : '';
        $quota = $kind === 'quota' || preg_match('~\b429\b|exceeded your current quota|RESOURCE_EXHAUSTED~i', $text) === 1;
        if ($quota) {
            try {
                $this->pauses?->pauseFor($provider . ':' . $model->id, self::QUOTA_PAUSE, ProviderPauses::LIMIT, mb_substr($text, 0, 300));
            } catch (Throwable) {
                // the pause is a courtesy: the call failed either way
            }
        }
    }
}
