<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runs one validated call. A tool error, an exception or a timeout is always a result for the model;
 * only cancellation of the run itself (/stop, shutdown) propagates.
 *
 * spec: BRIEF.md#инструменты — an error is a result, not an exception
 * spec: BRIEF.md#лимиты — timeout per tool call
 */
final class ToolExecutor {
    /** @param positive-int $maxResultChars */
    public function __construct(
        private readonly int $maxResultChars = 50_000,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @param positive-int $timeoutSeconds */
    public function run(ValidatedCall $call, ToolContext $context, int $timeoutSeconds): ToolResult {
        if ($call->error !== null || $call->tool === null || $call->args === null) {
            return ToolResult::error($call->error ?? 'Вызов не прошёл проверку.');
        }
        $tool = $call->tool;
        $name = $call->call->name;
        $timeout = new TimeoutCancellation($timeoutSeconds);

        try {
            $result = $tool->execute($call->args, $context->withCancellation(new CompositeCancellation($context->cancellation, $timeout)));
        } catch (CancelledException $e) {
            if ($context->cancellation->isRequested()) {
                throw $e;
            }
            $this->logger->warning('Tool timed out', ['tool' => $name, 'timeout' => $timeoutSeconds]);
            return ToolResult::error(sprintf('Инструмент %s не уложился в %d с.', $name, $timeoutSeconds));
        } catch (Throwable $e) {
            // The message may carry secrets or internals: the model and the log get the class only.
            $this->logger->error('Tool failed', ['tool' => $name, 'error' => $e::class]);
            return ToolResult::error(sprintf('Инструмент %s завершился с ошибкой (%s).', $name, (new \ReflectionClass($e))->getShortName()));
        }

        $content = $this->truncate($result->content);
        if ($tool->untrusted()) {
            $content = UntrustedWrapper::wrap($name, $content);
        }
        return new ToolResult($content, $result->isError);
    }

    private function truncate(string $content): string {
        $length = mb_strlen($content);
        if ($length <= $this->maxResultChars) {
            return $content;
        }
        return mb_substr($content, 0, $this->maxResultChars)
            . sprintf("\n… [обрезано: показано %d из %d символов]", $this->maxResultChars, $length);
    }
}
