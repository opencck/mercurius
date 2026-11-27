<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ProviderProfile;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\ModelRoleRepository;

use function Mercurius\I18n\t;

/**
 * Which model plays which role. The agent chooses models for tasks itself; so do the owner and those the owner
 * allowed (the set_model_role tool). What the owner fixed, only the owner changes.
 *
 * spec: brief/agent-loop.md#модели — role table, who manages it
 * spec: brief/models.md#начальная-таблица-ролей-моделей — seed values
 */
final class ModelRoles {
    private const MIN_REASON = 10;

    /** @param list<string> $providers providers with a key; their models are the only ones that can be used */
    public function __construct(
        private readonly ModelRoleRepository $roles,
        private readonly ModelCatalogRepository $catalog,
        private readonly array $providers = [ModelRef::OPENROUTER],
        /** moves the open conversations of a role to its new model; null — they keep theirs */
        private readonly ?RoleConversations $conversations = null,
        /** the models the owner allowed for each role; null — any model of the catalog (tests of the roles themselves) */
        private readonly ?ModelLists $lists = null,
    ) {}

    /**
     * Whether the model may answer in a conversation at all: it is in one of the owner's text lists.
     *
     * spec: brief/models.md#списки-моделей-по-категориям — a model in no list is never called
     */
    public function allowedText(ModelRef $model): bool {
        return $this->lists === null || $this->lists->allowsText($model);
    }

    /** @return list<ModelRef> the fallback text models the owner named */
    public function fallback(): array {
        return $this->lists?->fallback() ?? [];
    }

    /** @return list<ModelRef> the owner's list of a role; empty — no list is kept (any model) */
    public function listOf(string $role): array {
        return $this->lists?->of($role) ?? [];
    }

    /** @return list<string> */
    public function providers(): array {
        return $this->providers;
    }

    /** @return list<string> the ids of a provider's catalog, at most 40: the catalog of a subscription is short */
    public function catalogOf(string $provider): array {
        return array_slice(array_map(static fn(\Mercurius\Llm\ModelCatalog\ModelInfo $m): string => $m->id, $this->catalog->models($provider)), 0, 40);
    }

    public function seed(): void {
        $this->roles->seed(array_map(ModelRef::parse(...), $this->seedTable()), t('model.model_roles.nachalnaya_tablitsa_roley_brief_2026'));
    }

    /** A role whose model is of a provider without a key falls back to the seed value of the providers there are. */
    public function model(string $role): ModelRef {
        $row = $this->roles->all()[$role] ?? null;
        if ($this->lists !== null) {
            // spec: brief/models.md#списки-моделей-по-категориям — the model of a role is one of its list: the one chosen, while
            //       it is still there, else the first of the list
            $role = isset(ModelLists::TEXT[$role]) ? $role : ModelRole::MAIN;
            $row = $this->roles->all()[$role] ?? null;
            if ($row !== null && $this->lists->allows($role, $row->model) && in_array($row->model->provider, $this->providers, true)) {
                return $row->model;
            }
            foreach ($this->lists->of($role) as $allowed) {
                if (in_array($allowed->provider, $this->providers, true)) {
                    return $allowed;
                }
            }
            return $this->lists->of($role)[0] ?? $this->lists->of(ModelRole::MAIN)[0] ?? ModelRef::parse($this->seedTable()[ModelRole::MAIN]);
        }
        if ($row !== null && in_array($row->model->provider, $this->providers, true)) {
            return $row->model;
        }
        $seed = $this->seedTable();
        return ModelRef::parse($seed[$role] ?? $seed[ModelRole::MAIN]);
    }

    /**
     * The seed of the first provider with a key, in the order of the profiles: OpenRouter first.
     *
     * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — default roles by provider
     *
     * @return array<string, string>
     */
    private function seedTable(): array {
        if ($this->lists !== null) {
            $seed = [];
            foreach (array_keys(ModelLists::TEXT) as $role) {
                $first = $this->lists->of($role)[0] ?? null;
                if ($first !== null) {
                    $seed[$role] = (string) $first;
                }
            }
            if (isset($seed[ModelRole::MAIN])) {
                return $seed;
            }
        }
        foreach (ProviderProfile::all() as $name => $profile) {
            if (in_array($name, $this->providers, true)) {
                return $profile->roles;
            }
        }
        return ProviderProfile::all()[ModelRef::OPENROUTER]->roles;
    }

    /**
     * Whether the model would cost more per token than the one in the role now; an unknown price counts as more.
     */
    public function pricier(string $role, string $model): bool {
        try {
            $next = $this->catalog->find(ModelRef::parse(trim($model)));
        } catch (InvalidArgumentException) {
            return true;
        }
        $now = $this->catalog->find($this->model($role));
        if ($next === null || $next->inputPricePerToken === null || $next->outputPricePerToken === null) {
            return true;
        }
        if ($now === null || $now->inputPricePerToken === null || $now->outputPricePerToken === null) {
            return false;
        }
        return $next->inputPricePerToken > $now->inputPricePerToken || $next->outputPricePerToken > $now->outputPricePerToken;
    }

    /** @return array<string, ModelRole> */
    public function all(): array {
        return $this->roles->all();
    }

    /**
     * The roles as they work now, for a report: the record of a role, or — when the model of the record is no longer
     * in the list of the role or its provider has no key — the model that answers instead, with a note of what is gone.
     * The table keeps the old record; a report that printed it named a model that is never called.
     *
     * spec: brief/models.md#списки-моделей-по-категориям — the model of a role is one of its list
     *
     * @return array<string, ModelRole>
     */
    public function current(): array {
        $current = [];
        foreach ($this->roles->all() as $name => $row) {
            $works = isset(ModelLists::TEXT[$name]) || $this->lists === null ? $this->model($name) : $row->model;
            $current[$name] = (string) $works === (string) $row->model
                ? $row
                : new ModelRole($name, $works, sprintf(t('model.model_roles.choice_gone'), (string) $row->model), ModelRole::SET_BY_LIST);
        }
        return $current;
    }

    /**
     * The ids of the provider's catalog that are like the one asked for, the closest first: a model is often named
     * by its words («fable 5.2») while the catalog writes them its own way.
     *
     * @return list<string>
     */
    private function alike(ModelRef $ref, ?string $provider = null, int $names = 1): array {
        $words = array_values(array_filter(preg_split('~[^a-z0-9]+~', strtolower($ref->model)) ?: [], static fn(string $w): bool => $w !== ''));
        $named = array_filter($words, static fn(string $w): bool => strlen($w) >= 3 && !ctype_digit($w));
        $scores = [];
        foreach ($this->catalog->models($provider ?? $ref->provider) as $info) {
            $parts = preg_split('~[^a-z0-9]+~', strtolower($info->id)) ?: [];
            if (count(array_intersect($named, $parts)) < $names) {
                continue;
            }
            $scores[$info->id] = count(array_intersect($words, $parts));
        }
        arsort($scores);
        return array_slice(array_map('strval', array_keys($scores)), 0, 5);
    }

    /**
     * The models of a connected subscription that are the same family as a model paid by the token: an owner who says
     * «claude sonnet» with a subscription of Claude signed in most likely means the subscription, while a name without
     * a provider goes to the paid one. Two words of the name must agree (`claude` and `sonnet`), so a subscription is
     * not offered for every model of its maker.
     *
     * spec: brief/models.md#списки-моделей-по-категориям
     *
     * @return list<string> provider:model, the closest first; empty for a model of a subscription itself
     */
    public function bySubscription(ModelRef $ref): array {
        if (in_array($ref->provider, ModelRef::SUBSCRIPTIONS, true)) {
            return [];
        }
        $found = [];
        foreach (array_intersect(ModelRef::SUBSCRIPTIONS, $this->providers) as $subscription) {
            foreach ($this->alike($ref, $subscription, 2) as $id) {
                $found[] = $subscription . ':' . $id;
            }
        }
        return $found;
    }

    /**
     * The latest changes of the models of roles, newest first.
     *
     * @return list<array{id: int, role: string, model: ModelRef, reason: string, setBy: string, by: ?int, at: int}>
     */
    public function history(int $limit): array {
        return $this->roles->history($limit);
    }

    /**
     * Brings a role back to the model of record $id; the owner's. Says what was done or why not.
     *
     * spec: brief/self-improvement.md#самоулучшение — the history of model roles with a rollback
     */
    public function rollback(int $id, Member $by): string {
        if (!$by->isOwner()) {
            return sprintf(t('model.model_roles.rollback_owner_only'), $id);
        }
        foreach ($this->roles->history(200) as $record) {
            if ($record['id'] === $id) {
                try {
                    $this->set($record['role'], (string) $record['model'], sprintf(t('model.model_roles.rollback_reason'), $id), $by, false);
                } catch (DomainException $e) {
                    return $e->getMessage();
                }
                return sprintf(t('model.model_roles.rolled_back'), $record['role'], (string) $record['model'], $id);
            }
        }
        return sprintf(t('model.model_roles.no_record'), $id);
    }

    /**
     * Puts a model into a role. A fix (owner only) stops anyone else from changing the role later. The open
     * conversations of the role move to the new model from their next run.
     *
     * spec: brief/agent-loop.md#модели — a change of the model of a role reaches the open conversations of that role
     *
     * @throws DomainException with a message for the model when the change is refused
     */
    /** @var (\Closure(string, string, Member): void)|null */
    private ?\Closure $widen = null;

    /**
     * A model the owner names for a role joins the list of that role through this; without it a model outside the
     * list is refused to everyone.
     *
     * spec: brief/models.md#списки-моделей-по-категориям
     *
     * @param \Closure(string, string, Member): void $widen the role, the model, who asked
     */
    public function widenWith(\Closure $widen): void {
        $this->widen = $widen;
    }

    /**
     * Whether a model can play a text role: its provider has a key, the catalog knows it, and it calls tools unless the
     * role is the cheap one.
     *
     * @throws DomainException with what is wrong
     */
    public function check(string $role, ModelRef $ref): void {
        if (!in_array($ref->provider, $this->providers, true)) {
            throw new DomainException(sprintf(t('model.model_roles.u_provaydera_net_klyucha_dostupny'), $ref->provider, implode(', ', $this->providers)));
        }
        $info = $this->catalog->find($ref);
        if ($info === null) {
            $alike = $this->alike($ref);
            throw new DomainException(sprintf(t('model.model_roles.modeli_net_v_kataloge_provaydera'), $ref->model, $ref->provider)
                . ($alike === [] ? '' : ' ' . sprintf(t('model.model_roles.alike'), implode(', ', $alike))));
        }
        if ($role !== ModelRole::CHEAP && !$info->supportsTools()) {
            throw new DomainException(sprintf(t('model.model_roles.model_ne_vyzyvaet_instrumenty_a'), $ref, $role));
        }
    }

    public function set(string $role, string $model, string $reason, Member $by, bool $fix): RoleChange {
        if (!in_array($role, ModelRole::ALL, true)) {
            throw new DomainException(sprintf(t('model.model_roles.net_roli_roli'), $role, implode(', ', ModelRole::ALL)));
        }
        if (mb_strlen(trim($reason)) < self::MIN_REASON) {
            throw new DomainException(t('model.model_roles.nuzhna_prichina_ne_koroche_10'));
        }
        if ($fix && !$by->isOwner()) {
            throw new DomainException(t('model.model_roles.zafiksirovat_rol_mozhet_tolko_vladelets'));
        }
        try {
            $ref = ModelRef::parse(trim($model));
        } catch (InvalidArgumentException) {
            throw new DomainException(t('model.model_roles.model_ukazyvaetsya_kak_provayder_identif'));
        }
        $this->check($role, $ref);
        // spec: brief/models.md#списки-моделей-по-категориям — the agent chooses among the models the owner allowed for
        //       the role; a model the owner names for a role joins its list, from anyone else it is refused
        if ($this->lists !== null && !$this->lists->allows($role, $ref)) {
            if ($by->isOwner() && $this->widen !== null && isset(ModelLists::TEXT[$role])) {
                ($this->widen)($role, (string) $ref, $by);
            } else {
                $allowed = implode(', ', array_map(static fn(ModelRef $m): string => (string) $m, $this->lists->of($role)));
                throw new DomainException(sprintf(t('model.model_roles.not_in_list'), (string) $ref, $role, $allowed === '' ? '—' : $allowed, ModelLists::TEXT[$role] ?? 'LLM_MAIN_MODELS'));
            }
        }
        // read before the write: the conversations to move are those of the old model
        $old = $this->model($role);
        $holders = count(array_filter(ModelRole::ALL, fn(string $r): bool => (string) $this->model($r) === (string) $old));
        $setBy = $fix ? ModelRole::SET_BY_OWNER : ModelRole::SET_BY_AGENT;
        if (!$this->roles->set($role, $ref, trim($reason), $setBy, $by->id, $by->isOwner())) {
            throw new DomainException(sprintf(t('model.model_roles.rol_zafiksiroval_vladelets_izmenit_ee'), $role));
        }
        return new RoleChange($ref, $this->conversations?->move($role, $old, $ref, $holders === 1) ?? 0);
    }
}
