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
use OpenCCK\Infrastructure\Media\MediaDrawing;
use OpenCCK\Infrastructure\Media\MediaModels;

use function Mercurius\I18n\t;

/**
 * A picture drawn by an image model of the owner's list (`MEDIA_IMAGE_MODELS`), saved as a file of the participant's
 * sandbox (`images/<name>.<ext>`): the agent sends it with send_file or looks at it with media. The owner's principles
 * of choosing come with the tool; the first model of the list draws when none is named, the next one when it fails.
 * Offered only while the list has a model.
 *
 * spec: brief/models.md#категории-моделей — image generation
 * ref: references/hermes-agent/tools/image_generation_tool.py@e96221fa:L329-L345 (_postprocess_image_generate_result),
 *      L584-L592 (check_image_generation_requirements) — the picture is a file the agent can see and the platform can
 *      send, and the tool is offered only while a backend is there; hermes draws through FAL, here through the image
 *      models of OpenRouter into the sandbox
 */
final class ImageGenerateTool extends SandboxTool {
    public const NAME = 'image_generate';
    private const EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const RU = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public function __construct(
        Sandbox $sandbox,
        private readonly MediaModels $models,
        private readonly MediaSettings $settings,
    ) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        $rules = $this->settings->rules('image');
        return new ToolDefinition(
            self::NAME,
            sprintf(t('tool.image_generate.about'), implode(', ', $this->settings->models('image')))
                . ($rules === '' ? '' : ' ' . sprintf(t('tool.image_generate.rules'), $rules)),
            [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000, 'description' => t('tool.image_generate.prompt')],
                    'model' => ['type' => 'string', 'maxLength' => 200, 'description' => t('tool.image_generate.model')],
                    'aspect_ratio' => ['type' => 'string', 'pattern' => '^(auto|[0-9]{1,2}:[0-9]{1,2})$', 'description' => t('tool.image_generate.aspect')],
                    'name' => ['type' => 'string', 'maxLength' => 80, 'description' => t('tool.image_generate.name')],
                ],
                'required' => ['prompt'],
                'additionalProperties' => false,
            ],
        );
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    public function timeout(): int {
        return 300;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $prompt = trim(Args::string($args, 'prompt') ?? '');
        if ($prompt === '') {
            return ToolResult::error(t('tool.image_generate.no_prompt'));
        }
        $list = $this->settings->models('image');
        $asked = Args::string($args, 'model');
        if ($asked !== null && $asked !== '' && !in_array($asked, $list, true)) {
            return ToolResult::error(sprintf(t('tool.image_generate.not_in_list'), implode(', ', $list)));
        }
        $aspect = Args::string($args, 'aspect_ratio');
        $aspect = $aspect !== null && preg_match('/^(auto|\d{1,2}:\d{1,2})$/', $aspect) === 1 ? $aspect : null;
        $call = new MediaCall($context->conversationId, $context->runId, $context->cancellation);

        $errors = [];
        $drawn = null;
        foreach (array_values(array_unique(array_filter([$asked, ...$list], static fn(?string $m): bool => $m !== null && $m !== ''))) as $ref) {
            $drawing = $this->models->draw($ref, $prompt, $aspect, $call);
            if ($drawing->images !== []) {
                $drawn = $drawing;
                break;
            }
            $errors[] = $ref . ': ' . ($drawing->error ?? '');
        }
        if (!$drawn instanceof MediaDrawing) {
            return ToolResult::error(sprintf(t('tool.image_generate.failed'), implode('; ', $errors)));
        }
        $image = $drawn->images[0];
        $path = 'images/' . self::slug(Args::string($args, 'name') ?? '', $prompt) . '.' . (self::EXT[$image->mime] ?? 'png');
        $this->sandbox->put($context->principal->id, $limits, $path, $image->bytes, $context->cancellation);
        return ToolResult::ok(sprintf(t('tool.image_generate.saved'), $path, $drawn->model, strlen($image->bytes)));
    }

    /** A file name from the name asked for (or the prompt): latin letters, digits and dashes. */
    private static function slug(string $name, string $prompt): string {
        $source = mb_strtolower(trim($name) !== '' ? $name : mb_substr($prompt, 0, 40));
        $latin = strtr($source, self::RU);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $latin) ?? '', '-');
        $slug = substr($slug, 0, 60);
        return $slug === '' ? 'image-' . date('Ymd-His') : rtrim($slug, '-');
    }
}
