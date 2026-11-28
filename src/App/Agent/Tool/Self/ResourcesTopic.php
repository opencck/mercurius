<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Sandbox\SandboxUsage;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Infrastructure\Host\HostResources;

use function Mercurius\I18n\t;

/**
 * The topic resources of self_info: the server now (memory, processors, the disk, the agent's container) and the
 * sandbox of the one who asks — its limits and how much of the disk is used. Counted at the call.
 *
 * spec: brief/agent-loop.md#ресурсы-сервера-и-песочницы
 */
final class ResourcesTopic {
    public function __construct(
        private readonly HostResources $host,
        private readonly ?Sandbox $sandbox,
    ) {}

    public function text(ToolContext $context): string {
        return $this->host->text() . "\n\n" . $this->sandboxLine($context);
    }

    private function sandboxLine(ToolContext $context): string {
        $limits = $context->policy?->sandbox;
        if ($limits === null || $this->sandbox === null) {
            return t('self.resources.no_sandbox');
        }
        $memory = $limits->memoryBytes === 0 ? t('self.resources.no_limit') : self::mb($limits->memoryBytes);
        $used = null;
        if ($this->sandbox instanceof SandboxUsage) {
            try {
                $used = $this->sandbox->usage(\OpenCCK\App\Agent\Tool\Sandbox\SandboxTool::holderOf($context), $limits, $context->cancellation);
            } catch (SandboxFailure) {
                // the count did not come: the limits are said without it
            }
        }
        if ($used === null) {
            return sprintf(t('self.resources.sandbox_unused'), self::mb($limits->diskBytes), $limits->processes, $limits->cpuSeconds, $memory, $limits->concurrentCommands);
        }
        return sprintf(t('self.resources.sandbox'), self::mb($used), self::mb($limits->diskBytes), (int) round(100 * $used / $limits->diskBytes), $limits->processes, $limits->cpuSeconds, $memory, $limits->concurrentCommands);
    }

    private static function mb(int $bytes): string {
        return $bytes >= 1024 ** 3 ? sprintf(t('self.resources.gb'), round($bytes / 1024 ** 3, 1)) : sprintf(t('self.resources.mb'), (int) round($bytes / 1024 ** 2));
    }
}
