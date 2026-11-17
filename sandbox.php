<?php

declare(strict_types=1);

// Sandbox executor: runs inside the sandbox container as root with a minimal set of capabilities and does every
// participant operation as that participant's Unix user. Listens on the sandbox network only (compose).
// spec: BRIEF.md#песочница

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use OpenCCK\Infrastructure\Logger\LoggerFactory;
use OpenCCK\Sandbox\CommandRunner;
use OpenCCK\Sandbox\ExecutorServer;
use OpenCCK\Sandbox\Files;
use OpenCCK\Sandbox\Streams;
use OpenCCK\Sandbox\Users;
use OpenCCK\Sandbox\Workspace;

use function Amp\trapSignal;

require __DIR__ . '/vendor/autoload.php';

$token = getenv('SANDBOX_TOKEN');
if (!is_string($token) || strlen($token) < 16) {
    fwrite(STDERR, json_encode(['level' => 'CRITICAL', 'message' => 'SANDBOX_TOKEN must be set (at least 16 characters)'], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(78); // EX_CONFIG
}
// The token must not reach the participants' commands: they get a clean environment, and it leaves ours too.
putenv('SANDBOX_TOKEN');

$logger = LoggerFactory::create('info');
// /workspace is a project directory mounted from the host: participants may pass through it, not list it.
@chmod('/workspace', 0711);
$workspace = new Workspace('/workspace');
$users = new Users($workspace);
$files = new Files($workspace, $users);
$runner = new CommandRunner($workspace, $users, $files);
$files->attach($runner);
$streams = new Streams($runner, $users, $workspace);
// spec: BRIEF.md#песочница — a long-lived process nobody touched for half an hour is killed
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(60, static function () use ($streams, $logger): void {
    $reaped = $streams->reap();
    if ($reaped > 0) {
        $logger->info('Idle streams killed', ['count' => $reaped]);
    }
}));

// A few connections per address: participants cannot crowd out the core.
$server = SocketHttpServer::createForDirectAccess($logger, connectionLimit: 256, connectionLimitPerIp: 16);
$server->expose(new InternetAddress('0.0.0.0', 9000));
$server->start(new ExecutorServer($token, $workspace, $runner, $files, $logger, $streams), new DefaultErrorHandler());
$logger->info('Sandbox executor listening', ['port' => 9000]);

trapSignal([2, 15]); // SIGINT, SIGTERM
$streams->stopAll();
$server->stop();
