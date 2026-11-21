<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

/**
 * A message that starts with a command may carry several: every line that starts with "/command" is its own command,
 * and the lines without a slash between them are one ordinary message each. A private key in /secret stays one
 * command up to its END line.
 *
 * spec: brief/operations.md#команды-в-одном-сообщении
 */
final class CommandBatch {
    private const COMMAND = '~^/[A-Za-z][A-Za-z0-9_]*(@[A-Za-z0-9_]+)?(\s|$)~';

    /**
     * The pieces of the message in their order; a message that is not a batch comes back whole, as it is.
     *
     * @return list<string>
     */
    public static function split(string $text): array {
        $lines = preg_split('~\R~u', $text) ?: [$text];
        if (preg_match(self::COMMAND, ltrim($lines[0])) !== 1) {
            return [$text];
        }
        $pieces = [];
        $plain = [];
        $inKey = false;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($inKey) {
                $pieces[] = (array_pop($pieces) ?? '') . "\n" . rtrim($line);
                $inKey = !str_contains($trimmed, '-----END ');
                continue;
            }
            if (preg_match(self::COMMAND, $trimmed) !== 1) {
                $plain[] = $line;
                continue;
            }
            self::flush($pieces, $plain);
            $pieces[] = rtrim($trimmed);
            $inKey = str_contains($trimmed, '-----BEGIN ') && !str_contains($trimmed, '-----END ');
        }
        self::flush($pieces, $plain);
        return count($pieces) > 1 ? $pieces : [$text];
    }

    /**
     * @param list<string> $pieces
     * @param list<string> $plain
     */
    private static function flush(array &$pieces, array &$plain): void {
        $message = trim(implode("\n", $plain));
        if ($message !== '') {
            $pieces[] = $message;
        }
        $plain = [];
    }
}
