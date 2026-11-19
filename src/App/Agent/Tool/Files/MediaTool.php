<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Media\AttachmentIntake;
use OpenCCK\App\Agent\Media\MediaPrefs;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Infrastructure\Media\DocsService;
use OpenCCK\Infrastructure\Media\MediaAnswer;
use OpenCCK\Infrastructure\Media\MediaCall;
use OpenCCK\Infrastructure\Media\MediaModels;
use OpenCCK\Infrastructure\Media\VideoService;

use function Mercurius\I18n\t;

/**
 * Details of a file in the participant's sandbox, on request: a question to a picture, the text of speech, a question to
 * a video — by a video model, or by its frames (vision) and its sound (speech) when no video model is set —, the text of
 * a document from an offset, the models and the principles. The model is chosen by the principles of the owner and the
 * participant; only the owner's models are called. What comes back is data from outside.
 *
 * spec: BRIEF.md#входящие-вложения — the media tool
 * ref: references/hermes-agent/tools/vision_tools.py@e96221fa:L1-L7 — hermes has vision_analyze and video_analyze; here
 *      one tool for every kind, over the owner's list of models
 */
final class MediaTool extends SandboxTool implements ReadsOutside {
    public const NAME = 'media';

    public function __construct(
        Sandbox $sandbox,
        private readonly MediaModels $models,
        private readonly MediaPrefs $prefs,
        private readonly ?VideoService $video = null,
        private readonly ?DocsService $docs = null,
    ) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.media.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['look', 'transcribe', 'video', 'extract', 'models']],
                'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => t('tool.media.path')],
                'question' => ['type' => 'string', 'maxLength' => 2000, 'description' => t('tool.media.question')],
                'model' => ['type' => 'string', 'maxLength' => 200, 'description' => t('tool.media.model')],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => t('tool.media.offset')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return true;
    }

    public function readsOutside(array $args): bool {
        return Args::string($args, 'action') !== 'models';
    }

    public function timeout(): int {
        return 300;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        if ($action === 'models') {
            return ToolResult::ok($this->prefs->describe($context->principal->id));
        }
        $path = Args::string($args, 'path') ?? '';
        if ($path === '') {
            return ToolResult::error(t('tool.media.no_path'));
        }
        if ($action === 'extract') {
            return $this->extract($context, $limits, $path, max(0, Args::int($args, 'offset') ?? 0));
        }
        $model = ['look' => 'vision', 'transcribe' => 'speech', 'video' => 'video'][$action] ?? null;
        if ($model === null) {
            return ToolResult::error(t('tool.media.bad_action'));
        }
        $asked = Args::string($args, 'model');
        $settings = $this->prefs->settings;
        $kindOf = ['vision' => 'image', 'speech' => 'voice', 'video' => 'video'][$model];
        if ($asked !== null && $asked !== '' && !in_array($asked, [...$settings->models($model), ...($model === 'video' ? $settings->models('vision') : [])], true)) {
            return ToolResult::error(sprintf(t('media.prefs.bad_model'), implode(', ', $settings->models($model)) ?: t('media.prefs.none')));
        }
        $refs = MediaPrefs::candidates($settings, $model, $this->prefs->of($context->principal->id, $kindOf)->model, $asked);
        $question = trim(Args::string($args, 'question') ?? '') ?: t($model === 'video' ? 'media.prompt.video' : 'media.prompt.describe');
        $call = new MediaCall($context->conversationId, $context->runId, $context->cancellation);
        $bytes = $this->sandbox->fetch($context->principal->id, $limits, $path, AttachmentIntake::SANDBOX_MAX, $context->cancellation);
        $name = basename(str_replace('\\', '/', $path));
        $models = $this->models;
        return match ($model) {
            'vision' => $refs === [] ? self::notSet('vision') : self::answer($models->first($refs, static fn(string $ref): MediaAnswer => $models->look($ref, [['bytes' => $bytes, 'mime' => self::mime($name)]], $question, $call))),
            'speech' => $refs === [] ? self::notSet('speech') : self::answer($models->first($refs, static fn(string $ref): MediaAnswer => $models->transcribe($ref, $bytes, $name, $call))),
            default => $refs !== []
                ? self::answer($models->first($refs, static fn(string $ref): MediaAnswer => $models->video($ref, $bytes, self::mime($name), $question, $call)))
                : $this->byFrames($context, $bytes, $question, $asked, $call),
        };
    }

    /** No video model: its frames go to a vision model, its sound to a speech model, both answers together. */
    private function byFrames(ToolContext $context, string $bytes, string $question, ?string $asked, MediaCall $call): ToolResult {
        $settings = $this->prefs->settings;
        $vision = MediaPrefs::candidates($settings, 'vision', $this->prefs->of($context->principal->id, 'image')->model, $asked);
        $speech = MediaPrefs::candidates($settings, 'speech', $this->prefs->of($context->principal->id, 'voice')->model);
        if ($vision === [] && $speech === []) {
            return self::notSet('video');
        }
        // spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — frames and sound come from the media-video module, not the sandbox
        if ($this->video === null || !$this->video->up()) {
            return ToolResult::error(t('tool.media.no_video_module'));
        }
        try {
            $video = $this->video->frames($bytes, 8, $context->cancellation);
        } catch (\RuntimeException $e) {
            return ToolResult::error(sprintf(t('tool.media.no_frames'), $e->getMessage()));
        }
        $frames = array_map(static fn(string $frame): array => ['bytes' => $frame, 'mime' => 'image/jpeg'], $video['frames']);
        $parts = [];
        $models = $this->models;
        if ($frames !== [] && $vision !== []) {
            $seen = $models->first($vision, static fn(string $ref): MediaAnswer => $models->look($ref, $frames, $question . ' ' . t('tool.media.frames_note'), $call));
            $parts[] = sprintf(t('tool.media.frames'), $seen->model, $seen->text ?? ($seen->error ?? ''));
        }
        $sound = $video['audio'];
        if ($sound !== null && $speech !== []) {
            $heard = $models->first($speech, static fn(string $ref): MediaAnswer => $models->transcribe($ref, $sound, 'audio.m4a', $call));
            $parts[] = sprintf(t('tool.media.speech'), $heard->model, $heard->text ?? ($heard->error ?? ''));
        }
        return $parts === [] ? ToolResult::error(t('tool.media.nothing')) : ToolResult::ok(implode("\n\n", $parts));
    }

    /** The text of a document from the offset on, up to the limit: office and PDF read by media-docs, the rest as text. */
    private function extract(ToolContext $context, SandboxLimits $limits, string $path, int $offset): ToolResult {
        $limit = $this->prefs->settings->textLimit;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, DocsService::KINDS, true)) {
            // spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — office documents and PDF are read by the media-docs module
            if ($this->docs === null || !$this->docs->up()) {
                return ToolResult::error(t('media.intake.no_docs_module'));
            }
            $bytes = $this->sandbox->fetch($context->principal->id, $limits, $path, AttachmentIntake::SANDBOX_MAX, $context->cancellation);
            try {
                [$status, $text] = $this->docs->extract($bytes, basename(str_replace('\\', '/', $path)), $offset + $limit, $context->cancellation);
            } catch (\RuntimeException $e) {
                return ToolResult::error(sprintf(t('media.intake.not_extracted'), $e->getMessage()));
            }
            if ($status !== 200) {
                return ToolResult::error($status === 415 ? t('media.intake.unsupported') : sprintf(t('media.intake.not_extracted'), $text));
            }
        } else {
            $bytes = $this->sandbox->fetch($context->principal->id, $limits, $path, AttachmentIntake::SANDBOX_MAX, $context->cancellation);
            $converted = mb_check_encoding($bytes, 'UTF-8') ? $bytes : iconv('Windows-1251', 'UTF-8', $bytes);
            $text = $converted === false ? '' : $converted;
        }
        $piece = mb_substr($text, $offset, $limit);
        $rest = mb_strlen($text) - $offset - mb_strlen($piece);
        return ToolResult::ok(SecretMasker::mask($piece) . ($rest > 0 || mb_strlen($piece) === $limit ? sprintf(t('tool.media.more'), $offset + mb_strlen($piece)) : ''));
    }

    private static function answer(MediaAnswer $answer): ToolResult {
        return $answer->text === null
            ? ToolResult::error(sprintf(t('media.intake.failed_models'), $answer->error ?? ''))
            : ToolResult::ok(sprintf(t('media.intake.by_model'), $answer->model) . "\n" . SecretMasker::mask($answer->text));
    }

    private static function notSet(string $model): ToolResult {
        return ToolResult::error(sprintf(t('media.intake.not_set'), t('media.model.' . $model), 'MEDIA_' . strtoupper($model) . '_MODELS', t('media.input.' . $model)));
    }

    private static function mime(string $name): string {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'jpg', 'jpeg' => 'image/jpeg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
            default => 'application/octet-stream',
        };
    }
}
