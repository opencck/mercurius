<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Cancellation;
use Closure;
use Mercurius\Connectors\RouteException;
use Mercurius\Connectors\RouteFactory;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * One way to a server for every caller: classify, journal the start, take the secret and the route, run, journal the
 * end. A command from the "never" list does not run whoever asks; a journal that cannot record the start stops the
 * command; a key the owner has not trusted goes to the owner as a card.
 *
 * spec: BRIEF.md#фаза-7-по-частям
 * ref: references/ssh-mcp/src/tools/pipeline.ts@e04ebf2:L16-L30 — a failure after an allowed start is journalled with
 *      the real decision; here the start itself is a record, so a failure to journal the end is visible too
 */
final class ServerConsole {
    public const OUTPUT_MAX = 32_768;
    public const TIMEOUT_MAX = 600;

    /** @var Closure(Server, string, bool): void */
    private readonly Closure $askHostKey;
    private readonly ?string $vlessUri;

    /**
     * @param Closure(Server, string, bool): void $askHostKey tells the owner about a key to trust
     * @param string|null $vlessUri the application's VLESS route, for servers with route "vless"
     * @param list<string> $confirmGroups groups of servers where even a read waits for the owner
     */
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Vault $vault,
        private readonly CommandClassifier $classifier,
        private readonly CommandAuditRepository $audit,
        private readonly RemoteShell $shell,
        Closure $askHostKey,
        private readonly LoggerInterface $logger,
        #[SensitiveParameter] ?string $vlessUri = null,
        private readonly array $confirmGroups = [],
    ) {
        $this->askHostKey = $askHostKey;
        $this->vlessUri = $vlessUri;
    }

    /**
     * The class that decides how the command is let through on this server: a read on a server of a confirmed group
     * is a change.
     */
    public function classify(string $command, ?string $serverName = null): Classification {
        $class = $this->classifier->classify($command);
        if ($class->isRead() && $serverName !== null) {
            $server = $this->servers->byName($serverName);
            if ($server !== null && in_array($server->group, $this->confirmGroups, true)) {
                return new Classification(Classification::CHANGE, sprintf('группа %s: каждая команда с подтверждением', $server->group));
            }
        }
        return $class;
    }

    /**
     * @return array{bool, string} success and the text for the model (already masked)
     */
    public function run(Principal $principal, ?int $runId, string $serverName, string $command, int $timeout, Cancellation $cancellation): array {
        $server = $this->servers->byName($serverName);
        if ($server === null) {
            return [false, sprintf('Сервера «%s» нет в реестре.', $serverName)];
        }
        $class = $this->classify($command, $server->name);
        $record = ['principal' => $principal->id, 'run' => $runId, 'server' => $server->name, 'command' => SecretMasker::mask($command), 'class' => $class->class, 'exit' => null, 'ms' => null, 'error' => null];
        if ($class->class === Classification::NEVER) {
            $this->journal($record + ['event' => 'refused', 'error' => $class->reason]);
            return [false, sprintf('Эта команда запрещена всегда: %s.', $class->reason)];
        }
        $connection = $this->connection($server, $principal, true);
        if (is_string($connection)) {
            return [false, $connection];
        }
        [$command, $stdin] = $this->sudo($command, $class, $connection);
        try {
            $this->audit->append($record + ['event' => 'start']);
        } catch (Throwable $e) {
            $this->logger->error('Command journal is down; the command did not run', ['server' => $server->name, 'error' => $e::class]);
            return [false, 'Журнал команд недоступен — команда не выполнена.'];
        }
        $timeout = max(1, min(self::TIMEOUT_MAX, $timeout));
        try {
            $result = $this->shell->run($connection, $command, $timeout, self::OUTPUT_MAX, $cancellation, $stdin);
        } catch (UnknownHostKey $e) {
            $this->journal($record + ['event' => 'refused', 'error' => $e->changed ? 'host key changed' : 'host key unknown']);
            $at = $this->servers->byName($e->server ?? $server->name) ?? $server;
            if (!$e->changed) {
                $this->servers->setPendingHostKey($at->id, $e->fingerprint);
            }
            ($this->askHostKey)($at, $e->fingerprint, $e->changed);
            return [false, $e->changed
                ? sprintf('Ключ хоста «%s» изменился (%s) — подключение отклонено, владелец предупреждён.', $at->name, $e->fingerprint)
                : sprintf('Сервер «%s» ещё не знаком: владельцу отправлен отпечаток ключа (%s). Повтори, когда он подтвердит.', $at->name, $e->fingerprint)];
        } catch (RemoteFailure $e) {
            $this->journal($record + ['event' => 'failed', 'error' => SecretMasker::mask($e->getMessage())]);
            return [false, SecretMasker::mask($e->getMessage())];
        }
        $journalled = $this->journal($record + ['event' => 'done', 'exit' => $result->exitCode, 'ms' => $result->durationMs]);
        return [true, $this->report($result, $timeout, $journalled)];
    }

    /**
     * A command built by a tool (SQL, a file write) rather than typed by the model: its class is the tool's, the journal
     * keeps what it means instead of how it is spelled, stdin may carry data or a password.
     *
     * @return array{bool, string}
     */
    public function runPrepared(Principal $principal, ?int $runId, string $serverName, string $journalled, string $class, string $command, #[SensitiveParameter] ?string $stdin, int $timeout, Cancellation $cancellation): array {
        $server = $this->servers->byName($serverName);
        if ($server === null) {
            return [false, sprintf('Сервера «%s» нет в реестре.', $serverName)];
        }
        $record = ['principal' => $principal->id, 'run' => $runId, 'server' => $server->name, 'command' => SecretMasker::mask($journalled), 'class' => $class, 'exit' => null, 'ms' => null, 'error' => null];
        $connection = $this->connection($server, $principal, true);
        if (is_string($connection)) {
            return [false, $connection];
        }
        try {
            $this->audit->append($record + ['event' => 'start']);
        } catch (Throwable $e) {
            $this->logger->error('Command journal is down; the command did not run', ['server' => $server->name, 'error' => $e::class]);
            return [false, 'Журнал команд недоступен — команда не выполнена.'];
        }
        $timeout = max(1, min(self::TIMEOUT_MAX, $timeout));
        try {
            $result = $this->shell->run($connection, $command, $timeout, self::OUTPUT_MAX, $cancellation, $stdin);
        } catch (UnknownHostKey $e) {
            $this->journal($record + ['event' => 'refused', 'error' => 'host key']);
            $at = $this->servers->byName($e->server ?? $server->name) ?? $server;
            if (!$e->changed) {
                $this->servers->setPendingHostKey($at->id, $e->fingerprint);
            }
            ($this->askHostKey)($at, $e->fingerprint, $e->changed);
            return [false, sprintf('Ключ хоста «%s» не подтверждён: владельцу отправлен отпечаток (%s).', $at->name, $e->fingerprint)];
        } catch (RemoteFailure $e) {
            $this->journal($record + ['event' => 'failed', 'error' => SecretMasker::mask($e->getMessage())]);
            return [false, SecretMasker::mask($e->getMessage())];
        }
        $journalled = $this->journal($record + ['event' => 'done', 'exit' => $result->exitCode, 'ms' => $result->durationMs]);
        return [$result->exitCode === 0, $this->report($result, $timeout, $journalled)];
    }

    /**
     * The login and the route of a server; a text when one of them is missing. A jump goes one server deep.
     */
    private function connection(Server $server, Principal $principal, bool $mayJump): Connection|string {
        if ($server->secretId === null) {
            return sprintf('У сервера «%s» нет секрета для входа.', $server->name);
        }
        $credential = $this->vault->revealById($server->secretId, $principal);
        if ($credential === null) {
            return sprintf('Секрет сервера «%s» недоступен: его видят автор и владелец, и нужен SECRETS_KEY.', $server->name);
        }
        $kind = str_starts_with(ltrim($credential), '-----BEGIN') ? 'private_key' : 'password';
        $route = $server->route;
        try {
            if ($route === null) {
                return new Connection($server, $credential, $kind);
            }
            if ($route === 'vless') {
                return $this->vlessUri === null
                    ? sprintf('Сервер «%s» ходит через VLESS, а VLESS_URI не задан.', $server->name)
                    : new Connection($server, $credential, $kind, RouteFactory::fromUri($this->vlessUri, $this->logger));
            }
            if (str_starts_with($route, 'secret:')) {
                $uri = $this->vault->reveal(substr($route, 7), $principal);
                return $uri === null
                    ? sprintf('Секрет с маршрутом сервера «%s» недоступен.', $server->name)
                    : new Connection($server, $credential, $kind, RouteFactory::fromUri(trim($uri), $this->logger));
            }
            if (str_starts_with($route, 'via:')) {
                $via = $this->servers->byName(substr($route, 4));
                if (!$mayJump || $via === null || ($via->route !== null && str_starts_with($via->route, 'via:'))) {
                    return sprintf('Промежуточный сервер «%s» не найден или сам ходит через другой.', substr($route, 4));
                }
                $jump = $this->connection($via, $principal, false);
                return is_string($jump) ? $jump : new Connection($server, $credential, $kind, null, $jump);
            }
        } catch (RouteException) {
            return sprintf('Маршрут сервера «%s» не разобран: проверьте URI прокси.', $server->name);
        }
        return sprintf('Неизвестный маршрут сервера «%s».', $server->name);
    }

    /**
     * A command that starts with sudo gets the login password on stdin: sudo -S reads it there, the prompt is empty.
     * Only a password login has a password to give.
     *
     * @return array{string, ?string} the command and its stdin
     */
    private function sudo(string $command, Classification $class, Connection $connection): array {
        if ($class->class !== Classification::PRIVILEGED || $connection->kind !== 'password' || preg_match('~^\s*sudo\s+(?!-S\b)~', $command) !== 1) {
            return [$command, null];
        }
        return [preg_replace('~^\s*sudo\s+~', "sudo -S -p '' ", $command) ?? $command, $connection->credential . "\n"];
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
