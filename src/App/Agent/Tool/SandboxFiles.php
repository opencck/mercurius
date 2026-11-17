<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * Files to attach, read whole from the participant's sandbox before anything is sent: a missing one stops the message.
 *
 * spec: BRIEF.md#между-агентами — up to 10 files and 20 MB a message
 */
final class SandboxFiles {
    public const COUNT_MAX = 10;
    public const BYTES_MAX = 20_000_000;

    /**
     * @param array<string, mixed> $args the call: `files` is a list of paths
     * @return list<array{name: string, bytes: string}>
     * @throws RuntimeException with the reason for the model
     */
    public static function read(?Sandbox $sandbox, array $args, ToolContext $context): array {
        /** @var mixed $paths */
        $paths = $args['files'] ?? [];
        if (!is_array($paths) || $paths === []) {
            return [];
        }
        $limits = $context->policy?->sandbox;
        if ($sandbox === null || $limits === null) {
            throw new RuntimeException(t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika'));
        }
        $files = [];
        $total = 0;
        /** @var mixed $path */
        foreach (array_slice($paths, 0, self::COUNT_MAX) as $path) {
            if (!is_string($path)) {
                continue;
            }
            try {
                $bytes = $sandbox->fetch($context->principal->id, $limits, $path, SendFileTool::MAX_BYTES, $context->cancellation);
            } catch (SandboxFailure $e) {
                throw new RuntimeException($e->getMessage());
            }
            $total += strlen($bytes);
            if ($total > self::BYTES_MAX) {
                throw new RuntimeException(sprintf(t('tool.send_message.too_much'), self::BYTES_MAX));
            }
            $name = basename(str_replace('\\', '/', $path));
            $files[] = ['name' => $name === '' ? 'file' : $name, 'bytes' => $bytes];
        }
        return $files;
    }
}
