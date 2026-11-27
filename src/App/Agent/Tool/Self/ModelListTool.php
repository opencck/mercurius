<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\ModelCatalogView;
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
 * It also reads what the lists are chosen from: the providers the agent has and the catalog of each.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 * spec: brief/models.md#каталог-провайдеров-для-агента
 */
final class ModelListTool implements Tool, GrantKeyed, \OpenCCK\App\Agent\Tool\WideGrant {
    public const NAME = 'model_list';
    /** the actions that only read */
    private const READS = ['list', 'providers', 'catalog'];

    public function __construct(private readonly ModelListEditor $editor, private readonly ?ModelCatalogView $view = null) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.model_list.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => [...self::READS, ...ModelListEditor::ACTIONS]],
                'category' => ['type' => 'string', 'enum' => ModelListEditor::categories()],
                'provider' => ['type' => 'string', 'maxLength' => 40],
                'query' => ['type' => 'string', 'maxLength' => 100],
                'input' => ['type' => 'string', 'enum' => ModelCatalogView::INPUTS],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ModelCatalogView::MAX],
                'models' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200], 'maxItems' => ModelListEditor::MAX],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    /** Changing what the agent may call is the owner's: a page the agent read must not widen it. */
    public function danger(array $args): DangerClass {
        return self::reads($args) ? DangerClass::Read : DangerClass::Irreversible;
    }

    public function wideGrant(array $args): ?array {
        return self::reads($args) ? null : ['model-list-any', t('agent.grants.wide_model_list')];
    }

    public function grantKey(array $args): ?string {
        $action = Args::string($args, 'action') ?? 'list';
        $category = Args::string($args, 'category') ?? '';
        return self::reads($args) || $category === '' ? null : sprintf('model-list:%s:%s', $action, $category);
    }

    /** @param array<string, mixed> $args */
    private static function reads(array $args): bool {
        return in_array(Args::string($args, 'action') ?? 'list', self::READS, true);
    }

    public function describe(array $args): string {
        $models = isset($args['models']) && is_array($args['models']) ? implode(', ', array_filter($args['models'], 'is_string')) : '';
        $line = trim(sprintf('model_list %s %s %s', Args::string($args, 'action') ?? 'list', Args::string($args, 'category') ?? '', mb_substr($models, 0, 300)));
        // spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp — the card that puts a text model of an MCP says what it will see
        $category = Args::string($args, 'category') ?? '';
        if (str_contains($models, \OpenCCK\App\Mcp\McpModelProviders::PREFIX) && (\OpenCCK\App\Agent\Model\ModelListEditor::KIND_OF[$category] ?? null) === 'chat') {
            $line .= "
" . t('model.catalog.chat_risk');
        }
        return $line;
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
        if ($action === 'providers' || $action === 'catalog') {
            if ($this->view === null) {
                return ToolResult::error(sprintf(t('model.lists.no_action'), $action));
            }
            if ($action === 'providers') {
                return ToolResult::ok($this->view->providers());
            }
            try {
                $limit = isset($args['limit']) && is_int($args['limit']) ? $args['limit'] : ModelCatalogView::DEFAULT;
                return ToolResult::ok($this->view->catalog(Args::string($args, 'provider') ?? '', Args::string($args, 'query'), Args::string($args, 'input'), $limit));
            } catch (DomainException $e) {
                return ToolResult::error($e->getMessage());
            }
        }
        $models = isset($args['models']) && is_array($args['models']) ? array_values(array_filter($args['models'], 'is_string')) : [];
        try {
            return ToolResult::ok($this->editor->change($context->principal, Args::string($args, 'category') ?? '', $action, $models));
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
