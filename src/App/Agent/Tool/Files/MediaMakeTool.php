<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Infrastructure\Media\MediaCall;
use OpenCCK\Infrastructure\Media\MediaModels;

use function Mercurius\I18n\t;

/**
 * Speech from a text (`voice_generate`, the category voice) or a video from a description (`video_generate`, the
 * category film), saved as a file of the participant's sandbox. The models of these categories are the models of the
 * providers MCP servers offer: the first of the list makes it, the next one when it fails. Offered only while the list
 * has a model.
 *
 * spec: brief/models.md#категории-моделей — generation of sound and of video
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
 */
final class MediaMakeTool extends SandboxTool implements \OpenCCK\App\Agent\Tool\Available {
    public const VOICE = 'voice_generate';
    public const FILM = 'video_generate';
    private const EXT = ['audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

    public function __construct(
        Sandbox $sandbox,
        private readonly MediaModels $models,
        private readonly MediaSettings $settings,
        /** voice or film */
        private readonly string $kind,
    ) {
        parent::__construct($sandbox);
    }

    public function available(): bool {
        return $this->settings->models($this->kind) !== [];
    }

    public function definition(): ToolDefinition {
        $voice = $this->kind === 'voice';
        $properties = $voice
            ? ['text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000, 'description' => t('tool.media_make.text')]]
            : ['prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000, 'description' => t('tool.media_make.prompt')],
                'aspect_ratio' => ['type' => 'string', 'pattern' => '^[0-9]{1,2}:[0-9]{1,2}$']];
        $properties['model'] = ['type' => 'string', 'maxLength' => 200, 'description' => t('tool.media_make.model')];
        $properties['name'] = ['type' => 'string', 'maxLength' => 80, 'description' => t('tool.media_make.name')];
        return new ToolDefinition(
            $voice ? self::VOICE : self::FILM,
            sprintf(t($voice ? 'tool.media_make.voice_about' : 'tool.media_make.film_about'), implode(', ', $this->settings->models($this->kind))),
            ['type' => 'object', 'properties' => $properties, 'required' => [$voice ? 'text' : 'prompt'], 'additionalProperties' => false],
        );
    }

    /** A video is an expensive call: it is asked of the owner like other irreversible spending. */
    public function danger(array $args): DangerClass {
        return $this->kind === 'voice' ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function timeout(): int {
        return $this->kind === 'voice' ? 300 : 900;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $voice = $this->kind === 'voice';
        $input = trim(Args::string($args, $voice ? 'text' : 'prompt') ?? '');
        if ($input === '') {
            return ToolResult::error(t('tool.media_make.empty'));
        }
        $list = $this->settings->models($this->kind);
        $asked = Args::string($args, 'model');
        if ($asked !== null && $asked !== '' && !in_array($asked, $list, true)) {
            return ToolResult::error(sprintf(t('tool.image_generate.not_in_list'), implode(', ', $list)));
        }
        $call = new MediaCall($context->conversationId, $context->runId, $context->cancellation);
        $errors = [];
        foreach (array_values(array_unique(array_filter([$asked, ...$list], static fn(?string $m): bool => $m !== null && $m !== ''))) as $ref) {
            [$file, $error] = $voice ? $this->models->voice($ref, $input, $call) : $this->models->film($ref, $input, Args::string($args, 'aspect_ratio'), $call);
            if ($file !== null) {
                $base = preg_replace('~[^a-z0-9-]+~', '-', strtolower(Args::string($args, 'name') ?? '')) ?? '';
                $base = trim($base, '-') === '' ? ($voice ? 'speech' : 'video') . '-' . gmdate('Ymd-His') : trim($base, '-');
                $path = ($voice ? 'audio/' : 'video/') . $base . '.' . (self::EXT[$file['type']] ?? pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin');
                $this->sandbox->put($context->principal->id, $limits, $path, $file['bytes'], $context->cancellation);
                return ToolResult::ok(sprintf(t('tool.media_make.saved'), $path, $ref, strlen($file['bytes'])));
            }
            $errors[] = $ref . ': ' . ($error ?? '');
        }
        return ToolResult::error(sprintf(t('tool.media_make.failed'), implode('; ', $errors)));
    }
}
