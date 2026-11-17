<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DateTimeZone;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Repository\ExperienceRepository;

use function Mercurius\I18n\t;

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
        return new ToolDefinition(self::NAME, t('tool.experience_search.pokazyvaet_kak_shli_proshlye_chasti')
            . t('tool.experience_search.model_i_s_kakoy_byl')
            . t('tool.experience_search.kakaya_model_spravlyalas_s_pohozhey')
            . t('tool.experience_search.model_tolko_chasti_etoy_modeli'), [
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
            return ToolResult::error(t('tool.experience_search.beseda_ne_naydena'));
        }
        $query = trim(Args::string($args, 'query') ?? '');
        $found = $this->experience->search($query === '' ? [] : [$query], $scope, Args::int($args, 'limit') ?? self::LIMIT, Args::string($args, 'model'));
        if ($found === []) {
            return ToolResult::ok(t('tool.experience_search.opyta_po_takomu_zaprosu_net'));
        }
        return ToolResult::ok(implode("\n\n", array_map(self::line(...), $found)));
    }

    public static function line(Experience $e): string {
        $at = $e->createdAt?->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d') ?? '';
        $model = $e->firstModel === null ? $e->model : sprintf(t('tool.experience_search.kaskad_s'), $e->model, $e->firstModel);
        $outcome = $e->status . ($e->verdict === null ? '' : t('tool.experience_search.proverka') . $e->verdict) . ($e->reason === null ? '' : ', ' . $e->reason);
        $lines = [
            sprintf(t('tool.experience_search.rol_hodov_tokenov_s'), $e->id, $at, $e->role, $model, $outcome, $e->turns, $e->tokens, $e->durationMs / 1000),
            t('tool.experience_search.tsel') . SecretMasker::mask(HistorySearchTool::cut($e->goal, [], self::GOAL_CHARS)),
        ];
        if ($e->profile !== []) {
            $scores = [];
            foreach ($e->profile as $axis => $score) {
                $scores[] = $axis . ' ' . $score;
            }
            $lines[] = t('tool.experience_search.profil') . implode(', ', $scores);
        }
        return implode("\n", $lines);
    }
}
