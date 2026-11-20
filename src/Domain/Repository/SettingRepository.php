<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * spec: brief/operations.md#настройки-агента
 */
interface SettingRepository {
    /** @return array<string, array{value: ?string, secret: bool}> every stored setting by key */
    public function all(): array;

    /** A plain value, or a secret one (its value is in the vault, $value is null). */
    public function set(string $key, ?string $value, bool $secret, ?int $by): void;

    public function delete(string $key): bool;
}
