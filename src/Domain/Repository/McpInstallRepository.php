<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Mcp\McpInstall;

/** spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — installed MCP servers */
interface McpInstallRepository {
    /** @throws \DomainException the name is taken */
    public function add(string $name, string $source, int $installedBy): McpInstall;

    public function find(string $name): ?McpInstall;

    /** @return list<McpInstall> */
    public function all(): array;

    /**
     * The revision now in use; the one before becomes the previous.
     *
     * @param array<string, string> $classes
     * @param array<array-key, mixed> $entry
     */
    public function setRevision(string $name, string $revision, string $toolsHash, array $classes, array $entry): void;

    /** @param list<string> $steps the steps of the approved plan */
    public function setSteps(string $name, array $steps): void;

    /** The newest revision the source has, as the daily check saw it. */
    public function setLatest(string $name, ?string $latest): void;

    public function allow(string $name, string $context, bool $allowed): void;

    /** @return list<string> contexts an install is allowed to besides its installer's */
    public function contexts(string $name): array;

    public function remove(string $name): bool;
}
