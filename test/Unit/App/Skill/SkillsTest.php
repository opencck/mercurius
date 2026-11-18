<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Skill;

use Amp\PHPUnit\AsyncTestCase;
use InvalidArgumentException;
use Mercurius\Llm\ToolCall;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\SkillCards;
use OpenCCK\App\Agent\Tool\SkillProposeTool;
use OpenCCK\App\Agent\Tool\SkillViewTool;
use OpenCCK\App\Service\SkillCommands;
use OpenCCK\App\Skill\SkillCatalog;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemorySkillRepository;
use OpenCCK\Test\Support\ScriptedLlm;

final class SkillsTest extends AsyncTestCase {
    private InMemorySkillRepository $repo;
    private SkillCatalog $catalog;

    protected function setUp(): void {
        parent::setUp();
        $this->repo = new InMemorySkillRepository();
        $this->catalog = new SkillCatalog($this->repo);
    }

    private function accepted(string $name, string $description, string $body): void {
        $version = $this->catalog->propose($name, $description, $body, 'нужно', 1, 'owner');
        $this->repo->accept($version->id);
    }

    public function testADraftIsNotUsedUntilTheOwnerAccepts(): void {
        $stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'skill_propose', json_encode(['name' => 'deploy-check', 'description' => 'Проверка после развёртывания', 'body' => "1. docker compose ps\n2. логи", 'reason' => 'повторяется'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))),
            ScriptedLlm::text('Предложил.'),
        ]));
        $cards = new SkillCards($this->repo, $stack->channel, '42');
        $stack->registry->register(new SkillProposeTool($this->catalog, $cards));
        $stack->registry->register(new SkillViewTool($this->catalog));

        $stack->say('сделай скилл проверки после развёртывания');
        $stack->scheduler->awaitIdle();

        self::assertSame('', $this->catalog->index(), 'a draft is not in the index');
        self::assertNull($this->catalog->body('deploy-check'));
        $buttons = array_values(array_filter($stack->channel->buttons, static fn(array $rows): bool => $rows !== []));
        self::assertSame('sk:1:a', $buttons[0][0][0]->data);

        $cards->handleCallback(new IncomingCallback('telegram', 'cb', '42', '42', 'Owner', '5', 'sk:1:a', '9'));

        self::assertStringContainsString('- deploy-check: Проверка после развёртывания', $this->catalog->index());
        self::assertSame("1. docker compose ps\n2. логи", $this->catalog->body('deploy-check'));
    }

    public function testTheIndexIsInANewPromptAndAChangeIsANoteOnce(): void {
        $this->accepted('backup', 'Резервные копии базы', 'шаги');
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('a'), ScriptedLlm::text('b'), ScriptedLlm::text('c')]));
        $stack->scheduler->useSkills($this->catalog);

        $stack->say('привет');
        $stack->scheduler->awaitIdle();
        self::assertStringContainsString('- backup: Резервные копии базы', $stack->llm->requests[0]->messages[0]->content);

        $this->accepted('nginx', 'Настройка nginx', 'шаги');
        $stack->say('ещё');
        $stack->scheduler->awaitIdle();
        $stack->say('и ещё');
        $stack->scheduler->awaitIdle();

        $users = array_values(array_filter($stack->transcript->messages, static fn(array $m): bool => $m['message']->role->value === 'user'));
        self::assertStringContainsString('[Каталог скиллов изменился — появились: nginx (Настройка nginx).]', $users[1]['message']->content);
        self::assertStringNotContainsString('Каталог скиллов', $users[2]['message']->content, 'once');
        self::assertStringNotContainsString('nginx', $stack->llm->requests[2]->messages[0]->content, 'the prompt stays as it was');
    }

    public function testFilesOfAnAcceptedVersionAreReadWithSkillView(): void {
        $first = $this->catalog->propose('pdf', 'Работа с PDF', 'Смотри references/forms.md', 'импорт', null, 'external');
        $this->repo->attachFiles($first->id, ['references/forms.md' => "# Формы\nЗаполняй поля.", 'scripts/fill.py' => 'print(1)']);
        $view = new \OpenCCK\App\Agent\Tool\SkillViewTool($this->catalog);
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, new \OpenCCK\Domain\Entity\Principal(1, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::Owner, null), new \Amp\NullCancellation());

        self::assertTrue($view->execute(['name' => 'pdf', 'file_path' => 'scripts/fill.py'], $context)->isError, 'a draft shows nothing');
        $this->repo->accept($first->id);

        $body = $view->execute(['name' => 'pdf'], $context)->content;
        self::assertStringContainsString('Смотри references/forms.md', $body);
        self::assertStringContainsString('scripts/fill.py', $body, 'the body lists the files');
        self::assertSame('print(1)', $view->execute(['name' => 'pdf', 'file_path' => 'scripts/fill.py'], $context)->content);
        self::assertTrue($view->execute(['name' => 'pdf', 'file_path' => '../../etc/passwd'], $context)->isError);

        $second = $this->catalog->propose('pdf', 'Работа с PDF', 'Без файлов', 'обновление', null, 'external');
        $this->repo->accept($second->id);
        self::assertTrue($view->execute(['name' => 'pdf', 'file_path' => 'scripts/fill.py'], $context)->isError, 'files belong to their version');
        $this->repo->rollback('pdf', 1);
        self::assertSame('print(1)', $view->execute(['name' => 'pdf', 'file_path' => 'scripts/fill.py'], $context)->content, 'a rollback brings its files back');
    }

    public function testTextsAreChecked(): void {
        foreach ([
            ['Bad Name', 'описание', 'тело'],
            ['ok', "две\nстроки", 'тело'],
            ['ok', 'описание', "скрытое\u{E0041}\u{E0042}"],
            ['ok', 'описание', 'token=sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef'],
            ['ok', str_repeat('д', 121), 'тело'],
        ] as [$name, $description, $body]) {
            try {
                $this->catalog->propose($name, $description, $body, 'причина', 1, 'agent');
                self::fail(sprintf('accepted: %s / %s', $name, $description));
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->repo->skills);
    }

    public function testOwnerCommandsTurnOffAndRollBack(): void {
        $this->accepted('nginx', 'Настройка nginx', 'v1');
        $this->accepted('nginx', 'Настройка nginx (новое)', 'v2');
        $commands = new SkillCommands($this->repo);

        self::assertStringContainsString('nginx v2 — включён', $commands->list());
        self::assertSame('Скилл «nginx» выключен.', $commands->skill('nginx off'));
        self::assertSame('', $this->catalog->index());
        $commands->skill('nginx on');
        self::assertSame('Скилл «nginx» откатан к версии 1.', $commands->skill('nginx rollback 1'));
        self::assertSame('v1', $this->catalog->body('nginx'));
        self::assertSame(Skill::ACTIVE, $this->repo->find('nginx')?->status);
    }

    public function testUsageIsCountedAndAnUnusedSkillIsMarkedForTheOwner(): void {
        $this->accepted('nginx', 'Настройка nginx', 'v1');
        $this->accepted('backup', 'Резервные копии', 'v1');
        $usage = new \OpenCCK\Test\Support\InMemorySkillUsageRepository();
        $view = new \OpenCCK\App\Agent\Tool\SkillViewTool($this->catalog, $usage);
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, new \OpenCCK\Domain\Entity\Principal(1, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::Owner, 'Owner'), new \Amp\NullCancellation());

        $view->execute(['name' => 'nginx'], $context);
        $view->execute(['name' => 'nginx'], $context);
        $view->execute(['name' => 'nope'], $context);
        self::assertSame(2, $usage->stats()['nginx']['views'] ?? null);
        self::assertArrayNotHasKey('nope', $usage->stats(), 'a missing skill is not counted');

        $usage->viewedAt['backup'] = time() - 40 * 86400;
        $commands = new SkillCommands($this->repo, usage: $usage);
        $list = $commands->list();
        self::assertStringContainsString('nginx v1 — включён · открыт 2', $list);
        self::assertStringContainsString('backup v1 — включён · давно не открывался', $list);

        self::assertStringContainsString('закреплён', $commands->skill('backup pin'));
        self::assertStringNotContainsString('давно не открывался', $commands->list(), 'a pinned skill is never stale');
        $commands->skill('backup unpin');
        self::assertStringContainsString('давно не открывался', $commands->list());
    }
}
