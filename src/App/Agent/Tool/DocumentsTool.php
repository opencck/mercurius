<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Infrastructure\Media\DocsService;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * A new docx, xlsx or pptx by a description: the media-docs module makes the file, it lands in the sandbox and can be
 * sent on with send_file. The libraries are not in the sandbox.
 *
 * spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — media-docs builds documents on the agent's request
 */
final class DocumentsTool extends SandboxTool implements NeedsService {
    public const NAME = 'documents';

    public function __construct(Sandbox $sandbox, private readonly DocsService $docs) {
        parent::__construct($sandbox);
    }

    public function service(): string {
        return DocsService::MODULE;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.documents.description'), [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'maxLength' => 4096, 'description' => t('tool.documents.path')],
                'description' => ['type' => 'object', 'description' => t('tool.documents.spec')],
            ],
            'required' => ['path', 'description'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    public function timeout(): int {
        return 120;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $path = trim(Args::string($args, 'path') ?? '');
        $format = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($format, DocsService::BUILDS, true)) {
            return ToolResult::error(t('tool.documents.formats'));
        }
        /** @var mixed $given */
        $given = $args['description'] ?? null;
        // the format follows the extension, whatever the description says
        $spec = ['format' => $format] + (is_array($given) ? array_filter($given, 'is_string', ARRAY_FILTER_USE_KEY) : []);
        try {
            $bytes = $this->docs->build($spec, $context->cancellation);
        } catch (RuntimeException $e) {
            return ToolResult::error(sprintf(t('tool.documents.failed'), $e->getMessage()));
        }
        $this->sandbox->put($context->principal->id, $limits, $path, $bytes, $context->cancellation);
        return ToolResult::ok(sprintf(t('tool.documents.done'), $path, strlen($bytes)));
    }
}
