<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Tool\Sandbox\GitTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemorySecretRepository;

/**
 * git in the sandbox with a token of the vault: the token goes into the environment of this one command as an HTTP
 * header for the origin, never into the URL, the arguments, the repository config or the result.
 */
final class GitToolTest extends AsyncTestCase {
    private const TOKEN = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';
    private FakeSandbox $sandbox;
    private Vault $vault;
    private Principal $owner;

    private function tool(): GitTool {
        $this->sandbox = new FakeSandbox();
        $this->owner = new Principal(1, PrincipalKind::Human, Role::owner(), null);
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->vault->put('github', 'token', self::TOKEN, $this->owner);
        return new GitTool($this->sandbox, $this->sandbox, $this->vault, 120);
    }

    private function context(?Principal $who = null): ToolContext {
        return new ToolContext(3, 9, $who ?? $this->owner, new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    public function testATokenIsAHeaderOfTheOriginInTheEnvironmentOnly(): void {
        $tool = $this->tool();
        $this->sandbox->outcome = new CommandOutcome(0, 'pushed ' . self::TOKEN, 'remote: ok', false, false, 5);

        $result = $tool->execute(['args' => ['push', 'origin', 'master'], 'cwd' => 'app', 'secret' => 'github', 'origin' => 'https://github.com/opencck/x.git'], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame(["exec 1 git -c core.fsmonitor=false -c core.hooksPath=/dev/null -c credential.helper= -c http.followRedirects=false 'push' 'origin' 'master' @app t120"], $this->sandbox->calls);
        self::assertSame('1', $this->sandbox->env['GIT_CONFIG_COUNT']);
        self::assertSame('1', $this->sandbox->env['GIT_CONFIG_NOSYSTEM']);
        self::assertSame('/dev/null', $this->sandbox->env['GIT_CONFIG_GLOBAL']);
        self::assertSame('http.https://github.com/.extraheader', $this->sandbox->env['GIT_CONFIG_KEY_0']);
        self::assertSame('Authorization: basic ' . base64_encode('x-access-token:' . self::TOKEN), $this->sandbox->env['GIT_CONFIG_VALUE_0']);
        self::assertSame('0', $this->sandbox->env['GIT_TERMINAL_PROMPT']);
        self::assertStringNotContainsString(self::TOKEN, $result->content, 'what git printed is masked');
        self::assertStringContainsString('remote: ok', $result->content);
    }

    public function testWithoutASecretNothingIsAdded(): void {
        $tool = $this->tool();

        $result = $tool->execute(['args' => ['commit', '-m', 'x']], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame(["exec 1 git 'commit' '-m' 'x' @. t120"], $this->sandbox->calls);
        self::assertSame(['GIT_TERMINAL_PROMPT' => '0'], $this->sandbox->env);
    }

    public function testAReadStartsNoProgramOfTheRepository(): void {
        $tool = $this->tool();

        $result = $tool->execute(['args' => ['status', '--short']], $this->context());

        // spec: brief/scheduler.md#git-с-доступом-из-хранилища — a read runs without a card, so the repository may not make
        //       it start a program: settings that would are off, and a config with filters or diff programs stops it
        self::assertFalse($result->isError, $result->content);
        $command = $this->sandbox->calls[0] ?? '';
        self::assertStringContainsString("git -c core.fsmonitor=false -c core.hooksPath=/dev/null -c diff.external= -c core.pager=cat 'status' '--short'", $command);
        self::assertStringContainsString('git config --get-regexp', $command);
        self::assertStringContainsString('filter', $command);
        self::assertStringContainsString('textconv', $command);
        self::assertSame('1', $this->sandbox->env['GIT_CONFIG_NOSYSTEM'] ?? null);
        self::assertSame('/dev/null', $this->sandbox->env['GIT_CONFIG_GLOBAL'] ?? null);
    }

    public function testOptionsThatStartProgramsMakeAReadACard(): void {
        $tool = $this->tool();

        foreach ([['grep', '-O', 'x'], ['grep', '-Ovim', 'x'], ['grep', '--open-files-in-pager=sh', 'x'], ['diff', '--ext-diff'], ['log', '--textconv'], ['diff', '--output=/tmp/x'], ['show', '-x']] as $args) {
            self::assertSame(DangerClass::Irreversible, $tool->danger(['args' => $args]), implode(' ', $args));
        }
        self::assertNotSame(DangerClass::Read, $tool->danger(['args' => ['ls-remote', 'https://example.com/r.git']]), 'ls-remote talks to a server');
        self::assertSame(DangerClass::Read, $tool->danger(['args' => ['log', '--oneline', '-5']]));
    }

    public function testArgumentsAreQuotedAndOptionsThatRunProgramsAreRefused(): void {
        $tool = $this->tool();

        $quoted = $tool->execute(['args' => ['commit', '-m', "it's; env"]], $this->context());
        self::assertFalse($quoted->isError);
        self::assertSame(["exec 1 git 'commit' '-m' 'it'\''s; env' @. t120"], $this->sandbox->calls);

        foreach ([['-c', 'core.sshCommand=sh'], ['fetch', '--upload-pack=sh'], ['push', '--receive-pack', 'x'], ['--exec-path=/tmp', 'status'], ['--config-env=a=B', 'status'], ['clone', '-u', 'sh', 'x'], ['clone', '-ush', 'x'], ['fetch', '-qush'], ['ls-remote', '-u', 'sh', 'x'], ['-C', 'repo', 'clone', '-ush', 'x']] as $args) {
            self::assertTrue($tool->execute(['args' => $args, 'secret' => 'github', 'origin' => 'https://github.com'], $this->context())->isError, implode(' ', $args));
        }

        // -u of push and add sets the upstream or stages tracked files: nothing runs, the agent may use it
        $this->sandbox->calls = [];
        foreach ([['push', '-u', 'origin', 'main'], ['add', '-u']] as $args) {
            $result = $tool->execute(['args' => $args], $this->context());
            self::assertFalse($result->isError, implode(' ', $args) . ': ' . $result->content);
        }
        self::assertCount(2, $this->sandbox->calls);
    }

    public function testAnotherParticipantDoesNotGetTheOwnersSecret(): void {
        $tool = $this->tool();

        $result = $tool->execute(['args' => ['pull'], 'secret' => 'github', 'origin' => 'https://github.com'], $this->context(new Principal(5, PrincipalKind::Human, Role::user(), null)));

        self::assertTrue($result->isError);
        self::assertSame([], $this->sandbox->calls);
    }

    public function testTheOriginMustBeHttps(): void {
        $tool = $this->tool();

        self::assertTrue($tool->execute(['args' => ['pull'], 'secret' => 'github', 'origin' => 'http://github.com'], $this->context())->isError);
        self::assertTrue($tool->execute(['args' => ['pull'], 'secret' => 'github'], $this->context())->isError, 'a secret needs an origin');
    }

    public function testASecretAlwaysWaitsForTheOwnerAndTheGrantNamesTheSecretAndTheOrigin(): void {
        $tool = $this->tool();
        $read = ['args' => ['ls-remote', 'https://evil.example/x.git'], 'secret' => 'github', 'origin' => 'https://evil.example'];

        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a call with a secret is always a card: the pair secret + address
        self::assertSame(DangerClass::Irreversible, $tool->danger($read), 'a read with a secret is not a read');
        self::assertSame(DangerClass::Irreversible, $tool->danger(['args' => ['fetch'], 'secret' => 'github', 'origin' => 'https://github.com']));
        self::assertSame('git-secret:github https://evil.example', $tool->grantKey($read));
        self::assertSame('git-secret:github https://github.com', $tool->grantKey(['args' => ['push'], 'secret' => 'github', 'origin' => 'https://GitHub.com/opencck/x.git']));
        self::assertStringContainsString('https://evil.example', $tool->describe($read), 'the card shows the host');
    }

    public function testWithASecretOnlyNetworkCommandsRunAndNoSettingIsRevealed(): void {
        $tool = $this->tool();
        $this->vault->put('setting.OPENROUTER_API_KEY', 'token', 'sk-or-v1-secret', $this->owner);
        $with = ['secret' => 'github', 'origin' => 'https://github.com'];

        foreach ([['grep', '-Oenv | base64 > t #', 'x'], ['status'], ['-C', 'repo', 'push'], ['submodule', 'foreach', 'git push'], ['diff', '--output=x']] as $args) {
            self::assertTrue($tool->execute(['args' => $args] + $with, $this->context())->isError, implode(' ', $args));
        }
        self::assertTrue($tool->execute(['args' => ['fetch'], 'secret' => 'setting.OPENROUTER_API_KEY', 'origin' => 'https://github.com'], $this->context())->isError, 'settings of the agent are not tokens');
        self::assertSame([], $this->sandbox->calls);
        foreach ([['clone', 'https://github.com/o/r.git'], ['fetch'], ['pull'], ['push', 'origin', 'main'], ['ls-remote', 'origin']] as $args) {
            self::assertFalse($tool->execute(['args' => $args] + $with, $this->context())->isError, implode(' ', $args));
        }
    }

    public function testDangerFollowsTheCommand(): void {
        $tool = $this->tool();

        self::assertSame(DangerClass::Read, $tool->danger(['args' => ['status']]));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['args' => ['push', 'origin', 'master']]));
        self::assertStringContainsString('git push', $tool->describe(['args' => ['push', 'origin', 'master'], 'secret' => 'github']));
    }
}
