<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Memory;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Job\ParticipantClock;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ParticipantPrefRepository;

use function Mercurius\I18n\t;

/**
 * The participant's own time zone and quiet hours: their jobs run by their clock, reports wait for the end of their
 * night. Without arguments — what is set now. Each participant changes only their own.
 *
 * spec: brief/participants.md#несколько-пользователей
 */
final class MyPreferencesTool implements Tool {
    public const NAME = 'my_preferences';

    public function __construct(
        private readonly ParticipantPrefRepository $prefs,
        private readonly ParticipantClock $clock,
        /** spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия — the limits of the context in the participant's private chats */
        private readonly ?\OpenCCK\Domain\Repository\ContextLimitRepository $limits = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.my_preferences.about'), [
            'type' => 'object',
            'properties' => [
                'timezone' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.my_preferences.timezone')],
                'quiet_hours' => ['type' => 'string', 'maxLength' => 16, 'description' => t('tool.my_preferences.quiet')],
                'context_tokens' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10_000_000, 'description' => t('tool.my_preferences.context_tokens')],
                'compact_percent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 95, 'description' => t('tool.my_preferences.compact_percent')],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return $args === [] ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = $context->principal->id;
        $current = $this->prefs->get($id);
        $zone = $current['timezone'];
        $quiet = $current['quiet'];
        $askedZone = Args::string($args, 'timezone');
        if ($askedZone !== null) {
            $askedZone = trim($askedZone);
            if ($askedZone === 'default') {
                $zone = null;
            } elseif (ParticipantClock::parseZone($askedZone) === null) {
                return ToolResult::error(t('tool.my_preferences.bad_zone'));
            } else {
                $zone = $askedZone;
            }
        }
        $askedQuiet = Args::string($args, 'quiet_hours');
        if ($askedQuiet !== null) {
            $askedQuiet = trim($askedQuiet);
            if ($askedQuiet === 'default') {
                $quiet = null;
            } elseif ($askedQuiet === 'none' || $askedQuiet === '') {
                $quiet = '';
            } elseif (ParticipantClock::parseQuiet($askedQuiet) === null) {
                return ToolResult::error(t('tool.my_preferences.bad_quiet'));
            } else {
                $quiet = $askedQuiet;
            }
        }
        if ($askedZone !== null || $askedQuiet !== null) {
            $this->prefs->save($id, $zone, $quiet);
        }
        $context = '';
        if ($this->limits !== null) {
            [$tokens, $percent] = $this->limits->get(\OpenCCK\Domain\Repository\ContextLimitRepository::PRINCIPAL, $id);
            $askedTokens = isset($args['context_tokens']) && is_int($args['context_tokens']) ? $args['context_tokens'] : null;
            $askedPercent = isset($args['compact_percent']) && is_int($args['compact_percent']) ? $args['compact_percent'] : null;
            if ($askedTokens !== null) {
                [$tokens, $error] = \OpenCCK\App\Agent\Context\ContextLimits::tokens($askedTokens);
                if ($error !== null) {
                    return ToolResult::error($error);
                }
            }
            if ($askedPercent !== null) {
                [$percent, $error] = \OpenCCK\App\Agent\Context\ContextLimits::percent($askedPercent);
                if ($error !== null) {
                    return ToolResult::error($error);
                }
            }
            if ($askedTokens !== null || $askedPercent !== null) {
                $this->limits->save(\OpenCCK\Domain\Repository\ContextLimitRepository::PRINCIPAL, $id, $tokens, $percent);
            }
            $context = "\n" . \OpenCCK\App\Agent\Context\ContextLimits::describe($tokens, $percent);
        }
        $hours = $this->clock->quiet($id);
        return ToolResult::ok($context === '' ? sprintf(
            t('tool.my_preferences.now'),
            $this->clock->zone($id)->getName(),
            $hours === null ? t('tool.my_preferences.no_quiet') : sprintf('%d–%d', $hours[0], $hours[1]),
            $zone === null && $quiet === null ? t('tool.my_preferences.defaults') : '',
        ) : sprintf(
            t('tool.my_preferences.now'),
            $this->clock->zone($id)->getName(),
            $hours === null ? t('tool.my_preferences.no_quiet') : sprintf('%d–%d', $hours[0], $hours[1]),
            $zone === null && $quiet === null ? t('tool.my_preferences.defaults') : '',
        ) . $context);
    }
}
