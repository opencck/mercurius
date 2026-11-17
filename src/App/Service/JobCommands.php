<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DateTimeImmutable;
use OpenCCK\App\Agent\Tool\JobCancelTool;
use OpenCCK\App\Job\DailyDigest;
use OpenCCK\App\Job\HookReceiver;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Repository\JobRepository;

/**
 * The owner's commands for jobs: the list, the webhook of a job by event, the summary now.
 *
 * spec: BRIEF.md#планировщик-и-проекты — the secret of a webhook is named, not shown: the owner puts it in the vault
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
            return 'Заданий нет. Агент ставит их сам: «напомни…», «проверяй каждый день…».';
        }
        return "Задания:\n" . implode("\n", array_map(
            fn(Job $j): string => JobCancelTool::line($j, $this->scheduler) . ($j->byEvent() ? ($j->hookSecret === null ? '; webhook не подключён' : '; webhook: секрет ' . $j->hookSecret) : ''),
            $list,
        ));
    }

    public function hook(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $id = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : 0;
        $secret = $parts[1] ?? '';
        $job = $this->jobs->find($id);
        if ($job === null || $secret === '') {
            return '/hook <номер задания> <имя секрета> — принимать webhook; /hook <номер> off — перестать. Секрет сначала сохраните: /secret <имя> token <значение>.';
        }
        if (!$job->byEvent()) {
            return sprintf('Задание #%d идёт по расписанию, а не по событию.', $id);
        }
        if ($secret === 'off') {
            $this->jobs->bindHook($id, null);
            return sprintf('Задание #%d больше не принимает webhook.', $id);
        }
        if (!$this->vault->enabled() || $this->vault->id($secret) === null) {
            return sprintf('Секрета «%s» нет в хранилище: /secret %s token <значение>.', $secret, $secret);
        }
        $this->jobs->bindHook($id, $secret);
        return sprintf(
            "Задание #%d принимает webhook: POST %s/hooks/%d\n\nПодпись — секретом «%s»: заголовки X-Webhook-Timestamp (время Unix, ±%d с) и X-Webhook-Signature-V2 — hex HMAC-SHA256 от «<время>.<тело>». "
            . 'Webhook GitHub с этим секретом тоже принимается. Тело — до %d КБ, не чаще %d раз в час; повтор той же доставки не запускает задание.',
            $id,
            rtrim($this->appUrl ?? 'https://<адрес>', '/'),
            $id,
            $secret,
            HookReceiver::WINDOW,
            intdiv(HookReceiver::BODY_MAX, 1024),
            HookReceiver::PER_HOUR,
        );
    }

    public function digest(): string {
        return $this->digest->text(new DateTimeImmutable()) ?? 'Сводка пуста: нет сроков на сегодня и завтра и нет заданий на сегодня.';
    }
}
