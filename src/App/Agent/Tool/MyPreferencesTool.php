<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Job\ParticipantClock;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ParticipantPrefRepository;

use function Mercurius\I18n\t;

/**
 * The participant's own time zone and quiet hours: their jobs run by their clock, reports wait for the end of their
 * night. Without arguments — what is set now. Each participant changes only their own.
 *
 * spec: BRIEF.md#несколько-пользователей
 */
final class MyPreferencesTool implements Tool {
    public const NAME = 'my_preferences';

    public function __construct(
        private readonly ParticipantPrefRepository $prefs,
        private readonly ParticipantClock $clock,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.my_preferences.about'), [
            'type' => 'object',
            'properties' => [
                'timezone' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.my_preferences.timezone')],
                'quiet_hours' => ['type' => 'string', 'maxLength' => 16, 'description' => t('tool.my_preferences.quiet')],
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
        $hours = $this->clock->quiet($id);
        return ToolResult::ok(sprintf(
            t('tool.my_preferences.now'),
            $this->clock->zone($id)->getName(),
            $hours === null ? t('tool.my_preferences.no_quiet') : sprintf('%d–%d', $hours[0], $hours[1]),
            $zone === null && $quiet === null ? t('tool.my_preferences.defaults') : '',
        ));
    }
}
