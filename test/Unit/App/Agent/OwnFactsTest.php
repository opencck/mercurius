<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Memory\App\Audience;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\ContentScreen;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryMemoryStore;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

/**
 * A participant approves the facts of their own memory: the card goes to their private chat and their press decides
 * it; another participant cannot, the owner still can. Facts of the owner's memory stay with the owner.
 *
 * spec: BRIEF.md#несколько-пользователей — a participant approves their own facts
 */
final class OwnFactsTest extends AsyncTestCase {
    public function testAParticipantDecidesAProposalOfTheirOwnMemory(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $vasya = $principals->createVisitor('telegram', '77', 'Вася');
        $principals->decideVisitor($vasya->id, Role::User, PrincipalStatus::Active);
        $petya = $principals->createVisitor('telegram', '88', 'Петя');
        $principals->decideVisitor($petya->id, Role::User, PrincipalStatus::Active);
        $store = new InMemoryMemoryStore();
        $proposals = new Proposals($store, new TextAnalyzer(), new ContentScreen(), 'k');
        $channel = new FakeChannel(4096);
        $cards = new MemoryCards($proposals, $store, $channel, '42', $principals);
        $router = new CallbackRouter($channel, '42', [MemoryCards::PREFIX => $cards], openPrefixes: [MemoryCards::PREFIX]);

        $own = $proposals->propose('add', null, 'Вася пьёт чай без сахара', 'сказал сам', [Audience::principal($vasya->id)], $vasya->id, null);
        $cards->show($own);
        self::assertSame('77', $channel->log[0][1] ?? null, 'the card goes to Vasya, not the owner');

        $router->handleCallback(new IncomingCallback('telegram', 'cb1', '88', '88', 'Петя', '1', sprintf('mp:%d:a', $own->id), 'u1'));
        self::assertSame('pending', $store->proposal($own->id)?->status, 'another participant cannot decide it');

        $router->handleCallback(new IncomingCallback('telegram', 'cb2', '77', '77', 'Вася', '1', sprintf('mp:%d:a', $own->id), 'u2'));
        self::assertNotContains($own->id, array_map(static fn($p): int => $p->id, $store->pending()), 'Vasya decided it');

        $owners = $proposals->propose('add', null, 'Владелец живёт в Паттайе', 'сказал сам', [Audience::OWNER], $owner->id, null);
        $cards->show($owners);
        self::assertSame('42', (array_slice($channel->log, -1)[0] ?? ['', '', ''])[1],'the owner\'s facts stay with the owner');
        $router->handleCallback(new IncomingCallback('telegram', 'cb3', '77', '77', 'Вася', '2', sprintf('mp:%d:a', $owners->id), 'u3'));
        self::assertSame('pending', $store->proposal($owners->id)?->status);
    }
}
