<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;
use Throwable;

use function Amp\Socket\connect;

/**
 * Whether a port answers, and how fast: a TCP connection and nothing sent. Only public addresses — the agent's own
 * network is not mapped from here; inside a network of the owner, probe from a server with ssh_run.
 *
 * spec: BRIEF.md#фаза-7-по-частям — ping as a TCP probe (no ICMP: it would need NET_RAW)
 */
final class TcpProbeTool implements Tool {
    public const NAME = 'tcp_probe';

    public function __construct(private readonly AddressGuard $guard) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Проверяет, отвечает ли TCP-порт (вместо ping): host, port, timeout до 10 с. Только публичные адреса; '
            . 'внутри сети владельца проверяй с сервера через ssh_run (nc -z, curl).', [
            'type' => 'object',
            'properties' => [
                'host' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'required' => ['host', 'port'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $host = Args::string($args, 'host') ?? '';
        $port = Args::int($args, 'port') ?? 0;
        $timeout = Args::int($args, 'timeout') ?? 5;
        try {
            $ips = $this->guard->resolve($host);
        } catch (AddressRefused $e) {
            return ToolResult::error($e->getMessage());
        }
        $ip = $ips[0];
        $address = str_contains($ip, ':') ? sprintf('[%s]:%d', $ip, $port) : sprintf('%s:%d', $ip, $port);
        $started = hrtime(true);
        try {
            $socket = connect($address, (new ConnectContext())->withConnectTimeout((float) $timeout), new TimeoutCancellation((float) $timeout + 1.0));
            $socket->close();
            return ToolResult::ok(sprintf('%s:%d (%s) — порт открыт, соединение за %d мс.', $host, $port, $ip, intdiv(hrtime(true) - $started, 1_000_000)));
        } catch (Throwable $e) {
            return ToolResult::ok(sprintf('%s:%d (%s) — не отвечает за %d с (%s).', $host, $port, $ip, $timeout, (new \ReflectionClass($e))->getShortName()));
        }
    }
}
