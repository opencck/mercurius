<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\NullCancellation;
use Closure;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\Channel;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\Sandbox\SshKeyTool;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Domain\Ssh\SshKey;

use function Mercurius\I18n\t;

/**
 * `/sshkey add <name>` with a key file: the file goes straight into ~/.ssh of the participant's sandbox, past the model
 * and the history of the conversation. A private key gets rights 600 and its public part next to it when it has no
 * passphrase; a public key file is that public part.
 *
 * spec: brief/servers.md#ключи-ssh-в-песочнице
 */
final class SshKeyCommands {
    /** a key file is a few kilobytes; more is not a key */
    public const MAX_BYTES = 16384;
    private const PRIVATE = '~^-----BEGIN [A-Z ]*PRIVATE KEY-----~';
    private const PUBLIC = '~^(ssh-[a-z0-9-]+|ecdsa-sha2-[a-z0-9-]+|sk-[a-z0-9@.-]+) [A-Za-z0-9+/=]+~';

    /** @param Closure(Member): ?SandboxLimits $limits the sandbox of a participant; null — none */
    public function __construct(
        private readonly Channel $channel,
        private readonly Sandbox $sandbox,
        private readonly SshKeyRepository $keys,
        private readonly Closure $limits,
    ) {}

    public function add(Member $who, string $name, ?Attachment $file): string {
        $name = trim($name);
        if ($file === null) {
            return t('sshkey.usage');
        }
        if (preg_match(SshKey::NAME_PATTERN, $name) !== 1) {
            return t('tool.ssh_key.bad_name');
        }
        $limits = ($this->limits)($who);
        if ($limits === null) {
            return t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika');
        }
        if ($file->size !== null && $file->size > self::MAX_BYTES) {
            return t('sshkey.not_a_key');
        }
        $bytes = $this->channel->download($file->fileId, self::MAX_BYTES);
        if ($bytes === null) {
            return t('sshkey.not_a_key');
        }
        $bytes = str_replace("\r\n", "\n", $bytes);
        $private = preg_match(self::PRIVATE, $bytes) === 1;
        if (!$private && preg_match(self::PUBLIC, $bytes) !== 1) {
            return t('sshkey.not_a_key');
        }
        $path = '.ssh/' . $name . ($private ? '' : '.pub');
        $quoted = "'" . $path . "'";
        $cancellation = new NullCancellation();
        try {
            $this->sandbox->exec($who->id, $limits, 'sshkey', 'mkdir -p .ssh && chmod 700 .ssh', '.', 30, $cancellation);
            $this->sandbox->put($who->id, $limits, $path, $bytes, $cancellation);
            // a key without a passphrase gives its public part; one under a passphrase waits for its .pub
            $after = $private
                ? 'chmod 600 ' . $quoted . " && { [ -f '.ssh/" . $name . ".pub' ] || ssh-keygen -y -P '' -f " . $quoted . " > '.ssh/" . $name . ".pub' 2>/dev/null || rm -f '.ssh/" . $name . ".pub'; }"
                : 'chmod 644 ' . $quoted;
            $this->sandbox->exec($who->id, $limits, 'sshkey', $after, '.', 30, $cancellation);
            $outcome = $this->sandbox->exec($who->id, $limits, 'sshkey', SshKeyTool::LIST, '.', 30, $cancellation);
        } catch (SandboxFailure $e) {
            return sprintf(t('sshkey.failed'), $e->getMessage());
        }
        foreach (SshKeyTool::parse($who->id, $outcome->stdout) as $key) {
            if ($key->name === $name) {
                $this->keys->save($key);
                return sprintf(t('sshkey.added'), $name, $key->fingerprint);
            }
        }
        return sprintf(t($private ? 'sshkey.needs_public' : 'sshkey.needs_private'), $name, $name);
    }
}
