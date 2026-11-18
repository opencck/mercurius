<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Skill\SkillImporter;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;

use function Mercurius\I18n\t;

/**
 * The agent brings in an ordinary skill of another agent — a GitHub directory, an archive at an address or an archive
 * sent to the chat (it lies in the sandbox, inbox/) — checks it and unpacks it into the participant's or the active
 * project's folder of skills. The format is the same as of built-in skills: a directory with SKILL.md and files.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a skill from git or an archive, in the folder of the participant or the project
 */
final class SkillImportTool implements Tool, ReadsOutside, ChangesSandbox {
    public const NAME = 'skill_import';

    /** @param Closure(int, ?string, \OpenCCK\Domain\Entity\Principal): ?Project $projectOf the project of a conversation: one named, else the active one */
    public function __construct(
        private readonly SkillImporter $importer,
        private readonly SkillLibrary $library,
        private readonly Closure $projectOf,
        /** archives sent to the chat are read from here; null — only addresses */
        private readonly ?Sandbox $sandbox = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_import.description'), [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
                'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => t('tool.skill_import.path')],
                'where' => ['type' => 'string', 'enum' => ['mine', 'project'], 'description' => t('tool.skill_save.where')],
                'project' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.skill.project')],
            ],
            'additionalProperties' => false,
        ]);
    }

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
        $where = Args::string($args, 'where') ?? 'mine';
        $target = $this->library->target($context->principal, ($this->projectOf)($context->conversationId, Args::string($args, 'project'), $context->principal), $where);
        if ($target === null) {
            return ToolResult::error($where === 'project' ? t('tool.skill_save.no_project') : t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika'));
        }
        $path = Args::string($args, 'path');
        $url = Args::string($args, 'url') ?? '';
        try {
            if ($path !== null && $path !== '') {
                // spec: BRIEF.md#скиллы-и-mcp — a skill sent to the chat as an archive lies in the sandbox (inbox/)
                $limits = $context->policy?->sandbox;
                if ($this->sandbox === null || $limits === null) {
                    return ToolResult::error(t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika'));
                }
                [$bundle, $findings] = $this->importer->archive($this->sandbox->fetch($context->principal->id, $limits, $path, 5 * 1024 * 1024, $context->cancellation), $path);
            } elseif ($url !== '') {
                [$bundle, $findings] = $this->importer->fetch($url, new CompositeCancellation($context->cancellation, new TimeoutCancellation(80)));
            } else {
                return ToolResult::error(t('tool.skill_import.need'));
            }
            $dir = $this->library->write($bundle, $target, $context->cancellation);
        } catch (InvalidArgumentException | SandboxFailure $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf(t('tool.skill_import.done'), $bundle->name, $dir, $bundle->files === [] ? '—' : implode(', ', array_keys($bundle->files)), $findings === [] ? t('skill.import.clean') : implode("\n", array_slice($findings, 0, 10))));
    }
}
