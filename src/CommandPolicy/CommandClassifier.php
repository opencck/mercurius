<?php

declare(strict_types=1);

namespace OpenCCK\CommandPolicy;

/**
 * Classifies a shell command before it runs on a server. A read is proven: a command from the list of readers with
 * arguments its grammar knows and no shell syntax at all; anything else is a change and needs the owner. The "never"
 * list holds whatever the owner could not want from an agent; it reads operations, not one spelling.
 *
 * spec: BRIEF.md#фаза-7-по-частям — reads only by a proven list of commands and arguments, the rest asks, a list of never
 * ref: references/ssh-mcp/src/policy/classifier.ts@e04ebf2:L23 (shell metacharacters, even quoted, take a command out
 *      of the readers), L138-L544 (READERS), L1310-L1317 (elevation prefixes), L1333-L1343 (wrappers), L2243-L2272
 *      (FORBIDDEN_RULES), L1355-L1411 (disqualifying arguments) — the same idea, a smaller list; unlike ssh-mcp an
 *      unrecognised command is not "safe" but a change that asks
 */
final class CommandClassifier {
    public const MAX_LENGTH = 5000;
    /** raised whenever the lists or the rules change: grants given under an older policy stop working */
    public const VERSION = 1;

    /** single readers: name => null (any operands) or the most operands allowed, where a further one is a file written */
    private const READERS = [
        'cat' => null, 'head' => null, 'tail' => null, 'less' => null, 'grep' => null, 'egrep' => null, 'fgrep' => null,
        'zgrep' => null, 'zcat' => null, 'wc' => null, 'ls' => null, 'tree' => null, 'stat' => null, 'file' => null,
        'du' => null, 'df' => null, 'free' => null, 'uptime' => null, 'whoami' => null, 'id' => null, 'hostname' => 0,
        'uname' => null, 'date' => 0, 'ps' => null, 'pwd' => null, 'which' => null, 'whereis' => null, 'printenv' => null,
        'lsblk' => null, 'lscpu' => null, 'lsmem' => null, 'lsof' => null, 'ss' => null, 'netstat' => null,
        'journalctl' => null, 'dmesg' => null, 'last' => null, 'w' => null, 'who' => null, 'find' => null, 'sort' => null,
        'uniq' => 1, 'md5sum' => null, 'sha1sum' => null, 'sha256sum' => null, 'diff' => null, 'cmp' => null,
        'dig' => null, 'host' => null, 'nslookup' => null, 'ping' => null, 'getent' => null, 'nproc' => null,
        'vmstat' => null, 'iostat' => null, 'readlink' => null, 'realpath' => null, 'basename' => null, 'dirname' => null,
        'echo' => null, 'test' => null, 'true' => null, 'false' => null,
    ];

    /** readers by two words: "docker ps" and the like */
    private const SUBREADERS = [
        'docker' => ['ps', 'logs', 'inspect', 'images', 'stats', 'version', 'info', 'top', 'port', 'diff'],
        'git' => ['status', 'log', 'diff', 'show', 'branch', 'remote', 'rev-parse', 'describe', 'tag', 'blame', 'ls-files'],
        'systemctl' => ['status', 'is-active', 'is-enabled', 'is-failed', 'list-units', 'list-unit-files', 'list-timers', 'show', 'cat'],
        'ip' => ['addr', 'a', 'address', 'route', 'r', 'link', 'l', 'neigh', 'rule'],
        'apt' => ['list', 'show', 'policy', 'search'],
        'dpkg' => ['-l', '-L', '-s', '--list', '--status'],
        'nginx' => ['-t', '-T', '-v', '-V'],
    ];

    /** arguments that turn a reader into a writer or a launcher */
    private const DISQUALIFY = [
        'find' => ['-exec', '-execdir', '-ok', '-okdir', '-delete', '-fprint', '-fprint0', '-fprintf', '-fls'],
        'sort' => ['-o', '--output', '--compress-program'],
        'journalctl' => ['--vacuum-size', '--vacuum-time', '--vacuum-files', '--rotate', '--flush', '--sync', '--relinquish-var', '--setup-keys'],
        'docker' => ['--privileged'],
        'git' => ['-c', '--exec-path', '--output', '-o', '--ext-diff', '-d', '-D', '-m', '-M', '--delete', '--set-upstream-to', 'add', 'remove', 'rm', 'rename', 'set-url', 'prune'],
        'ip' => ['add', 'del', 'delete', 'change', 'replace', 'set', 'flush', 'append'],
        'ping' => ['-f'],
        'dmesg' => ['-c', '-C', '--clear', '--read-clear', '-n', '--console-level', '-D', '-E'],
    ];

    /** prefixes that raise privileges */
    private const ELEVATE = ['sudo', 'su', 'doas', 'pkexec', 'su-exec', 'gosu', 'sudo-rs', 'run0', 'pfexec', 'runuser', 'setpriv'];

    /** wrappers that run the command after them */
    private const WRAPPERS = ['env', 'nohup', 'nice', 'ionice', 'timeout', 'xargs', 'watch', 'time', 'stdbuf', 'command', 'exec', 'busybox', 'strace', 'flock', 'chroot', 'setsid'];

    private const SHUTDOWN = ['shutdown', 'reboot', 'halt', 'poweroff'];

    public function classify(string $command): Classification {
        $command = trim($command);
        if ($command === '') {
            return new Classification(Classification::CHANGE, 'пустая команда');
        }
        if (mb_strlen($command) > self::MAX_LENGTH || str_contains($command, "\0")) {
            return new Classification(Classification::CHANGE, 'команда слишком длинная или с нулевым байтом');
        }
        $never = $this->never($command);
        if ($never !== null) {
            return new Classification(Classification::NEVER, $never);
        }
        $segments = self::segments($command);
        foreach ($segments as $words) {
            if ($words === null) {
                continue;
            }
            $inner = self::shellProgram($words);
            if ($inner !== null) {
                $nested = $this->classify($inner);
                if ($nested->class === Classification::NEVER || $nested->class === Classification::PRIVILEGED) {
                    return $nested;
                }
            }
            $name = self::commandName($words);
            if ($name !== null && in_array($name, self::ELEVATE, true)) {
                return new Classification(Classification::PRIVILEGED, sprintf('повышение прав (%s)', $name));
            }
            if ($name === 'docker' && (in_array('--privileged', $words, true) || self::hasRootUser($words))) {
                return new Classification(Classification::PRIVILEGED, 'docker с правами root');
            }
        }
        if (preg_match('~[;&|<>`$(){}\n\r\\\\]~', $command) === 1) {
            return new Classification(Classification::CHANGE, 'синтаксис оболочки: прочитать команду нельзя');
        }
        if (preg_match('~[*?\[]~', $command) === 1) {
            return new Classification(Classification::CHANGE, 'шаблон имён: оболочка развернёт его в неизвестное');
        }
        if (count($segments) !== 1 || $segments[0] === null) {
            return new Classification(Classification::CHANGE, 'команду не удалось разобрать');
        }
        return $this->reader($segments[0]);
    }

    /** @param list<string> $words */
    private function reader(array $words): Classification {
        $name = $words[0] ?? '';
        $args = array_slice($words, 1);
        if (isset(self::SUBREADERS[$name])) {
            $sub = null;
            foreach ($args as $arg) {
                if (!str_starts_with($arg, '-') || in_array($arg, self::SUBREADERS[$name], true)) {
                    $sub = $arg;
                    break;
                }
            }
            if ($sub === null || !in_array($sub, self::SUBREADERS[$name], true)) {
                return new Classification(Classification::CHANGE, sprintf('%s %s не в списке читающих', $name, $sub ?? ''));
            }
        } elseif (!array_key_exists($name, self::READERS)) {
            return new Classification(Classification::CHANGE, sprintf('%s не в списке читающих команд', $name));
        } else {
            $max = self::READERS[$name];
            $operands = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '-')));
            if ($max !== null && count($operands) > $max) {
                return new Classification(Classification::CHANGE, sprintf('у %s слишком много операндов: последний может быть файлом для записи', $name));
            }
        }
        foreach ($args as $arg) {
            foreach (self::DISQUALIFY[$name] ?? [] as $bad) {
                if ($arg === $bad || str_starts_with($arg, $bad . '=') || (strlen($bad) === 2 && str_starts_with($arg, $bad) && $bad[0] === '-' && $bad[1] !== '-')) {
                    return new Classification(Classification::CHANGE, sprintf('аргумент %s у %s пишет или запускает', $bad, $name));
                }
            }
        }
        return new Classification(Classification::READ, sprintf('%s только читает', $name));
    }

    /** What is never allowed, read as operations; null when nothing of it is there. */
    private function never(string $command): ?string {
        $flat = preg_replace('~\s+~', ' ', $command) ?? $command;
        if (preg_match('~:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}~', $flat) === 1) {
            return 'fork-бомба';
        }
        if (preg_match('~\b(curl|wget|fetch)\b[^|]*\|\s*(sudo\s+)?(ba|z|da|k|a)?sh\b~', $flat) === 1) {
            return 'загрузка в оболочку из сети';
        }
        if (preg_match('~>\s*/dev/(sd|nvme|vd|hd|xvd|mmcblk)~', $flat) === 1) {
            return 'запись поверх диска';
        }
        if (preg_match('~(>>?\s*|\btee\b[^;|&]*\s|\b(cp|mv|install|ln)\b[^;|&]*\s)\S*(authorized_keys|/etc/cron|/etc/sudoers|/etc/systemd/)~', $flat) === 1) {
            return 'запись в authorized_keys, cron, sudoers или systemd';
        }
        foreach (self::segments($command) as $words) {
            if ($words === null) {
                continue;
            }
            $inner = self::shellProgram($words);
            if ($inner !== null && ($found = $this->never($inner)) !== null) {
                return $found;
            }
            $name = self::commandName($words, true);
            $index = $name === null ? -1 : (int) array_search($name, array_map(basename(...), $words), true);
            $args = $index < 0 ? [] : array_slice($words, $index + 1);
            if ($name === null) {
                continue;
            }
            if (in_array($name, self::SHUTDOWN, true) || $name === 'eval') {
                return sprintf('%s запрещён', $name);
            }
            if ($name === 'init' && array_intersect($args, ['0', '6']) !== []) {
                return 'выключение через init';
            }
            if ($name === 'systemctl' && array_intersect($args, ['reboot', 'poweroff', 'halt', 'kexec', 'emergency', 'rescue']) !== []) {
                return 'выключение через systemctl';
            }
            if (preg_match('~^(mkfs(\..+)?|mke2fs|mkswap|wipefs|mkdosfs|mkntfs)$~', $name) === 1) {
                return 'форматирование устройства';
            }
            if ($name === 'dd' && preg_grep('~^of=/dev/~', $args) !== []) {
                return 'запись dd на устройство';
            }
            if ($name === 'rm' && self::recursive($args) && array_filter($args, self::isRootPath(...)) !== []) {
                return 'рекурсивное удаление корня';
            }
            if (($name === 'chmod' || $name === 'chown') && self::recursive($args) && array_filter($args, self::isRootPath(...)) !== []) {
                return sprintf('рекурсивный %s корня', $name);
            }
            if ($name === 'iptables' || $name === 'ip6tables') {
                if (array_intersect($args, ['-F', '--flush', '-X']) !== [] || (in_array('-P', $args, true) && in_array('ACCEPT', $args, true))) {
                    return 'сброс межсетевого экрана';
                }
            }
            if ($name === 'nft' && in_array('flush', $args, true)) {
                return 'сброс межсетевого экрана';
            }
        }
        return null;
    }

    /**
     * The program text given to a shell with -c: "sh -c 'rm -rf /'" runs what is inside the quotes.
     *
     * @param list<string> $words
     */
    private static function shellProgram(array $words): ?string {
        foreach ($words as $i => $word) {
            if (in_array(basename($word), ['sh', 'bash', 'dash', 'zsh', 'ksh', 'ash', 'fish'], true)) {
                foreach (array_slice($words, $i + 1) as $j => $arg) {
                    if ($arg === '-c' || (str_starts_with($arg, '-') && !str_starts_with($arg, '--') && str_contains($arg, 'c'))) {
                        return $words[$i + 2 + $j] ?? null;
                    }
                }
            }
        }
        return null;
    }

    /** @param list<string> $args */
    private static function recursive(array $args): bool {
        foreach ($args as $arg) {
            if ($arg === '--recursive' || (preg_match('~^-[a-zA-Z]*[rR]~', $arg) === 1)) {
                return true;
            }
        }
        return false;
    }

    private static function isRootPath(string $arg): bool {
        if (str_starts_with($arg, '-')) {
            return false;
        }
        $normal = preg_replace('~/+~', '/', $arg) ?? $arg;
        $parts = [];
        foreach (explode('/', $normal) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        return str_starts_with($normal, '/') && ($parts === [] || $parts === ['*']);
    }

    /** @param list<string> $words */
    private static function hasRootUser(array $words): bool {
        foreach ($words as $i => $word) {
            if (($word === '-u' || $word === '--user') && in_array($words[$i + 1] ?? '', ['0', 'root'], true)) {
                return true;
            }
            if (in_array($word, ['--user=0', '--user=root', '-u0', '-uroot'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The name of the command a segment runs: after variable assignments and wrappers (and, for the "never" list, after
     * sudo and its kin: "sudo reboot" reboots).
     *
     * @param list<string> $words
     */
    private static function commandName(array $words, bool $throughElevation = false): ?string {
        $wrapped = false;
        foreach ($words as $word) {
            if (preg_match('~^[A-Za-z_][A-Za-z0-9_]*=~', $word) === 1) {
                continue;
            }
            if ($wrapped && (str_starts_with($word, '-') || preg_match('~^\d+[smhd]?$~', $word) === 1)) {
                continue;
            }
            $base = basename($word);
            if (in_array($base, self::WRAPPERS, true) || ($throughElevation && in_array($base, self::ELEVATE, true))) {
                $wrapped = true;
                continue;
            }
            return $base;
        }
        return null;
    }

    /**
     * Segments split on ; & | and new lines outside quotes, words with quotes taken off; null for a segment whose
     * quotes do not close.
     *
     * @return list<list<string>|null>
     */
    private static function segments(string $command): array {
        $segments = [];
        $words = [];
        $word = '';
        $hasWord = false;
        $quote = null;
        $length = strlen($command);
        for ($i = 0; $i < $length; $i++) {
            $c = $command[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                } elseif ($c === '\\' && $quote === '"' && $i + 1 < $length) {
                    $word .= $command[++$i];
                } else {
                    $word .= $c;
                }
                continue;
            }
            if ($c === '\'' || $c === '"') {
                $quote = $c;
                $hasWord = true;
                continue;
            }
            if ($c === '\\' && $i + 1 < $length) {
                $word .= $command[++$i];
                $hasWord = true;
                continue;
            }
            if (in_array($c, [';', '&', '|', "\n", "\r", '(', ')', '`'], true)) {
                if ($hasWord) {
                    $words[] = $word;
                }
                if ($words !== []) {
                    $segments[] = $words;
                }
                $words = [];
                $word = '';
                $hasWord = false;
                continue;
            }
            if ($c === ' ' || $c === "\t") {
                if ($hasWord) {
                    $words[] = $word;
                }
                $word = '';
                $hasWord = false;
                continue;
            }
            $word .= $c;
            $hasWord = true;
        }
        if ($quote !== null) {
            $segments[] = null;
            return $segments;
        }
        if ($hasWord) {
            $words[] = $word;
        }
        if ($words !== []) {
            $segments[] = $words;
        }
        return $segments;
    }
}
