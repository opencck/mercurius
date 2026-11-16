<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Logger;

use Amp\Log\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

use function Amp\ByteStream\getStdout;

/**
 * Structured JSON logs to stdout: one object per line, collected by Docker.
 */
final class LoggerFactory {
    /** @param 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency' $level */
    public static function create(string $level, string $channel = 'mercurius'): Logger {
        $handler = new StreamHandler(getStdout(), $level);
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true);
        $formatter->setJsonPrettyPrint(false);
        $handler->setFormatter($formatter);

        $logger = new Logger($channel);
        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->useLoggingLoopDetection(false);

        return $logger;
    }
}
