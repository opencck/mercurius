<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use InvalidArgumentException;
use JsonException;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * A parsed and checked request to the executor. Every operation names the participant and their limits;
 * the core is trusted (it holds the token), so limits come from the request.
 */
final class Request {
    public const OPERATIONS = ['exec', 'read', 'write', 'replace', 'list', 'usage', 'cancel'];

    /** @param array<string, mixed> $body */
    private function __construct(
        public readonly string $operation,
        public readonly int $principalId,
        public readonly SandboxLimits $limits,
        public readonly string $callId,
        private readonly array $body,
    ) {}

    /** @throws SandboxError */
    public static function parse(string $operation, string $json): self {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new SandboxError(sprintf('Неизвестная операция %s.', $operation), 404);
        }
        $body = self::decode($json);

        $principal = $body['principal_id'] ?? null;
        if (!is_int($principal) || $principal < 1) {
            throw new SandboxError('Не указан участник (principal_id).');
        }
        $callId = $body['call_id'] ?? '';
        if (!is_string($callId) || strlen($callId) > 128) {
            throw new SandboxError('Недопустимый call_id.');
        }
        return new self($operation, $principal, self::limits($body['limits'] ?? null), $callId, $body);
    }

    /** @throws SandboxError */
    public function string(string $key, ?string $default = null): string {
        $value = $this->body[$key] ?? $default;
        if (!is_string($value)) {
            throw new SandboxError(sprintf('Не указано поле %s.', $key));
        }
        return $value;
    }

    /** @throws SandboxError */
    public function int(string $key, int $default, int $min, int $max): int {
        $value = $this->body[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new SandboxError(sprintf('Поле %s должно быть целым от %d до %d.', $key, $min, $max));
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     * @throws SandboxError
     */
    private static function decode(string $json): array {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SandboxError('Тело запроса — не JSON.');
        }
        if (!is_array($decoded)) {
            throw new SandboxError('Тело запроса должно быть объектом.');
        }
        if ($decoded !== [] && array_is_list($decoded)) {
            throw new SandboxError('Тело запроса должно быть объектом.');
        }
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }

    /** @throws SandboxError */
    private static function limits(mixed $value): SandboxLimits {
        if (!is_array($value)) {
            throw new SandboxError('Не указаны лимиты (limits).');
        }
        $get = static function (string $key) use ($value): int {
            $number = $value[$key] ?? null;
            if (!is_int($number)) {
                throw new SandboxError(sprintf('Лимит %s должен быть целым.', $key));
            }
            return $number;
        };
        try {
            return new SandboxLimits(
                $get('disk_bytes'),
                $get('processes'),
                $get('cpu_seconds'),
                $get('memory_bytes'),
                $get('concurrent_commands'),
            );
        } catch (InvalidArgumentException $e) {
            throw new SandboxError($e->getMessage());
        }
    }
}
