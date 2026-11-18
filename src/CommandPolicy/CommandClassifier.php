<?php

declare(strict_types=1);

namespace OpenCCK\CommandPolicy;

use function Mercurius\I18n\t;

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
    public const VERSION = 2;

    /**
     * Readers and their grammars: a command name, or two words for a reader of a tool with subcommands. The first
     * argument after a two-word reader's name must be that subcommand, the grammar reads what follows it. Grammars
     * come from ssh-mcp where it has one; the rest are ours and say why in a word.
     *
     * ref: references/ssh-mcp/src/policy/classifier.ts@e04ebf2:L138-L545 (READERS with grammars) — taken for date,
     *      diff, file, find, git branch/diff/log/remote/show, hostname, journalctl, netstat, sort, ss, systemctl
     *      status, uniq; the 'any' readers with their audit notes; ip objects by the first operand
     */
    private const GRAMMARS = [
        // arguments are data: nothing these tools take writes a file or starts a program
        'cat' => self::ANY, 'head' => self::ANY, 'tail' => self::ANY, 'grep' => self::ANY, 'egrep' => self::ANY,
        'fgrep' => self::ANY, 'zgrep' => self::ANY, 'zcat' => self::ANY, 'wc' => self::ANY, 'ls' => self::ANY,
        'stat' => self::ANY, 'du' => self::ANY, 'df' => self::ANY, 'free' => self::ANY, 'uptime' => self::ANY,
        'whoami' => self::ANY, 'id' => self::ANY, 'uname' => self::ANY, 'ps' => self::ANY, 'pwd' => self::ANY,
        'which' => self::ANY, 'whereis' => self::ANY, 'printenv' => self::ANY, 'lsblk' => self::ANY, 'lscpu' => self::ANY,
        'lsmem' => self::ANY, 'lsof' => self::ANY, 'last' => self::ANY, 'w' => self::ANY, 'who' => self::ANY,
        'md5sum' => self::ANY, 'sha1sum' => self::ANY, 'sha256sum' => self::ANY, 'cmp' => self::ANY, 'dig' => self::ANY,
        'host' => self::ANY, 'nslookup' => self::ANY, 'getent' => self::ANY, 'nproc' => self::ANY, 'vmstat' => self::ANY,
        'iostat' => self::ANY, 'readlink' => self::ANY, 'realpath' => self::ANY, 'basename' => self::ANY,
        'dirname' => self::ANY, 'echo' => self::ANY, 'test' => self::ANY, 'true' => self::ANY, 'false' => self::ANY,
        'date' => ['args' => 'getopt',
            'flags' => ['-u', '--utc', '--universal', '-R', '--rfc-2822', '--rfc-email'],
            'value' => ['-r', '--reference', '--rfc-3339'],
            'optional' => ['-I', '--iso-8601'],
            'operands' => ['max' => 1, 'each' => '~^\+~']],
        'diff' => ['args' => 'getopt',
            'flags' => ['-q', '--brief', '-s', '--report-identical-files', '-c', '-u', '-e', '--ed', '-n', '--rcs',
                '-y', '--side-by-side', '--left-column', '--suppress-common-lines', '-p', '--show-c-function',
                '-t', '--expand-tabs', '-T', '--initial-tab', '--suppress-blank-empty', '-r', '--recursive',
                '--no-dereference', '-N', '--new-file', '--unidirectional-new-file', '--ignore-file-name-case',
                '--no-ignore-file-name-case', '-i', '--ignore-case', '-E', '--ignore-tab-expansion', '-b',
                '--ignore-space-change', '-w', '--ignore-all-space', '-B', '--ignore-blank-lines', '-a', '--text',
                '--strip-trailing-cr', '-d', '--minimal'],
            'value' => ['-C', '-U', '-W', '--width', '--tabsize', '-F', '--show-function-line', '-I',
                '--ignore-matching-lines', '-D', '--ifdef', '--label', '-x', '--exclude', '-X', '--exclude-from',
                '-S', '--starting-file', '--from-file', '--to-file', '--line-format'],
            'optional' => ['--context', '--unified', '--color'],
            'operands' => ['max' => 2]],
        'file' => ['args' => 'getopt',
            'flags' => ['-b', '--brief', '-c', '--checking-printout', '-i', '--mime', '--mime-type', '--mime-encoding',
                '-k', '--keep-going', '-L', '--dereference', '-h', '--no-dereference', '-n', '--no-buffer',
                '-r', '--raw', '-s', '--special-files', '-0', '--print0'],
            'value' => ['-f', '--files-from', '-m', '--magic-file', '-M', '-e', '--exclude', '-F', '--separator']],
        'find' => ['args' => 'exact',
            'flags' => ['-H', '-L', '-P', '-print', '-print0', '-ls', '-not', '-a', '-and', '-o', '-or', '-true', '-false',
                '-empty', '-xdev', '-mount', '-depth', '-daystart', '-executable', '-readable', '-writable'],
            'value' => ['-name', '-iname', '-path', '-ipath', '-wholename', '-iwholename', '-regex', '-iregex',
                '-type', '-perm', '-user', '-group', '-uid', '-gid', '-size', '-mtime', '-mmin', '-atime', '-amin',
                '-ctime', '-cmin', '-newer', '-anewer', '-cnewer', '-links', '-inum', '-maxdepth', '-mindepth',
                '-printf']],
        'hostname' => ['args' => 'getopt',
            'flags' => ['-a', '--alias', '-A', '--all-fqdns', '-d', '--domain', '-f', '--fqdn', '--long', '-i',
                '--ip-address', '-I', '--all-ip-addresses', '-s', '--short'],
            'operands' => ['max' => 0]],
        'journalctl' => ['args' => 'getopt',
            'flags' => ['-f', '--follow', '-e', '--pager-end', '-r', '--reverse', '-k', '--dmesg', '-q', '--quiet',
                '-x', '--catalog', '-a', '--all', '-l', '--full', '-m', '--merge', '--no-pager', '--no-hostname',
                '--no-tail', '--disk-usage', '--header', '--list-boots', '--system', '--user'],
            'value' => ['-u', '--unit', '--since', '--until', '-S', '-U', '-o', '--output', '-p', '--priority',
                '-g', '--grep', '-t', '--identifier', '-D', '--directory'],
            'optional' => ['-n', '--lines', '-b', '--boot', '--facility']],
        'netstat' => ['args' => 'getopt',
            'flags' => ['-a', '-n', '-t', '-u', '-l', '-p', '-r', '-i', '-s', '-e', '-W', '-x', '-w', '-4', '-6',
                '-v', '-b', '-d', '-m'],
            'optional' => ['-f', '-I']],
        'sort' => ['args' => 'getopt',
            'flags' => ['-b', '-c', '-d', '-f', '-g', '-h', '-i', '-M', '-m', '-n', '-R', '-r', '-s', '-u', '-V', '-z',
                '--ignore-leading-blanks', '--dictionary-order', '--ignore-case', '--general-numeric-sort',
                '--human-numeric-sort', '--ignore-nonprinting', '--month-sort', '--merge', '--numeric-sort',
                '--random-sort', '--reverse', '--stable', '--unique', '--version-sort', '--debug',
                '--zero-terminated'],
            'value' => ['-k', '--key', '-t', '--field-separator', '-S', '--buffer-size', '--sort', '--parallel'],
            'optional' => ['--check']],
        'ss' => ['args' => 'getopt',
            'flags' => ['-H', '--no-header', '-O', '--oneline', '-n', '--numeric', '-r', '--resolve', '-a', '--all',
                '-l', '--listening', '-o', '--options', '-e', '--extended', '-m', '--memory', '-p', '--processes',
                '-i', '--info', '-s', '--summary', '-t', '--tcp', '-u', '--udp', '-d', '--dccp', '-w', '--raw',
                '-x', '--unix', '-4', '-6', '-0', '--packet'],
            'value' => ['-f', '--family', '-A', '--query', '--socket', '-F', '--filter']],
        'uniq' => ['args' => 'getopt',
            'flags' => ['-c', '--count', '-d', '--repeated', '-u', '--unique', '-i', '--ignore-case', '-z',
                '--zero-terminated', '-D'],
            'value' => ['-f', '--skip-fields', '-s', '--skip-chars', '-w', '--check-chars'],
            'optional' => ['--all-repeated'],
            'optionsBeforeOperands' => true,
            'operands' => ['max' => 1]],
        // ours: no option that writes (-o, -R with -H) or reads a file of commands
        'tree' => ['args' => 'getopt',
            'flags' => ['-a', '-d', '-l', '-f', '-x', '-i', '-q', '-N', '-Q', '-p', '-u', '-g', '-s', '-h', '--si', '-D',
                '-F', '-v', '-t', '-c', '-U', '-r', '--dirsfirst', '--du', '--noreport', '--inodes', '--device', '-J',
                '-X', '-C', '-n', '-A', '-S'],
            'value' => ['-L', '-P', '-I', '--filelimit', '--charset', '--timefmt', '--sort']],
        // ours: shows the ring buffer; -c/-C/-D/-E/-n clear it or change the console, so only display options pass
        'dmesg' => ['args' => 'getopt',
            'flags' => ['-T', '--ctime', '-H', '--human', '-k', '--kernel', '-u', '--userspace', '-x', '--decode', '-L',
                '-t', '--notime', '-w', '--follow', '-W', '--follow-new', '-r', '--raw', '-P', '--nopager', '-S',
                '--syslog', '-e', '--reltime', '-d', '--show-delta'],
            'value' => ['-l', '--level', '-f', '--facility', '-s', '--buffer-size', '-F', '--file', '--since',
                '--until', '--time-format'],
            'optional' => ['--color'],
            'operands' => ['max' => 0]],
        // ours: no flood (-f), no preload (-l), one host
        'ping' => ['args' => 'getopt',
            'flags' => ['-n', '-q', '-v', '-4', '-6', '-D', '-O', '-a', '-A', '-R', '-U', '-b'],
            'value' => ['-c', '-i', '-W', '-w', '-s', '-t', '-I', '-Q', '-M'],
            'operands' => ['max' => 1]],
        // ours: test or print the configuration; -c, -g, -p and -s would read another config or signal the master
        'nginx' => ['args' => 'getopt', 'flags' => ['-t', '-T', '-v', '-V', '-q'], 'operands' => ['max' => 0]],
        'git branch' => ['args' => 'getopt',
            'flags' => ['-a', '--all', '-r', '--remotes', '-v', '--verbose', '-q', '--quiet', '--list', '--show-current'],
            'value' => ['--sort'],
            'optional' => ['--color', '--contains', '--merged', '--no-merged'],
            'operands' => ['max' => 0]],
        'git diff' => ['args' => 'getopt',
            'flags' => ['-p', '-u', '--patch', '--name-only', '--name-status', '--cached', '--staged', '--raw', '-w',
                '--ignore-all-space', '-b', '--ignore-space-change', '--summary', '--shortstat', '--exit-code',
                '--quiet', '-R', '--no-color', '--first-parent'],
            'value' => ['--diff-filter', '-S', '-G', '-O'],
            'optional' => ['-M', '--find-renames', '-C', '--find-copies', '-U', '--unified', '--color',
                '--word-diff', '--stat']],
        'git log' => ['args' => 'getopt',
            'flags' => ['--oneline', '--graph', '--all', '-p', '-u', '--patch', '--name-only', '--name-status',
                '--abbrev-commit', '--relative-date', '--reverse', '--topo-order', '--date-order', '--parents',
                '--merges', '--no-merges'],
            'value' => ['-n', '--max-count', '--since', '--until', '--author', '--grep', '--date', '-S', '-G',
                '--skip'],
            'optional' => ['--pretty', '--format', '--decorate', '--branches', '--tags', '--remotes',
                '--stat', '-L'],
            'numericShort' => true],
        'git remote' => ['args' => 'getopt',
            'flags' => ['-v', '--verbose', '-n', '--push'],
            'operands' => ['max' => 2, 'first' => ['get-url']]],
        'git show' => ['args' => 'getopt',
            'flags' => ['-s', '--no-patch', '--name-only', '--name-status', '--oneline', '--abbrev-commit', '--raw'],
            'value' => ['--date'],
            'optional' => ['--pretty', '--format', '--decorate', '--stat'],
            'numericShort' => true],
        'git status' => self::ANY, 'git rev-parse' => self::ANY, 'git describe' => self::ANY, 'git blame' => self::ANY,
        'git ls-files' => self::ANY,
        // ours: listing only — a name would create a tag
        'git tag' => ['args' => 'getopt', 'flags' => ['-l', '--list', '-n'], 'value' => ['--sort', '--contains', '--points-at'], 'operands' => ['max' => 0]],
        'docker ps' => self::ANY, 'docker logs' => self::ANY, 'docker inspect' => self::ANY, 'docker images' => self::ANY,
        'docker stats' => self::ANY, 'docker version' => self::ANY, 'docker info' => self::ANY, 'docker top' => self::ANY,
        'docker port' => self::ANY, 'docker diff' => self::ANY,
        'systemctl status' => ['args' => 'getopt',
            'flags' => ['-l', '--full', '--no-pager', '-a', '--all', '-q', '--quiet', '--user', '--system', '-r',
                '--recursive', '--no-legend'],
            'optional' => ['-n', '--lines', '-o', '--output']],
        'systemctl is-active' => self::ANY, 'systemctl is-enabled' => self::ANY, 'systemctl is-failed' => self::ANY,
        'systemctl list-units' => self::ANY, 'systemctl list-unit-files' => self::ANY, 'systemctl list-timers' => self::ANY,
        'systemctl show' => self::ANY, 'systemctl cat' => self::ANY,
        // ours: an ip object reads only with show, list or get (or nothing, which is show); abbreviated verbs change
        'ip addr' => self::IP_SHOW, 'ip a' => self::IP_SHOW, 'ip address' => self::IP_SHOW,
        'ip link' => self::IP_SHOW, 'ip l' => self::IP_SHOW, 'ip neigh' => self::IP_SHOW, 'ip rule' => self::IP_SHOW,
        'ip route' => self::IP_ROUTE, 'ip r' => self::IP_ROUTE,
        'apt list' => self::ANY, 'apt show' => self::ANY, 'apt policy' => self::ANY, 'apt search' => self::ANY,
        // ours: the query mode is the subcommand; another option after it (-i, -r) would install or remove
        'dpkg -l' => self::OPERANDS_ONLY, 'dpkg -L' => self::OPERANDS_ONLY, 'dpkg -s' => self::OPERANDS_ONLY,
        'dpkg --list' => self::OPERANDS_ONLY, 'dpkg --status' => self::OPERANDS_ONLY,
    ];
    private const ANY = ['args' => 'any'];
    private const OPERANDS_ONLY = ['args' => 'getopt'];
    private const IP_SHOW = ['args' => 'exact', 'operands' => ['first' => ['show', 'list']]];
    private const IP_ROUTE = ['args' => 'exact', 'operands' => ['first' => ['show', 'list', 'get']]];
    /** tools whose readers are two words: the first alone reads nothing */
    private const TOOLS = ['git', 'docker', 'systemctl', 'ip', 'apt', 'dpkg'];

    /** prefixes that raise privileges */
    private const ELEVATE = ['sudo', 'su', 'doas', 'pkexec', 'su-exec', 'gosu', 'sudo-rs', 'run0', 'pfexec', 'runuser', 'setpriv'];

    /** wrappers that run the command after them */
    private const WRAPPERS = ['env', 'nohup', 'nice', 'ionice', 'timeout', 'xargs', 'watch', 'time', 'stdbuf', 'command', 'exec', 'busybox', 'strace', 'flock', 'chroot', 'setsid'];

    private const SHUTDOWN = ['shutdown', 'reboot', 'halt', 'poweroff'];

    public function classify(string $command): Classification {
        $command = trim($command);
        if ($command === '') {
            return new Classification(Classification::CHANGE, t('commandpolicy.command_classifier.pustaya_komanda'));
        }
        if (mb_strlen($command) > self::MAX_LENGTH || str_contains($command, "\0")) {
            return new Classification(Classification::CHANGE, t('commandpolicy.command_classifier.komanda_slishkom_dlinnaya_ili_s'));
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
                return new Classification(Classification::PRIVILEGED, sprintf(t('commandpolicy.command_classifier.povyshenie_prav'), $name));
            }
            if ($name === 'docker' && (in_array('--privileged', $words, true) || self::hasRootUser($words))) {
                return new Classification(Classification::PRIVILEGED, t('commandpolicy.command_classifier.docker_s_pravami_root'));
            }
        }
        if (preg_match('~[;&|<>`$(){}\n\r\\\\]~', $command) === 1) {
            return new Classification(Classification::CHANGE, t('commandpolicy.command_classifier.sintaksis_obolochki_prochitat_komandu_ne'));
        }
        if (preg_match('~[*?\[]~', $command) === 1) {
            return new Classification(Classification::CHANGE, t('commandpolicy.command_classifier.shablon_imen_obolochka_razvernet_ego'));
        }
        if (count($segments) !== 1 || $segments[0] === null) {
            return new Classification(Classification::CHANGE, t('commandpolicy.command_classifier.komandu_ne_udalos_razobrat'));
        }
        return $this->reader($segments[0]);
    }

    /** @param list<string> $words */
    private function reader(array $words): Classification {
        $name = $words[0] ?? '';
        $args = array_slice($words, 1);
        $pair = $name . ' ' . ($args[0] ?? '');
        if (isset($args[0]) && isset(self::GRAMMARS[$pair])) {
            $grammar = self::GRAMMARS[$pair];
            $rest = array_slice($args, 1);
            $label = $pair;
        } elseif (isset(self::GRAMMARS[$name])) {
            $grammar = self::GRAMMARS[$name];
            $rest = $args;
            $label = $name;
        } elseif (in_array($name, self::TOOLS, true)) {
            return new Classification(Classification::CHANGE, sprintf(t('commandpolicy.command_classifier.ne_v_spiske_chitayuschih'), $name, $args[0] ?? ''));
        } else {
            return new Classification(Classification::CHANGE, sprintf(t('commandpolicy.command_classifier.ne_v_spiske_chitayuschih_komand'), $name));
        }
        $refused = ReaderGrammar::refused($rest, $grammar);
        if ($refused !== null) {
            return new Classification(Classification::CHANGE, sprintf(t('commandpolicy.command_classifier.not_in_grammar'), $refused, $label));
        }
        return new Classification(Classification::READ, sprintf(t('commandpolicy.command_classifier.tolko_chitaet'), $label));
    }

    /** What is never allowed, read as operations; null when nothing of it is there. */
    private function never(string $command): ?string {
        $flat = preg_replace('~\s+~', ' ', $command) ?? $command;
        if (preg_match('~:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}~', $flat) === 1) {
            return t('commandpolicy.command_classifier.fork_bomba');
        }
        if (preg_match('~\b(curl|wget|fetch)\b[^|]*\|\s*(sudo\s+)?(ba|z|da|k|a)?sh\b~', $flat) === 1) {
            return t('commandpolicy.command_classifier.zagruzka_v_obolochku_iz_seti');
        }
        if (preg_match('~>\s*/dev/(sd|nvme|vd|hd|xvd|mmcblk)~', $flat) === 1) {
            return t('commandpolicy.command_classifier.zapis_poverh_diska');
        }
        if (preg_match('~(>>?\s*|\btee\b[^;|&]*\s|\b(cp|mv|install|ln)\b[^;|&]*\s)\S*(authorized_keys|/etc/cron|/etc/sudoers|/etc/systemd/)~', $flat) === 1) {
            return t('commandpolicy.command_classifier.zapis_v_authorized_keys_cron');
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
                return sprintf(t('commandpolicy.command_classifier.zapreschen'), $name);
            }
            if ($name === 'init' && array_intersect($args, ['0', '6']) !== []) {
                return t('commandpolicy.command_classifier.vyklyuchenie_cherez_init');
            }
            if ($name === 'systemctl' && array_intersect($args, ['reboot', 'poweroff', 'halt', 'kexec', 'emergency', 'rescue']) !== []) {
                return t('commandpolicy.command_classifier.vyklyuchenie_cherez_systemctl');
            }
            if (preg_match('~^(mkfs(\..+)?|mke2fs|mkswap|wipefs|mkdosfs|mkntfs)$~', $name) === 1) {
                return t('commandpolicy.command_classifier.formatirovanie_ustroystva');
            }
            if ($name === 'dd' && preg_grep('~^of=/dev/~', $args) !== []) {
                return t('commandpolicy.command_classifier.zapis_dd_na_ustroystvo');
            }
            if ($name === 'rm' && self::recursive($args) && array_filter($args, self::isRootPath(...)) !== []) {
                return t('commandpolicy.command_classifier.rekursivnoe_udalenie_kornya');
            }
            if (($name === 'chmod' || $name === 'chown') && self::recursive($args) && array_filter($args, self::isRootPath(...)) !== []) {
                return sprintf(t('commandpolicy.command_classifier.rekursivnyy_kornya'), $name);
            }
            if ($name === 'iptables' || $name === 'ip6tables') {
                if (array_intersect($args, ['-F', '--flush', '-X']) !== [] || (in_array('-P', $args, true) && in_array('ACCEPT', $args, true))) {
                    return t('commandpolicy.command_classifier.sbros_mezhsetevogo_ekrana');
                }
            }
            if ($name === 'nft' && in_array('flush', $args, true)) {
                return t('commandpolicy.command_classifier.sbros_mezhsetevogo_ekrana');
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
