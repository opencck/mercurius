<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Web;

use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\Guard\AddressRefused;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use Throwable;

use function Amp\Socket\connect;

use function Mercurius\I18n\t;

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
        return new ToolDefinition(self::NAME, t('tool.tcp_probe.proveryaet_otvechaet_li_tcp_port')
            . t('tool.tcp_probe.vnutri_seti_vladeltsa_proveryay_s'), [
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
            return ToolResult::ok(sprintf(t('tool.tcp_probe.port_otkryt_soedinenie_za_ms'), $host, $port, $ip, intdiv(hrtime(true) - $started, 1_000_000)));
        } catch (Throwable $e) {
            return ToolResult::ok(sprintf(t('tool.tcp_probe.ne_otvechaet_za_s'), $host, $port, $ip, $timeout, (new \ReflectionClass($e))->getShortName()));
        }
    }
}
