<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Improve\Improvements;
use OpenCCK\App\Improve\PromptNotes;
use OpenCCK\App\Settings\SettingsCatalog;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent proposes an improvement of itself to the owner: a new version of the notes to its prompt, a value of a
 * setting or an idea for whoever develops it. The tool only proposes: the owner decides on a card.
 *
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18
 * ref: references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L1-L7 — hermes lets the agent change its own skills
 *      and memory directly; here every change of global behaviour is a proposal the owner accepts
 */
final class ImprovementProposeTool implements Tool {
    public const NAME = 'improvement_propose';

    public function __construct(private readonly Improvements $improvements) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.improvement_propose.about'), [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => Improvements::KINDS],
                'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => PromptNotes::MAX, 'description' => t('tool.improvement_propose.text')],
                'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 1000, 'description' => t('tool.improvement_propose.reason')],
                'key' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.improvement_propose.key')],
            ],
            'required' => ['kind', 'title', 'text', 'reason'],
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
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.improvement_propose.owner_only'));
        }
        $kind = Args::string($args, 'kind') ?? '';
        $text = Args::string($args, 'text') ?? '';
        $title = Args::string($args, 'title') ?? '';
        $reason = Args::string($args, 'reason') ?? '';
        if (!in_array($kind, Improvements::KINDS, true) || trim($text) === '' || trim($title) === '') {
            return ToolResult::error(t('tool.improvement_propose.bad'));
        }
        // a proposal lands in the prompt of every conversation: no secrets and no hidden characters in it
        if (SecretMasker::mask($text) !== $text || preg_match('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{E0000}-\x{E007F}]~u', $text) === 1) {
            return ToolResult::error(t('tool.improvement_propose.secret'));
        }
        $key = null;
        if ($kind === 'setting') {
            $key = strtoupper(trim(Args::string($args, 'key') ?? ''));
            if (!SettingsCatalog::has($key) || SettingsCatalog::secret($key)) {
                return ToolResult::error(t('tool.improvement_propose.setting'));
            }
            $problem = SettingsCatalog::problem($key, trim($text));
            if ($problem !== null) {
                return ToolResult::error($problem);
            }
        }
        // spec: BRIEF.md#самоулучшение-решено-2026-11-18 — what the owner rejected is not proposed again word for word
        $normal = static fn(string $s): string => mb_strtolower(trim(preg_replace('~\s+~u', ' ', $s) ?? $s));
        $rejected = $this->improvements->rejectedTitles();
        foreach ($rejected as $old) {
            if ($normal($old) === $normal($title)) {
                return ToolResult::error(sprintf(t('tool.improvement_propose.rejected_before'), implode('; ', array_map(static fn(string $t): string => '«' . $t . '»', $rejected))));
            }
        }
        $id = $this->improvements->propose($kind, $title, $kind === 'setting' ? trim($text) : $text, $reason, $key);
        return ToolResult::ok(sprintf(t('tool.improvement_propose.done'), $id));
    }
}
