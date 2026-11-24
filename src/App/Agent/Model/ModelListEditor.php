<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use InvalidArgumentException;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Entity\Principal;

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

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ModelLists $lists,
        private readonly ModelRoles $roles,
    ) {}

    /** @return list<string> the categories: the text roles, then the others */
    public static function categories(): array {
        return [...array_keys(ModelLists::TEXT), ...array_keys(ModelLists::OTHER)];
    }

    public static function setting(string $category): ?string {
        return ModelLists::TEXT[$category] ?? ModelLists::OTHER[$category] ?? null;
    }

    /** Every category: its setting and its models now. */
    public function show(): string {
        $lines = [];
        foreach (ModelLists::TEXT as $role => $setting) {
            $lines[] = sprintf('%s — %s: %s', $role, $setting, implode(', ', array_map('strval', $this->lists->of($role))));
        }
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
    public function change(Principal $who, string $category, string $action, array $models): string {
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
        $asked = [];
        foreach ($models as $model) {
            $model = trim($model);
            if ($model === '') {
                continue;
            }
            if ($text) {
                try {
                    $ref = ModelRef::parse($model);
                } catch (InvalidArgumentException) {
                    throw new DomainException(t('model.model_roles.model_ukazyvaetsya_kak_provayder_identif'));
                }
                // removing does not need the model to be callable now: a model gone from the catalog leaves too
                if ($action !== 'remove') {
                    $this->roles->check($category, $ref);
                }
                $model = (string) $ref;
            }
            $asked[$model] = true;
        }
        $asked = array_keys($asked);
        $now = $text ? array_map('strval', $this->lists->of($category)) : $this->lists->other($category);
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
        if ($text && $action !== 'remove') {
            // a name without a provider is a model paid by the token; the agent is told when the subscription has the same
            foreach ($asked as $model) {
                $same = $this->roles->bySubscription(ModelRef::parse($model));
                if ($same !== []) {
                    $said .= "\n" . sprintf(t('model.lists.by_subscription'), $model, implode(', ', $same));
                }
            }
        }
        return $said;
    }
}
