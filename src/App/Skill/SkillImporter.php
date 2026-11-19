<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use Amp\CancelledException;
use InvalidArgumentException;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Brings an ordinary skill of another agent in — from a GitHub directory, an archive at an address or an archive the
 * agent already has (sent to the chat) — and checks it: a critical finding refuses the skill. Where it goes is the
 * caller's choice (the participant's or the project's folder); the findings that do not refuse are returned to show.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a skill from git or an archive, in the folder of the participant or the project
 */
final class SkillImporter {
    /**
     * @param list<SkillSource> $sources
     * @param int $maxFiles the owner's cap on the files of a skill (SKILL_MAX_FILES); 0 — none
     */
    public function __construct(private readonly array $sources, private readonly int $maxFiles = 0) {}

    /**
     * @return array{SkillBundle, list<string>} the skill and what the check found
     * @throws InvalidArgumentException with a reason the owner or the agent can read
     */
    public function fetch(string $url, Cancellation $cancellation): array {
        $source = null;
        foreach ($this->sources as $candidate) {
            if ($candidate->supports($url)) {
                $source = $candidate;
                break;
            }
        }
        if ($source === null) {
            throw new InvalidArgumentException(t('skill.import.unknown_source'));
        }
        try {
            $bundle = $source->fetch($url, $cancellation);
        } catch (InvalidArgumentException | CancelledException $e) {
            throw $e;
        } catch (Throwable $e) {
            // the network, a body over the limit, a broken answer: the reason goes back to whoever asked
            throw new InvalidArgumentException(sprintf(t('skill.import.fetch_failed'), $e->getMessage()));
        }
        return [$bundle, self::check($bundle)];
    }

    /**
     * A skill in an archive the agent already has: sent to the chat or made in the sandbox.
     *
     * @return array{SkillBundle, list<string>}
     * @throws InvalidArgumentException
     */
    public function archive(string $bytes, string $fileName): array {
        if (!ArchiveSkillSource::supportsName($fileName)) {
            throw new InvalidArgumentException(t('skill.import.unknown_source'));
        }
        $bundle = ArchiveSkillSource::bundle($bytes, $fileName, 'file:' . basename($fileName), $this->maxFiles);
        return [$bundle, self::check($bundle)];
    }

    /**
     * @return list<string> the findings, worst first
     * @throws InvalidArgumentException when a finding refuses the skill
     */
    private static function check(SkillBundle $bundle): array {
        $findings = SkillGuard::scan($bundle);
        $lines = array_map(
            static fn(array $f): string => sprintf('%s %s:%d — %s (%s)', $f['severity'], $f['file'], $f['line'], $f['description'], $f['id']),
            $findings,
        );
        if (SkillGuard::blocks($findings)) {
            throw new InvalidArgumentException(sprintf(t('skill.import.blocked'), $bundle->name, implode("\n", $lines)));
        }
        return $lines;
    }
}
