<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Domain\Skill\SkillVersion;

final class InMemorySkillRepository implements SkillRepository {
    /** @var array<string, Skill> */
    public array $skills = [];
    /** @var array<int, SkillVersion> */
    public array $versions = [];

    public function find(string $name): ?Skill {
        return $this->skills[$name] ?? null;
    }

    public function active(): array {
        $active = array_values(array_filter($this->skills, static fn(Skill $s): bool => $s->status === Skill::ACTIVE));
        usort($active, static fn(Skill $a, Skill $b): int => strcmp($a->name, $b->name));
        return $active;
    }

    public function all(): array {
        $all = array_values($this->skills);
        usort($all, static fn(Skill $a, Skill $b): int => strcmp($a->name, $b->name));
        return $all;
    }

    public function propose(string $name, string $description, string $body, string $reason, ?int $principalId, string $origin): SkillVersion {
        $skill = $this->skills[$name] ??= new Skill(count($this->skills) + 1, $name, '', '', Skill::DRAFT, $origin, 0);
        $number = 1 + count(array_filter($this->versions, static fn(SkillVersion $v): bool => $v->skillId === $skill->id));
        $id = count($this->versions) + 1;
        return $this->versions[$id] = new SkillVersion($id, $skill->id, $name, $number, $description, $body, SkillVersion::PROPOSED, $reason, $principalId);
    }

    public function version(int $id): ?SkillVersion {
        return $this->versions[$id] ?? null;
    }

    /** @var array<int, array<string, string>> version id => files */
    private array $files = [];

    public function attachFiles(int $versionId, array $files): void {
        $this->files[$versionId] = $files + ($this->files[$versionId] ?? []);
        ksort($this->files[$versionId]);
    }

    public function versionFiles(int $versionId): array {
        return $this->files[$versionId] ?? [];
    }

    public function files(string $name): array {
        $skill = $this->skills[$name] ?? null;
        if ($skill === null || $skill->status !== Skill::ACTIVE) {
            return [];
        }
        foreach ($this->versions as $v) {
            if ($v->skillId === $skill->id && $v->version === $skill->version) {
                return $this->files[$v->id] ?? [];
            }
        }
        return [];
    }

    public function accept(int $versionId): ?Skill {
        $v = $this->versions[$versionId] ?? null;
        if ($v === null || $v->status !== SkillVersion::PROPOSED) {
            return null;
        }
        $this->versions[$versionId] = new SkillVersion($v->id, $v->skillId, $v->name, $v->version, $v->description, $v->body, SkillVersion::ACCEPTED, $v->reason, $v->principalId);
        $s = $this->skills[$v->name];
        return $this->skills[$v->name] = new Skill($s->id, $s->name, $v->description, $v->body, Skill::ACTIVE, $s->origin, $v->version);
    }

    public function reject(int $versionId): bool {
        $v = $this->versions[$versionId] ?? null;
        if ($v === null || $v->status !== SkillVersion::PROPOSED) {
            return false;
        }
        $this->versions[$versionId] = new SkillVersion($v->id, $v->skillId, $v->name, $v->version, $v->description, $v->body, SkillVersion::REJECTED, $v->reason, $v->principalId);
        return true;
    }

    public function setStatus(string $name, string $status): bool {
        $s = $this->skills[$name] ?? null;
        if ($s === null || $s->version === 0) {
            return false;
        }
        $this->skills[$name] = new Skill($s->id, $s->name, $s->description, $s->body, $status, $s->origin, $s->version);
        return true;
    }

    public function history(string $name): array {
        $found = array_values(array_filter($this->versions, static fn(SkillVersion $v): bool => $v->name === $name && $v->status === SkillVersion::ACCEPTED));
        usort($found, static fn(SkillVersion $a, SkillVersion $b): int => $b->version <=> $a->version);
        return $found;
    }

    public function rollback(string $name, int $version): ?Skill {
        foreach ($this->history($name) as $v) {
            if ($v->version === $version) {
                $s = $this->skills[$name];
                return $this->skills[$name] = new Skill($s->id, $s->name, $v->description, $v->body, $s->status, $s->origin, $v->version);
            }
        }
        return null;
    }
}
