<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Closure;

/**
 * A circuit breaker per MCP server: after three failures in a row the calls are refused for a minute with a message
 * that tells the model to stop retrying; then one call probes the server, success closes the breaker and failure
 * opens it again. Without it a dead server makes the model call it turn after turn.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a failing server does not eat the run
 * ref: references/hermes-agent/tools/mcp_tool.py@e96221fa:L587-L640 (_bump_server_error, _reset_server_error) and
 *      tools/mcp_tool_handlers.py@e96221fa:L310-L330 (_check_circuit_breaker) — threshold 3, cooldown 60 s, half-open
 *      probe; a server that answered every time with an error is "rejected", not "unreachable"
 */
final class McpBreaker {
    public const THRESHOLD = 3;
    public const COOLDOWN = 60.0;

    /** @var array<string, int> */
    private array $failures = [];
    /** @var array<string, float> */
    private array $openedAt = [];
    /** @var array<string, bool> every failure of the streak was the server's own error answer */
    private array $rejected = [];
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock */
    public function __construct(?Closure $clock = null) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** Why a call to the server must not go now; null — it may. */
    public function refusal(string $server): ?string {
        $failures = $this->failures[$server] ?? 0;
        $age = ($this->clock)() - ($this->openedAt[$server] ?? 0.0);
        if ($failures < self::THRESHOLD || $age >= self::COOLDOWN) {
            return null;
        }
        $retry = max(1, (int) (self::COOLDOWN - $age));
        if ($this->rejected[$server] ?? false) {
            return sprintf(
                'MCP-сервер «%s» отклонил %d последних вызова (он отвечает — смотри текст их ошибок). Пауза ~%d с. '
                . 'Не повторяй тот же вызов: исправь аргументы или зайди с другой стороны.',
                $server,
                $failures,
                $retry,
            );
        }
        return sprintf(
            'MCP-сервер «%s» недоступен после %d неудач подряд. Повтор будет возможен через ~%d с. '
            . 'Не вызывай его сейчас: обойдись без него или попроси владельца проверить сервер.',
            $server,
            $failures,
            $retry,
        );
    }

    /** @param bool $rejected the server answered with an error, the connection was fine */
    public function failed(string $server, bool $rejected): void {
        $n = ($this->failures[$server] ?? 0) + 1;
        $this->failures[$server] = $n;
        $this->rejected[$server] = $rejected && ($n === 1 || ($this->rejected[$server] ?? false));
        if ($n >= self::THRESHOLD) {
            $this->openedAt[$server] = ($this->clock)();
        }
    }

    public function succeeded(string $server): void {
        unset($this->failures[$server], $this->openedAt[$server], $this->rejected[$server]);
    }
}
