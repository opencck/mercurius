<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Improve;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\Tool\Self\ImprovementProposeTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Improve\GateVerdict;
use OpenCCK\App\Improve\Improvements;
use OpenCCK\App\Improve\PromptNotes;
use OpenCCK\App\Service\PromptCommands;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryImprovementRepository;

/**
 * Self-improvement under the owner: the agent proposes, the owner accepts or rejects on a card; a change of the prompt
 * notes is taken only when the evaluation set shows no category worse than the noise; versions stay, a rollback
 * moves the pointer back.
 *
 * spec: brief/self-improvement.md#самоулучшение
 */
final class ImprovementsTest extends AsyncTestCase {
    private InMemoryImprovementRepository $store;
    private FakeChannel $channel;
    private PromptNotes $notes;
    private Member $owner;
    /** @var list<array{string, string}> what the gate was asked to compare: [current, candidate] */
    private array $gated = [];
    private ?GateVerdict $verdict = null;
    /** @var list<array{string, string}> */
    private array $settingsSet = [];

    protected function setUp(): void {
        parent::setUp();
        $this->store = new InMemoryImprovementRepository();
        $this->channel = new FakeChannel(4096);
        $this->notes = new PromptNotes($this->store);
        $this->owner = new Member(1, MemberKind::Human, Role::owner(), 'Owner');
    }

    private function improvements(): Improvements {
        return new Improvements(
            $this->store,
            $this->notes,
            $this->channel,
            '42',
            function (string $current, string $candidate): GateVerdict {
                $this->gated[] = [$current, $candidate];
                return $this->verdict ?? new GateVerdict(true, 'всё ровно', ['overall' => 0.9]);
            },
            function (string $key, string $value): string {
                $this->settingsSet[] = [$key, $value];
                return 'сохранено';
            },
        );
    }

    private function press(Improvements $improvements, int $id, string $answer): void {
        $improvements->handleCallback(new IncomingCallback('telegram', 'cb' . $id . $answer, '42', '42', 'Owner', '1', sprintf('im:%d:%s', $id, $answer), 'u' . $id . $answer));
    }

    public function testAProposalIsACardAndARejectionChangesNothing(): void {
        $improvements = $this->improvements();
        $tool = new ImprovementProposeTool($improvements);

        $result = $tool->execute(['kind' => 'prompt', 'title' => 'Короче отчёты', 'text' => 'Отчёт о задании — не длиннее пяти строк.', 'reason' => 'в трассах отчёты по 40 строк'], new ToolContext(1, 1, $this->owner, new NullCancellation()));

        self::assertFalse($result->isError, $result->content);
        $card = $this->channel->texts('send')[0] ?? '';
        self::assertStringContainsString('Короче отчёты', $card);
        self::assertStringContainsString('в трассах отчёты по 40 строк', $card);
        $this->press($improvements, 1, 'r');
        self::assertSame('', $this->notes->active());
        self::assertSame([], $this->gated, 'a rejection runs no evaluation');
        self::assertSame('rejected', $this->store->find(1)['status'] ?? null);
    }

    public function testAnAcceptedPromptChangePassesTheGateAndBecomesTheActiveVersion(): void {
        $improvements = $this->improvements();
        $id = $improvements->propose('prompt', 'Короче отчёты', 'Отчёт — не длиннее пяти строк.', 'длинные отчёты');

        $this->press($improvements, $id, 'a');

        self::assertSame([['', 'Отчёт — не длиннее пяти строк.']], $this->gated);
        self::assertSame('Отчёт — не длиннее пяти строк.', $this->notes->active());
        self::assertSame('accepted', $this->store->find($id)['status'] ?? null);
        self::assertStringContainsString('всё ровно', implode("\n", $this->channel->texts('send')), 'the owner sees the evaluation');
    }

    public function testAWorseCategoryKeepsTheOldVersion(): void {
        $improvements = $this->improvements();
        $id = $improvements->propose('prompt', 'Смелее', 'Делай без подтверждений.', 'быстрее');
        $this->verdict = new GateVerdict(false, 'безопасность: 0,91 → 0,40', ['overall' => 0.7]);

        $this->press($improvements, $id, 'a');

        self::assertSame('', $this->notes->active());
        self::assertSame('failed', $this->store->find($id)['status'] ?? null);
        self::assertStringContainsString('0,40', implode("\n", $this->channel->texts('send')));
    }

    public function testASettingIsSetOnlyWhenTheOwnerAccepts(): void {
        $improvements = $this->improvements();
        $id = $improvements->propose('setting', 'Дешевле сжатие', 'openai/gpt-5.6-luna', 'сжатие дорогое', 'LLM_CHEAP_MODEL');
        self::assertCount(0, $this->settingsSet);

        $this->press($improvements, $id, 'a');

        self::assertSame([['LLM_CHEAP_MODEL', 'openai/gpt-5.6-luna']], $this->settingsSet);
        self::assertSame([], $this->gated);
    }

    /** @return array{string, string}|null the setting set last */
    private function lastSetting(): ?array {
        return $this->settingsSet === [] ? null : $this->settingsSet[count($this->settingsSet) - 1];
    }

    public function testTheOwnerAllowsAKindOfChangeForGood(): void {
        /** @var \ArrayObject<int, string> $grants */
        $grants = new \ArrayObject();
        $improvements = new Improvements(
            $this->store,
            $this->notes,
            $this->channel,
            '42',
            fn(string $current, string $candidate): GateVerdict => new GateVerdict(true, 'ок', ['overall' => 0.9]),
            function (string $key, string $value): string {
                $this->settingsSet[] = [$key, $value];
                return 'сохранено';
            },
            granted: static fn(string $key): bool => in_array($key, $grants->getArrayCopy(), true),
            grant: static function (string $key) use ($grants): void {
                $grants->append($key);
            },
        );
        $first = $improvements->propose('setting', 'Дешевле сжатие', 'a/one', 'дорого', 'LLM_CHEAP_MODEL');
        $buttons = array_map(static fn($b): string => $b->data, (array_values(array_filter($this->channel->buttons))[0] ?? [[]])[0]);
        self::assertContains(sprintf('im:%d:g', $first), $buttons, 'the card offers to allow this kind for good');

        // spec: brief/self-improvement.md#самоизменение-с-разрешением-навсегда — self-modification may be allowed for good
        $this->press($improvements, $first, 'g');
        self::assertSame(['self-improve:setting'], $grants->getArrayCopy());
        self::assertSame(['LLM_CHEAP_MODEL', 'a/one'], $this->lastSetting());

        $cards = count(array_filter($this->channel->buttons));
        $improvements->propose('setting', 'Ещё дешевле', 'a/two', 'ещё дороже', 'LLM_CHEAP_MODEL');
        self::assertSame(['LLM_CHEAP_MODEL', 'a/two'], $this->lastSetting(), 'the next one goes by the grant');
        self::assertSame($cards, count(array_filter($this->channel->buttons)), 'with a notice, not a card');
    }

    public function testAnUndecidedProposalIsShownAgain(): void {
        $improvements = $this->improvements();
        $improvements->propose('idea', 'Первая', 'текст', 'почему');
        $second = $improvements->propose('idea', 'Вторая', 'текст', 'почему');
        $this->press($improvements, $second, 'r');

        self::assertSame(1, $improvements->showOpen());
        self::assertStringContainsString('Первая', (array_slice($this->channel->texts('send'), -1)[0] ?? ''), 'the card comes again with its buttons');
    }

    public function testVersionsStayAndARollbackMovesThePointer(): void {
        $first = $this->notes->add('Правило один.', 'первое');
        $this->notes->activate($first);
        $second = $this->notes->add('Правило два.', 'второе');
        $this->notes->activate($second);
        self::assertSame('Правило два.', $this->notes->active());

        $commands = new PromptCommands($this->notes);
        self::assertStringContainsString('v' . $second, $commands->handle(''));
        $commands->handle('rollback ' . $first);
        self::assertSame('Правило один.', $this->notes->active(), 'the earlier version is back as it was');
        self::assertCount(2, $this->store->notes(10), 'no version is lost');
        $commands->handle('off');
        self::assertSame('', $this->notes->active());
    }

    public function testTheReportComparesTheScoresOfTwoVersions(): void {
        // spec: brief/self-improvement.md#самоулучшение — the report compares versions category by category
        $first = $this->notes->add('Правило один.', 'первое');
        $scores = new \OpenCCK\Test\Support\InMemoryEvalScoreRepository();
        $commands = new PromptCommands($this->notes, $scores);
        self::assertStringContainsString(\Mercurius\I18n\t('improve.scores.none'), $commands->handle('scores'));

        $scores->record(\OpenCCK\App\Improve\PromptNotes::versionKey(''), 'agent/model', ['safety' => ['mean' => 0.8, 'se' => 0.02, 'n' => 9], 'tools' => ['mean' => 0.6, 'se' => 0.05, 'n' => 9]]);
        $scores->record(\OpenCCK\App\Improve\PromptNotes::versionKey('Правило один.'), 'agent/model', ['safety' => ['mean' => 0.85, 'se' => 0.02, 'n' => 9], 'tools' => ['mean' => 0.55, 'se' => 0.05, 'n' => 9]]);

        $report = $commands->handle('scores');
        self::assertStringContainsString('v' . $first, $report, 'a version known by its text is named');
        self::assertStringContainsString('safety: 0.80 → 0.85', $report);
        self::assertStringContainsString('tools: 0.60 → 0.55', $report);
    }

    public function testAProposalLeftInCheckingByARestartWaitsForTheOwnerAgain(): void {
        // spec: brief/self-improvement.md#самоулучшение — a restart in the middle of the gate leaves nothing in checking
        $id = $this->store->propose('prompt', 'Короче', 'Отвечай короче.', 'длинные ответы', null);
        $this->store->move($id, ['proposed'], 'checking');

        self::assertSame(1, $this->improvements()->recover());

        self::assertSame('proposed', $this->store->find($id)['status'] ?? null);
        self::assertStringContainsString('#' . $id, implode("\n", $this->channel->texts('send')), 'the owner is told and gets the card again');
        self::assertSame(0, $this->improvements()->recover());
    }

    public function testARejectedTitleIsNotProposedAgainWordForWord(): void {
        // spec: brief/self-improvement.md#самоулучшение — the agent sees what was refused and does not repeat it
        $id = $this->store->propose('prompt', 'Короче отвечать', 'Отвечай короче.', 'длинные ответы', null);
        $this->store->move($id, ['proposed'], 'rejected');
        $tool = new \OpenCCK\App\Agent\Tool\Self\ImprovementProposeTool($this->improvements());
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $this->owner, new \Amp\NullCancellation());

        $again = $tool->execute(['kind' => 'prompt', 'title' => ' короче  ОТВЕЧАТЬ ', 'text' => 'Отвечай короче.', 'reason' => 'снова'], $context);

        self::assertTrue($again->isError);
        self::assertStringContainsString('Короче отвечать', $again->content, 'the refused titles are shown');
        self::assertFalse($tool->execute(['kind' => 'prompt', 'title' => 'Списки вместо абзацев', 'text' => 'Пиши списками.', 'reason' => 'читать легче'], $context)->isError);
    }

    public function testOnlyTheOwnerProposesAndASecretIsNotAProposal(): void {
        $tool = new ImprovementProposeTool($this->improvements());
        $user = new Member(5, MemberKind::Human, Role::user(), 'Вася');

        self::assertTrue($tool->execute(['kind' => 'idea', 'title' => 'x', 'text' => 'y', 'reason' => 'z'], new ToolContext(1, 1, $user, new NullCancellation()))->isError);
        self::assertTrue($tool->execute(['kind' => 'prompt', 'title' => 'x', 'text' => 'token=ghp_abcdefghijklmnopqrstuvwxyz0123', 'reason' => 'z'], new ToolContext(1, 1, $this->owner, new NullCancellation()))->isError);
        self::assertTrue($tool->execute(['kind' => 'setting', 'title' => 'x', 'text' => 'v', 'reason' => 'z', 'key' => 'OPENROUTER_API_KEY'], new ToolContext(1, 1, $this->owner, new NullCancellation()))->isError, 'a secret setting is not proposed');
    }
}
