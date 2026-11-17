<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\SkillCards;
use OpenCCK\App\Skill\SkillCatalog;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * skill_propose: a new skill or a new version of one, from the agent; the owner accepts it with a card. Active skills
 * are never changed in place.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a lock on changes by the agent, a log of versions with rollback
 */
final class SkillProposeTool implements Tool {
    public const NAME = 'skill_propose';

    public function __construct(
        private readonly SkillCatalog $catalog,
        private readonly SkillCards $cards,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Предлагает владельцу новый скилл или новую версию существующего (полный текст); в каталог он '
            . 'попадёт после одобрения. Скилл — инструкция для повторяющейся задачи: когда применять, что нужно, шаги, ловушки, '
            . 'как проверить. description — одна строка до 120 символов; name — латиницей через дефис.', [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            ],
            'required' => ['name', 'description', 'body', 'reason'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        try {
            $version = $this->catalog->propose(
                Args::string($args, 'name') ?? '',
                Args::string($args, 'description') ?? '',
                Args::string($args, 'body') ?? '',
                Args::string($args, 'reason') ?? '',
                $context->principal->id,
                'agent',
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        if (!$this->cards->show($version)) {
            return ToolResult::error(sprintf('Версия %d скилла «%s» сохранена, но карточку владельцу отправить не удалось.', $version->version, $version->name));
        }
        return ToolResult::ok(sprintf('Скилл «%s» версии %d отправлен владельцу на одобрение; до решения он не используется.', $version->name, $version->version));
    }
}
