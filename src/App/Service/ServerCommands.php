<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use SensitiveParameter;

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
            return $this->vault->remove($name, $principal) ? sprintf('Секрет «%s» удалён.', $name) : sprintf('Секрета «%s» нет или он не ваш.', $name);
        }
        $parts = preg_split('~\s+~', $argument, 2) ?: [];
        if (count($parts) < 2 || $parts[1] === '') {
            return 'Формат: /secret <имя> <значение>. Сообщение с секретом я удалю из чата.';
        }
        [$name, $value] = $parts;
        $kind = str_starts_with($value, '-----BEGIN') ? 'private_key' : 'password';
        try {
            $this->vault->put($name, $kind, $value, $principal);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        return sprintf('Секрет «%s» сохранён (%s). Сообщение с ним удаляю из чата.', $name, $kind === 'private_key' ? 'закрытый ключ' : 'пароль');
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
            return 'Хранилище секретов выключено: в .env нет SECRETS_KEY.';
        }
        $names = $this->vault->names($principal);
        return $names === [] ? 'Секретов нет. /secret <имя> <значение> — сохранить.' : "Секреты (только имена):\n" . implode("\n", array_map(static fn(array $s): string => sprintf('%s — %s', $s['name'], $s['kind']), $names));
    }

    public function servers(): string {
        $all = $this->servers->all();
        if ($all === []) {
            return 'Серверов в реестре нет. Попросите меня добавить сервер (server_add), секрет для входа — /secret.';
        }
        return "Серверы:\n" . implode("\n", array_map(static fn(Server $s): string => sprintf(
            '%s — %s@%s (%s)%s',
            $s->name,
            $s->username,
            $s->address(),
            $s->group,
            $s->hostKey === null ? ', ключ хоста не подтверждён' : '',
        ), $all));
    }

    public function audit(): string {
        $broken = $this->audit->verify();
        $lines = array_map(
            static fn(array $r): string => sprintf('#%d %s %s [%s, %s%s] %s', $r['id'], $r['at'], $r['server'], $r['class'], $r['event'], $r['exit'] === null ? '' : ', код ' . $r['exit'], mb_substr(SecretMasker::mask($r['command']), 0, 200)),
            $this->audit->recent(self::AUDIT),
        );
        $chain = $broken === null ? 'Цепочка журнала цела.' : sprintf('⚠ Цепочка журнала нарушена на записи #%d.', $broken);
        return $lines === [] ? "Журнал команд пуст.\n" . $chain : "Последние команды:\n" . implode("\n", $lines) . "\n\n" . $chain;
    }
}
