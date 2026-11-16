<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

final class Migration {
    /**
     * @param positive-int $version
     * @param non-empty-string $sql
     */
    public function __construct(
        public readonly int $version,
        public readonly string $name,
        public readonly string $sql,
    ) {}
}
