<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Sandbox;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxEnv;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Domain\Ssh\SshKey;

use function Mercurius\I18n\t;

/**
 * Keys of the participant's ~/.ssh: a new ed25519 pair, the list with fingerprints, a public key, deleting, the secret
 * with a key's passphrase. The private key is made and stays in the sandbox; only its public part and fingerprint come
 * out and are kept in the account. A key put into ~/.ssh by hand joins the account at the next list.
 *
 * spec: brief/servers.md#ключи-ssh-в-песочнице
 * No counterpart in the references: hermes runs on the user's own machine and uses the keys already there.
 */
final class SshKeyTool extends SandboxTool implements GrantKeyed {
    public const NAME = 'ssh_key';
    private const ACTIONS = ['list', 'create', 'show', 'delete', 'passphrase'];
    private const TIMEOUT = 30;
    /** one line per key: KEY, name, fingerprint, public key */
    public const LIST = 'for p in .ssh/*.pub; do [ -f "$p" ] || continue; n=${p#.ssh/}; n=${n%.pub}; [ -f ".ssh/$n" ] || continue; '
        . 'f=$(ssh-keygen -lf "$p" 2>/dev/null | cut -d" " -f2); [ -n "$f" ] || continue; '
        . 'printf "KEY\t%s\t%s\t%s\n" "$n" "$f" "$(head -c 4000 "$p" | tr -d "\n")"; done';

    public function __construct(
        Sandbox $sandbox,
        private readonly SandboxEnv $env,
        private readonly SshKeyRepository $keys,
        private readonly Vault $vault,
    ) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.ssh_key.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'name' => ['type' => 'string', 'maxLength' => 32, 'description' => t('tool.ssh_key.name')],
                'comment' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.ssh_key.comment')],
                'secret' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.ssh_key.secret')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return match (Args::string($args, 'action')) {
            'list', 'show' => DangerClass::Read,
            'delete' => DangerClass::Irreversible,
            default => DangerClass::Write,
        };
    }

    public function grantKey(array $args): ?string {
        $action = Args::string($args, 'action') ?? '';
        return in_array($action, ['create', 'passphrase'], true) ? 'ssh-key:' . $action : null;
    }

    public function describe(array $args): string {
        return trim(sprintf('ssh_key %s %s', Args::string($args, 'action') ?? '', Args::string($args, 'name') ?? ''));
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        $holder = $context->principal->id;
        if ($action === 'list') {
            return $this->listed($this->scan($holder, $limits, $context));
        }
        $name = Args::string($args, 'name') ?? '';
        if (preg_match(SshKey::NAME_PATTERN, $name) !== 1) {
            return ToolResult::error(t('tool.ssh_key.bad_name'));
        }
        return match ($action) {
            'create' => $this->create($holder, $name, Args::string($args, 'comment') ?? 'mercurius', $limits, $context),
            'show' => $this->show($holder, $name, $limits, $context),
            'delete' => $this->delete($holder, $name, $limits, $context),
            'passphrase' => $this->passphrase($holder, $name, Args::string($args, 'secret'), $context),
            default => ToolResult::error(t('tool.ssh_key.bad_action')),
        };
    }

    /**
     * The keys of ~/.ssh as they are now; the account follows them.
     *
     * @return list<SshKey>
     */
    private function scan(int $holder, SandboxLimits $limits, ToolContext $context): array {
        $outcome = $this->env->execEnv($holder, $limits, $context->callId, self::LIST, '.', self::TIMEOUT, [], $context->cancellation);
        $found = [];
        foreach (self::parse($holder, $outcome->stdout) as $key) {
            $this->keys->save($key);
            $found[$key->name] = true;
        }
        foreach ($this->keys->of($holder) as $kept) {
            if (!isset($found[$kept->name])) {
                $this->keys->remove($holder, $kept->name);
            }
        }
        return $this->keys->of($holder);
    }

    /** @return list<SshKey> */
    public static function parse(int $holder, string $stdout): array {
        $keys = [];
        foreach (explode("\n", $stdout) as $line) {
            $parts = explode("\t", trim($line, "\r"));
            if (count($parts) === 4 && $parts[0] === 'KEY' && preg_match(SshKey::NAME_PATTERN, $parts[1]) === 1 && str_starts_with($parts[2], 'SHA256:')) {
                $keys[] = new SshKey($holder, $parts[1], $parts[2], mb_substr($parts[3], 0, 4000));
            }
        }
        return $keys;
    }

    /** @param list<SshKey> $keys */
    private function listed(array $keys): ToolResult {
        if ($keys === []) {
            return new ToolResult(t('tool.ssh_key.none'));
        }
        $lines = [];
        foreach ($keys as $key) {
            $lines[] = sprintf('%s — %s%s', $key->name, $key->fingerprint, $key->passphraseSecret === null ? '' : sprintf(t('tool.ssh_key.with_passphrase'), $key->passphraseSecret));
        }
        return new ToolResult(implode("\n", $lines));
    }

    private function create(int $holder, string $name, string $comment, SandboxLimits $limits, ToolContext $context): ToolResult {
        $comment = preg_replace('~[^A-Za-z0-9@._-]~', '', $comment) ?? '';
        $file = self::quote('.ssh/' . $name);
        $command = 'mkdir -p .ssh && chmod 700 .ssh && '
            . 'if [ -e ' . $file . ' ]; then echo exists >&2; exit 3; fi && '
            . "ssh-keygen -q -t ed25519 -N '' -C " . self::quote($comment === '' ? 'mercurius' : $comment) . ' -f ' . $file . ' && '
            . 'chmod 600 ' . $file . ' && '
            . 'printf "KEY\t%s\t%s\t%s\n" ' . self::quote($name) . ' "$(ssh-keygen -lf ' . self::quote('.ssh/' . $name . '.pub') . ' | cut -d" " -f2)" "$(tr -d "\n" < ' . self::quote('.ssh/' . $name . '.pub') . ')"';
        $outcome = $this->env->execEnv($holder, $limits, $context->callId, $command, '.', self::TIMEOUT, [], $context->cancellation);
        if ($outcome->exitCode === 3) {
            return ToolResult::error(sprintf(t('tool.ssh_key.exists'), $name));
        }
        $key = self::parse($holder, $outcome->stdout)[0] ?? null;
        if ($outcome->exitCode !== 0 || $key === null) {
            return ToolResult::error(sprintf(t('tool.ssh_key.not_made'), trim(mb_substr($outcome->stderr, 0, 300))));
        }
        $this->keys->save($key);
        return new ToolResult(sprintf(t('tool.ssh_key.made'), $name, $key->fingerprint, $key->publicKey));
    }

    private function show(int $holder, string $name, SandboxLimits $limits, ToolContext $context): ToolResult {
        foreach ($this->scan($holder, $limits, $context) as $key) {
            if ($key->name === $name) {
                return new ToolResult(sprintf(t('tool.ssh_key.shown'), $name, $key->fingerprint, $key->publicKey));
            }
        }
        return ToolResult::error(sprintf(t('tool.ssh_key.no_key'), $name));
    }

    private function delete(int $holder, string $name, SandboxLimits $limits, ToolContext $context): ToolResult {
        $outcome = $this->env->execEnv($holder, $limits, $context->callId, 'rm -f ' . self::quote('.ssh/' . $name) . ' ' . self::quote('.ssh/' . $name . '.pub'), '.', self::TIMEOUT, [], $context->cancellation);
        if ($outcome->exitCode !== 0) {
            return ToolResult::error(sprintf(t('tool.ssh_key.not_deleted'), trim(mb_substr($outcome->stderr, 0, 300))));
        }
        $this->keys->remove($holder, $name);
        return new ToolResult(sprintf(t('tool.ssh_key.deleted'), $name));
    }

    private function passphrase(int $holder, string $name, ?string $secret, ToolContext $context): ToolResult {
        $secret = $secret === null || trim($secret) === '' ? null : trim($secret);
        if ($secret !== null && $this->vault->reveal($secret, $context->principal) === null) {
            return ToolResult::error(sprintf(t('tool.ssh_key.no_secret'), $secret, $secret));
        }
        if (!$this->keys->setPassphrase($holder, $name, $secret)) {
            return ToolResult::error(sprintf(t('tool.ssh_key.no_key'), $name));
        }
        return new ToolResult($secret === null ? sprintf(t('tool.ssh_key.passphrase_off'), $name) : sprintf(t('tool.ssh_key.passphrase_set'), $name, $secret));
    }

    private static function quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
