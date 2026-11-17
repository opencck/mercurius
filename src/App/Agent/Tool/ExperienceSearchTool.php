<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DateTimeZone;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Repository\ExperienceRepository;

/**
 * How earlier delegated parts went, for choosing how to split and whom to give a new one.
 *
 * spec: BRIEF.md#память-слои — layer 7, experience through search
 */
final class ExperienceSearchTool implements Tool {
    public const NAME = 'experience_search';
    public const LIMIT = 8;
    private const GOAL_CHARS = 300;

    public function __construct(
        private readonly ExperienceRepository $experience,
        private readonly ConversationAudience $audience,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Показывает, как шли прошлые части задач, порученные воркерам: цель, роль и профиль, '
            . 'модель (и с какой был каскад), итог, вердикт проверяющего, ходы, токены, время. Пригодится перед делегированием: '
            . 'какая модель справлялась с похожей задачей и во что она обошлась. query — слова цели (без него — последние), '
            . 'model — только части этой модели.', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'maxLength' => 300],
                'model' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $scope = $this->audience->history($context->principal, $context->conversationId);
        if ($scope === null) {
            return ToolResult::error('Беседа не найдена.');
        }
        $query = trim(Args::string($args, 'query') ?? '');
        $found = $this->experience->search($query === '' ? [] : [$query], $scope, Args::int($args, 'limit') ?? self::LIMIT, Args::string($args, 'model'));
        if ($found === []) {
            return ToolResult::ok('Опыта по такому запросу нет.');
        }
        return ToolResult::ok(implode("\n\n", array_map(self::line(...), $found)));
    }

    public static function line(Experience $e): string {
        $at = $e->createdAt?->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d') ?? '';
        $model = $e->firstModel === null ? $e->model : sprintf('%s (каскад с %s)', $e->model, $e->firstModel);
        $outcome = $e->status . ($e->verdict === null ? '' : ', проверка: ' . $e->verdict) . ($e->reason === null ? '' : ', ' . $e->reason);
        $lines = [
            sprintf('#%d %s · роль %s · %s · %s · ходов %d, токенов %d, %.1f с', $e->id, $at, $e->role, $model, $outcome, $e->turns, $e->tokens, $e->durationMs / 1000),
            'цель: ' . SecretMasker::mask(HistorySearchTool::cut($e->goal, [], self::GOAL_CHARS)),
        ];
        if ($e->profile !== []) {
            $scores = [];
            foreach ($e->profile as $axis => $score) {
                $scores[] = $axis . ' ' . $score;
            }
            $lines[] = 'профиль: ' . implode(', ', $scores);
        }
        return implode("\n", $lines);
    }
}
