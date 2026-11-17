<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\ModelRoleRepository;

use function Mercurius\I18n\t;

/**
 * Which model plays which role. The agent chooses models for tasks itself; so do the owner and those the owner
 * allowed (the set_model_role tool). What the owner fixed, only the owner changes.
 *
 * spec: BRIEF.md#модели — role table, who manages it
 * spec: BRIEF.md#начальная-таблица-ролей-моделей — seed values
 */
final class ModelRoles {
    /** @var array<string, string> */
    public const SEED = [
        ModelRole::CHEAP => 'openrouter:openai/gpt-5.6-luna',
        ModelRole::MAIN => 'openrouter:z-ai/glm-5.3-flash',
        ModelRole::STRONG => 'openrouter:anthropic/claude-opus-5',
        ModelRole::MEDIA => 'openrouter:google/gemini-3.8-flash',
    ];

    /**
     * Seed values when only the OpenAI API has a key.
     *
     * spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — default roles by provider
     *
     * @var array<string, string>
     */
    public const SEED_OPENAI = [
        ModelRole::CHEAP => 'openai:gpt-6-luna',
        ModelRole::MAIN => 'openai:gpt-6.1-terra',
        ModelRole::STRONG => 'openai:gpt-6-sol',
        ModelRole::MEDIA => 'openai:gpt-6-luna',
    ];

    private const MIN_REASON = 10;

    /** @param list<string> $providers providers with a key; their models are the only ones that can be used */
    public function __construct(
        private readonly ModelRoleRepository $roles,
        private readonly ModelCatalogRepository $catalog,
        private readonly array $providers = [ModelRef::OPENROUTER],
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

    /** @return array<string, string> */
    private function seedTable(): array {
        return in_array(ModelRef::OPENROUTER, $this->providers, true) ? self::SEED : self::SEED_OPENAI;
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
     * Puts a model into a role. A fix (owner only) stops anyone else from changing the role later.
     *
     * @throws DomainException with a message for the model when the change is refused
     */
    public function set(string $role, string $model, string $reason, Principal $by, bool $fix): ModelRef {
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
        $setBy = $fix ? ModelRole::SET_BY_OWNER : ModelRole::SET_BY_AGENT;
        if (!$this->roles->set($role, $ref, trim($reason), $setBy, $by->id, $by->isOwner())) {
            throw new DomainException(sprintf(t('model.model_roles.rol_zafiksiroval_vladelets_izmenit_ee'), $role));
        }
        return $ref;
    }
}
