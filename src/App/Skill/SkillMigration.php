<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\TimeoutCancellation;
use InvalidArgumentException;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\SkillRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The skills of the earlier catalog in the base (active versions with their files) move once into the owner's folder
 * of skills; one already there by that name is left as it is.
 *
 * spec: BRIEF.md#скиллы-и-mcp — skills are files in the sandbox
 */
final class SkillMigration {
    public function __construct(
        private readonly SkillRepository $skills,
        private readonly SkillLibrary $library,
        private readonly LoggerInterface $logger,
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
            foreach ($this->skills->active() as $skill) {
                if (isset($present[$skill->name]) && $present[$skill->name]['dir'] !== null) {
                    continue;
                }
                try {
                    $bundle = SkillBundle::fromFiles(['SKILL.md' => "---\nname: " . $skill->name . "\ndescription: " . $skill->description . "\n---\n\n" . $skill->body] + $this->skills->files($skill->name), 'catalog', '');
                } catch (InvalidArgumentException) {
                    continue;
                }
                $this->library->write($bundle, $target, new TimeoutCancellation(30));
                $moved[] = $skill->name;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Skills of the catalog were not moved', ['error' => $e::class]);
        }
        if ($moved !== []) {
            $this->logger->info('Skills moved into the owner folder', ['skills' => $moved]);
        }
        return $moved;
    }
}
