<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Cancellation;
use Closure;
use DomainException;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Repository\SshHostKeyRepository;
use OpenCCK\Domain\Ssh\SshHostKey;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

use function Mercurius\I18n\t;

/**
 * One way to a server for every caller: classify, journal the start, run through the process of the SSH entry, journal
 * the end. The core does not connect by itself: our MCP in the sandbox does, with the secrets of its entry and the host
 * key the owner trusted. A command from the "never" list does not run whoever asks; a journal that cannot record the
 * start stops the command; a key the owner has not trusted goes to the owner as a card.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the wrapper of the core around our MCP
 * ref: references/ssh-mcp/src/tools/pipeline.ts@e04ebf2:L16-L30 — a failure after an allowed start is journalled with
 *      the real decision; here the start itself is a record, so a failure to journal the end is visible too
 */
final class ServerConsole {
    public const OUTPUT_MAX = 32_768;
    public const TIMEOUT_MAX = 600;
    /** what our MCP answers for a key it was not given: the fingerprint, new or changed, and whose — the jump's */
    private const UNKNOWN_KEY = '~^UNKNOWN_HOST_KEY (SHA256:[A-Za-z0-9+/=]+) (new|changed)( jump)?~';

    /** @var Closure(SshTarget, array<string, mixed>, Cancellation): array{string, bool, array<array-key, mixed>|null} */
    private readonly Closure $call;
    /** @var Closure(SshTarget, SshHostKey, string, bool, bool): void */
    private readonly Closure $askHostKey;

    /**
     * @param Closure(SshTarget, array<string, mixed>, Cancellation): array{string, bool, array<array-key, mixed>|null} $call
     *        the tool `run` of the entry's process: its text, whether it is an error, its structured content
     * @param Closure(SshTarget, SshHostKey, string, bool, bool): void $askHostKey tells the owner about a key to trust:
     *        the entry, the row of its address, the fingerprint, whether it changed, whether it is of the jump
     * @param list<string> $confirmGroups groups of servers where even a read waits for the owner
     */
    public function __construct(
        private readonly SshEntries $entries,
        private readonly SshCommandClassifier $classifier,
        private readonly CommandAuditRepository $audit,
        Closure $call,
        private readonly SshHostKeyRepository $keys,
        Closure $askHostKey,
        private readonly LoggerInterface $logger,
        private readonly array $confirmGroups = [],
    ) {
        $this->call = $call;
        $this->askHostKey = $askHostKey;
    }

    /**
     * The class that decides how the command is let through: a read on a server of a confirmed group is a change. A
     * name may be kept by several (a member's entry and a project's): a read waits if any of them is in such a group.
     */
    public function classify(string $command, ?string $serverName = null): Classification {
        $class = $this->classifier->classify($command);
        if ($class->isRead() && $serverName !== null) {
            foreach ($this->entries->named($serverName) as $target) {
                if (in_array($target->group, $this->confirmGroups, true)) {
                    return new Classification(Classification::CHANGE, sprintf(t('server.server_console.gruppa_kazhdaya_komanda_s_podtverzhdenie'), $target->group));
                }
            }
        }
        return $class;
    }

    /** The entry a call names, as the caller reaches it from the run; null — none. */
    public function target(string $serverName, Member $principal, ?string $context): ?SshTarget {
        return $this->entries->find($serverName, $principal->id, $context);
    }

    /**
     * @param bool $approved the owner allowed this very call (a card or a saved grant): only then a command of the "never"
     *                       list runs — spec: brief/agent-loop.md#подтверждения-и-запреты, the owner decides even about those
     * @param string|null $context the context of the run: its entries first, then the caller's own
     * @param string|null $stdin what the command reads: data, never a reason to classify it otherwise
     * @return array{bool, string} success and the text for the model (already masked)
     */
    public function run(Member $principal, ?int $runId, string $serverName, string $command, int $timeout, Cancellation $cancellation, bool $approved = false, ?string $context = null, #[SensitiveParameter] ?string $stdin = null): array {
        $target = $this->target($serverName, $principal, $context);
        if ($target === null) {
            return [false, sprintf(t('server.server_console.servera_net_v_reestre'), $serverName)];
        }
        $class = $this->classify($command, $target->key);
        $record = self::record($principal, $runId, $target, SecretMasker::mask($command), $class->class);
        if ($class->class === Classification::NEVER && !$approved) {
            $this->journal(['event' => 'refused', 'error' => $class->reason] + $record);
            return [false, sprintf(t('server.server_console.eta_komanda_zapreschena_vsegda'), $class->reason)];
        }
        return $this->go($target, $record, $stdin === null ? ['command' => $command] : ['command' => $command, 'stdin' => $stdin], $timeout, $cancellation, false);
    }

    /**
     * A command built by a tool (SQL, a file write, a probe) rather than typed by the model: its class is the tool's,
     * the journal keeps what it means instead of how it is spelled, stdin may carry data or a password.
     *
     * @return array{bool, string}
     */
    public function runPrepared(Member $principal, ?int $runId, string $serverName, string $journalled, string $class, string $command, #[SensitiveParameter] ?string $stdin, int $timeout, Cancellation $cancellation, ?string $context = null): array {
        $target = $this->target($serverName, $principal, $context);
        if ($target === null) {
            return [false, sprintf(t('server.server_console.servera_net_v_reestre'), $serverName)];
        }
        $args = ['command' => $command];
        if ($stdin !== null) {
            $args['stdin'] = $stdin;
        }
        return $this->go($target, self::record($principal, $runId, $target, SecretMasker::mask($journalled), $class), $args, $timeout, $cancellation, true);
    }

    /**
     * @param array{principal: ?int, run: ?int, server: string, command: string, class: string, exit: ?int, ms: ?int, error: ?string} $record
     * @param array<string, mixed> $args
     * @return array{bool, string}
     */
    private function go(SshTarget $target, array $record, array $args, int $timeout, Cancellation $cancellation, bool $exitDecides): array {
        try {
            $this->audit->append(['event' => 'start'] + $record);
        } catch (Throwable $e) {
            $this->logger->error('Command journal is down; the command did not run', ['server' => $target->key, 'error' => $e::class]);
            return [false, t('server.server_console.zhurnal_komand_nedostupen_komanda_ne')];
        }
        $timeout = max(1, min(self::TIMEOUT_MAX, $timeout));
        $started = hrtime(true);
        try {
            $result = $this->remote($target, $args + ['timeout' => $timeout], $cancellation, $started);
        } catch (UnknownHostKey $e) {
            $this->journal(['event' => 'refused', 'error' => $e->changed ? 'host key changed' : 'host key unknown'] + $record);
            return [false, $this->unknownKey($target, $e)];
        } catch (RemoteFailure $e) {
            $this->journal(['event' => 'failed', 'error' => SecretMasker::mask($e->getMessage())] + $record);
            return [false, SecretMasker::mask($e->getMessage())];
        }
        $journalled = $this->journal(['event' => 'done', 'exit' => $result->exitCode, 'ms' => $result->durationMs] + $record);
        return [!$exitDecides || $result->exitCode === 0, $this->report($result, $timeout, $journalled)];
    }

    /**
     * The command through the entry's process: its fields, or the refused host key, or the failure in its words.
     *
     * @param array<string, mixed> $args
     * @throws UnknownHostKey
     * @throws RemoteFailure
     */
    private function remote(SshTarget $target, array $args, Cancellation $cancellation, int $started): RemoteResult {
        try {
            [$text, $error, $structured] = ($this->call)($target, $args, $cancellation);
        } catch (DomainException $e) {
            throw new RemoteFailure($e->getMessage());
        }
        // only an answer without fields: the output of a command cannot pass for a refused key
        if ($structured === null && preg_match(self::UNKNOWN_KEY, $text, $m) === 1) {
            throw new UnknownHostKey($m[1], $m[2] === 'changed', ($m[3] ?? '') !== '' ? 'jump' : null);
        }
        $exit = $structured['exit'] ?? null;
        if ($structured === null || !array_key_exists('exit', $structured) || ($exit !== null && !is_int($exit))) {
            throw new RemoteFailure($error && trim($text) !== '' ? trim($text) : t('ssh.amp_ssh_shell.vyvod_komandy_ne_prochitan'));
        }
        /** @var mixed $stdout */
        $stdout = $structured['stdout'] ?? '';
        /** @var mixed $stderr */
        $stderr = $structured['stderr'] ?? '';
        return new RemoteResult(
            $exit,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
            ($structured['cut'] ?? false) === true,
            intdiv(hrtime(true) - $started, 1_000_000),
        );
    }

    /** A key not trusted: a new one waits for the owner's card, a changed one is a warning with a way to forget it. */
    private function unknownKey(SshTarget $target, UnknownHostKey $e): string {
        $jump = $e->server === 'jump';
        $address = $jump ? ($target->jumpAddress() ?? $target->address()) : $target->address();
        $name = $jump ? sprintf('%s (%s)', $target->name, $address) : $target->name;
        $row = $e->changed ? ($this->keys->of($target->key, $address) ?? $this->keys->setPending($target->key, $address, $e->fingerprint)) : $this->keys->setPending($target->key, $address, $e->fingerprint);
        try {
            ($this->askHostKey)($target, $row, $e->fingerprint, $e->changed, $jump);
        } catch (Throwable $failure) {
            $this->logger->warning('The host key card was not sent', ['server' => $target->key, 'error' => $failure::class]);
        }
        return $e->changed
            ? sprintf(t('server.server_console.klyuch_hosta_izmenilsya_podklyuchenie_ot'), $name, $e->fingerprint)
            : sprintf(t('server.server_console.server_esche_ne_znakom_vladeltsu'), $name, $e->fingerprint);
    }

    /** @return array{principal: ?int, run: ?int, server: string, command: string, class: string, exit: ?int, ms: ?int, error: ?string} */
    private static function record(Member $principal, ?int $runId, SshTarget $target, string $command, string $class): array {
        return ['principal' => $principal->id, 'run' => $runId, 'server' => $target->key, 'command' => $command, 'class' => $class, 'exit' => null, 'ms' => null, 'error' => null];
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
            t('server.server_console.kod_vyhoda_ms'),
            $result->exitCode === null ? t('server.server_console.net') : (string) $result->exitCode,
            $result->durationMs,
            $result->exitCode === RemoteResult::TIMED_OUT ? sprintf(t('server.server_console.ostanovlena_po_taymautu_s'), $timeout) : '',
        )];
        $cut = $result->truncated;
        foreach (['stdout' => $result->stdout, 'stderr' => $result->stderr] as $stream => $text) {
            if ($text === '') {
                continue;
            }
            if (strlen($text) > self::OUTPUT_MAX) {
                $text = mb_strcut($text, 0, self::OUTPUT_MAX);
                $cut = true;
            }
            $parts[] = $stream . ":\n" . SecretMasker::mask($text);
        }
        if ($cut) {
            $parts[] = sprintf(t('server.server_console.vyvod_obrezan_do_bayt'), self::OUTPUT_MAX);
        }
        if (!$journalled) {
            $parts[] = t('server.server_console.vnimanie_itog_ne_zapisan_v');
        }
        return implode("\n\n", $parts);
    }
}
