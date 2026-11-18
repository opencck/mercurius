<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Tool\GitTool;
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
        $this->owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
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
        self::assertSame(["exec 1 git 'push' 'origin' 'master' @app t120"], $this->sandbox->calls);
        self::assertSame('1', $this->sandbox->env['GIT_CONFIG_COUNT']);
        self::assertSame('http.https://github.com/.extraheader', $this->sandbox->env['GIT_CONFIG_KEY_0']);
        self::assertSame('Authorization: basic ' . base64_encode('x-access-token:' . self::TOKEN), $this->sandbox->env['GIT_CONFIG_VALUE_0']);
        self::assertSame('0', $this->sandbox->env['GIT_TERMINAL_PROMPT']);
        self::assertStringNotContainsString(self::TOKEN, $result->content, 'what git printed is masked');
        self::assertStringContainsString('remote: ok', $result->content);
    }

    public function testWithoutASecretNothingIsAdded(): void {
        $tool = $this->tool();

        $result = $tool->execute(['args' => ['status', '--short']], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame(["exec 1 git 'status' '--short' @. t120"], $this->sandbox->calls);
        self::assertSame(['GIT_TERMINAL_PROMPT' => '0'], $this->sandbox->env);
    }

    public function testArgumentsAreQuotedAndOptionsThatRunProgramsAreRefused(): void {
        $tool = $this->tool();

        $quoted = $tool->execute(['args' => ['commit', '-m', "it's; env"]], $this->context());
        self::assertFalse($quoted->isError);
        self::assertSame(["exec 1 git 'commit' '-m' 'it'\''s; env' @. t120"], $this->sandbox->calls);

        foreach ([['-c', 'core.sshCommand=sh'], ['fetch', '--upload-pack=sh'], ['push', '--receive-pack', 'x'], ['--exec-path=/tmp', 'status'], ['--config-env=a=B', 'status']] as $args) {
            self::assertTrue($tool->execute(['args' => $args, 'secret' => 'github', 'origin' => 'https://github.com'], $this->context())->isError, implode(' ', $args));
        }
    }

    public function testAnotherParticipantDoesNotGetTheOwnersSecret(): void {
        $tool = $this->tool();

        $result = $tool->execute(['args' => ['pull'], 'secret' => 'github', 'origin' => 'https://github.com'], $this->context(new Principal(5, PrincipalKind::Human, Role::User, null)));

        self::assertTrue($result->isError);
        self::assertSame([], $this->sandbox->calls);
    }

    public function testTheOriginMustBeHttps(): void {
        $tool = $this->tool();

        self::assertTrue($tool->execute(['args' => ['pull'], 'secret' => 'github', 'origin' => 'http://github.com'], $this->context())->isError);
        self::assertTrue($tool->execute(['args' => ['pull'], 'secret' => 'github'], $this->context())->isError, 'a secret needs an origin');
    }

    public function testDangerFollowsTheCommand(): void {
        $tool = $this->tool();

        self::assertSame(DangerClass::Read, $tool->danger(['args' => ['status']]));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['args' => ['push', 'origin', 'master']]));
        self::assertStringContainsString('git push', $tool->describe(['args' => ['push', 'origin', 'master'], 'secret' => 'github']));
    }
}
