<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SettingRepository;

final class InMemorySettingRepository implements SettingRepository {
    /** @var array<string, array{value: ?string, secret: bool}> */
    public array $settings = [];

    public function all(): array {
        ksort($this->settings);
        return $this->settings;
    }

    public function set(string $key, ?string $value, bool $secret, ?int $by): void {
        $this->settings[$key] = ['value' => $value, 'secret' => $secret];
    }

    public function delete(string $key): bool {
        $found = isset($this->settings[$key]);
        unset($this->settings[$key]);
        return $found;
    }
}
