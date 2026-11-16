<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

final class ReadFileTool extends SandboxTool implements Unspillable {
    public const NAME = 'read_file';

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Читает текстовый файл из твоего рабочего каталога в песочнице: строки с номерами, '
            . 'по умолчанию первые 500. Пути — относительно рабочего каталога.', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'maxLength' => 4096],
                'start_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'С какой строки, по умолчанию 1'],
                'max_lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'description' => 'Сколько строк, по умолчанию 500'],
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
        $read = $this->sandbox->read($context->principal->id, $limits, Args::string($args, 'path') ?? '', $start, Args::int($args, 'max_lines') ?? 500, $context->cancellation);
        $lines = $read['text'] === '' ? [] : explode("\n", rtrim($read['text'], "\n"));
        $numbered = [];
        foreach ($lines as $offset => $line) {
            $numbered[] = ($start + $offset) . '| ' . $line;
        }
        $text = implode("\n", $numbered);
        $end = $start + count($lines) - 1;
        if ($start > 1 || $end < $read['totalLines']) {
            $text .= ($text === '' ? '' : "\n") . sprintf('Показаны строки %d–%d из %d.', $start, max($start, $end), $read['totalLines']);
        }
        return ToolResult::ok($text);
    }
}
