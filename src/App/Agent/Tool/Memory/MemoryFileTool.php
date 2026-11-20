<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Memory;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ContextMemory;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\MemoryPlace;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;

use function Mercurius\I18n\t;

/**
 * Reads and edits a MEMORY.md: the one of this place (the project of the chat, or the group, channel, topic) or the
 * person's own. Everyone with access to the place may change its file, both sections; the person's own file is also
 * an ordinary file of their folder.
 *
 * spec: brief/memory.md#файл-памяти-места
 */
final class MemoryFileTool implements Tool {
    public const NAME = 'memory_file';

    public function __construct(
        private readonly ContextMemory $memory,
        private readonly ConversationRepository $conversations,
        private readonly ConversationAudience $audience,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.memory_file.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['read', 'write', 'replace']],
                'file' => ['type' => 'string', 'enum' => ['here', 'mine'], 'description' => t('tool.memory_file.file')],
                'content' => ['type' => 'string', 'maxLength' => ContextMemory::MAX_BYTES],
                'old' => ['type' => 'string'],
                'new' => ['type' => 'string'],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return ($args['action'] ?? null) === 'read' ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $place = $this->place(Args::string($args, 'file'), $context);
        if ($place === null) {
            return ToolResult::error(t('tool.memory_file.none'));
        }
        $action = Args::string($args, 'action');
        if ($action === 'read') {
            $text = $this->memory->read($place, $context->cancellation);
            return ToolResult::ok($text === '' ? t('tool.memory_file.empty') : $text);
        }
        $content = Args::string($args, 'content');
        $old = Args::string($args, 'old');
        $new = Args::string($args, 'new');
        foreach ([$content, $new] as $text) {
            // spec: brief/memory.md#файл-памяти-места — a secret has no place in a file every run of the place reads
            if ($text !== null && SecretMasker::mask($text) !== $text) {
                return ToolResult::error(t('tool.memory_file.secret'));
            }
        }
        try {
            if ($action === 'write' && $content !== null) {
                $this->memory->write($place, $content, $context->cancellation);
            } elseif ($action === 'replace' && $old !== null && $new !== null) {
                $this->memory->replace($place, $old, $new, $context->cancellation);
            } else {
                return ToolResult::error(t('tool.memory_file.how'));
            }
        } catch (SandboxFailure $e) {
            return ToolResult::error(sprintf(t('tool.memory_file.failed'), $e->getMessage()));
        }
        return ToolResult::ok(sprintf(t('tool.memory_file.saved'), $place->kind === MemoryPlace::PERSON ? t('tool.memory_file.of_person') : t('tool.memory_file.of_place')));
    }

    /**
     * The file meant: «mine» — the person's own; «here» or nothing — the place's when there is one, else, in a private
     * chat, the person's. Outside a private chat a person's own file is not at hand: it is not that place's.
     */
    private function place(?string $file, ToolContext $context): ?MemoryPlace {
        $private = $this->audience->isPrivate($context->conversationId);
        if ($file === 'mine') {
            return $private ? $this->memory->personal($context->principal) : null;
        }
        $meta = $this->conversations->meta($context->conversationId);
        $shared = $meta === null ? null : $this->memory->shared($context->principal, $meta->channel, $meta->chatId, $meta->threadId, $private);
        if ($shared !== null || $file === 'here' || !$private) {
            return $shared;
        }
        return $this->memory->personal($context->principal);
    }
}
