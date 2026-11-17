<?php

declare(strict_types=1);

namespace OpenCCK\CommandPolicy;

/**
 * What a shell command does, as far as the classifier can prove: only reads, changes something, raises privileges, or
 * is never allowed. Whatever is not proven to read is not a read.
 */
final class Classification {
    public const READ = 'read';
    public const CHANGE = 'change';
    public const PRIVILEGED = 'privileged';
    public const NEVER = 'never';

    public function __construct(
        public readonly string $class,
        public readonly string $reason,
    ) {}

    public function isRead(): bool {
        return $this->class === self::READ;
    }
}
