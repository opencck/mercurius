<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Tool\Unspillable;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

final class ReadFileTool extends SandboxTool implements Unspillable {
    public const NAME = 'read_file';

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.read_file.chitaet_tekstovyy_fayl_iz_tvoego')
            . t('tool.read_file.po_umolchaniyu_pervye_500_puti'), [
            'type' => 'object',
            'properties' => [
                'area' => self::areaProperty(),
                'path' => ['type' => 'string', 'maxLength' => 4096],
                'start_line' => ['type' => 'integer', 'minimum' => 1, 'description' => t('tool.read_file.s_kakoy_stroki_po_umolchaniyu')],
                'max_lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'description' => t('tool.read_file.skolko_strok_po_umolchaniyu_500')],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $start = Args::int($args, 'start_line') ?? 1;
        $read = $this->sandbox->read($this->holder($args, $context), $limits, Args::string($args, 'path') ?? '', $start, Args::int($args, 'max_lines') ?? 500, $context->cancellation);
        $lines = $read['text'] === '' ? [] : explode("\n", rtrim($read['text'], "\n"));
        $numbered = [];
        foreach ($lines as $offset => $line) {
            $numbered[] = ($start + $offset) . '| ' . $line;
        }
        $text = implode("\n", $numbered);
        $end = $start + count($lines) - 1;
        if ($start > 1 || $end < $read['totalLines']) {
            $text .= ($text === '' ? '' : "\n") . sprintf(t('tool.read_file.pokazany_stroki_iz'), $start, max($start, $end), $read['totalLines']);
        }
        return ToolResult::ok($text);
    }
}
