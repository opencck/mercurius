<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Checkpoints;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * Snapshots of the working folder, taken before the first change of every run: the list, what changed since one, and
 * a restore of the folder or of one path (what is there now is kept first, so a restore can be undone).
 *
 * spec: BRIEF.md#планировщик-и-проекты — snapshots of the working folder with a rollback
 */
final class CheckpointTool extends SandboxTool {
    public const NAME = 'checkpoint';

    public function __construct(Sandbox $sandbox, private readonly Checkpoints $checkpoints) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.checkpoint.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'diff', 'restore']],
                'id' => ['type' => 'string', 'maxLength' => 40, 'description' => t('tool.checkpoint.id')],
                'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => t('tool.checkpoint.path')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'restore' ? DangerClass::Write : DangerClass::Read;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $action = Args::string($args, 'action') ?? 'list';
        if ($action === 'list') {
            $out = $this->checkpoints->list($context->principal->id, $limits, $context->cancellation);
            return ToolResult::ok(trim($out->stdout) === '' || trim($out->stdout) === 'none' ? t('tool.checkpoint.none') : t('tool.checkpoint.list') . "\n" . trim($out->stdout));
        }
        $id = Args::string($args, 'id') ?? '';
        $path = Args::string($args, 'path');
        if (!Checkpoints::validId($id) || ($path !== null && !Checkpoints::validPath($path))) {
            return ToolResult::error(t('tool.checkpoint.bad'));
        }
        if ($action === 'diff') {
            $out = $this->checkpoints->diff($context->principal->id, $limits, $id, $context->cancellation);
            return $out->exitCode === 0 ? ToolResult::ok(trim($out->stdout) === '' ? t('tool.checkpoint.same') : trim($out->stdout)) : ToolResult::error(trim($out->stdout . $out->stderr));
        }
        $out = $this->checkpoints->restore($context->principal->id, $limits, $id, $path, $context->cancellation);
        return $out->exitCode === 0
            ? ToolResult::ok(sprintf(t('tool.checkpoint.restored'), $path ?? '.', $id))
            : ToolResult::error(mb_substr(trim($out->stdout . "\n" . $out->stderr), 0, 500));
    }
}
