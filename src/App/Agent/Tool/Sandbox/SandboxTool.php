<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Sandbox;

use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * Common part of the tools that work in the participant's sandbox home. Without sandbox limits in the policy the
 * participant has no sandbox; a sandbox refusal is a result for the model.
 *
 * spec: brief/agent-loop.md#песочница — file and command tools run in the sandbox
 */
abstract class SandboxTool implements Tool {
    public function __construct(protected readonly Sandbox $sandbox) {}

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $limits = $context->policy?->sandbox;
        if ($limits === null) {
            return ToolResult::error(t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika'));
        }
        try {
            return $this->run($args, $context, $limits);
        } catch (SandboxFailure $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     * @throws SandboxFailure
     */
    abstract protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult;
}
