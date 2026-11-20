<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Media;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Infrastructure\Config\MediaSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Files that came in are kept MEDIA_INBOX_DAYS: then the days' folders of inbox/ are removed, as each participant in
 * their own home. What the agent saved elsewhere stays.
 *
 * spec: brief/internet.md#входящие-вложения — inbox/ is kept 30 days
 */
final class InboxCleaner {
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
        private readonly Sandbox $sandbox,
        private readonly MediaSettings $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @return int homes looked at */
    public function run(): int {
        $command = sprintf('find inbox -mindepth 1 -maxdepth 1 -type d -mtime +%d -exec rm -rf {} + 2>/dev/null; true', $this->settings->inboxDays);
        $done = 0;
        foreach ($this->principals->all() as $principal) {
            $limits = $this->policies->forPrincipal($principal)->sandbox;
            if ($limits === null || $principal->status === PrincipalStatus::Rejected) {
                continue;
            }
            try {
                $this->sandbox->exec($principal->id, $limits, 'inbox-' . $principal->id, $command, '.', 60, new NullCancellation());
                $done++;
            } catch (SandboxFailure $e) {
                $this->logger->notice('Inbox was not cleaned', ['principal' => $principal->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
        return $done;
    }
}
