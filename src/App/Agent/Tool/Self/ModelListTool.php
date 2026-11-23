<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\ModelListEditor;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent shows and changes the owner's lists of models by category at the owner's word: what was a command of the
 * owner only (/set, then /restart) is now a request in words.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 */
final class ModelListTool implements Tool, GrantKeyed, \OpenCCK\App\Agent\Tool\WideGrant {
    public const NAME = 'model_list';

    public function __construct(private readonly ModelListEditor $editor) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.model_list.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', ...ModelListEditor::ACTIONS]],
                'category' => ['type' => 'string', 'enum' => ModelListEditor::categories()],
                'models' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200], 'maxItems' => ModelListEditor::MAX],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    /** Changing what the agent may call is the owner's: a page the agent read must not widen it. */
    public function danger(array $args): DangerClass {
        return (Args::string($args, 'action') ?? 'list') === 'list' ? DangerClass::Read : DangerClass::Irreversible;
    }

    public function wideGrant(array $args): ?array {
        return (Args::string($args, 'action') ?? 'list') === 'list' ? null : ['model-list-any', t('agent.grants.wide_model_list')];
    }

    public function grantKey(array $args): ?string {
        $action = Args::string($args, 'action') ?? 'list';
        $category = Args::string($args, 'category') ?? '';
        return $action === 'list' || $category === '' ? null : sprintf('model-list:%s:%s', $action, $category);
    }

    public function describe(array $args): string {
        $models = isset($args['models']) && is_array($args['models']) ? implode(', ', array_filter($args['models'], 'is_string')) : '';
        return trim(sprintf('model_list %s %s %s', Args::string($args, 'action') ?? 'list', Args::string($args, 'category') ?? '', mb_substr($models, 0, 300)));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('model.lists.owner_only'));
        }
        $action = Args::string($args, 'action') ?? 'list';
        if ($action === 'list') {
            return ToolResult::ok($this->editor->show());
        }
        $models = isset($args['models']) && is_array($args['models']) ? array_values(array_filter($args['models'], 'is_string')) : [];
        try {
            return ToolResult::ok($this->editor->change($context->principal, Args::string($args, 'category') ?? '', $action, $models));
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
