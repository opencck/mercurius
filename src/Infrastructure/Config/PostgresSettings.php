<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * PostgreSQL connection settings. The password is hidden from var_dump/print_r output.
 */
final class PostgresSettings {
    private readonly string $password;

    /** @param int<1, 65535> $port */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $user,
        #[SensitiveParameter] string $password,
    ) {
        $this->password = $password;
    }

    public function password(): string {
        return $this->password;
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array {
        return ['host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'user' => $this->user, 'password' => '***'];
    }
}
