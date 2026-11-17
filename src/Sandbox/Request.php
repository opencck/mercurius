<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use InvalidArgumentException;
use JsonException;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * A parsed and checked request to the executor. Every operation names the participant and their limits;
 * the core is trusted (it holds the token), so limits come from the request.
 */
final class Request {
    public const OPERATIONS = ['exec', 'read', 'write', 'replace', 'list', 'usage', 'cancel', 'spawn', 'send', 'receive', 'close', 'fetch'];

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
            throw new SandboxError(sprintf(t('sandbox.request.neizvestnaya_operatsiya'), $operation), 404);
        }
        $body = self::decode($json);

        $principal = $body['principal_id'] ?? null;
        if (!is_int($principal) || $principal < 1) {
            throw new SandboxError(t('sandbox.request.ne_ukazan_uchastnik_principal_id'));
        }
        $callId = $body['call_id'] ?? '';
        if (!is_string($callId) || strlen($callId) > 128) {
            throw new SandboxError(t('sandbox.request.nedopustimyy_call_id'));
        }
        return new self($operation, $principal, self::limits($body['limits'] ?? null), $callId, $body);
    }

    /** @throws SandboxError */
    public function string(string $key, ?string $default = null): string {
        $value = $this->body[$key] ?? $default;
        if (!is_string($value)) {
            throw new SandboxError(sprintf(t('sandbox.request.ne_ukazano_pole'), $key));
        }
        return $value;
    }

    /**
     * Extra environment of a spawned process: names to string values.
     *
     * @return array<string, string>
     * @throws SandboxError
     */
    public function env(): array {
        $value = $this->body['env'] ?? [];
        if (!is_array($value)) {
            throw new SandboxError(sprintf(t('sandbox.request.ne_ukazano_pole'), 'env'));
        }
        $env = [];
        foreach ($value as $name => $text) {
            if (!is_string($name) || !is_string($text)) {
                throw new SandboxError(sprintf(t('sandbox.request.ne_ukazano_pole'), 'env'));
            }
            $env[$name] = $text;
        }
        return $env;
    }

    /** @throws SandboxError */
    public function int(string $key, int $default, int $min, int $max): int {
        $value = $this->body[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new SandboxError(sprintf(t('sandbox.request.pole_dolzhno_byt_tselym_ot'), $key, $min, $max));
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
            throw new SandboxError(t('sandbox.request.telo_zaprosa_ne_json'));
        }
        if (!is_array($decoded)) {
            throw new SandboxError(t('sandbox.request.telo_zaprosa_dolzhno_byt_obektom'));
        }
        if ($decoded !== [] && array_is_list($decoded)) {
            throw new SandboxError(t('sandbox.request.telo_zaprosa_dolzhno_byt_obektom'));
        }
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }

    /** @throws SandboxError */
    private static function limits(mixed $value): SandboxLimits {
        if (!is_array($value)) {
            throw new SandboxError(t('sandbox.request.ne_ukazany_limity_limits'));
        }
        $get = static function (string $key) use ($value): int {
            $number = $value[$key] ?? null;
            if (!is_int($number)) {
                throw new SandboxError(sprintf(t('sandbox.request.limit_dolzhen_byt_tselym'), $key));
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
