<?php

declare(strict_types=1);

/**
 * Servers over SSH with the command policy, skills, MCP servers and the built-in mail.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\HostKeyCards;
use OpenCCK\App\Agent\Tool\Files\FileWriteTool;
use OpenCCK\App\Agent\Tool\Self\OpenRouterByokTool;
use OpenCCK\App\Agent\Tool\Servers\ServerAddTool;
use OpenCCK\App\Agent\Tool\Servers\ServersTool;
use OpenCCK\App\Agent\Tool\Servers\SqlQueryTool;
use OpenCCK\App\Agent\Tool\Servers\SshRunTool;
use OpenCCK\App\Agent\Tool\Skills\SkillViewTool;
use OpenCCK\App\Agent\Tool\Web\TcpProbeTool;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Infrastructure\Http\HttpClients;
use Mercurius\Mcp\HttpMcpClient;
use OpenCCK\Infrastructure\Repository\PostgresCommandAuditRepository;
use OpenCCK\Infrastructure\Repository\PostgresMcpServerRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Infrastructure\Repository\PostgresServerRepository;
use OpenCCK\Infrastructure\Ssh\AmpSshShell;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\App\Agent\Tool\Guard\AddressGuard $addressGuard
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\Infrastructure\Repository\PostgresConversationRepository $conversations
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var Amp\Http\Client\HttpClient $modelHttp
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\App\Service\PolicyService $policies
 * @var OpenCCK\Infrastructure\Repository\PostgresMemberRepository $principals
 * @var OpenCCK\Infrastructure\Sandbox\HttpSandboxClient|null $sandbox
 * @var OpenCCK\Infrastructure\Config\SandboxSettings $sandboxSettings
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\App\Vault\Vault $vault
 * @var OpenCCK\Infrastructure\Config\VaultSettings $vaultSettings
 */

// spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the vault, the registry of servers, SSH with a command policy and a journal
if (!$vault->enabled()) {
    $logger->warning('SECRETS_KEY is not set: the vault and SSH are off');
}
$serverRepository = new PostgresServerRepository($db);
$commandAudit = new PostgresCommandAuditRepository($db);
$hostKeyCards = new HostKeyCards($serverRepository, $channel, $ownerDirectory);
// spec: brief/servers.md#правила-никогда-без-владельца — the owner may take off any rule of what is never done
$sshNeverOff = array_values(array_filter(array_map('trim', explode(',', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'SSH_NEVER_OFF') ?? '')), static fn(string $r): bool => $r !== ''));
$serverConsole = new ServerConsole(
    $serverRepository,
    $vault,
    new SshCommandClassifier($sshNeverOff),
    $commandAudit,
    new AmpSshShell(null, $logger),
    $hostKeyCards->ask(...),
    $logger,
    $llmSettings->routeUri(),
    $vaultSettings->confirmGroups,
);
$tools->register(new ServersTool($serverRepository));
$tools->register(new ServerAddTool($serverRepository, $vault));
$tools->register(new \OpenCCK\App\Agent\Tool\Servers\ServerEditTool($serverRepository, $vault));
$tools->register(new \OpenCCK\App\Agent\Tool\Servers\ServerRemoveTool($serverRepository));
$tools->register(new SshRunTool($serverConsole));
$tools->register($sqlQueryTool = new SqlQueryTool($serverConsole, $vault));
$tools->register(new FileWriteTool($serverConsole, new SshCommandClassifier($sshNeverOff)));
$tools->register(new TcpProbeTool($addressGuard));
$tools->register(new OpenRouterByokTool($modelHttp, $vault));

// spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — skills are files: built-in ones (submodules in skills/), the participant's folder and the
// active project's folder in the sandbox; the index is in the prompt, the texts on demand
$skillProjects = new PostgresProjectRepository($db);
$skillLibrary = new \OpenCCK\App\Skill\SkillLibrary(
    \OpenCCK\App\Skill\BuiltinSkills::load(PATH_ROOT . '/skills', $logger),
    $sandbox,
    static function (int $principalId) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
        $principal = $principals->find($principalId);
        return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
    },
    static fn(): ?int => $ownerDirectory->principal()?->id,
    $logger,
);
$scheduler->useSkills($skillLibrary);
// spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — skills of the old layout move to .mercurius/skills at start, the old folder kept
if ($sandbox !== null) {
    \Amp\async(static function () use ($sandbox, $principals, $policies, $skillProjects, $ownerDirectory, $logger): void {
        $limitsOf = static function (int $id) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
            $principal = $principals->find($id);
            return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
        };
        try {
            $people = array_values(array_map(
                static fn(Member $p): int => $p->id,
                array_filter($principals->all(), static fn(Member $p): bool => $p->role->may(\OpenCCK\Domain\Entity\Permission::Skills)),
            ));
            $owner = $ownerDirectory->principal()?->id;
            $projects = [];
            foreach ($skillProjects->all(1000) as $project) {
                $holder = $project->ownerId ?? $owner;
                if ($holder !== null) {
                    $projects[] = [$project->id, $holder];
                }
            }
            $moved = (new \OpenCCK\App\Skill\SkillFolders($sandbox, $limitsOf, null, $logger))->migrate($people, $projects);
            $logger->info('Skill folders checked', ['moved' => $moved]);
        } catch (Throwable $e) {
            $logger->warning('Skill folders were not checked', ['error' => $e::class]);
        }
    })->ignore();
}
// spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — a project named by the agent (a private chat about it), else the conversation's active one
$skillProjectOf = static function (int $conversationId, ?string $name, \OpenCCK\Domain\Entity\Member $who) use ($conversations, $skillProjects): ?\OpenCCK\Domain\Project\Project {
    if ($name !== null && trim($name) !== '') {
        return $skillProjects->byName(trim($name), \OpenCCK\Domain\Project\Project::scopeOf($who));
    }
    $meta = $conversations->meta($conversationId);
    return $meta === null ? null : $skillProjects->active($meta->channel, $meta->chatId, $meta->threadId);
};
$skillUsage = new \OpenCCK\Infrastructure\Repository\PostgresSkillUsageRepository($db);
$skillLibrary->useUsage($skillUsage);
// spec: brief/participants.md#доступ-к-проектам-и-передача — the skills of a project are open to its members
$skillLibrary->useMembers(static fn(\OpenCCK\Domain\Project\Project $project, \OpenCCK\Domain\Entity\Member $who): ?string => $skillProjects->memberLevel($project->id, $who->id));
$skillLibrary->useEdits(new \OpenCCK\Infrastructure\Repository\PostgresSkillEditRepository($db));
$tools->register(new SkillViewTool($skillLibrary, $skillProjectOf, static fn(\OpenCCK\Domain\Entity\Member $who): array => $skillProjects->all(20, \OpenCCK\Domain\Project\Project::scopeOf($who))));
$tools->register(new \OpenCCK\App\Agent\Tool\Skills\SkillSaveTool($skillLibrary, $skillProjectOf));
// spec: brief/skills-mcp.md#скиллы-других-агентов-импорт — ordinary SKILL.md skills of other agents, through the SSRF guard
$skillHttp = HttpClients::guarded($addressGuard);
// spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — no limit on a skill by default; the owner may cap its number of files
$skillMaxFiles = max(0, (int) (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'SKILL_MAX_FILES') ?? '0'));
$skillImporter = new \OpenCCK\App\Skill\SkillImporter([new \OpenCCK\App\Skill\GitHubSkillSource($skillHttp, $skillMaxFiles), new \OpenCCK\App\Skill\ArchiveSkillSource($skillHttp, $skillMaxFiles)], $skillMaxFiles);
$tools->register(new \OpenCCK\App\Agent\Tool\Skills\SkillImportTool($skillImporter, $skillLibrary, $skillProjectOf, $sandbox));
// spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — external MCP servers over HTTP, and stdio ones in the sandbox, added and approved by the owner
$mcpServers = new PostgresMcpServerRepository($db);
// spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — the MCP client of the package speaks in the agent's language
$mcpTexts = \OpenCCK\App\Mcp\McpLanguage::texts();
/** @var McpManager|null $mcpManager the stdio client tells it when a server's tools change */
$mcpManager = null;
$stdioMcp = $sandbox === null ? null : new \OpenCCK\Infrastructure\Mcp\StdioMcpClient(
    $sandbox,
    // a server lives for days: its CPU time is capped by a day, not by the limit of one command
    new \OpenCCK\Domain\Agent\SandboxLimits($sandboxSettings->ownerLimits()->diskBytes, $sandboxSettings->ownerLimits()->processes, 86_400, $sandboxSettings->ownerLimits()->memoryBytes, $sandboxSettings->ownerLimits()->concurrentCommands),
    static function (string $server) use (&$mcpManager): void {
        $mcpManager?->toolsChanged($server);
    },
    $logger,
    $mcpTexts,
);
$mcpClient = new \OpenCCK\Infrastructure\Mcp\RoutingMcpClient(new HttpMcpClient(HttpClients::create(), $mcpTexts), $stdioMcp);
$mcpManager = new McpManager(
    $mcpServers,
    $mcpClient,
    $vault,
    $tools,
    // spec: brief/skills-mcp.md#подстановка-секретов — the token of a member's connection is that member's secret, of a
    //       project's — its owner's; others — the first owner's until secrets are bound to MCP
    static function (string $context) use ($principals, $ownerDirectory, $skillProjects): ?Member {
        if (preg_match('~^project:(\d+)$~', $context, $m) === 1) {
            $owner = $skillProjects->find((int) $m[1])?->ownerId;
            if ($owner === null) {
                return $ownerDirectory->principal();
            }
            $context = 'member:' . $owner;
        }
        if (!str_starts_with($context, 'member:')) {
            return $ownerDirectory->principal();
        }
        $member = $principals->find((int) substr($context, 7));
        return $member !== null && $member->status === \OpenCCK\Domain\Entity\MemberStatus::Active ? $member : null;
    },
    static function (string $text) use ($channel): void {
        $channel->send(new OutgoingMessage(\OpenCCK\App\Service\OwnersChannel::OWNERS, $text));
    },
    $logger,
    stdio: $stdioMcp !== null,
);
// spec: brief/skills-mcp.md#подстановка-секретов — an approved entry gets the secrets it names from the layers of its
//       context; the wider layers only where they are turned on
$secretInheritance = new \OpenCCK\Infrastructure\Repository\PostgresSecretInheritanceRepository($db);
// spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — a server asks the owner of its entry
$mcpElicitations = new \OpenCCK\App\Mcp\McpElicitations(
    $channel,
    static function (string $context) use ($principals, $skillProjects, $channel): ?array {
        if (preg_match('~^project:(\d+)$~', $context, $m) === 1) {
            $owner = $skillProjects->find((int) $m[1])?->ownerId;
            $context = $owner === null ? 'owners' : 'member:' . $owner;
        }
        if (preg_match('~^member:(\d+)$~', $context, $m) === 1) {
            $chat = $principals->externalId((int) $m[1], $channel->name());
            return $chat === null ? null : [$chat, (int) $m[1]];
        }
        return [\OpenCCK\App\Service\OwnersChannel::OWNERS, null];
    },
    static fn(string $user): bool => $ownerDirectory->isOwnerAccount($user),
);
// spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — a process nobody asked for 10 minutes stops
if ($stdioMcp !== null) {
    \Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(60, static function () use ($stdioMcp): void {
        $stdioMcp->stopIdle();
    }));
}
$stdioMcp?->useElicitation(static function (string $key, array $params, \Amp\Cancellation $session) use ($mcpElicitations): array {
    // a connection of a context is context|name; a built-in server has no context: the owners answer for it
    $parts = explode('|', $key, 2);
    return count($parts) === 2 ? $mcpElicitations->ask($parts[0], $parts[1], $params, $session) : $mcpElicitations->ask('owners', $key, $params, $session);
});
$mcpSecrets = new \OpenCCK\App\Mcp\McpSecrets(
    $vault,
    static fn(int $project): ?int => $skillProjects->find($project)?->ownerId,
    $secretInheritance,
);
$mcpManager->useSecrets($mcpSecrets);
// spec: brief/skills-mcp.md#подстановка-секретов — a request to a site takes the same wider layers
/** @var OpenCCK\App\Agent\Tool\Web\HttpRequestTool|null $httpRequestTool */
$httpRequestTool?->useLayers($mcpSecrets->outside(...));
// spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a command takes the caller's own secret, then the
//       wider layers of the context; a project's secret is for its site and does not go into commands
/**
 * @var OpenCCK\App\Agent\Tool\Sandbox\RunCommandTool|null $runCommandTool
 * @var OpenCCK\App\Agent\Tool\Sandbox\GitTool|null $gitTool
 * @var OpenCCK\App\Agent\Tool\Web\BrowserTool|null $browserTool
 * @var Closure(int, Member): ?int $secretProjectOf
 */
$widerSecret = static function (string $name, \OpenCCK\App\Agent\Tool\ToolContext $c) use ($mcpSecrets, $secretProjectOf): ?string {
    $project = $secretProjectOf($c->conversationId, $c->principal);
    return $mcpSecrets->outside($name, $project === null ? 'member:' . $c->principal->id : 'project:' . $project);
};
$gitTool?->useWiderSecrets($widerSecret);
$browserTool?->useWiderSecrets($widerSecret);
$sqlQueryTool->useWiderSecrets($widerSecret);
if ($runCommandTool !== null && $sandbox !== null) {
    $runCommandTool->useSecrets($sandbox, static fn(string $name, \OpenCCK\App\Agent\Tool\ToolContext $c): ?string => $vault->reveal($name, $c->principal) ?? $widerSecret($name, $c));
}
// spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — the package of an npx/uvx server is looked up in OSV before /mcp on starts it
$mcpManager->useOsv(new \OpenCCK\App\Mcp\OsvCheck(HttpClients::create(), $logger));
if ($sandbox !== null) {
    $mcpManager->useSandbox($sandbox, new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00'));
}
// spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — .mcp.json of each member and project: read at start, once a
//       minute and at once after a write; an entry works only while its hash is the approved one
$mcpFiles = null;
$mcpCards = null;
if ($sandbox !== null) {
    // the card of an entry needs the files to approve by; the files send cards: the holder is filled just below
    /** @var \ArrayObject<string, \OpenCCK\App\Mcp\McpEntryCards> $mcpCardsHolder */
    $mcpCardsHolder = new \ArrayObject();
    $mcpFiles = new \OpenCCK\App\Mcp\McpFiles(
        $sandbox,
        // the owners' area works with the limits of an owner of the agent
        static function (int $principalId) use ($principals, $policies, $ownerDirectory): ?\OpenCCK\Domain\Agent\SandboxLimits {
            $principal = $principalId === \OpenCCK\App\Mcp\McpFiles::OWNERS_AREA ? $ownerDirectory->principal() : $principals->find($principalId);
            return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
        },
        $mcpServers,
        $mcpManager,
        new \OpenCCK\App\Mcp\McpEntryGuard(),
        static function (int $projectId) use ($skillProjects, $ownerDirectory): ?int {
            $project = $skillProjects->find($projectId);
            return $project === null ? null : ($project->ownerId ?? $ownerDirectory->principal()?->id);
        },
        static function (string $context, \OpenCCK\App\Mcp\McpEntry $entry) use ($mcpCardsHolder): void {
            $cards = $mcpCardsHolder['cards'] ?? null;
            if ($cards instanceof \OpenCCK\App\Mcp\McpEntryCards) {
                $cards->ask($context, $entry);
            }
        },
        $logger,
    );
    // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the owners approve an entry from its card
    $mcpCards = new \OpenCCK\App\Mcp\McpEntryCards(
        $channel,
        $ownerDirectory,
        $mcpFiles,
        $mcpSecrets,
        static fn(\Mercurius\Messenger\IncomingCallback $press): ?Member => $principals->findByIdentity($channel->name(), $press->userId),
    );
    $mcpCardsHolder['cards'] = $mcpCards;
    // spec: brief/skills-mcp.md#установка-и-обновление — sources, the agent's plan, steps by the install's own user, a
    //       trial start and the approval of the set; the entry goes into the context that asked for it
    $mcpInstalls = new \OpenCCK\Infrastructure\Repository\PostgresMcpInstallRepository($db);
    $osvCheck = new \OpenCCK\App\Mcp\OsvCheck(HttpClients::create(), $logger);
    /** @psalm-suppress PossiblyNullReference the sandbox is on here, and with it the stdio client */
    $mcpInstaller = new \OpenCCK\App\Mcp\McpInstaller(
        $mcpInstalls,
        new \OpenCCK\App\Mcp\McpSources($sandbox),
        $sandbox,
        static fn(int $holder, \OpenCCK\Domain\Mcp\McpServer $server): array => $stdioMcp->trial($holder, $server, new \Amp\TimeoutCancellation(120)),
        static fn(\OpenCCK\App\Mcp\McpEntry $entry, string $context): \OpenCCK\App\Mcp\McpRendered => $mcpSecrets->render($entry, $context),
        static function (string $context, \OpenCCK\App\Mcp\McpEntry $entry, Member $by) use ($mcpFiles): string {
            $mcpFiles->put($context, $entry);
            return $mcpFiles->approve($context, $entry->name, $by, substr($entry->hash(), 0, 12));
        },
        static fn(array $packages): ?string => $osvCheck->packages($packages, new \Amp\TimeoutCancellation(30)),
    );
    $tools->register(new \OpenCCK\App\Mcp\McpInstallTool($mcpInstaller, $sandbox));
    $tools->register(new \OpenCCK\App\Mcp\McpManageTool($mcpInstaller));
    // the tools of an entry that runs installed code get the classes its approver set (a tool not in the set is
    // irreversible), and such an entry runs only in a context the install is allowed to
    $installOf = static fn(\OpenCCK\Domain\Mcp\McpServer $server): ?string => preg_match('~/workspace/mcp/([a-z0-9_]+)/~', $server->url, $m) === 1 ? $m[1] : null;
    $mcpManager->useInstalls(
        static function (\OpenCCK\Domain\Mcp\McpServer $server, string $tool) use ($mcpInstalls, $installOf): ?string {
            $name = $installOf($server);
            $install = $name === null ? null : $mcpInstalls->find($name);
            return $install === null ? null : ($install->classes[$tool] ?? 'irreversible');
        },
        static function (\OpenCCK\Domain\Mcp\McpServer $server) use ($mcpInstaller, $installOf): bool {
            $name = $installOf($server);
            return $name === null || $server->context === '' || $mcpInstaller->allowedIn($name, $server->context);
        },
    );
    // spec: brief/skills-mcp.md#установка-и-обновление — new revisions are looked for once a day; nothing updates by itself
    \Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(86400, static function () use ($mcpInstaller, $principals, $channel, $logger): void {
        try {
            foreach ($mcpInstaller->checkUpdates() as [$by, $name, $revision]) {
                $chat = $principals->externalId($by, $channel->name());
                if ($chat !== null) {
                    $channel->send(new \Mercurius\Messenger\OutgoingMessage($chat, sprintf(\Mercurius\I18n\t('mcp.install.new_revision'), $name, $revision)));
                }
            }
        } catch (\Throwable $e) {
            $logger->warning('MCP revisions were not checked', ['error' => $e::class]);
        }
    }));
    $mcpContexts = static function () use ($principals, $skillProjects): array {
        $contexts = ['owners'];
        foreach ($principals->all() as $member) {
            if ($member->status === \OpenCCK\Domain\Entity\MemberStatus::Active) {
                $contexts[] = 'member:' . $member->id;
            }
        }
        foreach ($skillProjects->all(1000) as $project) {
            $contexts[] = 'project:' . $project->id;
        }
        return $contexts;
    };
    // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — there a server runs only by its approved entry
    $mcpManager->useFiles($mcpFiles->keeps(...));
    // a slow sandbox may hold a pass past the next minute: passes never overlap, and one context failing stops no other
    /** @var \ArrayObject<string, true> $mcpSyncing */
    $mcpSyncing = new \ArrayObject();
    $syncMcpFiles = static function () use ($mcpFiles, $mcpContexts, $mcpSyncing, $logger): void {
        if (isset($mcpSyncing['on'])) {
            return;
        }
        $mcpSyncing['on'] = true;
        try {
            foreach ($mcpContexts() as $context) {
                try {
                    $mcpFiles->sync($context);
                } catch (\Throwable $e) {
                    $logger->warning('A .mcp.json was not read', ['context' => $context, 'error' => $e::class]);
                }
            }
        } catch (\Throwable $e) {
            $logger->warning('MCP files were not read', ['error' => $e::class]);
        } finally {
            unset($mcpSyncing['on']);
        }
    };
    \Revolt\EventLoop::defer(static function () use ($syncMcpFiles, $mcpManager): void {
        $syncMcpFiles();
        $mcpManager->connectAll();
    });
    \Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(60, static function () use ($syncMcpFiles): void {
        \Amp\async($syncMcpFiles)->ignore();
    }));
    /** @var \OpenCCK\App\Agent\Tool\Files\WriteFileTool|null $writeFile */
    $writeFile?->useMcpFiles(static function (int $member, string $path) use ($mcpFiles): void {
        $path = str_replace('\\', '/', $path);
        if ($member === \OpenCCK\App\Mcp\McpFiles::OWNERS_AREA) {
            // from the owners' area: the root's own file, or a member's or project's file inside a member's folder
            $path = preg_replace('~^/workspace/~', '', $path) ?? $path;
            if (preg_match('~^p(\d+)/(.*)$~', $path, $m) === 1) {
                $member = (int) $m[1];
                $path = $m[2];
            } else {
                \Amp\async(static fn() => $mcpFiles->sync('owners'))->ignore();
                return;
            }
        }
        $path = preg_replace('~^/workspace/p\d+/~', '', $path) ?? $path;
        $context = preg_match('~^projects/(\d+)/\.mcp\.json$~', $path, $m) === 1 ? 'project:' . $m[1] : 'member:' . $member;
        \Amp\async(static fn() => $mcpFiles->sync($context))->ignore();
    });
} else {
    \Revolt\EventLoop::defer($mcpManager->connectAll(...));
}
// spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — servers down at start are tried again, connected ones are listed again for changes
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(30, static function (string $id) use ($mcpManager): void {
    \Amp\async($mcpManager->tick(...))->ignore();
}));
$tools->register(new \OpenCCK\App\Agent\Tool\Skills\McpResourcesTool($mcpManager));
// spec: brief/skills-mcp.md#ящики-участников-и-проектов — the built-in mail server keeps no mailbox: each call brings the
// mailbox of the caller in its headers, the password comes from the vault at that moment
$mailToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MAIL_MCP_TOKEN');
if ($mailToken !== null) {
    $mailServer = new \OpenCCK\Domain\Mcp\McpServer(0, 'mail', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MAIL_MCP_URL') ?? 'http://mail-mcp:8090/mcp', null, \OpenCCK\Domain\Mcp\McpServer::ACTIVE, null, 'встроенный');
    $mailboxes = new \OpenCCK\App\Mail\Mailboxes(
        new \OpenCCK\Infrastructure\Repository\PostgresMailboxRepository($db),
        $vault,
        $principals,
        $addressGuard,
        static fn(int $conversationId, Member $who): ?\OpenCCK\Domain\Project\Project => $skillProjectOf($conversationId, null, $who),
        static fn(\OpenCCK\Domain\Project\Project $project, Member $who): ?\OpenCCK\Domain\Project\ProjectLevel => $project->levelOf($who, $skillProjects->memberLevel($project->id, $who->id)),
    );
    $tools->register(new \OpenCCK\App\Mail\MailboxTool(
        $mailboxes,
        static fn(string $name, Member $who): ?\OpenCCK\Domain\Project\Project => $skillProjects->byName($name, \OpenCCK\Domain\Project\Project::scopeOf($who)),
        /** @param array<non-empty-string, string> $account */
        static function (#[\SensitiveParameter] array $account, \Amp\Cancellation $cancellation) use ($mcpClient, $mailServer, $mailToken): ?string {
            try {
                [$text, $failed] = $mcpClient->call($mailServer->withHeaders($account), $mailToken, 'mail_folders', [], $cancellation);
                return $failed ? mb_substr(\OpenCCK\App\Agent\Tool\Guard\SecretMasker::mask($text), 0, 300) : null;
            } catch (\RuntimeException $e) {
                return mb_substr(\OpenCCK\App\Agent\Tool\Guard\SecretMasker::mask($e->getMessage()), 0, 300);
            }
        },
    ));
    \Revolt\EventLoop::defer(static fn() => $mcpManager->connectBuiltin(
        $mailServer,
        $mailToken,
        static fn(\OpenCCK\App\Mcp\McpTool $tool, string $remoteName): \OpenCCK\App\Agent\Tool\Tool => new \OpenCCK\App\Mail\MailTool($tool, $remoteName, $mailboxes),
    ));
}
