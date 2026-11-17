<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Search\SearchException;
use Mercurius\Search\SearchProvider;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * Searching the web: the agent picks a provider from the configured ones by what the task needs, or leaves it to the
 * order of SEARCH_PROVIDERS (a failed one hands over to the next); answers are cached for a while. The answer and the
 * pages are untrusted data; to check a fact, open the page with web_read.
 *
 * spec: BRIEF.md#решения-владельца-2026-11-17 — search through an interface with pluggable providers
 */
final class WebSearchTool implements Tool {
    public const NAME = 'web_search';

    /**
     * @param array<string, SearchProvider> $providers each configured provider by name
     * @param array<string, string> $about what each is good for
     */
    public function __construct(
        private readonly SearchProvider $search,
        private readonly array $providers = [],
        private readonly array $about = [],
    ) {}

    public function definition(): ToolDefinition {
        $properties = [
            'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 500],
            'results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
        ];
        $choice = '';
        if (count($this->providers) > 1) {
            $properties['provider'] = ['type' => 'string', 'enum' => array_keys($this->providers)];
            $choice = t('tool.web_search.provider_kakoy_poisk_vzyat_bez') . implode('; ', array_map(
                fn(string $name): string => $name . ' — ' . ($this->about[$name] ?? ''),
                array_keys($this->providers),
            )) . t('tool.web_search.dlya_prostogo_fakta_hvatit_deshevogo');
        }
        return new ToolDefinition(self::NAME, t('tool.web_search.ischet_v_internete_vozvraschaet_spisok')
            . t('tool.web_search.otvet_poiska_mozhet_oshibatsya_vazhnoe') . $choice, [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['query'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 90;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $limit = max(1, min(10, Args::int($args, 'results') ?? 5));
        $name = Args::string($args, 'provider');
        $search = $name === null ? $this->search : ($this->providers[$name] ?? null);
        if ($search === null) {
            return ToolResult::error(sprintf(t('tool.web_search.poiska_net_est'), (string) $name, implode(', ', array_keys($this->providers))));
        }
        try {
            return ToolResult::ok($search->search(Args::string($args, 'query') ?? '', $limit, $context->cancellation)->toText());
        } catch (SearchException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
