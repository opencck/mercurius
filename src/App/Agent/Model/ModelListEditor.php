<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use InvalidArgumentException;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Member;

use function Mercurius\I18n\t;

/**
 * The owner's lists of models by category, changed in the dialog: the agent adds, removes or replaces models at the
 * owner's word. A model of a text category is checked as for a role; the lists are settings, so a text list works at
 * once and the others after a restart, as their settings do.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 */
final class ModelListEditor {
    public const ACTIONS = ['add', 'remove', 'set'];
    /** models in one list at most: the agent chooses among them, a long list is a list nobody reads */
    public const MAX = 20;
    /** what a model of a provider of an MCP must do to stand in a category */
    public const KIND_OF = [
        ModelRole::CHEAP => 'chat', ModelRole::MAIN => 'chat', ModelRole::STRONG => 'chat', ModelLists::FALLBACK_CATEGORY => 'chat', 'sleep' => 'chat',
        'image' => 'image', 'voice' => 'voice', 'film' => 'film', 'speech' => 'speech', 'vision' => 'vision', 'video' => 'video',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ModelLists $lists,
        private readonly ModelRoles $roles,
    ) {}

    /** @return list<string> the categories: the text roles, the fallback one, then the others */
    public static function categories(): array {
        return [...array_keys(ModelLists::TEXT), ModelLists::FALLBACK_CATEGORY, ...array_keys(ModelLists::OTHER)];
    }

    public static function setting(string $category): ?string {
        return ModelLists::TEXT[$category] ?? ($category === ModelLists::FALLBACK_CATEGORY ? ModelLists::FALLBACK : null) ?? ModelLists::OTHER[$category] ?? null;
    }

    /** Every category: its setting and its models now. */
    public function show(): string {
        $lines = [];
        foreach (ModelLists::TEXT as $role => $setting) {
            $lines[] = sprintf('%s — %s: %s', $role, $setting, implode(', ', array_map('strval', $this->lists->of($role))));
        }
        $fallback = $this->lists->fallback();
        $lines[] = sprintf('%s — %s: %s', ModelLists::FALLBACK_CATEGORY, ModelLists::FALLBACK, $fallback === [] ? '—' : implode(', ', array_map('strval', $fallback)));
        foreach (ModelLists::OTHER as $category => $setting) {
            $models = $this->lists->other($category);
            $lines[] = sprintf('%s — %s: %s', $category, $setting, $models === [] ? '—' : implode(', ', $models));
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<string> $models
     * @return string what the settings said: works at once or after a restart
     * @throws DomainException with what is wrong
     */
    public function change(Member $who, string $category, string $action, array $models): string {
        if (!$who->isOwner()) {
            throw new DomainException(t('model.lists.owner_only'));
        }
        $setting = self::setting($category);
        if ($setting === null) {
            throw new DomainException(sprintf(t('model.lists.no_category'), $category, implode(', ', self::categories())));
        }
        if (!in_array($action, self::ACTIONS, true)) {
            throw new DomainException(sprintf(t('model.lists.no_action'), $action));
        }
        $text = isset(ModelLists::TEXT[$category]);
        $fallback = $category === ModelLists::FALLBACK_CATEGORY;
        $asked = [];
        foreach ($models as $model) {
            $model = trim($model);
            if ($model === '') {
                continue;
            }
            if ($text || $fallback) {
                try {
                    $ref = ModelRef::parse($model);
                } catch (InvalidArgumentException) {
                    throw new DomainException(t('model.model_roles.model_ukazyvaetsya_kak_provayder_identif'));
                }
                // removing does not need the model to be callable now: a model gone from the catalog leaves too
                // a fallback model answers in a conversation of any role: it is checked as the main one
                if ($action !== 'remove') {
                    $this->roles->check($fallback ? ModelRole::MAIN : $category, $ref);
                }
                $model = (string) $ref;
            }
            $asked[$model] = true;
        }
        $asked = array_keys($asked);
        if ($category === 'sleep' && $action !== 'remove') {
            foreach ($asked as $model) {
                if (ModelLists::interactiveOnly($model)) {
                    throw new DomainException(sprintf(t('model.lists.sleep_interactive_only'), $model));
                }
            }
        }
        $chat = $this->checkMcp($category, $action, $asked);
        // spec: brief/models.md#модели-поиска — a model of the search whose provider has no key would leave no search at all
        if ($category === 'search') {
            foreach ($asked as $model) {
                $provider = \OpenCCK\Infrastructure\Config\SearchSettings::providerOf($model);
                if ($provider !== null && !in_array($provider, $this->roles->providers(), true)) {
                    throw new DomainException(sprintf(t('model.lists.search_no_key'), $model, $provider, implode(', ', array_intersect(\OpenCCK\Infrastructure\Config\SearchSettings::MODEL_PROVIDERS, $this->roles->providers())) ?: '—'));
                }
            }
        }
        $now = match (true) {
            $text => array_map('strval', $this->lists->of($category)),
            $fallback => array_map('strval', $this->lists->fallback()),
            default => $this->lists->other($category),
        };
        $next = match ($action) {
            'add' => array_values(array_unique([...$now, ...$asked])),
            'remove' => array_values(array_diff($now, $asked)),
            default => $asked,
        };
        if ($text && $next === []) {
            throw new DomainException(sprintf(t('model.lists.text_not_empty'), $category));
        }
        if (count($next) > self::MAX) {
            throw new DomainException(sprintf(t('model.lists.too_many'), self::MAX));
        }
        $said = $this->settings->set($who, $setting, implode(',', $next));
        $this->lists->forget();
        if ($text) {
            // spec: brief/models.md#списки-моделей-по-категориям — the order of a list does not choose the model of the role:
            //      the answer says which one the role takes now, so nobody tells the owner a guess
            $said .= "
" . sprintf(t('model.lists.role_now'), $category, (string) $this->roles->model($category));
        }
        if ($text && $action !== 'remove') {
            // a name without a provider is a model paid by the token; the agent is told when the subscription has the same
            foreach ($asked as $model) {
                $same = $this->roles->bySubscription(ModelRef::parse($model));
                if ($same !== []) {
                    $said .= "\n" . sprintf(t('model.lists.by_subscription'), $model, implode(', ', $same));
                }
            }
        }
        // spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp — a text model of an MCP sees the whole conversation
        return $chat ? $said . "
" . t('model.catalog.chat_risk') : $said;
    }

    /**
     * A model of a provider of an MCP stands only in a category its kind fits, and only while its provider is known.
     *
     * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
     *
     * @param list<string> $models
     * @return bool whether a text model of an MCP is put in
     * @throws DomainException
     */
    private function checkMcp(string $category, string $action, array $models): bool {
        $chat = false;
        $registry = $this->roles->registry();
        foreach ($models as $name) {
            if (!str_starts_with($name, \OpenCCK\App\Mcp\McpModelProviders::PREFIX) || $action === 'remove') {
                continue;
            }
            [$provider, $id] = explode(':', $name, 2) + [1 => ''];
            $need = self::KIND_OF[$category] ?? null;
            $model = $need === null ? null : $registry?->model($provider, $id, $need);
            $model ??= $registry?->model($provider, $id);
            if ($model === null) {
                $known = $registry === null ? [] : array_keys($registry->all());
                throw new DomainException(sprintf(t('model.lists.mcp_unknown'), $name, implode(', ', $known) ?: '—'));
            }
            if ($need !== $model->kind) {
                $fit = array_keys(array_filter(self::KIND_OF, static fn(string $kind): bool => $kind === $model->kind));
                throw new DomainException(sprintf(t('model.lists.mcp_kind'), $name, $model->kind, $category, implode(', ', $fit) ?: '—'));
            }
            $chat = $chat || $model->kind === 'chat';
        }
        return $chat;
    }
}
