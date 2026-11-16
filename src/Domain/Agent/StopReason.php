<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * Why a run ended. Every stop has a named reason.
 *
 * spec: BRIEF.md#запуск-и-цикл — stop reasons
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
            self::Answered => 'ответ дан',
            self::TurnLimit => 'исчерпан лимит ходов',
            self::TokenBudget => 'исчерпан бюджет токенов',
            self::Deadline => 'истекло время запуска',
            self::LoopGuard => 'агент повторял одни и те же действия',
            self::InvalidCalls => 'модель несколько раз подряд вызывала инструменты с ошибками',
            self::Truncated => 'ответ модели обрывался на середине вызова инструмента',
            self::PersistFailed => 'не удалось сохранить шаг в базу',
            self::ModelFailed => 'модель недоступна',
            self::Stopped => 'остановлено владельцем',
        };
    }
}
