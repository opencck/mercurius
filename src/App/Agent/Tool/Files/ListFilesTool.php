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

final class ListFilesTool extends SandboxTool {
    public const NAME = 'list_files';

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.list_files.spisok_faylov_i_katalogov_v')
            . t('tool.list_files.ne_bolshe_500_zapisey'), [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'maxLength' => 4096, 'description' => t('tool.list_files.katalog_po_umolchaniyu_rabochiy')],
                'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'description' => t('tool.list_files.glubina_po_umolchaniyu_1')],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $entries = $this->sandbox->list($context->principal->id, $limits, Args::string($args, 'path') ?? '.', Args::int($args, 'depth') ?? 1, $context->cancellation);
        if ($entries === []) {
            return ToolResult::ok(t('tool.list_files.katalog_pust'));
        }
        return ToolResult::ok(implode("\n", array_map(static fn(array $e): string => sprintf('%s %d %s', $e['type'], $e['size'], $e['path']), $entries)));
    }
}
