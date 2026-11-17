<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use Amp\CancelledException;
use InvalidArgumentException;
use OpenCCK\App\Agent\SkillCards;
use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\SkillVersion;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Brings an ordinary skill of another agent in: fetch, check, propose a version with its files, show the owner a card
 * with the files and what the check found. A critical finding refuses the skill before the card; until the owner
 * accepts, nothing of it is visible to the agent.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 */
final class SkillImporter {
    private const FINDINGS_ON_CARD = 10;

    /** @param list<SkillSource> $sources */
    public function __construct(
        private readonly array $sources,
        private readonly SkillCatalog $catalog,
        private readonly SkillRepository $skills,
        private readonly SkillCards $cards,
    ) {}

    /** @throws InvalidArgumentException with a reason the owner or the agent can read */
    public function import(string $url, string $reason, ?int $principalId, Cancellation $cancellation): SkillVersion {
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
        $findings = SkillGuard::scan($bundle);
        $lines = array_map(
            static fn(array $f): string => sprintf('%s %s:%d — %s (%s)', $f['severity'], $f['file'], $f['line'], $f['description'], $f['id']),
            $findings,
        );
        if (SkillGuard::blocks($findings)) {
            throw new InvalidArgumentException(sprintf(t('skill.import.blocked'), $bundle->name, implode("\n", $lines)));
        }
        $version = $this->catalog->propose($bundle->name, $bundle->description, $bundle->body, trim($reason) === '' ? $bundle->source : $reason, $principalId, 'external');
        $this->skills->attachFiles($version->id, $bundle->files);
        $this->cards->show($version, sprintf(
            t('skill.import.card'),
            $bundle->source,
            $bundle->files === [] ? '—' : implode(', ', array_keys($bundle->files)),
            $bundle->skipped === [] ? '' : sprintf(t('skill.import.skipped'), implode(', ', $bundle->skipped)),
            $lines === [] ? t('skill.import.clean') : implode("\n", array_slice($lines, 0, self::FINDINGS_ON_CARD)) . (count($lines) > self::FINDINGS_ON_CARD ? "\n…" : ''),
        ));
        return $version;
    }
}
