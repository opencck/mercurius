<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

use Closure;
use DomainException;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Mail\Mailbox;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectLevel;
use OpenCCK\Domain\Repository\MailboxRepository;
use OpenCCK\Domain\Repository\MemberRepository;

use function Mercurius\I18n\t;

/**
 * The mailboxes of the participants: who may connect one, which one a call uses and what the mail server gets to open
 * it. A mailbox belongs to the participant who connected it; given to a project, it serves the chats and the jobs of
 * that project. The password is a secret of the vault, read at the moment of a call and never shown to a model.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class Mailboxes {
    /**
     * @param Closure(int, Member): ?Project $projectOf the project of a conversation, for the one who talks in it
     * @param Closure(Project, Member): ?ProjectLevel $levelOf what the participant may do in the project
     */
    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly Vault $vault,
        private readonly MemberRepository $principals,
        private readonly AddressGuard $addresses,
        private readonly Closure $projectOf,
        private readonly Closure $levelOf,
    ) {}

    /** spec: brief/participants.md#свои-роли — mail is a permission of a role; the owner has every permission */
    public static function may(Member $who): bool {
        return $who->role->may(Permission::Mail);
    }

    /**
     * @throws DomainException with the reason, in words for the participant
     */
    public function add(Member $owner, string $name, string $host, string $user, string $secret, ?int $imapPort = null, ?int $smtpPort = null, ?string $from = null, ?string $authservId = null): Mailbox {
        if (!self::may($owner)) {
            throw new DomainException(t('mail.mailbox.no_power'));
        }
        $name = trim($name);
        $host = strtolower(trim($host));
        $user = trim($user);
        $from = trim($from ?? '') !== '' ? trim((string) $from) : $user;
        $authservId = strtolower(trim($authservId ?? ''));
        $imapPort ??= Mailbox::IMAP_PORTS[0];
        $smtpPort ??= Mailbox::SMTP_PORTS[0];
        if (preg_match(Mailbox::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('mail.mailbox.bad_name'));
        }
        if (preg_match(Mailbox::HOST_PATTERN, $host) !== 1) {
            throw new DomainException(t('mail.mailbox.bad_host'));
        }
        if (!in_array($imapPort, Mailbox::IMAP_PORTS, true) || !in_array($smtpPort, Mailbox::SMTP_PORTS, true)) {
            throw new DomainException(t('mail.mailbox.bad_port'));
        }
        if (preg_match(Mailbox::USER_PATTERN, $user) !== 1) {
            throw new DomainException(t('mail.mailbox.bad_user'));
        }
        if (preg_match(Mailbox::FROM_PATTERN, $from) !== 1) {
            throw new DomainException(t('mail.mailbox.bad_from'));
        }
        if ($authservId !== '' && preg_match(Mailbox::HOST_PATTERN, $authservId) !== 1) {
            throw new DomainException(t('mail.mailbox.bad_authserv'));
        }
        // spec: brief/agent-loop.md#подтверждения-и-запреты — a mailbox is a way out of the internal network: only a public host
        $refusal = $this->addresses->refusal($host);
        if ($refusal !== null) {
            throw new DomainException(sprintf(t('mail.mailbox.host_refused'), $host, $refusal));
        }
        $secretId = $this->vault->id(trim($secret));
        if ($secretId === null || $this->vault->reveal(trim($secret), $owner) === null) {
            throw new DomainException(sprintf(t('mail.mailbox.no_secret'), trim($secret)));
        }
        if (count($this->mailboxes->of($owner->id)) >= Mailbox::MAX_PER_OWNER) {
            throw new DomainException(sprintf(t('mail.mailbox.too_many'), Mailbox::MAX_PER_OWNER));
        }
        return $this->mailboxes->add($owner->id, $name, $host, $imapPort, $smtpPort, $user, $from, $authservId, $secretId);
    }

    /** @return list<Mailbox> the participant's own mailboxes */
    public function own(Member $who): array {
        return $this->mailboxes->of($who->id);
    }

    public function ownByName(Member $who, string $name): ?Mailbox {
        foreach ($this->mailboxes->of($who->id) as $mailbox) {
            if (mb_strtolower($mailbox->name) === mb_strtolower(trim($name))) {
                return $mailbox;
            }
        }
        return null;
    }

    public function remove(Member $who, string $name): bool {
        $mailbox = $this->ownByName($who, $name);
        return $mailbox !== null && $this->mailboxes->remove($mailbox->id);
    }

    /**
     * Gives the mailbox to a project or takes it back (null). Only the owner of the mailbox does it, and only for a
     * project where they may change everything.
     *
     * @throws DomainException
     */
    public function toProject(Member $who, string $name, ?Project $project): Mailbox {
        $mailbox = $this->ownByName($who, $name) ?? throw new DomainException(sprintf(t('mail.mailbox.unknown'), trim($name)));
        if ($project !== null && ($this->levelOf)($project, $who)?->atLeast(ProjectLevel::Full) !== true) {
            throw new DomainException(sprintf(t('mail.mailbox.project_denied'), $project->name));
        }
        return $this->mailboxes->setProject($mailbox->id, $project?->id) ?? throw new DomainException(sprintf(t('mail.mailbox.unknown'), trim($name)));
    }

    /** @return list<Mailbox> the mailboxes of the project of this conversation the participant may use */
    public function ofConversation(Member $who, int $conversationId): array {
        $project = ($this->projectOf)($conversationId, $who);
        if ($project === null || ($this->levelOf)($project, $who)?->atLeast(ProjectLevel::Write) !== true) {
            return [];
        }
        return $this->mailboxes->ofProject($project->id);
    }

    /**
     * The mailbox of a call: the named one; with no name — the mailbox of the project of the conversation, then the
     * participant's own. Several to choose from and no name — the answer lists them: a mailbox is not guessed.
     *
     * @return Mailbox|string the mailbox, or why there is none
     */
    public function pick(Member $who, int $conversationId, ?string $name): Mailbox|string {
        if (!self::may($who)) {
            return t('mail.mailbox.no_power');
        }
        $ofProject = $this->ofConversation($who, $conversationId);
        $own = $this->mailboxes->of($who->id);
        $name = trim($name ?? '');
        if ($name !== '') {
            foreach ([...$ofProject, ...$own] as $mailbox) {
                if (mb_strtolower($mailbox->name) === mb_strtolower($name)) {
                    return $mailbox;
                }
            }
            return sprintf(t('mail.mailbox.unknown'), $name) . self::names([...$ofProject, ...$own]);
        }
        foreach ([$ofProject, $own] as $found) {
            if (count($found) === 1) {
                return $found[0];
            }
            if (count($found) > 1) {
                return t('mail.mailbox.which') . self::names($found);
            }
        }
        return t('mail.mailbox.none');
    }

    /** Whether the participant may use this very mailbox in this conversation: checked again when the call runs. */
    public function usable(Member $who, int $conversationId, int $mailboxId): ?Mailbox {
        if (!self::may($who)) {
            return null;
        }
        foreach ([...$this->ofConversation($who, $conversationId), ...$this->mailboxes->of($who->id)] as $mailbox) {
            if ($mailbox->id === $mailboxId) {
                return $mailbox;
            }
        }
        return null;
    }

    public function find(int $id): ?Mailbox {
        return $this->mailboxes->find($id);
    }

    /** Who decides what leaves the mailbox: the participant who connected it. */
    public function ownerOf(Mailbox $mailbox): ?Member {
        return $this->principals->find($mailbox->ownerId);
    }

    /**
     * What the mail server needs to open the mailbox, as headers of one request.
     *
     * @return array<non-empty-string, string>|string the headers, or why the mailbox cannot be opened
     */
    public function account(Mailbox $mailbox): array|string {
        $owner = $this->ownerOf($mailbox);
        $password = $owner === null ? null : $this->vault->revealById($mailbox->secretId, $owner);
        if ($password === null) {
            return sprintf(t('mail.mailbox.secret_gone'), $mailbox->name);
        }
        $refusal = $this->addresses->refusal($mailbox->host);
        if ($refusal !== null) {
            return sprintf(t('mail.mailbox.host_refused'), $mailbox->host, $refusal);
        }
        $headers = [
            'x-mail-host' => $mailbox->host,
            'x-mail-user' => $mailbox->username,
            'x-mail-password' => $password,
            'x-mail-imap-port' => (string) $mailbox->imapPort,
            'x-mail-smtp-port' => (string) $mailbox->smtpPort,
            'x-mail-from' => $mailbox->from,
        ];
        if ($mailbox->authservId !== '') {
            $headers['x-mail-authserv-id'] = $mailbox->authservId;
        }
        return $headers;
    }

    /** @param list<Mailbox> $mailboxes */
    private static function names(array $mailboxes): string {
        return $mailboxes === [] ? '' : ' ' . sprintf(t('mail.mailbox.available'), implode(', ', array_map(static fn(Mailbox $m): string => $m->name, $mailboxes)));
    }
}
