<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase {
    public function testSecretNamedArgumentsAreMaskedAtAnyDepth(): void {
        $masked = SecretMasker::maskArgs([
            'url' => 'https://api.example.com/v1',
            'headers' => ['X-Api-Key' => 'abcd1234', 'Authorization' => 'Basic dXNlcjpw', 'Accept' => 'application/json'],
            'body' => ['user' => 'bob', 'password' => 'hunter2', 'nested' => ['client_secret' => 'xyz']],
        ]);
        $json = json_encode($masked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach (['abcd1234', 'dXNlcjpw', 'hunter2', 'xyz'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertStringContainsString('application/json', $json);
        self::assertStringContainsString('bob', $json);
    }

    public function testPasswordsSaidInRussianAreMasked(): void {
        self::assertSame('пароль от роутера admin / ***', SecretMasker::mask('пароль от роутера admin / Qwerty-EVAL-2026'));
        self::assertSame('Пароль от роутера: ***', SecretMasker::mask('Пароль от роутера: Qwerty-EVAL-2026'));
        self::assertSame('роутер: логин admin, пароль ***', SecretMasker::mask('роутер: логин admin, пароль Qwerty-EVAL-2026'));
        self::assertSame('токен бота: ***', SecretMasker::mask('токен бота: 123456:ABC-def'));
        self::assertSame('Сменить пароль от почты завтра', SecretMasker::mask('Сменить пароль от почты завтра'), 'no value — nothing to hide');
        self::assertSame('пароль должен быть длиннее 12 символов', SecretMasker::mask('пароль должен быть длиннее 12 символов'));
    }

    public function testJsonInAStringIsMasked(): void {
        $masked = SecretMasker::mask('{"password": "hunter2", "X-Api-Key":"abcd1234", "name": "ok"}');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('abcd1234', $masked);
        self::assertStringContainsString('"name": "ok"', $masked);
    }

    public function testCommandLinePasswordsAndUrlIdsAreMasked(): void {
        $masked = SecretMasker::mask('mysql -uroot -phunter22 db; psql --password s3cretpw; vless://0b5c8a1e-1111-4222-8333-944455556666@host:443?type=ws');

        self::assertStringNotContainsString('hunter22', $masked);
        self::assertStringNotContainsString('s3cretpw', $masked);
        self::assertStringNotContainsString('0b5c8a1e-1111-4222-8333-944455556666', $masked);
        self::assertStringContainsString('mysql -uroot', $masked);
        self::assertSame('mkdir -p build && ssh -p 22 host', SecretMasker::mask('mkdir -p build && ssh -p 22 host'));
    }

    public function testUrlCredentialsAndSecretQueryValuesAreMasked(): void {
        $masked = SecretMasker::mask('git push https://user:hunter2@github.com/x.git; curl "https://api.example.com/x?access_key=s3cr3t&page=2"');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('s3cr3t', $masked);
        self::assertStringContainsString('github.com/x.git', $masked);
        self::assertStringContainsString('page=2', $masked);
    }
    public function testAKnownSecretIsMaskedByItsValueWhateverItLooksLike(): void {
        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a password like any word is caught by its value
        SecretMasker::remember('correct horse battery');
        SecretMasker::remember('short');
        $masked = SecretMasker::mask('the phrase was correct horse battery, and short stays');

        self::assertStringNotContainsString('correct horse battery', $masked);
        self::assertStringContainsString('short stays', $masked, 'a value too short to tell from a word is not taken');
    }

    public function testTelegramTokensAndPrivateKeysAreMasked(): void {
        // ref: references/hermes-agent/agent/redact.py@e96221fa:L620-L640 — the shapes of a bot token and a PEM key
        $masked = SecretMasker::mask("url https://api.telegram.org/bot1234567890:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/getMe\n-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\nAAAAQ\n-----END OPENSSH PRIVATE KEY-----\nend");

        self::assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $masked);
        self::assertStringContainsString('bot1234567890:***', $masked);
        self::assertStringNotContainsString('b3BlbnNzaC1rZXktdjEAAAAA', $masked);
        self::assertStringContainsString('end', $masked);
        self::assertSame('a time 12:30 and an id 12345678:short', SecretMasker::mask('a time 12:30 and an id 12345678:short'));
    }

    public function testTheLogHidesSecretsInItsMessageAndContext(): void {
        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a log line does not carry a secret
        SecretMasker::remember('log-secret-value-123');
        $record = new \Monolog\LogRecord(new \DateTimeImmutable(), 'mercurius', \Monolog\Level::Info, 'failed with log-secret-value-123', ['error' => 'token=abcdef123456 here', 'nested' => ['x' => 'log-secret-value-123'], 'n' => 5]);
        $clean = (new \OpenCCK\Infrastructure\Logger\SecretProcessor())($record);

        self::assertStringNotContainsString('log-secret-value-123', $clean->message);
        self::assertStringNotContainsString('abcdef123456', json_encode($clean->context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('log-secret-value-123', json_encode($clean->context, JSON_THROW_ON_ERROR));
        self::assertSame(5, $clean->context['n']);
    }
    public function testAMessageWithAHashOfACommitDoesNotCarryASecret(): void {
        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a message is deleted for a secret, not for a hash
        self::assertFalse(SecretMasker::carriesSecret('посмотри коммит 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e'));
        self::assertFalse(SecretMasker::carriesSecret('картинка aGVsbG8gd29ybGQgdGhpcyBpcyBqdXN0IGJhc2U2NCBkYXRhIG5vdCBhIGtleQ=='));
        self::assertTrue(SecretMasker::carriesSecret('токен: 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a'));
        self::assertTrue(SecretMasker::carriesSecret('ключ sk-or-v1-0123456789abcdef0123456789abcdef'));
        self::assertNotSame('коммит 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e', SecretMasker::mask('коммит 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e'), 'output is still masked as before');
    }
    public function testTextForAnotherAgentHidesEmailsAndPhones(): void {
        // spec: brief/network.md#подпись-адреса-доказательство-ключа-и-маскирование — what goes to another agent carries no addresses and phones
        // ref: references/hermes-agent/agent/redact.py@e96221fa:L757-L759 — the E.164 shape of a phone
        $masked = SecretMasker::forAgents('Напиши ivan.petrov+work@mail.example или позвони +79991234567, 8 (999) 123-45-67, +7 999 123 45 67. Версия 3.14.7+20260901, дата 2026-11-18, заказ 12345678.');

        self::assertStringNotContainsString('ivan.petrov', $masked);
        self::assertStringNotContainsString('9991234567', $masked);
        self::assertStringNotContainsString('123-45-67', $masked);
        self::assertStringNotContainsString('123 45 67', $masked);
        self::assertStringContainsString('3.14.7+20260901', $masked, 'a version is not a phone');
        self::assertStringContainsString('2026-11-18', $masked);
        self::assertStringContainsString('12345678', $masked, 'a plain number is not a phone');
    }

    public function testACountOfTokensIsNotASecret(): void {
        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — the totals of a run («токенов: …, время: …») stay readable
        $totals = 'Ходов: 1, токенов: 18318, время: 61 с.';
        self::assertSame($totals, SecretMasker::mask($totals));
        self::assertSame('токен: ***', SecretMasker::mask('токен: abc123XYZ'));
        self::assertSame('Токен бота: ***', SecretMasker::mask('Токен бота: 12345'));
        self::assertSame('пароль от роутера: *** дальше', SecretMasker::mask('пароль от роутера: hunter2 дальше'));
    }

    public function testAnAuthorIsNotAnAuthorization(): void {
        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a name is secret by a whole word: "auth" inside "author" is not one
        foreach (['Co-Authored-By: Claude Opus <noreply@example.org>', 'author: Ivan', 'authority=central', 'Authors: A, B'] as $line) {
            self::assertSame($line, SecretMasker::mask($line), $line);
            self::assertFalse(SecretMasker::carriesSecret($line), $line);
        }
        foreach (['Authorization: abc123def', 'auth=abc123def', 'x-auth-token: abc123def', 'oauth_token=abc123def', 'authorisation: abc123def'] as $line) {
            self::assertStringNotContainsString('abc123def', SecretMasker::mask($line), $line);
        }
    }

    public function testAPinIsAWordOfItsOwn(): void {
        // spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — "pin" inside "Skipping" names no secret
        foreach (["print(f'Skipping {ch}: only {len(vids)} videos (need 4+)')", 'Shipping cost: 12 USD', 'spinner: dots'] as $line) {
            self::assertSame($line, SecretMasker::mask($line), $line);
        }
        foreach (['PIN: 4821', 'pin code = 4821', 'пин-код: 4821'] as $line) {
            self::assertStringNotContainsString('4821', SecretMasker::mask($line), $line);
        }
    }

    /**
     * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — secrets as people write them
     * ref: references/hermes-plugin-holographic/tests/test_holographic_screen_gaps.py@9b49943 — the cases of its review:
     *      a short name before a separator, a payment key, the seed of one-time codes, a seed phrase after its name
     */
    public function testSecretsWrittenAsPeopleWriteThemAreMasked(): void {
        foreach ([
            'pass: hunter22x',
            'Wi-Fi pw = hunter22x',
            'ключ sk_live_abcdefghijklmnop1234',
            'rk_test_abcdefghijklmnop1234',
            'TOTP secret JBSWY3DPEHPK3PXP',
            '2FA seed: JBSWY3DPEHPK3PXP',
            'seed phrase: abandon ability able about above absent absorb abstract absurd abuse access accident',
            'сид-фраза кошелька — abandon ability able about above absent absorb abstract absurd abuse access accident',
        ] as $text) {
            self::assertNotSame($text, SecretMasker::mask($text), $text);
            self::assertTrue(SecretMasker::carriesSecret($text), $text);
        }
    }

    public function testEverydayWordsAreNotSecrets(): void {
        foreach ([
            'Считаем токены GPT-4o по тарифу',
            'токены gpt-4o-mini дешевле',
            'Нужно отправить Лене ключи от квартиры в субботу',
            'ключ API Яндекс.Метрики лежит в хранилище',
            'boarding pass is in the bag',
            'pass the salt, please: it is on the table',
        ] as $text) {
            self::assertSame($text, SecretMasker::mask($text), $text);
        }
    }

    /**
     * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a payment card and a PIN with no separator
     * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@46d2f9e:L4068-L4077 (CARD_NUMBER_RE, _PIN_VALUE_RE),
     *      L4089-L4101 (_luhn, _unseparated_secret) — a card passes the checksum, a year is no PIN
     */
    public function testAPaymentCardAndAPinAreMasked(): void {
        foreach (['карта 4111 1111 1111 1111, cvv 123', 'номер карты 5555555555554444', 'PIN карты 4821', 'пин-код от домофона 90210'] as $text) {
            self::assertNotSame($text, SecretMasker::mask($text), $text);
            self::assertTrue(SecretMasker::carriesSecret($text), $text);
        }
        self::assertSame('карта *** и всё', SecretMasker::mask('карта 4111-1111-1111-1111 и всё'));
        foreach (['заказ 4111111111111112', 'сменил PIN в 2024 году', 'чат -1004111111111111', 'https://x.com/a/status/4111111111111111', 'pin 827c639e в журнале', 'GPIO pin 17 занят'] as $text) {
            self::assertSame($text, SecretMasker::mask($text), $text);
        }
    }
}
