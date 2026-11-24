<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Messenger\ParseMode;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\ScriptedLlm;

final class ChatServiceTest extends AsyncTestCase {
    public function testStrangerWaitsForTheOwnerWithoutCallingTheModel(): void {
        $stack = new AgentStack();
        $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $stack->say('привет', '99');
        $stack->scheduler->awaitIdle();

        self::assertContains(\Mercurius\I18n\t(VisitorService::WAITING), $stack->channel->texts('send'));
        self::assertSame([], $stack->llm->requests);
    }

    public function testNonTextMessageGetsAHint(): void {
        $stack = new AgentStack();
        $stack->say('  ');

        self::assertSame([\Mercurius\I18n\t(ChatService::NOT_TEXT)], $stack->channel->texts('send'));
    }

    public function testStartGreets(): void {
        $stack = new AgentStack();
        $stack->say('/start');

        self::assertSame([\Mercurius\I18n\t(ChatService::GREETING)], $stack->channel->texts('send'));
        self::assertSame([], $stack->llm->requests);
    }

    public function testACodeForClaudeNeverReachesTheModelWhereTheModuleIsOff(): void {
        $stack = new AgentStack();
        $stack->say('/claude code secret-sign-in-code');
        $stack->scheduler->awaitIdle();

        self::assertSame([\Mercurius\I18n\t('claude.module_off')], $stack->channel->texts('send'));
        self::assertSame([], $stack->llm->requests);
        self::assertCount(1, $stack->channel->deleted, 'the message with the code leaves the chat');
    }

    public function testACodeOnTheNextLineOrAfterATabStaysWithTheCommand(): void {
        foreach (["/claude code\nsecret-sign-in-code", "/claude\tcode secret-sign-in-code", "/claude@SomeBot code secret-sign-in-code", "/new\n/claude code\n  secret-sign-in-code"] as $text) {
            $stack = new AgentStack();
            $stack->say($text);
            $stack->scheduler->awaitIdle();

            self::assertContains(\Mercurius\I18n\t('claude.module_off'), $stack->channel->texts('send'), $text);
            self::assertSame([], $stack->llm->requests, 'the code never reaches the model: ' . $text);
            self::assertNotSame([], $stack->channel->deleted, $text);
        }
    }

    public function testNewCommandStartsAFreshConversation(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('один'), ScriptedLlm::text('два')]));
        $stack->say('первый');
        $stack->scheduler->awaitIdle();
        $stack->say('/new');
        $stack->say('второй');
        $stack->scheduler->awaitIdle();

        self::assertContains(\Mercurius\I18n\t(ChatService::NEW_CONVERSATION), $stack->channel->texts('send'));
        $messages = $stack->llm->requests[1]->messages;
        self::assertCount(2, $messages);
        self::assertSame('второй', $messages[1]->content);
    }

    public function testHistoryOfEarlierRunsIsSent(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ответ 1'), ScriptedLlm::text('ответ 2')]));
        $stack->say('вопрос 1');
        $stack->scheduler->awaitIdle();
        $stack->say('вопрос 2');
        $stack->scheduler->awaitIdle();

        self::assertSame(['вопрос 1', 'ответ 1', 'вопрос 2'], array_map(static fn($m) => $m->content, array_slice($stack->llm->requests[1]->messages, 1)));
    }

    public function testAnEnglishAgentAnswersInEnglish(): void {
        \Mercurius\I18n\Lang::use(new \Mercurius\I18n\CatalogTranslator('en', \Mercurius\I18n\Catalogs::bundled()));
        try {
            $stack = new AgentStack();
            $stack->say('/stop');

            self::assertSame(['Nothing is running right now.'], $stack->channel->texts('send'));
        } finally {
            \Mercurius\I18n\Lang::reset();
        }
    }

    public function testTheLanguageSettingTakesOnlyKnownLanguages(): void {
        self::assertNull(\OpenCCK\App\Settings\SettingsCatalog::problem('AGENT_LANGUAGE', 'en'));
        self::assertNotNull(\OpenCCK\App\Settings\SettingsCatalog::problem('AGENT_LANGUAGE', 'de'));
    }

    public function testStopWhenIdle(): void {
        $stack = new AgentStack();
        $stack->say('/stop');

        self::assertSame([\Mercurius\I18n\t(ChatService::NOTHING_TO_STOP)], $stack->channel->texts('send'));
    }

    public function testModelFailureIsReported(): void {
        $stack = new AgentStack(new ScriptedLlm([new LlmException(ErrorKind::Network, 'down'), new LlmException(ErrorKind::Network, 'down')]));
        $stack->say('привет');
        $stack->scheduler->awaitIdle();

        $edits = $stack->channel->texts('edit');
        self::assertStringContainsString('Остановлено: модель недоступна.', (string) end($edits));
        self::assertSame('network', $stack->calls->calls[0]->errorKind);
    }

    public function testModelAnswerIsMarkdownAndServiceTextsArePlain(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('**жирный**')]));
        $stack->say('/start');
        $stack->say('привет');
        $stack->scheduler->awaitIdle();

        self::assertContains(['send', \Mercurius\I18n\t(ChatService::GREETING), ParseMode::Plain], $stack->channel->modes);
        self::assertContains(['edit', '**жирный**', ParseMode::Markdown], $stack->channel->modes);
    }
}
