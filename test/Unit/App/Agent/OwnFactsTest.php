<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use Mercurius\Memory\App\Audience;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\FakeChannel;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use OpenCCK\Test\Support\InMemoryMemberRepository;

/**
 * A participant approves the facts of their own memory: the card goes to their private chat and their press decides
 * it; another participant cannot, the owner still can. Facts of the owner's memory stay with the owner.
 *
 * spec: brief/participants.md#несколько-пользователей — a participant approves their own facts
 */
final class OwnFactsTest extends AsyncTestCase {
    public function testAParticipantDecidesAProposalOfTheirOwnMemory(): void {
        $principals = new InMemoryMemberRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $vasya = $principals->createVisitor('telegram', '77', 'Вася');
        $principals->decideVisitor($vasya->id, Role::user(), MemberStatus::Active);
        $petya = $principals->createVisitor('telegram', '88', 'Петя');
        $principals->decideVisitor($petya->id, Role::user(), MemberStatus::Active);
        $store = new InMemoryMemoryStore();
        $proposals = new Proposals($store, new TextAnalyzer(), new ContentScreen(), 'k');
        $channel = new FakeChannel(4096);
        $cards = new MemoryCards($proposals, $store, $channel, '42', $principals);
        $router = new CallbackRouter($channel, '42', [MemoryCards::PREFIX => $cards, MemoryCards::FORGET => $cards], openPrefixes: [MemoryCards::PREFIX, MemoryCards::FORGET]);

        // a new fact is written at once; the notice goes to the one whose memory it is, and they may forget it
        $own = $proposals->propose('add', null, 'Вася пьёт чай без сахара', 'сказал сам', [Audience::principal($vasya->id)], $vasya->id, null);
        $cards->show($own);
        self::assertSame('77', $channel->log[0][1] ?? null, 'the notice goes to Vasya, not the owner');
        $factId = (int) $own->factId;
        $router->handleCallback(new IncomingCallback('telegram', 'cb0', '88', '88', 'Петя', '1', sprintf('mf:%d:f', $factId), 'u0'));
        self::assertNotNull($store->fact($factId), 'another participant cannot forget it');
        $router->handleCallback(new IncomingCallback('telegram', 'cb1', '77', '77', 'Вася', '1', sprintf('mf:%d:f', $factId), 'u1'));
        self::assertNull($store->fact($factId), 'Vasya forgot it');

        // a change of a fact still waits for a decision: Vasya's own, not Petya's
        $fact = $store->addFact(Audience::principal($vasya->id), 'knowledge', 'Вася живёт в Москве', ['моск'], 0.6, 'agent', null);
        $change = $proposals->propose('change', $fact->id, 'Вася живёт в Казани', 'переехал', [Audience::principal($vasya->id)], $vasya->id, null);
        $cards->show($change);
        $router->handleCallback(new IncomingCallback('telegram', 'cb2', '88', '88', 'Петя', '2', sprintf('mp:%d:a', $change->id), 'u2'));
        self::assertSame('pending', $store->proposal($change->id)?->status, 'another participant cannot decide it');
        $router->handleCallback(new IncomingCallback('telegram', 'cb3', '77', '77', 'Вася', '2', sprintf('mp:%d:a', $change->id), 'u3'));
        self::assertNotContains($change->id, array_map(static fn($p): int => $p->id, $store->pending()), 'Vasya decided it');

        $ownersFact = $store->addFact(Audience::OWNER, 'knowledge', 'Владелец живёт в Паттайе', ['патт'], 0.6, 'agent', null);
        $owners = $proposals->propose('change', $ownersFact->id, 'Владелец живёт в Бангкоке', 'сказал сам', [Audience::OWNER], $owner->id, null);
        $cards->show($owners);
        self::assertSame('42', (array_slice($channel->log, -1)[0] ?? ['', '', ''])[1], 'the owner\'s facts stay with the owner');
        $router->handleCallback(new IncomingCallback('telegram', 'cb4', '77', '77', 'Вася', '3', sprintf('mp:%d:a', $owners->id), 'u4'));
        self::assertSame('pending', $store->proposal($owners->id)?->status);
    }
}
