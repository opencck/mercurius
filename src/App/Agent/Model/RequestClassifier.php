<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Closure;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\Message;
use OpenCCK\App\Agent\Tool\SecretMasker;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Sorts a request into short, normal or complex: heuristics first, the cheap model when they cannot tell.
 * A wrong or missing answer means normal — the default model, never a guess towards the expensive one.
 *
 * spec: BRIEF.md#модели — heuristics (length, commands), unclear — the cheap model
 */
final class RequestClassifier {
    private const SHORT = 200;
    private const LONG = 2000;
    /** Requests to do something complex, as whole words: not «планшет», «планета», «архитектура дома». */
    private const COMPLEX = '/(?<!\pL)(спроектируй|спроектировать|составь\s+план|распиши\s+план|план\s+(миграции|внедрения|работ|действий)|проанализируй|проанализировать|сравни\s+варианты|отрефактор\pL*|рефакторинг\pL*|исследуй|по\s+шагам|пошагово|архитектуру\s+\pL+)(?!\pL)/iu';
    private const PROMPT = 'model.request_classifier.prompt';

    /**
     * @param Closure(): string $cheapModel
     * @param float $timeout seconds: the first message waits for this, so a slow classifier is not waited out
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Closure $cheapModel,
        private readonly LoggerInterface $logger,
        private readonly float $timeout = 8.0,
        /** where calls of models are counted; null — not counted */
        private readonly ?\OpenCCK\Domain\Repository\LlmCallRepository $calls = null,
    ) {}

    /**
     * @param int|null $conversationId the conversation the call is counted on
     * @return array{RequestClass, string, string} class, method (heuristic, model, fallback), reason
     */
    public function classify(string $text, ?Cancellation $cancellation = null, ?int $conversationId = null): array {
        $text = trim($text);
        $length = mb_strlen($text);
        if (str_starts_with($text, '/')) {
            return [RequestClass::Short, 'heuristic', t('model.request_classifier.komanda')];
        }
        if (preg_match(self::COMPLEX, $text) === 1) {
            return [RequestClass::Complex, 'heuristic', t('model.request_classifier.priznaki_slozhnoy_zadachi')];
        }
        if ($length >= self::LONG) {
            return [RequestClass::Complex, 'heuristic', sprintf(t('model.request_classifier.dlinnyy_zapros_simvolov'), $length)];
        }
        if ($length <= self::SHORT) {
            return [RequestClass::Short, 'heuristic', sprintf(t('model.request_classifier.korotkiy_zapros_simvolov'), $length)];
        }
        $limit = new TimeoutCancellation($this->timeout);
        $model = ($this->cheapModel)();
        $started = microtime(true);
        try {
            $response = $this->llm->complete(new ChatRequest(
                $model,
                [Message::system(\Mercurius\I18n\t(self::PROMPT)), Message::user(SecretMasker::mask(mb_substr($text, 0, 4000)))],
                [],
                300,
            ), $cancellation === null ? $limit : new CompositeCancellation($cancellation, $limit));
        } catch (CancelledException $e) {
            if ($cancellation?->isRequested() === true) {
                throw $e;
            }
            $this->count($conversationId, $model, 'timeout', $started, null);
            $this->logger->warning('Request classification timed out');
            return [RequestClass::Normal, 'fallback', t('model.request_classifier.klassifikator_ne_uspel_otvetit')];
        } catch (Throwable $e) {
            $this->count($conversationId, $model, 'error', $started, null);
            $this->logger->warning('Request classification failed', ['error' => $e::class]);
            return [RequestClass::Normal, 'fallback', t('model.request_classifier.klassifikator_ne_otvetil')];
        }
        $this->count($conversationId, $model, null, $started, $response);
        $class = RequestClass::tryFrom(strtolower(trim($response->text, " \t\n\r.\"'«»")));
        return $class === null
            ? [RequestClass::Normal, 'fallback', t('model.request_classifier.klassifikator_otvetil_ne_po_formatu')]
            : [$class, 'model', t('model.request_classifier.otsenka_deshevoy_modeli')];
    }

    /**
     * The call of the cheap model goes into the calls of models: its tokens and money are counted like any other.
     *
     * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — every call of a model is counted, the classifier's too
     */
    private function count(?int $conversationId, string $model, ?string $error, float $started, ?\Mercurius\Llm\ChatResponse $response): void {
        if ($this->calls === null) {
            return;
        }
        $usage = $response?->usage;
        try {
            $this->calls->record(new \OpenCCK\Domain\Entity\LlmCallRecord(
                conversationId: $conversationId,
                purpose: 'classify',
                model: $model,
                finishReason: $response?->finishReason->value,
                errorKind: $error,
                inputTokens: max(0, $usage->inputTokens ?? 0),
                outputTokens: max(0, $usage->outputTokens ?? 0),
                cacheReadTokens: max(0, $usage->cacheReadTokens ?? 0),
                cacheWriteTokens: max(0, $usage->cacheWriteTokens ?? 0),
                reasoningTokens: max(0, $usage->reasoningTokens ?? 0),
                costUsd: $usage?->costUsd,
                durationMs: max(0, (int) round((microtime(true) - $started) * 1000.0)),
            ));
        } catch (Throwable $e) {
            $this->logger->error('Could not record model usage', ['error' => $e::class]);
        }
    }
}
