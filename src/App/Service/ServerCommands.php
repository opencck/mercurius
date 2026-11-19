<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * Commands for secrets, servers and the journal. A secret comes only by command, never through the model; the message
 * that carried it is deleted by the caller.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a secret that appeared in Telegram is deleted from the chat and kept in the vault
 */
final class ServerCommands {
    private const AUDIT = 15;

    public function __construct(
        private readonly Vault $vault,
        private readonly ServerRepository $servers,
        private readonly CommandAuditRepository $audit,
    ) {}

    /** "/secret <name> <value>" stores, "/secret - <name>" removes. */
    public function secret(Principal $principal, #[SensitiveParameter] string $argument): string {
        $argument = trim($argument);
        if (str_starts_with($argument, '- ')) {
            $name = trim(substr($argument, 2));
            return $this->vault->remove($name, $principal) ? sprintf(t('service.server_commands.sekret_udalen'), $name) : sprintf(t('service.server_commands.sekreta_net_ili_on_ne'), $name);
        }
        $parts = preg_split('~\s+~', $argument, 2) ?: [];
        if (count($parts) < 2 || $parts[1] === '') {
            return t('service.server_commands.format_secret_imya_znachenie_soobschenie');
        }
        [$name, $value] = $parts;
        $kind = str_starts_with($value, '-----BEGIN') ? 'private_key' : 'password';
        try {
            $this->vault->put($name, $kind, $value, $principal);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        return sprintf(t('service.server_commands.sekret_sohranen_soobschenie_s_nim'), $name, $kind === 'private_key' ? t('service.server_commands.zakrytyy_klyuch') : t('service.server_commands.parol'));
    }

    /**
     * Keeps the text of a message that carried a secret; returns the name it is kept under, null when it could not be.
     */
    public function stash(Principal $principal, #[SensitiveParameter] string $text, string $messageId): ?string {
        $name = 'msg-' . (preg_replace('~[^A-Za-z0-9]~', '', $messageId) ?? '');
        try {
            $this->vault->put($name, 'token', $text, $principal);
            return $name;
        } catch (DomainException) {
            return null;
        }
    }

    public function secrets(Principal $principal): string {
        if (!$this->vault->enabled()) {
            return t('service.server_commands.hranilische_sekretov_vyklyucheno_v_env');
        }
        $names = $this->vault->names($principal);
        return $names === [] ? t('service.server_commands.sekretov_net_secret_imya_znachenie') : t('service.server_commands.sekrety_tolko_imena') . implode("\n", array_map(static fn(array $s): string => sprintf('%s — %s', $s['name'], $s['kind']), $names));
    }

    /**
     * "/server - <name>" removes a server from the registry; the owner typed it, so no card.
     *
     * spec: BRIEF.md#фаза-7-по-частям — the registry is changed in the dialogue
     */
    public function server(string $argument): string {
        $argument = trim($argument);
        if (!str_starts_with($argument, '- ') || trim(substr($argument, 2)) === '') {
            return t('service.server_commands.server_usage');
        }
        $name = trim(substr($argument, 2));
        return \OpenCCK\App\Agent\Tool\Servers\ServerRemoveTool::remove($this->servers, $name) ?? sprintf(t('service.server_commands.server_removed'), $name);
    }

    public function servers(): string {
        $all = $this->servers->all();
        if ($all === []) {
            return t('service.server_commands.serverov_v_reestre_net_poprosite');
        }
        return t('service.server_commands.servery') . implode("\n", array_map(static fn(Server $s): string => sprintf(
            '%s — %s@%s (%s)%s',
            $s->name,
            $s->username,
            $s->address(),
            $s->group,
            $s->hostKey === null ? t('service.server_commands.klyuch_hosta_ne_podtverzhden') : '',
        ), $all));
    }

    public function audit(): string {
        $broken = $this->audit->verify();
        $lines = array_map(
            static fn(array $r): string => sprintf('#%d %s %s%s [%s, %s%s] %s', $r['id'], $r['at'], $r['server'], $r['who'] === null ? '' : ' (' . $r['who'] . ')', $r['class'], $r['event'], $r['exit'] === null ? '' : t('service.server_commands.kod') . $r['exit'], mb_substr(SecretMasker::mask($r['command']), 0, 200)),
            $this->audit->recent(self::AUDIT),
        );
        $chain = $broken === null ? t('service.server_commands.tsepochka_zhurnala_tsela') : sprintf(t('service.server_commands.tsepochka_zhurnala_narushena_na_zapisi'), $broken);
        return $lines === [] ? t('service.server_commands.zhurnal_komand_pust') . $chain : t('service.server_commands.poslednie_komandy') . implode("\n", $lines) . "\n\n" . $chain;
    }
}
