<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * SQL on a database the owner's server reaches, through that server's psql or mysql — the same way as any command:
 * the journal, the route, the host key. A read runs inside a read-only transaction, so the database itself refuses a
 * write; one statement only, so the transaction cannot be closed from inside. A write waits for the owner. The password
 * goes on stdin, never on the command line.
 *
 * spec: BRIEF.md#фаза-7-по-частям — SQL tools for PostgreSQL and MariaDB/MySQL go the same way
 */
final class SqlQueryTool implements Tool {
    public const NAME = 'sql_query';
    private const QUERY_MAX = 20_000;
    /** what a single read statement may not start with: it would leave or change the transaction */
    private const NOT_A_READ = '~^\s*(begin|start|commit|end|rollback|abort|set|reset|discard|savepoint|release|prepare|deallocate|do|call|copy|lock|load|handler|flush|kill|shutdown|grant|revoke)\b~i';
    /** functions that act outside the database even in a read-only transaction */
    private const SIDE_EFFECTS = '~\b(pg_terminate_backend|pg_cancel_backend|pg_reload_conf|pg_rotate_logfile|lo_export|lo_import|pg_read_file|pg_read_binary_file|pg_ls_dir|dblink\w*|sleep|benchmark|sys_exec|load_file)\s*\(~i';

    public function __construct(
        private readonly ServerConsole $console,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'SQL-запрос к базе через сервер из реестра (на сервере нужен psql или mysql). engine — postgres или mysql; '
            . 'host и port — адрес базы с точки зрения сервера (по умолчанию 127.0.0.1); secret — имя секрета с паролем базы. Без write '
            . 'запрос — одно выражение в транзакции только для чтения и выполняется сразу; write: true — изменение, ждёт подтверждения владельца.', [
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
        return ($args['write'] ?? false) === true ? DangerClass::Irreversible : DangerClass::Read;
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
                return ToolResult::error(sprintf('%s — латиница, цифры, точка, дефис, подчёркивание.', $field));
            }
        }
        if (!$write) {
            if (str_contains($query, ';')) {
                return ToolResult::error('Для чтения — одно выражение без «;». Несколько изменений — write: true с подтверждением владельца.');
            }
            if (preg_match(self::NOT_A_READ, $query) === 1 || preg_match(self::SIDE_EFFECTS, $query) === 1) {
                return ToolResult::error('Это не чтение: такой запрос — только с write: true и подтверждением владельца.');
            }
        }
        $password = null;
        $secret = Args::string($args, 'secret');
        if ($secret !== null) {
            $password = $this->vault->reveal($secret, $context->principal);
            if ($password === null) {
                return ToolResult::error(sprintf('Секрет «%s» недоступен.', $secret));
            }
        }
        $port = Args::int($args, 'port');
        $sql = $engine === 'mysql'
            ? ($write ? $query . ";\n" : "START TRANSACTION READ ONLY;\n" . $query . ";\nROLLBACK;\n")
            : ($write ? $query . ";\n" : "BEGIN READ ONLY;\n" . $query . ";\nROLLBACK;\n");
        $client = $engine === 'mysql'
            ? sprintf('mysql -h %s%s -u %s -D %s -B --default-character-set=utf8mb4', $host, $port === null ? '' : ' -P ' . $port, $user, $database)
            : sprintf('psql -X -A -F "	" -v ON_ERROR_STOP=1 -h %s%s -U %s -d %s -f -', $host, $port === null ? '' : ' -p ' . $port, $user, $database);
        $variable = $engine === 'mysql' ? 'MYSQL_PWD' : 'PGPASSWORD';
        // The first line of stdin is the password; the rest is the SQL the client reads.
        $command = $password === null ? 'exec ' . $client : sprintf('IFS= read -r %1$s; export %1$s; exec %2$s', $variable, $client);
        $stdin = ($password === null ? '' : $password . "\n") . $sql;
        [, $text] = $this->console->runPrepared(
            $context->principal,
            $context->runId,
            Args::string($args, 'server') ?? '',
            sprintf('sql %s %s@%s/%s%s: %s', $engine, $user, $host, $database, $write ? ' (запись)' : '', mb_substr($query, 0, 2000)),
            $write ? Classification::CHANGE : Classification::READ,
            $command,
            $stdin,
            120,
            $context->cancellation,
        );
        return ToolResult::ok($text);
    }
}
