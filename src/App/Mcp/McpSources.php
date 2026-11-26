<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\NullCancellation;
use DomainException;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Mcp\McpInstall;

use function Mercurius\I18n\t;

/**
 * The sources of an MCP to install: a revision of a git repository or an archive, put into releases/<revision> in the
 * home of the install's own sandbox user, next to the revisions before it. Nothing of the sources runs here; the
 * answer is what the agent reads to make the plan — the README, the files of dependencies, a ready
 * mercurius.mcp.json — and it is untrusted data.
 *
 * spec: brief/skills-mcp.md#установка-и-обновление
 * ref: references/hermes-agent/hermes_cli/mcp_catalog.py@e96221fa:L778-L848 (_install_entry) — a clone at a pinned
 *      revision into a folder of the install; hermes clones on the user's machine, here the clone runs in the sandbox
 *      as the install's own user, and the revision is read from the clone instead of being given
 */
final class McpSources {
    /** what installing may take: the limits of the install's own user */
    public const LIMITS = [1_073_741_824, 256, 600, 2_147_483_648, 4];
    private const URL = '#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~-]+)+/?$#';
    private const ARCHIVE_MAX_FILES = 5000;
    /** the files the agent reads to make a plan, each cut at 8 KB */
    private const READ = 'README.md README README.rst package.json requirements.txt pyproject.toml mercurius.mcp.json';

    public function __construct(private readonly Sandbox $sandbox) {}

    public static function limits(): SandboxLimits {
        return new SandboxLimits(...self::LIMITS);
    }

    /** @throws DomainException */
    public function fromGit(McpInstall $install, string $url): McpFetched {
        if (preg_match(self::URL, $url) !== 1) {
            throw new DomainException(t('mcp.install.bad_url'));
        }
        $this->link($install);
        $clone = 'chmod 755 . && mkdir -p releases && rm -rf releases/.incoming && '
            . 'git clone --quiet --depth 1 -- ' . self::quote($url) . ' releases/.incoming && '
            . 'rev=$(git -C releases/.incoming rev-parse HEAD) && short=$(printf %s "$rev" | cut -c1-12) && '
            . 'rm -rf releases/.incoming/.git';
        return $this->settle($install, $clone, null);
    }

    /** @throws DomainException */
    public function fromArchive(McpInstall $install, string $fileName, string $bytes): McpFetched {
        $lower = strtolower($fileName);
        if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
            $kind = 'tar';
        } elseif (str_ends_with($lower, '.zip')) {
            $kind = 'zip';
        } else {
            throw new DomainException(t('mcp.install.bad_archive'));
        }
        $this->link($install);
        $revision = 'a' . substr(hash('sha256', $bytes), 0, 11);
        $this->run(fn() => $this->sandbox->put($install->holder(), self::limits(), 'incoming.archive', $bytes, new NullCancellation()));
        // the names first: none absolute, none climbing out, not too many; then links that lead out are refused
        $names = $kind === 'tar' ? 'tar -tzf incoming.archive' : 'unzip -Z1 incoming.archive';
        $unpack = $kind === 'tar'
            ? 'tar -xzf incoming.archive --no-same-owner --no-same-permissions -C releases/.incoming'
            : 'unzip -q incoming.archive -d releases/.incoming';
        $script = 'chmod 755 . && mkdir -p releases && rm -rf releases/.incoming && mkdir releases/.incoming && '
            . 'n=$(' . $names . ' | wc -l) && [ "$n" -le ' . self::ARCHIVE_MAX_FILES . ' ] && '
            . '! ' . $names . " | grep -Eq '(^/|(^|/)\\.\\.(/|$))' && "
            . $unpack . ' && rm -f incoming.archive && '
            . "! find releases/.incoming -type l -lname '/*' | grep -q . && ! find releases/.incoming -type l -lname '*..*' | grep -q . && "
            . 'short=' . self::quote($revision);
        return $this->settle($install, $script, $revision);
    }

    /**
     * The code at /workspace/mcp/<name> for every process: a link the owners' area keeps to the install's home.
     *
     * @throws DomainException
     */
    public function link(McpInstall $install): void {
        // the folder of links is passed through by every process, listed by none
        $script = 'mkdir -p mcp && chmod 711 mcp && ln -sfn ' . self::quote('../p' . $install->holder()) . ' ' . self::quote('mcp/' . $install->name);
        $this->run(fn(): CommandOutcome => $this->sandbox->exec(0, self::limits(), 'mcp-install', $script, '.', 30, new NullCancellation()));
    }

    /** Moves the incoming sources to their revision and reads what the plan is made of. */
    private function settle(McpInstall $install, string $script, ?string $revision): McpFetched {
        $read = 'cd "releases/$short" && echo "REV $short" && for f in ' . self::READ . '; do [ -f "$f" ] && echo "== FILE $f" && head -c 8000 "$f" && echo; done; '
            . 'echo "== FILES" && find . -type f | head -200';
        $target = $revision === null ? '"releases/$short"' : self::quote('releases/' . $revision);
        $full = $script . ' && rm -rf ' . $target . ' && mv releases/.incoming ' . $target . ' && ' . $read;
        $outcome = $this->run(fn(): CommandOutcome => $this->sandbox->exec($install->holder(), self::limits(), 'mcp-install', $full, '.', 600, new NullCancellation()));
        if ($outcome->exitCode !== 0 || preg_match('~^REV (\S+)~m', $outcome->stdout, $m) !== 1) {
            throw new DomainException(sprintf(t('mcp.install.not_fetched'), trim(mb_substr($outcome->stderr, -600))));
        }
        $got = $revision ?? substr($m[1], 0, 12);
        return new McpFetched($got, mb_substr($outcome->stdout, 0, 40_000));
    }

    /**
     * @template T
     * @param \Closure(): T $call
     * @return T
     * @throws DomainException
     */
    private function run(\Closure $call): mixed {
        try {
            return $call();
        } catch (SandboxFailure $e) {
            throw new DomainException(sprintf(t('mcp.install.not_fetched'), $e->getMessage()));
        }
    }

    private static function quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
