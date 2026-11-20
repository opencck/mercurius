<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use DateTimeImmutable;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\TraceReviewRepository;

use function Mercurius\I18n\t;

/**
 * The agent reads its own traces of a period, for the owner only: where runs stopped and why, which tools failed or
 * were refused, calls repeated after a refusal (a check of safety, not only of cost), the costliest runs and the spend
 * by purpose. What failed calls returned is outside text, so the result is untrusted.
 *
 * spec: brief/self-improvement.md#самоулучшение — a review of its own traces for mistakes, waste and safety
 * ref: references/hermes-agent/agent/insights.py@e96221fa:L1-L2 (InsightsEngine) — hermes sums sessions, tools and cost of a period; here
 *      for the agent itself, with refusals and repeats after a refusal
 */
final class TraceReviewTool implements Tool {
    public const NAME = 'trace_review';

    private ?\OpenCCK\App\Skill\SkillLibrary $skills = null;

    public function __construct(private readonly TraceReviewRepository $traces) {}

    /** spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — the review names the skills nobody opened for a month */
    public function useSkills(\OpenCCK\App\Skill\SkillLibrary $skills): void {
        $this->skills = $skills;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.trace_review.about'), [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30],
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
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.trace_review.owner_only'));
        }
        $days = max(1, min(30, Args::int($args, 'days') ?? 7));
        $data = $this->traces->review(new DateTimeImmutable('-' . $days . ' days'));
        $out = [sprintf(t('tool.trace_review.period'), $days)];
        $out[] = t('tool.trace_review.stops') . "\n" . ($data['stops'] === [] ? '—' : implode("\n", array_map(
            static fn(array $s): string => sprintf('- %s: %d (%d tok)', $s['reason'], $s['runs'], $s['tokens']),
            $data['stops'],
        )));
        $out[] = t('tool.trace_review.tools') . "\n" . ($data['tools'] === [] ? '—' : implode("\n", array_map(
            static fn(array $s): string => sprintf('- %s: %d / %d / %d', $s['name'], $s['calls'], $s['failed'], $s['denied']) . ($s['sample'] === '' ? '' : ' — «' . SecretMasker::mask(str_replace("\n", ' ', $s['sample'])) . '»'),
            $data['tools'],
        )));
        $out[] = sprintf(t('tool.trace_review.retried'), $data['retried']);
        $out[] = t('tool.trace_review.costly') . "\n" . ($data['costly'] === [] ? '—' : implode("\n", array_map(
            static fn(array $s): string => sprintf('- #%d: %d tok, %d turns, %s', $s['run'], $s['tokens'], $s['turns'], $s['reason']),
            $data['costly'],
        )));
        $out[] = t('tool.trace_review.spend') . "\n" . ($data['spend'] === [] ? '—' : implode("\n", array_map(
            static fn(array $s): string => sprintf('- %s: $%.4f (%d)', $s['purpose'], $s['usd'], $s['calls']),
            $data['spend'],
        )));
        $out[] = t('tool.trace_review.refused') . "\n" . ($data['refused'] === [] ? '—' : implode("\n", array_map(
            static fn(array $s): string => sprintf('- %s: %d (%d)', $s['name'], $s['calls'], $s['runs']),
            $data['refused'],
        )));
        $out[] = t('tool.trace_review.after_refusal') . "\n" . ($data['afterRefusal'] === [] ? '—' : implode("\n", array_map(
            static fn(array $s): string => sprintf('- #%d: %s → %s', $s['run'], $s['denied'], $s['then']),
            $data['afterRefusal'],
        )));
        if ($this->skills !== null) {
            // ref: references/hermes-agent/agent/curator.py@e96221fa:L31, L205-L240 — skills unused for a while are named
            //      for the owner; hermes archives them itself after 30 days, here the owner turns them off or pins them
            $unused = $this->skills->unused($context->principal, null);
            $out[] = sprintf(t('tool.trace_review.unused_skills'), \OpenCCK\App\Skill\SkillLibrary::STALE_DAYS) . "\n" . ($unused === [] ? '—' : implode(', ', $unused));
        }
        return ToolResult::ok(implode("\n\n", $out));
    }
}
