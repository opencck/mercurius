<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\App\Agent\Tool\Messaging\ChannelPostTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryChannelRepository;

/**
 * Posts in a channel: what is published is seen; publishing, editing and deleting wait for the owner's card.
 */
final class ChannelPostToolTest extends AsyncTestCase {
    private FakeChannel $chat;
    private InMemoryChannelRepository $channels;
    private ChannelPostTool $tool;
    private ToolContext $context;

    protected function setUp(): void {
        parent::setUp();
        $this->chat = new FakeChannel();
        $this->channels = new InMemoryChannelRepository();
        $this->channels->save(new PublicChannel('telegram', '-1002', 'Блог', true, true, false, true));
        $this->channels->save(new PublicChannel('telegram', '-1003', 'Старый', false, false, false, false));
        $this->tool = new ChannelPostTool($this->chat, $this->channels);
        $this->context = new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation());
    }

    public function testChangesAreIrreversibleAndGrantedPerActionAndChannel(): void {
        self::assertSame(DangerClass::Read, $this->tool->danger(['action' => 'history']));
        self::assertSame(DangerClass::Irreversible, $this->tool->danger(['action' => 'publish']));
        self::assertSame(DangerClass::Irreversible, $this->tool->danger(['action' => 'delete']));
        self::assertSame('channel:publish:-1002', $this->tool->grantKey(['action' => 'publish', 'channel' => 'блог', 'text' => 'x']));
        self::assertSame('channel:delete:-1002', $this->tool->grantKey(['action' => 'delete', 'channel' => '-1002', 'message_id' => 5]));
        $card = $this->tool->describe(['action' => 'publish', 'channel' => 'Блог', 'text' => "Первая строка\nВторая строка"]);
        self::assertStringContainsString('«Блог»', $card);
        self::assertStringContainsString("Первая строка\nВторая строка", $card, 'the card shows the whole post');
    }

    public function testAPublishedPostGoesToTheChannelAndItsHistory(): void {
        $result = $this->tool->execute(['action' => 'publish', 'text' => 'Привет, подписчики'], $this->context);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['send', '-1002', 'Привет, подписчики']], $this->chat->log, 'the only available channel is taken');
        self::assertSame('Привет, подписчики', $this->channels->posts('telegram', '-1002', 5)[0]->text ?? null);
        $edit = $this->tool->execute(['action' => 'edit', 'channel' => 'Блог', 'message_id' => 100, 'text' => 'Привет всем'], $this->context);
        self::assertFalse($edit->isError, $edit->content);
        self::assertSame('Привет всем', $this->channels->post('telegram', '-1002', '100')?->text);
        self::assertStringContainsString('Привет всем', $this->tool->execute(['action' => 'history', 'channel' => 'Блог'], $this->context)->content);
    }

    public function testWithoutTheRightOrTheChannelNothingIsSent(): void {
        self::assertTrue($this->tool->execute(['action' => 'delete', 'channel' => 'Блог', 'message_id' => 5], $this->context)->isError, 'no right to delete');
        self::assertTrue($this->tool->execute(['action' => 'publish', 'channel' => 'Старый', 'text' => 'x'], $this->context)->isError, 'not an admin there any more');
        self::assertTrue($this->tool->execute(['action' => 'publish', 'channel' => 'Нет такого', 'text' => 'x'], $this->context)->isError);
        self::assertTrue($this->tool->execute(['action' => 'publish', 'channel' => 'Блог', 'text' => 'ключ sk-or-v1-' . str_repeat('a', 40)], $this->context)->isError, 'no secrets in a post');
        self::assertSame([], $this->chat->log);
        $list = $this->tool->execute(['action' => 'list'], $this->context)->content;
        self::assertStringContainsString('«Блог» (-1002): публикация, правка', $list);
        self::assertStringContainsString('«Старый» (-1003): недоступен', $list);
    }
}
