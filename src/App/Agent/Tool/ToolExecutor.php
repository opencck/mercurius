<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\Files\CheckpointTool;
use OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper;
use OpenCCK\App\Agent\Tool\Sandbox\ChangesSandbox;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\Domain\Agent\DangerClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Runs one validated call. A tool error, an exception or a timeout is always a result for the model;
 * only cancellation of the run itself (/stop, shutdown) propagates.
 *
 * spec: BRIEF.md#инструменты — an error is a result, not an exception
 * spec: BRIEF.md#лимиты — timeout per tool call
 */
final class ToolExecutor {
    /**
     * @param positive-int $maxResultChars results longer than this go to a file in the sandbox, or are cut without one
     * @param positive-int $previewChars what stays in the context of a spilled result
     */
    public function __construct(
        private readonly int $maxResultChars = 50_000,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?Sandbox $sandbox = null,
        private readonly int $previewChars = 1500,
        /** snapshots of the working folder before the first change of a run; null — none */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Checkpoints $checkpoints = null,
    ) {}

    /**
     * @param positive-int $timeoutSeconds
     * @param positive-int|null $maxChars the ceiling of this result in the context (by the window of the model); null — the default
     */
    public function run(ValidatedCall $call, ToolContext $context, int $timeoutSeconds, ?int $maxChars = null): ToolResult {
        if ($call->error !== null || $call->tool === null || $call->args === null) {
            return ToolResult::error($call->error ?? t('tool.tool_executor.vyzov_ne_proshel_proverku'));
        }
        $tool = $call->tool;
        $name = $call->call->name;
        $timeout = new TimeoutCancellation($timeoutSeconds);
        // spec: BRIEF.md#планировщик-и-проекты — a snapshot before the first change a run makes in the working folder
        $limits = $context->policy?->sandbox;
        if ($this->checkpoints !== null && $limits !== null && ($tool instanceof SandboxTool || $tool instanceof ChangesSandbox) && !$tool instanceof CheckpointTool && $tool->danger($call->args) !== DangerClass::Read) {
            $this->checkpoints->ensure($context->principal->id, $limits, $context->runId, $context->cancellation);
        }

        try {
            $result = $tool->execute($call->args, $context->withCancellation(new CompositeCancellation($context->cancellation, $timeout)));
        } catch (CancelledException $e) {
            if ($context->cancellation->isRequested()) {
                throw $e;
            }
            $this->logger->warning('Tool timed out', ['tool' => $name, 'timeout' => $timeoutSeconds]);
            return ToolResult::error(sprintf(t('tool.tool_executor.instrument_ne_ulozhilsya_v_s'), $name, $timeoutSeconds));
        } catch (Throwable $e) {
            // The message may carry secrets or internals: the model and the log get the class only.
            $this->logger->error('Tool failed', ['tool' => $name, 'error' => $e::class]);
            return ToolResult::error(sprintf(t('tool.tool_executor.instrument_zavershilsya_s_oshibkoy'), $name, (new \ReflectionClass($e))->getShortName()));
        }

        // PostgreSQL text cannot hold NUL, and a fetched body may contain it.
        $content = str_replace("\0", "\u{FFFD}", $result->content);
        $limit = $maxChars ?? $this->maxResultChars;
        $content = $tool instanceof Unspillable ? $this->truncate($content, $limit) : ($this->spill($content, $context, $limit) ?? $this->truncate($content, $limit));
        if ($tool->untrusted()) {
            $content = UntrustedWrapper::wrap($name, $content);
        }
        return new ToolResult($content, $result->isError);
    }

    /**
     * A long result goes into the participant's sandbox home; the context gets its beginning and the path.
     * Null when it is short or there is nowhere to put it (no sandbox, write failed).
     *
     * spec: BRIEF.md#инструменты — a large result is saved to a file, a preview and the path stay in the context
     * ref: references/hermes-agent/tools/tool_result_storage.py@e96221f:L278-L300, L424 (generate_preview,
     *      maybe_persist_tool_result) — persist instead of cutting, preview with the path; the threshold is fixed here
     *      until the model catalogue gives the window size
     */
    /**
     * Makes a result small for the budget of the turn: its file and a preview, or a cut to the preview when there is
     * no sandbox.
     *
     * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the results of a turn fit 30% of the window
     */
    public function shrink(string $content, ToolContext $context): string {
        return $this->spill($content, $context, $this->previewChars) ?? $this->truncate($content, $this->previewChars);
    }

    private function spill(string $content, ToolContext $context, int $limit): ?string {
        $length = mb_strlen($content);
        $limits = $context->policy?->sandbox;
        if ($length <= $limit || $this->sandbox === null || $limits === null) {
            return null;
        }
        $callId = preg_replace('/[^A-Za-z0-9_-]/', '_', $context->callId) ?? '';
        $callId = $callId === '' ? 'call' : $callId;
        $path = sprintf('.mercurius/results/%d/%s.txt', $context->runId, $callId);
        try {
            $this->sandbox->write($context->principal->id, $limits, $path, $content, $context->cancellation);
        } catch (SandboxFailure $e) {
            $this->logger->warning('Could not spill a tool result', ['run' => $context->runId]);
            return null;
        }
        return mb_substr($content, 0, $this->previewChars)
            . sprintf(t('tool.tool_executor.polnyy_rezultat_simvolov_sohranen_chitay'), $length, $path);
    }

    private function truncate(string $content, int $limit): string {
        $length = mb_strlen($content);
        if ($length <= $limit) {
            return $content;
        }
        return mb_substr($content, 0, $limit)
            . sprintf(t('tool.tool_executor.obrezano_pokazano_iz_simvolov'), $limit, $length);
    }
}
