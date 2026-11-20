<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use function Mercurius\I18n\t;

/**
 * Why a run ended. Every stop has a named reason.
 *
 * spec: brief/agent-loop.md#запуск-и-цикл — stop reasons
 */
enum StopReason: string {
    case Answered = 'answered';
    case TurnLimit = 'turn_limit';
    case TokenBudget = 'token_budget';
    case Deadline = 'deadline';
    case LoopGuard = 'loop_guard';
    case InvalidCalls = 'invalid_calls';
    case Truncated = 'truncated';
    case PersistFailed = 'persist_failed';
    case ModelFailed = 'model_failed';
    case Stopped = 'stopped';
    case InternalError = 'internal_error';
    case SpendCap = 'spend_cap';

    /** A limit ran out: the model gets one last call without tools to sum up. */
    public function isLimit(): bool {
        return $this === self::TurnLimit || $this === self::TokenBudget || $this === self::Deadline;
    }

    public function status(): RunStatus {
        return match ($this) {
            self::Answered => RunStatus::Done,
            self::Stopped => RunStatus::Stopped,
            default => RunStatus::Failed,
        };
    }

    /** Text for the owner's report. */
    public function label(): string {
        return match ($this) {
            self::Answered => t('agent.stop_reason.otvet_dan'),
            self::TurnLimit => t('agent.stop_reason.ischerpan_limit_hodov'),
            self::TokenBudget => t('agent.stop_reason.ischerpan_byudzhet_tokenov'),
            self::Deadline => t('agent.stop_reason.isteklo_vremya_zapuska'),
            self::LoopGuard => t('agent.stop_reason.agent_povtoryal_odni_i_te'),
            self::InvalidCalls => t('agent.stop_reason.model_neskolko_raz_podryad_vyzyvala'),
            self::Truncated => t('agent.stop_reason.otvet_modeli_obryvalsya_na_seredine'),
            self::PersistFailed => t('agent.stop_reason.ne_udalos_sohranit_shag_v'),
            self::ModelFailed => t('agent.stop_reason.model_nedostupna'),
            self::Stopped => t('agent.stop_reason.ostanovleno_vladeltsem'),
            self::InternalError => t('agent.stop_reason.vnutrennyaya_oshibka'),
            self::SpendCap => t('agent.stop_reason.dostignut_limit_rashoda_v_dengah'),
        };
    }
}
