<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ProviderProfile;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Principal;
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
    ) {}

    /** @return list<string> */
    public function providers(): array {
        return $this->providers;
    }

    public function seed(): void {
        $this->roles->seed(array_map(ModelRef::parse(...), $this->seedTable()), t('model.model_roles.nachalnaya_tablitsa_roley_brief_2026'));
    }

    /** A role whose model is of a provider without a key falls back to the seed value of the providers there are. */
    public function model(string $role): ModelRef {
        $row = $this->roles->all()[$role] ?? null;
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
     * spec: brief/decisions.md#самоулучшение-решено-2026-11-18 — the history of model roles with a rollback (decision В8)
     */
    public function rollback(int $id, Principal $by): string {
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
    public function set(string $role, string $model, string $reason, Principal $by, bool $fix): RoleChange {
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
        if (!in_array($ref->provider, $this->providers, true)) {
            throw new DomainException(sprintf(t('model.model_roles.u_provaydera_net_klyucha_dostupny'), $ref->provider, implode(', ', $this->providers)));
        }
        $info = $this->catalog->find($ref);
        if ($info === null) {
            throw new DomainException(sprintf(t('model.model_roles.modeli_net_v_kataloge_provaydera'), $ref->model, $ref->provider));
        }
        if ($role !== ModelRole::CHEAP && !$info->supportsTools()) {
            throw new DomainException(sprintf(t('model.model_roles.model_ne_vyzyvaet_instrumenty_a'), $ref, $role));
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
