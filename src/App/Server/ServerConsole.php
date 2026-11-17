<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Cancellation;
use Closure;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One way to a server for every caller: classify, journal the start, take the secret, run, journal the end. A command
 * from the "never" list does not run whoever asks; a journal that cannot record the start stops the command; a key the
 * owner has not trusted goes to the owner as a card.
 *
 * spec: BRIEF.md#фаза-7-по-частям
 * ref: references/ssh-mcp/src/tools/pipeline.ts@e04ebf2:L16-L30 — a failure after an allowed start is journalled with
 *      the real decision; here the start itself is a record, so a failure to journal the end is visible too
 */
final class ServerConsole {
    public const OUTPUT_MAX = 32_768;
    public const TIMEOUT_MAX = 600;

    /**
     * @param Closure(Server, string, bool): void $askHostKey tells the owner about a key to trust
     */
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Vault $vault,
        private readonly CommandClassifier $classifier,
        private readonly CommandAuditRepository $audit,
        private readonly RemoteShell $shell,
        private readonly Closure $askHostKey,
        private readonly LoggerInterface $logger,
    ) {}

    public function classify(string $command): Classification {
        return $this->classifier->classify($command);
    }

    /**
     * @return array{bool, string} success and the text for the model (already masked)
     */
    public function run(Principal $principal, ?int $runId, string $serverName, string $command, int $timeout, Cancellation $cancellation): array {
        $server = $this->servers->byName($serverName);
        if ($server === null) {
            return [false, sprintf('Сервера «%s» нет в реестре.', $serverName)];
        }
        $class = $this->classifier->classify($command);
        $masked = SecretMasker::mask($command);
        $record = ['principal' => $principal->id, 'run' => $runId, 'server' => $server->name, 'command' => $masked, 'class' => $class->class, 'exit' => null, 'ms' => null, 'error' => null];
        if ($class->class === Classification::NEVER) {
            $this->journal($record + ['event' => 'refused', 'error' => $class->reason]);
            return [false, sprintf('Эта команда запрещена всегда: %s.', $class->reason)];
        }
        if ($server->secretId === null) {
            return [false, sprintf('У сервера «%s» нет секрета для входа.', $server->name)];
        }
        $credential = $this->vault->revealById($server->secretId, $principal);
        if ($credential === null) {
            return [false, sprintf('Секрет сервера «%s» недоступен: его видят автор и владелец, и нужен SECRETS_KEY.', $server->name)];
        }
        $kind = str_starts_with(ltrim($credential), '-----BEGIN') ? 'private_key' : 'password';
        try {
            $this->audit->append($record + ['event' => 'start']);
        } catch (Throwable $e) {
            $this->logger->error('Command journal is down; the command did not run', ['server' => $server->name, 'error' => $e::class]);
            return [false, 'Журнал команд недоступен — команда не выполнена.'];
        }
        $timeout = max(1, min(self::TIMEOUT_MAX, $timeout));
        try {
            $result = $this->shell->run($server, $credential, $kind, $command, $timeout, self::OUTPUT_MAX, $cancellation);
        } catch (UnknownHostKey $e) {
            $this->journal($record + ['event' => 'refused', 'error' => $e->changed ? 'host key changed' : 'host key unknown']);
            if (!$e->changed) {
                $this->servers->setPendingHostKey($server->id, $e->fingerprint);
            }
            ($this->askHostKey)($server, $e->fingerprint, $e->changed);
            return [false, $e->changed
                ? sprintf('Ключ хоста «%s» изменился (%s) — подключение отклонено, владелец предупреждён.', $server->name, $e->fingerprint)
                : sprintf('Сервер «%s» ещё не знаком: владельцу отправлен отпечаток ключа (%s). Повтори, когда он подтвердит.', $server->name, $e->fingerprint)];
        } catch (RemoteFailure $e) {
            $this->journal($record + ['event' => 'failed', 'error' => SecretMasker::mask($e->getMessage())]);
            return [false, SecretMasker::mask($e->getMessage())];
        }
        $journalled = $this->journal($record + ['event' => 'done', 'exit' => $result->exitCode, 'ms' => $result->durationMs]);
        return [true, $this->report($result, $timeout, $journalled)];
    }

    /** @param array{principal: ?int, run: ?int, server: string, command: string, class: string, event: string, exit: ?int, ms: ?int, error: ?string} $record */
    private function journal(array $record): bool {
        try {
            $this->audit->append($record);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Command journal record lost', ['server' => $record['server'], 'event' => $record['event'], 'error' => $e::class]);
            return false;
        }
    }

    private function report(RemoteResult $result, int $timeout, bool $journalled): string {
        $parts = [sprintf(
            'код выхода: %s, %d мс%s',
            $result->exitCode === null ? 'нет' : (string) $result->exitCode,
            $result->durationMs,
            $result->exitCode === RemoteResult::TIMED_OUT ? sprintf(' (остановлена по таймауту %d с)', $timeout) : '',
        )];
        if ($result->stdout !== '') {
            $parts[] = "stdout:\n" . SecretMasker::mask($result->stdout);
        }
        if ($result->stderr !== '') {
            $parts[] = "stderr:\n" . SecretMasker::mask($result->stderr);
        }
        if ($result->truncated) {
            $parts[] = sprintf('(вывод обрезан до %d байт)', self::OUTPUT_MAX);
        }
        if (!$journalled) {
            $parts[] = '(внимание: итог не записан в журнал команд)';
        }
        return implode("\n\n", $parts);
    }
}
