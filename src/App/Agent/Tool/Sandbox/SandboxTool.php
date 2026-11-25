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
 * spec: brief/agent-loop.md#инструменты — file and command tools run in the sandbox
 */
abstract class SandboxTool implements Tool {
    public function __construct(protected readonly Sandbox $sandbox) {}

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 60;
    }

    /** the holder of the owners' area in the sandbox: the root, where every member's folder lies */
    public const OWNERS_AREA = 0;

    public function execute(array $args, ToolContext $context): ToolResult {
        $limits = $context->policy?->sandbox;
        if ($limits === null) {
            return ToolResult::error(t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika'));
        }
        // spec: brief/agent-loop.md#раскладка-каталогов — the root is the owners' area: only an owner of the agent works there
        if (self::inOwnersArea($args) && !$context->principal->isOwner()) {
            return ToolResult::error(t('tool.sandbox.area_owners_only'));
        }
        try {
            return $this->run($args, $context, $limits);
        } catch (SandboxFailure $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /** @param array<string, mixed> $args */
    private static function inOwnersArea(array $args): bool {
        return ($args['area'] ?? null) === 'owners';
    }

    /**
     * Whose folder the call works in: the owners' area or the caller's own. Only for the sandbox: grants, secrets and
     * approvals stay the caller's.
     *
     * @param array<string, mixed> $args
     */
    protected function holder(array $args, ToolContext $context): int {
        return self::inOwnersArea($args) ? self::OWNERS_AREA : $context->principal->id;
    }

    /** @return array<string, mixed> the argument of the tools that may work in the owners' area */
    protected static function areaProperty(): array {
        return ['type' => 'string', 'enum' => ['own', 'owners'], 'description' => t('tool.sandbox.area')];
    }

    /**
     * @param array<string, mixed> $args
     * @throws SandboxFailure
     */
    abstract protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult;
}
