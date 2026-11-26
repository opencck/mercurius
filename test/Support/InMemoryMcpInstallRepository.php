<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Mcp\McpInstall;
use OpenCCK\Domain\Repository\McpInstallRepository;

final class InMemoryMcpInstallRepository implements McpInstallRepository {
    /** @var array<string, McpInstall> */
    public array $installs = [];
    /** @var array<string, array<string, true>> */
    private array $allowed = [];
    private int $next = 1;
    /** @var array<string, string> */
    private array $environments = [];

    public function environment(string $name): ?string {
        return $this->environments[$name] ?? null;
    }

    public function setEnvironment(string $name, string $environment): void {
        $this->environments[$name] = $environment;
    }

    public function add(string $name, string $source, int $installedBy): McpInstall {
        if (isset($this->installs[$name])) {
            throw new DomainException('taken');
        }
        return $this->installs[$name] = new McpInstall($this->next++, $name, $source, $installedBy);
    }

    public function find(string $name): ?McpInstall {
        return $this->installs[$name] ?? null;
    }

    public function all(): array {
        return array_values($this->installs);
    }

    public function setRevision(string $name, string $revision, string $toolsHash, array $classes, array $entry): void {
        $i = $this->installs[$name] ?? null;
        if ($i !== null) {
            $this->installs[$name] = new McpInstall($i->id, $i->name, $i->source, $i->installedBy, $revision, $i->revision, $toolsHash, $classes, $entry, $i->latest, $i->steps);
        }
    }

    public function setLatest(string $name, ?string $latest): void {
        $i = $this->installs[$name] ?? null;
        if ($i !== null) {
            $this->installs[$name] = new McpInstall($i->id, $i->name, $i->source, $i->installedBy, $i->revision, $i->previous, $i->toolsHash, $i->classes, $i->entry, $latest, $i->steps);
        }
    }

    public function setSteps(string $name, array $steps): void {
        $i = $this->installs[$name] ?? null;
        if ($i !== null) {
            $this->installs[$name] = new McpInstall($i->id, $i->name, $i->source, $i->installedBy, $i->revision, $i->previous, $i->toolsHash, $i->classes, $i->entry, $i->latest, $steps);
        }
    }

    public function allow(string $name, string $context, bool $allowed): void {
        if ($allowed) {
            $this->allowed[$name][$context] = true;
        } else {
            unset($this->allowed[$name][$context]);
        }
    }

    public function contexts(string $name): array {
        $contexts = array_keys($this->allowed[$name] ?? []);
        sort($contexts);
        return $contexts;
    }

    public function remove(string $name): bool {
        if (!isset($this->installs[$name])) {
            return false;
        }
        unset($this->installs[$name], $this->allowed[$name]);
        return true;
    }
}
