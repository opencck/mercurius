<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Skill\SkillImporter;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent brings in an ordinary skill of another agent (a GitHub directory or an archive). It is checked and goes to
 * the owner's card; the agent can use it only after the owner accepts.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 */
final class SkillImportTool implements Tool, ReadsOutside {
    public const NAME = 'skill_import';

    public function __construct(private readonly SkillImporter $importer) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_import.description'), [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            ],
            'required' => ['url', 'reason'],
            'additionalProperties' => false,
        ]);
    }

    /** It changes nothing until the owner accepts the card. */
    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    /** spec: BRIEF.md#подтверждения-и-запреты — a skill of another agent is outside text */
    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 90;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        try {
            $version = $this->importer->import(
                Args::string($args, 'url') ?? '',
                Args::string($args, 'reason') ?? '',
                $context->principal->id,
                new CompositeCancellation($context->cancellation, new TimeoutCancellation(80)),
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf(t('skill.import.proposed'), $version->name, $version->version));
    }
}