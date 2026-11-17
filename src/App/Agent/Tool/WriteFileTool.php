<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

final class WriteFileTool extends SandboxTool {
    public const NAME = 'write_file';

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.write_file.pishet_fayl_v_tvoem_rabochem')
            . t('tool.write_file.zamenoy_fragmenta_old_new_old'), [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'maxLength' => 4096],
                'content' => ['type' => 'string'],
                'old' => ['type' => 'string'],
                'new' => ['type' => 'string'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $path = Args::string($args, 'path') ?? '';
        $content = Args::string($args, 'content');
        $old = Args::string($args, 'old');
        $new = Args::string($args, 'new');
        if ($content !== null && $old === null && $new === null) {
            $this->sandbox->write($context->principal->id, $limits, $path, $content, $context->cancellation);
            return ToolResult::ok(sprintf(t('tool.write_file.fayl_zapisan_bayt'), $path, strlen($content)));
        }
        if ($content === null && $old !== null && $new !== null) {
            $this->sandbox->replace($context->principal->id, $limits, $path, $old, $new, $context->cancellation);
            return ToolResult::ok(sprintf(t('tool.write_file.fayl_izmenen'), $path));
        }
        return ToolResult::error(t('tool.write_file.ukazhi_content_ili_paru_old'));
    }
}
