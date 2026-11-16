<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

final class ListFilesTool extends SandboxTool {
    public const NAME = 'list_files';

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Список файлов и каталогов в твоём рабочем каталоге песочницы: тип, размер, путь '
            . '(не больше 500 записей).', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'maxLength' => 4096, 'description' => 'Каталог, по умолчанию рабочий'],
                'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'description' => 'Глубина, по умолчанию 1'],
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
            return ToolResult::ok('Каталог пуст.');
        }
        return ToolResult::ok(implode("\n", array_map(static fn(array $e): string => sprintf('%s %d %s', $e['type'], $e['size'], $e['path']), $entries)));
    }
}
