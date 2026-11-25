<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Cancellation;
use Closure;
use Mercurius\Connectors\RouteException;
use Mercurius\Connectors\RouteFactory;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

use function Mercurius\I18n\t;

/**
 * One way to a server for every caller: classify, journal the start, take the secret and the route, run, journal the
 * end. A command from the "never" list does not run whoever asks; a journal that cannot record the start stops the
 * command; a key the owner has not trusted goes to the owner as a card.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh
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
        private readonly SshCommandClassifier $classifier,
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
                return new Classification(Classification::CHANGE, sprintf(t('server.server_console.gruppa_kazhdaya_komanda_s_podtverzhdenie'), $server->group));
            }
        }
        return $class;
    }

    /**
     * @param bool $approved the owner allowed this very call (a card or a saved grant): only then a command of the "never"
     *                       list runs — spec: brief/agent-loop.md#подтверждения-и-запреты, the owner decides even about those
     * @return array{bool, string} success and the text for the model (already masked)
     */
    public function run(Member $principal, ?int $runId, string $serverName, string $command, int $timeout, Cancellation $cancellation, bool $approved = false): array {
        $server = $this->servers->byName($serverName);
        if ($server === null) {
            return [false, sprintf(t('server.server_console.servera_net_v_reestre'), $serverName)];
        }
        $class = $this->classify($command, $server->name);
        $record = ['principal' => $principal->id, 'run' => $runId, 'server' => $server->name, 'command' => SecretMasker::mask($command), 'class' => $class->class, 'exit' => null, 'ms' => null, 'error' => null];
        if ($class->class === Classification::NEVER && !$approved) {
            $this->journal(['event' => 'refused', 'error' => $class->reason] + $record);
            return [false, sprintf(t('server.server_console.eta_komanda_zapreschena_vsegda'), $class->reason)];
        }
        $connection = $this->connection($server, $principal, true);
        if (is_string($connection)) {
            return [false, $connection];
        }
        [$command, $stdin] = $this->sudo($command, $class, $connection);
        try {
            $this->audit->append(['event' => 'start'] + $record);
        } catch (Throwable $e) {
            $this->logger->error('Command journal is down; the command did not run', ['server' => $server->name, 'error' => $e::class]);
            return [false, t('server.server_console.zhurnal_komand_nedostupen_komanda_ne')];
        }
        $timeout = max(1, min(self::TIMEOUT_MAX, $timeout));
        try {
            $result = $this->shell->run($connection, $command, $timeout, self::OUTPUT_MAX, $cancellation, $stdin);
        } catch (UnknownHostKey $e) {
            $this->journal(['event' => 'refused', 'error' => $e->changed ? 'host key changed' : 'host key unknown'] + $record);
            $at = $this->servers->byName($e->server ?? $server->name) ?? $server;
            if (!$e->changed) {
                $this->servers->setPendingHostKey($at->id, $e->fingerprint);
            }
            ($this->askHostKey)($at, $e->fingerprint, $e->changed);
            return [false, $e->changed
                ? sprintf(t('server.server_console.klyuch_hosta_izmenilsya_podklyuchenie_ot'), $at->name, $e->fingerprint)
                : sprintf(t('server.server_console.server_esche_ne_znakom_vladeltsu'), $at->name, $e->fingerprint)];
        } catch (RemoteFailure $e) {
            $this->journal(['event' => 'failed', 'error' => SecretMasker::mask($e->getMessage())] + $record);
            return [false, SecretMasker::mask($e->getMessage())];
        }
        $journalled = $this->journal(['event' => 'done', 'exit' => $result->exitCode, 'ms' => $result->durationMs] + $record);
        return [true, $this->report($result, $timeout, $journalled, $connection)];
    }

    /**
     * A command built by a tool (SQL, a file write) rather than typed by the model: its class is the tool's, the journal
     * keeps what it means instead of how it is spelled, stdin may carry data or a password.
     *
     * @return array{bool, string}
     */
    public function runPrepared(Member $principal, ?int $runId, string $serverName, string $journalled, string $class, string $command, #[SensitiveParameter] ?string $stdin, int $timeout, Cancellation $cancellation): array {
        $server = $this->servers->byName($serverName);
        if ($server === null) {
            return [false, sprintf(t('server.server_console.servera_net_v_reestre'), $serverName)];
        }
        $record = ['principal' => $principal->id, 'run' => $runId, 'server' => $server->name, 'command' => SecretMasker::mask($journalled), 'class' => $class, 'exit' => null, 'ms' => null, 'error' => null];
        $connection = $this->connection($server, $principal, true);
        if (is_string($connection)) {
            return [false, $connection];
        }
        try {
            $this->audit->append(['event' => 'start'] + $record);
        } catch (Throwable $e) {
            $this->logger->error('Command journal is down; the command did not run', ['server' => $server->name, 'error' => $e::class]);
            return [false, t('server.server_console.zhurnal_komand_nedostupen_komanda_ne')];
        }
        $timeout = max(1, min(self::TIMEOUT_MAX, $timeout));
        try {
            $result = $this->shell->run($connection, $command, $timeout, self::OUTPUT_MAX, $cancellation, $stdin);
        } catch (UnknownHostKey $e) {
            $this->journal(['event' => 'refused', 'error' => 'host key'] + $record);
            $at = $this->servers->byName($e->server ?? $server->name) ?? $server;
            if (!$e->changed) {
                $this->servers->setPendingHostKey($at->id, $e->fingerprint);
            }
            ($this->askHostKey)($at, $e->fingerprint, $e->changed);
            return [false, sprintf(t('server.server_console.klyuch_hosta_ne_podtverzhden_vladeltsu'), $at->name, $e->fingerprint)];
        } catch (RemoteFailure $e) {
            $this->journal(['event' => 'failed', 'error' => SecretMasker::mask($e->getMessage())] + $record);
            return [false, SecretMasker::mask($e->getMessage())];
        }
        $journalled = $this->journal(['event' => 'done', 'exit' => $result->exitCode, 'ms' => $result->durationMs] + $record);
        return [$result->exitCode === 0, $this->report($result, $timeout, $journalled, $connection)];
    }

    /**
     * The login and the route of a server; a text when one of them is missing. A jump goes one server deep.
     */
    private function connection(Server $server, Member $principal, bool $mayJump): Connection|string {
        if ($server->secretId === null) {
            return sprintf(t('server.server_console.u_servera_net_sekreta_dlya'), $server->name);
        }
        $credential = $this->vault->revealById($server->secretId, $principal);
        if ($credential === null) {
            return sprintf(t('server.server_console.sekret_servera_nedostupen_ego_vidyat'), $server->name);
        }
        $kind = str_starts_with(ltrim($credential), '-----BEGIN') ? 'private_key' : 'password';
        $route = $server->route;
        try {
            if ($route === null) {
                return new Connection($server, $credential, $kind);
            }
            if ($route === 'vless') {
                return $this->vlessUri === null
                    ? sprintf(t('server.server_console.server_hodit_cherez_vless_a'), $server->name)
                    : new Connection($server, $credential, $kind, RouteFactory::fromUri($this->vlessUri, $this->logger));
            }
            if (str_starts_with($route, 'secret:')) {
                $uri = $this->vault->reveal(substr($route, 7), $principal);
                return $uri === null
                    ? sprintf(t('server.server_console.sekret_s_marshrutom_servera_nedostupen'), $server->name)
                    : new Connection($server, $credential, $kind, RouteFactory::fromUri(trim($uri), $this->logger));
            }
            if (str_starts_with($route, 'via:')) {
                $via = $this->servers->byName(substr($route, 4));
                if (!$mayJump || $via === null || ($via->route !== null && str_starts_with($via->route, 'via:'))) {
                    return sprintf(t('server.server_console.promezhutochnyy_server_ne_nayden_ili'), substr($route, 4));
                }
                $jump = $this->connection($via, $principal, false);
                return is_string($jump) ? $jump : new Connection($server, $credential, $kind, null, $jump);
            }
        } catch (RouteException) {
            return sprintf(t('server.server_console.marshrut_servera_ne_razobran_proverte'), $server->name);
        }
        return sprintf(t('server.server_console.neizvestnyy_marshrut_servera'), $server->name);
    }

    /**
     * A command of one part that starts with sudo gets the login password — for sudo alone: `sudo -S` reads it on
     * stdin, the command runs in `sh -c` with its own stdin from /dev/null. A command of several parts (`;`, `&&`, `|`, a substitution, a second line) gets no password: the other parts would
     * read it. Only a password login has a password to give.
     *
     * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the password of sudo reaches no command
     * @return array{string, ?string} the command and its stdin
     */
    private function sudo(string $command, Classification $class, Connection $connection): array {
        if ($class->class !== Classification::PRIVILEGED || $connection->kind !== 'password' || preg_match('~^\s*sudo\s+(?!-S\b)~', $command) !== 1) {
            return [$command, null];
        }
        if (preg_match('~[;&|\n\r`]|\$\(~', $command) === 1) {
            return [$command, null];
        }
        // sudo reads the password from the stdin of the session; the command inside gets /dev/null as its own, so it
        // cannot read the password even when sudo does not ask for it (a ticket, NOPASSWD). A ticket of `sudo -v` is
        // no way: without a terminal the next sudo does not see it (checked on the SSH test server).
        $rest = preg_replace('~^\s*sudo\s+~', '', $command) ?? $command;
        return ["sudo -S -p '' sh -c '" . str_replace("'", "'\\''", $rest . ' < /dev/null') . "'", $connection->credential . "\n"];
    }

    /** The output without the login password, in the spellings a command could print it in. */
    private static function hide(string $text, ?Connection $connection): string {
        if ($connection === null || $connection->kind !== 'password' || strlen($connection->credential) < 4) {
            return $text;
        }
        $secret = $connection->credential;
        return str_replace(array_values(array_unique([$secret, base64_encode($secret), rtrim(base64_encode($secret), '='), bin2hex($secret), strtoupper(bin2hex($secret))])), '***', $text);
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

    private function report(RemoteResult $result, int $timeout, bool $journalled, ?Connection $connection = null): string {
        $parts = [sprintf(
            t('server.server_console.kod_vyhoda_ms'),
            $result->exitCode === null ? t('server.server_console.net') : (string) $result->exitCode,
            $result->durationMs,
            $result->exitCode === RemoteResult::TIMED_OUT ? sprintf(t('server.server_console.ostanovlena_po_taymautu_s'), $timeout) : '',
        )];
        if ($result->stdout !== '') {
            $parts[] = "stdout:\n" . SecretMasker::mask(self::hide($result->stdout, $connection));
        }
        if ($result->stderr !== '') {
            $parts[] = "stderr:\n" . SecretMasker::mask(self::hide($result->stderr, $connection));
        }
        if ($result->truncated) {
            $parts[] = sprintf(t('server.server_console.vyvod_obrezan_do_bayt'), self::OUTPUT_MAX);
        }
        if (!$journalled) {
            $parts[] = t('server.server_console.vnimanie_itog_ne_zapisan_v');
        }
        return implode("\n\n", $parts);
    }
}
