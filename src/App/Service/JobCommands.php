<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DateTimeImmutable;
use OpenCCK\App\Agent\Tool\Projects\JobManageTool;
use OpenCCK\App\Job\DailyDigest;
use OpenCCK\App\Job\HookReceiver;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Repository\JobRepository;

use function Mercurius\I18n\t;

/**
 * The owner's commands for jobs: the list, the webhook of a job by event, the summary now.
 *
 * spec: brief/scheduler.md#как-сделано-задания-по-событию-и-проекты — the secret of a webhook is named, not shown: the owner puts it in the vault
 *       with /secret, the chat and the transcript never carry it
 */
final class JobCommands {
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobScheduler $scheduler,
        private readonly Vault $vault,
        private readonly DailyDigest $digest,
        private readonly ?string $appUrl,
    ) {}

    public function jobs(): string {
        $list = $this->jobs->list(null);
        if ($list === []) {
            return t('service.job_commands.zadaniy_net_agent_stavit_ih');
        }
        return t('service.job_commands.zadaniya') . implode("\n", array_map(
            fn(Job $j): string => JobManageTool::line($j, $this->scheduler) . ($j->byEvent() ? ($j->hookSecret === null ? t('service.job_commands.webhook_ne_podklyuchen') : t('service.job_commands.webhook_sekret') . $j->hookSecret) : ''),
            $list,
        ));
    }

    public function hook(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $id = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : 0;
        $secret = $parts[1] ?? '';
        $job = $this->jobs->find($id);
        if ($job === null || $secret === '') {
            return t('service.job_commands.hook_nomer_zadaniya_imya_sekreta');
        }
        if (!$job->byEvent()) {
            return sprintf(t('service.job_commands.zadanie_idet_po_raspisaniyu_a'), $id);
        }
        if ($secret === 'off') {
            $this->jobs->bindHook($id, null);
            return sprintf(t('service.job_commands.zadanie_bolshe_ne_prinimaet_webhook'), $id);
        }
        if (!$this->vault->enabled() || $this->vault->id($secret) === null) {
            return sprintf(t('service.job_commands.sekreta_net_v_hranilische_secret'), $secret, $secret);
        }
        $this->jobs->bindHook($id, $secret);
        return sprintf(
            t('service.job_commands.zadanie_prinimaet_webhook_post_hooks')
            . t('service.job_commands.webhook_github_s_etim_sekretom'),
            $id,
            rtrim($this->appUrl ?? t('service.job_commands.https_adres'), '/'),
            $id,
            $secret,
            HookReceiver::WINDOW,
            intdiv(HookReceiver::BODY_MAX, 1024),
            HookReceiver::PER_HOUR,
        );
    }

    public function digest(): string {
        return $this->digest->text(new DateTimeImmutable()) ?? t('service.job_commands.svodka_pusta_net_srokov_na');
    }
}
