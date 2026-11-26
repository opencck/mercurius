<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\NullCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\McpInstallRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * At start the MCPs of the delivery are installed from the sandbox image (`McpInstaller::preinstall`). On a clean
 * install — no install yet and no entry of the owner — the owner's `.mcp.json` gets sample entries: they wait for
 * their secrets, named in their error, until the owner sets the server and the mailbox.
 *
 * spec: brief/skills-mcp.md#предустановленные-mcp-ssh-и-mail
 */
final class McpPreinstall {
    /**
     * the sample entries: name, the arguments after the code's own, env; a mailbox is not named `mail` — the tools of
     * the core take that name
     */
    public const SAMPLES = [
        'ssh' => ['name' => 'ssh', 'args' => ['--host=', '--user=root'], 'env' => ['SSH_PASSWORD' => '{SSH_PASSWORD}']],
        'mail' => ['name' => 'mailbox', 'args' => ['--host=', '--user='], 'env' => ['MAIL_PASSWORD' => '{MAIL_PASSWORD}']],
    ];

    /**
     * @param Closure(): ?Member $owner the owner of the agent
     * @param Closure(string): bool $hasEntries whether a context keeps entries of .mcp.json
     */
    public function __construct(
        private readonly McpInstaller $installer,
        private readonly McpInstallRepository $installs,
        private readonly Sandbox $sandbox,
        private readonly Closure $owner,
        private readonly Closure $hasEntries,
        private readonly LoggerInterface $logger,
        /** @var (Closure(int, string): void)|null a word to a member: the installer of an MCP built again */
        private readonly ?Closure $tell = null,
    ) {}

    /**
     * The print of the environments of the sandbox image; null — the sandbox did not answer.
     *
     * spec: brief/skills-mcp.md#установка-и-обновление
     */
    public function environment(): ?string {
        try {
            $outcome = $this->sandbox->exec(0, McpSources::limits(), 'mcp-install', "node -v 2>/dev/null; python3 -V 2>&1; php -r 'echo PHP_VERSION, PHP_EOL;'; go version 2>/dev/null", '.', 30, new NullCancellation());
        } catch (SandboxFailure) {
            return null;
        }
        return substr(hash('sha256', trim($outcome->stdout)), 0, 12);
    }

    /** @return list<McpBundle>|null the MCPs the image carries, none when it has no index; null — the sandbox did not answer */
    public function bundles(): ?array {
        try {
            $outcome = $this->sandbox->exec(0, McpSources::limits(), 'mcp-install', 'cat ' . McpBundle::INDEX, '.', 30, new NullCancellation());
        } catch (SandboxFailure) {
            return null;
        }
        return $outcome->exitCode === 0 ? McpBundle::fromIndex($outcome->stdout) : [];
    }

    /** @return bool false — the sandbox did not answer, to try again later */
    public function run(): bool {
        $bundles = $this->bundles();
        if ($bundles === null) {
            return false;
        }
        $this->installer->useBundled(array_map(static fn(McpBundle $b): string => $b->name, $bundles));
        $owner = ($this->owner)();
        if ($bundles === [] || $owner === null) {
            $this->logger->info('No MCP of the delivery was installed', ['bundles' => count($bundles), 'owner' => $owner !== null]);
            return true;
        }
        $clean = $this->installs->all() === [] && !($this->hasEntries)('member:' . $owner->id);
        // spec: brief/skills-mcp.md#установка-и-обновление — other environments in the image: the dependencies are built again
        $environment = $this->environment();
        foreach ($environment === null ? [] : $this->installer->rebuildChanged($environment) as [$by, $name, $failed]) {
            $this->logger->info('MCP dependencies built again for new environments', ['name' => $name, 'failed' => $failed !== null]);
            if ($this->tell !== null) {
                ($this->tell)($by, $failed === null ? sprintf(t('mcp.install.env_rebuilt'), $name) : sprintf(t('mcp.install.env_rebuild_failed'), $name, $failed));
            }
        }
        foreach ($bundles as $bundle) {
            $result = $this->installer->preinstall($owner, $bundle, $clean ? self::SAMPLES[$bundle->name] ?? null : null);
            $this->logger->info('MCP of the delivery', ['name' => $bundle->name, 'result' => $result]);
        }
        return true;
    }
}
