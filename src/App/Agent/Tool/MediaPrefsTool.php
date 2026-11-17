<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Media\MediaPrefs;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Media\MediaPref;

use function Mercurius\I18n\t;

/**
 * The participant's own way with files, set when they say it in words ("do not transcribe voice messages", "receipts
 * — the strong model"): at once, on request or not at all per kind, a preferred model among the owner's, their
 * principles. Only for the participant the agent works for; no card — it changes nobody else's spend.
 *
 * spec: BRIEF.md#входящие-вложения — how users influence it
 */
final class MediaPrefsTool implements Tool {
    public const NAME = 'media_prefs';

    public function __construct(private readonly MediaPrefs $prefs) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.media_prefs.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['show', 'set']],
                'kind' => ['type' => 'string', 'enum' => MediaPref::KINDS],
                'mode' => ['type' => 'string', 'enum' => ['', ...MediaPref::MODES], 'description' => t('tool.media_prefs.mode')],
                'model' => ['type' => 'string', 'maxLength' => 200, 'description' => t('tool.media_prefs.model')],
                'rules' => ['type' => 'string', 'maxLength' => 1000, 'description' => t('tool.media_prefs.rules')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'set' ? DangerClass::Write : DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = $context->principal->id;
        if (Args::string($args, 'action') !== 'set') {
            return ToolResult::ok($this->prefs->describe($id));
        }
        $error = $this->prefs->set($id, Args::string($args, 'kind') ?? '', Args::string($args, 'mode'), Args::string($args, 'model'), Args::string($args, 'rules'));
        return $error === null ? ToolResult::ok(t('tool.media_prefs.saved') . "\n" . $this->prefs->describe($id)) : ToolResult::error($error);
    }
}
