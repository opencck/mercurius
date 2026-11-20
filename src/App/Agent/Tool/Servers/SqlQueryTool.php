<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Servers;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * SQL on a database the owner's server reaches, through that server's psql or mysql — the same way as any command:
 * the journal, the route, the host key. A read runs inside a read-only transaction, so the database itself refuses a
 * write; one statement only, so the transaction cannot be closed from inside. A write waits for the owner. The password
 * goes on stdin, never on the command line.
 *
 * spec: brief/servers.md#как-сделано-запросы-к-базам-данных — SQL tools for PostgreSQL and MariaDB/MySQL go the same way
 *
 * The text goes to psql or mysql, and both clients read their own commands from the same input: `\!` and `system`
 * start a shell on the server, `\c` opens a new session outside the transaction, `\copy … to program` runs a
 * program. So a backslash outside a string and a mysql client command at the start of a line are refused in any
 * mode, and a read is kept read-only by the server itself as well (`default_transaction_read_only`, a read-only
 * session), not only by the wrapping transaction.
 * A secret goes only where the owner allowed it: with a secret the call waits for a card, and the grant names the
 * server, the engine, the database host, port and user and the secret — another host is another card. A grant for a
 * write names the exact statement. The password is masked in what the client prints.
 * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a call with a secret is always a card; a grant for a write names
 *       the statement and the connection
 */
final class SqlQueryTool implements Tool, GrantKeyed {
    public const NAME = 'sql_query';
    private const QUERY_MAX = 20_000;
    /** what a single read statement may not start with: it would leave or change the transaction */
    private const NOT_A_READ = '~^\s*(begin|start|commit|end|rollback|abort|set|reset|discard|savepoint|release|prepare|deallocate|do|call|copy|lock|load|handler|flush|kill|shutdown|grant|revoke)\b~i';
    /** functions that act outside the database even in a read-only transaction */
    private const SIDE_EFFECTS = '~[\s(,."`]?\b(pg_terminate_backend|pg_cancel_backend|pg_reload_conf|pg_rotate_logfile|lo_export|lo_import|pg_read_file|pg_read_binary_file|pg_stat_file|pg_ls_dir|pg_sleep\w*|dblink\w*|sleep|benchmark|sys_exec|sys_eval|load_file)["`]?\s*\(~i';
    /** a read that writes a file on the database server */
    private const INTO_FILE = '~\binto\s+(outfile|dumpfile)\b~i';
    /** commands of the mysql client, recognised at the start of a line */
    private const MYSQL_CLIENT = '~^\s*(system|source|tee|notee|pager|nopager|connect|use|delimiter|edit|charset|warnings|nowarning|prompt|rehash|status|exit|quit|go|ego|help|print|query_attributes|resetconnection|ssl_session_data_print)\b~im';
    private const PORTS = ['postgres' => 5432, 'mysql' => 3306];
    /** secrets of the agent's own settings (`/set`): never a database password */
    private const SETTING_PREFIX = 'setting.';

    public function __construct(
        private readonly ServerConsole $console,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.sql_query.sql_zapros_k_baze_cherez')
            . t('tool.sql_query.host_i_port_adres_bazy')
            . t('tool.sql_query.zapros_odno_vyrazhenie_v_tranzaktsii'), [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'engine' => ['type' => 'string', 'enum' => ['postgres', 'mysql']],
                'database' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'host' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::QUERY_MAX],
                'write' => ['type' => 'boolean'],
            ],
            'required' => ['server', 'engine', 'database', 'user', 'query'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return ($args['write'] ?? false) === true || self::secretOf($args) !== null ? DangerClass::Irreversible : DangerClass::Read;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    public function grantKey(array $args): ?string {
        $write = ($args['write'] ?? false) === true;
        $secret = self::secretOf($args);
        if (!$write && $secret === null) {
            return null;
        }
        $server = mb_strtolower(trim(Args::string($args, 'server') ?? ''));
        $engine = Args::string($args, 'engine') ?? '';
        $host = mb_strtolower(Args::string($args, 'host') ?? '127.0.0.1');
        $port = Args::int($args, 'port') ?? (self::PORTS[$engine] ?? 0);
        $user = Args::string($args, 'user') ?? '';
        if ($server === '') {
            return null;
        }
        $connection = sprintf('%s:%s:%s:%d:%s', $server, $engine, $host, $port, $user);
        if (!$write) {
            return sprintf('sql-read:%s:%s', $connection, $secret);
        }
        $statement = substr(hash('sha256', trim(rtrim(trim(Args::string($args, 'query') ?? ''), ';'))), 0, 16);
        return sprintf('sql-write:%s:%s:%s', $connection, Args::string($args, 'database') ?? '', $statement) . ($secret === null ? '' : ':' . $secret);
    }

    public function describe(array $args): string {
        return sprintf('sql_query %s/%s: %s', Args::string($args, 'server') ?? Args::string($args, 'host') ?? '', Args::string($args, 'database') ?? '', mb_substr(Args::string($args, 'query') ?? '', 0, 300));
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 180;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $engine = Args::string($args, 'engine') ?? '';
        $database = Args::string($args, 'database') ?? '';
        $user = Args::string($args, 'user') ?? '';
        $host = Args::string($args, 'host') ?? '127.0.0.1';
        $query = trim(rtrim(trim(Args::string($args, 'query') ?? ''), ';'));
        $write = ($args['write'] ?? false) === true;
        foreach (['database' => $database, 'user' => $user, 'host' => $host] as $field => $value) {
            if (preg_match('~^[A-Za-z0-9._:\-\[\]]+$~', $value) !== 1) {
                return ToolResult::error(sprintf(t('tool.sql_query.latinitsa_tsifry_tochka_defis_podcherkiv'), $field));
            }
        }
        $refused = self::clientCommand($query, $engine);
        if ($refused !== null) {
            return ToolResult::error(sprintf(t('tool.sql_query.client_command'), $refused));
        }
        if (!$write) {
            if (preg_match(self::INTO_FILE, $query) === 1) {
                return ToolResult::error(t('tool.sql_query.eto_ne_chtenie_takoy_zapros'));
            }
            if (str_contains($query, ';')) {
                return ToolResult::error(t('tool.sql_query.dlya_chteniya_odno_vyrazhenie_bez'));
            }
            if (preg_match(self::NOT_A_READ, $query) === 1 || preg_match(self::SIDE_EFFECTS, $query) === 1) {
                return ToolResult::error(t('tool.sql_query.eto_ne_chtenie_takoy_zapros'));
            }
        }
        $password = null;
        $secret = self::secretOf($args);
        if ($secret !== null) {
            if (str_starts_with(strtolower($secret), self::SETTING_PREFIX)) {
                return ToolResult::error(sprintf(t('tool.sql_query.sekret_nedostupen'), $secret));
            }
            $password = $this->vault->reveal($secret, $context->principal);
            if ($password === null) {
                return ToolResult::error(sprintf(t('tool.sql_query.sekret_nedostupen'), $secret));
            }
        }
        $port = Args::int($args, 'port');
        $sql = $engine === 'mysql'
            ? ($write ? $query . ";\n" : "START TRANSACTION READ ONLY;\n" . $query . ";\nROLLBACK;\n")
            : ($write ? $query . ";\n" : "BEGIN READ ONLY;\n" . $query . ";\nROLLBACK;\n");
        $client = $engine === 'mysql'
            ? sprintf('mysql -h %s%s -u %s -D %s -B --default-character-set=utf8mb4%s', $host, $port === null ? '' : ' -P ' . $port, $user, $database, $write ? '' : " --init-command='SET SESSION TRANSACTION READ ONLY'")
            : sprintf('psql -X -A -F "	" -v ON_ERROR_STOP=1 -h %s%s -U %s -d %s -f -', $host, $port === null ? '' : ' -p ' . $port, $user, $database);
        // the server keeps a read read-only for every transaction of the session, not only the wrapping one
        $readOnly = $engine === 'postgres' && !$write ? "export PGOPTIONS='-c default_transaction_read_only=on'; " : '';
        $variable = $engine === 'mysql' ? 'MYSQL_PWD' : 'PGPASSWORD';
        // The first line of stdin is the password; the rest is the SQL the client reads.
        $command = $password === null ? $readOnly . 'exec ' . $client : sprintf('IFS= read -r %1$s; export %1$s; %2$sexec %3$s', $variable, $readOnly, $client);
        $stdin = ($password === null ? '' : $password . "\n") . $sql;
        [, $text] = $this->console->runPrepared(
            $context->principal,
            $context->runId,
            Args::string($args, 'server') ?? '',
            sprintf('sql %s %s@%s/%s%s: %s', $engine, $user, $host, $database, $write ? t('tool.sql_query.zapis') : '', mb_substr($query, 0, 2000)),
            $write ? Classification::CHANGE : Classification::READ,
            $command,
            $stdin,
            120,
            $context->cancellation,
        );
        return ToolResult::ok($password === null || $password === '' ? $text : str_replace([$password, base64_encode($password)], '***', $text));
    }

    /** @param array<string, mixed> $args */
    private static function secretOf(array $args): ?string {
        $secret = trim(Args::string($args, 'secret') ?? '');
        return $secret === '' ? null : $secret;
    }

    /**
     * The command of the client that would run with the query, null when there is none. A backslash counts only
     * outside strings and quoted names: there it is the start of a psql or mysql command. Comments are not trusted —
     * a backslash in a comment is refused too. A string that is not closed is refused: the rest would be read as code.
     */
    public static function clientCommand(string $query, string $engine): ?string {
        $mysql = $engine === 'mysql';
        if ($mysql && preg_match(self::MYSQL_CLIENT, self::outsideStrings($query, true) ?? '', $m) === 1) {
            return trim($m[0]);
        }
        $outside = self::outsideStrings($query, $mysql);
        if ($outside === null) {
            return t('tool.sql_query.unclosed');
        }
        $at = strpos($outside, '\\');
        return $at === false ? null : trim(substr($outside, $at, 12));
    }

    /**
     * The query with every string and quoted name replaced by spaces; null when one is not closed.
     * PostgreSQL: '…' ('' inside; E'…' with backslash escapes), "…", $tag$…$tag$. MySQL: '…' and "…" with backslash
     * escapes, `…`.
     */
    private static function outsideStrings(string $query, bool $mysql): ?string {
        $out = '';
        $length = strlen($query);
        for ($i = 0; $i < $length; $i++) {
            $c = $query[$i];
            if (!$mysql && $c === '$' && preg_match('~\G\$([A-Za-z_][A-Za-z0-9_]*)?\$~', $query, $m, 0, $i) === 1) {
                $end = strpos($query, $m[0], $i + strlen($m[0]));
                if ($end === false) {
                    return null;
                }
                $out .= str_repeat(' ', $end + strlen($m[0]) - $i);
                $i = $end + strlen($m[0]) - 1;
                continue;
            }
            $single = $c === "'";
            $backtick = $c === '`';
            if (!$single && $c !== '"' && !($mysql && $backtick)) {
                $out .= $c;
                continue;
            }
            $quote = $c;
            $escapes = $mysql ? !$backtick : ($single && $i > 0 && ($query[$i - 1] === 'E' || $query[$i - 1] === 'e'));
            $j = $i + 1;
            $closed = false;
            while ($j < $length) {
                if ($escapes && $query[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($query[$j] === $quote) {
                    if ($j + 1 < $length && $query[$j + 1] === $quote) {
                        $j += 2;
                        continue;
                    }
                    $closed = true;
                    break;
                }
                $j++;
            }
            if (!$closed) {
                return null;
            }
            $out .= str_repeat(' ', $j - $i + 1);
            $i = $j;
        }
        return $out;
    }
}
