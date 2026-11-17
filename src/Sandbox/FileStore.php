<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * File operations in the participant's home. Paths are relative to the home or absolute inside it.
 */
interface FileStore {
    /**
     * @return array{text: string, totalLines: int}
     * @throws SandboxError
     */
    public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines): array;

    /** @throws SandboxError */
    public function write(int $principalId, SandboxLimits $limits, string $path, string $content): void;

    /** @throws SandboxError */
    public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new): void;

    /**
     * @return list<array{path: string, type: string, size: int}>
     * @throws SandboxError
     */
    public function list(int $principalId, SandboxLimits $limits, string $path, int $depth): array;

    public function usage(int $principalId): int;

    /**
     * The file whole, as bytes, up to $maxBytes.
     *
     * @throws SandboxError
     */
    public function fetch(int $principalId, SandboxLimits $limits, string $path, int $maxBytes): string;
}
