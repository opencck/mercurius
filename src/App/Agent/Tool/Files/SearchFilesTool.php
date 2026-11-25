<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * Searches the files of the participant: inside them by a regular expression (grep) or by name (find). A read: one
 * command in the sandbox with every argument quoted, the folder of snapshots left out, the lines bounded.
 *
 * spec: brief/agent-loop.md#инструменты — search_files, a read over the files of the participant and the project
 * ref: references/hermes-agent/tools/file_tools.py@e96221fa:L1301-L1318 (SEARCH_FILES_SCHEMA) — pattern, target
 *      content or files, path, file_glob, limit; ripgrep there, grep and find here (they are in the sandbox)
 */
final class SearchFilesTool extends SandboxTool {
    public const NAME = 'search_files';
    private const LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.search_files.about'), [
            'type' => 'object',
            'properties' => [
                'area' => self::areaProperty(),
                'pattern' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => t('tool.search_files.pattern')],
                'target' => ['type' => 'string', 'enum' => ['content', 'files']],
                'path' => ['type' => 'string', 'maxLength' => 4096],
                'file_glob' => ['type' => 'string', 'maxLength' => 200],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
            ],
            'required' => ['pattern'],
            'additionalProperties' => false,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function ownDanger(array $args): DangerClass {
        return DangerClass::Read;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $pattern = Args::string($args, 'pattern') ?? '';
        $path = Args::string($args, 'path') ?? '.';
        $limit = max(1, min(self::MAX_LIMIT, Args::int($args, 'limit') ?? self::LIMIT));
        $glob = Args::string($args, 'file_glob');
        $command = (Args::string($args, 'target') ?? 'content') === 'files'
            ? sprintf("find -- %s -name %s -not -path '*/.checkpoints/*' 2>/dev/null | head -n %d", self::quote($path), self::quote($pattern), $limit)
            : sprintf('grep -rInE --exclude-dir=.checkpoints %s-e %s -- %s 2>/dev/null | head -n %d', $glob === null || $glob === '' ? '' : '--include=' . self::quote($glob) . ' ', self::quote($pattern), self::quote($path), $limit);
        $outcome = $this->sandbox->exec($this->holder($args, $context), $limits, $context->callId, $command, '.', 60, $context->cancellation);
        $found = rtrim($outcome->stdout, "\n");
        if ($found === '') {
            return ToolResult::ok(t('tool.search_files.nothing'));
        }
        return ToolResult::ok($found);
    }

    private static function quote(string $value): string {
        return "'" . str_replace("'", "'\''", $value) . "'";
    }

    public function danger(array $args): DangerClass {
        return self::areaDanger($args, $this->ownDanger($args));
    }
}
