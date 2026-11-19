<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Logger;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;

/**
 * Every log line goes through the mask of secrets: its message and every string of its context, at any depth.
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a log line does not carry a secret
 */
final class SecretProcessor implements ProcessorInterface {
    public function __invoke(LogRecord $record): LogRecord {
        return $record->with(message: SecretMasker::mask($record->message), context: self::clean($record->context));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function clean(array $values): array {
        /** @var mixed $value */
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = SecretMasker::mask($value);
            } elseif (is_array($value)) {
                $values[$key] = self::clean($value);
            }
        }
        return $values;
    }
}
