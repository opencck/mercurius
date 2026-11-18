<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\TimeoutCancellation;
use InvalidArgumentException;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\OldSkillCatalog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The skills an earlier release kept in the base move once into the owner's folder of skills, then the old records
 * are cleared: skills are files now. One already in the folder by that name is left as it is; a built-in one kept in
 * the base by an earlier start is served from the code and is not copied.
 *
 * spec: BRIEF.md#скиллы-и-mcp — skills are files in the sandbox, not rows of the base
 */
final class SkillMigration {
    public function __construct(
        private readonly OldSkillCatalog $old,
        private readonly SkillLibrary $library,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @return list<string> the skills moved */
    public function toFolder(Principal $owner): array {
        $target = $this->library->target($owner, null, 'mine');
        if ($target === null) {
            return [];
        }
        $moved = [];
        try {
            $present = $this->library->catalog($owner, null);
            foreach ($this->old->active() as $skill) {
                if ($skill['origin'] === 'builtin' || (isset($present[$skill['name']]) && $present[$skill['name']]['dir'] !== null)) {
                    continue;
                }
                try {
                    $bundle = SkillBundle::fromFiles(['SKILL.md' => "---\nname: " . $skill['name'] . "\ndescription: " . $skill['description'] . "\n---\n\n" . $skill['body']] + $skill['files'], 'catalog', '');
                } catch (InvalidArgumentException) {
                    continue;
                }
                $this->library->write($bundle, $target, new TimeoutCancellation(30));
                $moved[] = $skill['name'];
            }
            // everything worth keeping is in the folder now: the base keeps no skills
            $this->old->clear();
        } catch (Throwable $e) {
            $this->logger->warning('Skills of the old catalog were not moved', ['error' => $e::class]);
            return $moved;
        }
        if ($moved !== []) {
            $this->logger->info('Skills moved into the owner folder', ['skills' => $moved]);
        }
        return $moved;
    }
}
