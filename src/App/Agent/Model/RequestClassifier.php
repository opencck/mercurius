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
    ) {}

    /** @return array{RequestClass, string, string} class, method (heuristic, model, fallback), reason */
    public function classify(string $text, ?Cancellation $cancellation = null): array {
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
        try {
            $response = $this->llm->complete(new ChatRequest(
                ($this->cheapModel)(),
                [Message::system(\Mercurius\I18n\t(self::PROMPT)), Message::user(SecretMasker::mask(mb_substr($text, 0, 4000)))],
                [],
                300,
            ), $cancellation === null ? $limit : new CompositeCancellation($cancellation, $limit));
        } catch (CancelledException $e) {
            if ($cancellation?->isRequested() === true) {
                throw $e;
            }
            $this->logger->warning('Request classification timed out');
            return [RequestClass::Normal, 'fallback', t('model.request_classifier.klassifikator_ne_uspel_otvetit')];
        } catch (Throwable $e) {
            $this->logger->warning('Request classification failed', ['error' => $e::class]);
            return [RequestClass::Normal, 'fallback', t('model.request_classifier.klassifikator_ne_otvetil')];
        }
        $class = RequestClass::tryFrom(strtolower(trim($response->text, " \t\n\r.\"'«»")));
        return $class === null
            ? [RequestClass::Normal, 'fallback', t('model.request_classifier.klassifikator_otvetil_ne_po_formatu')]
            : [$class, 'model', t('model.request_classifier.otsenka_deshevoy_modeli')];
    }
}
