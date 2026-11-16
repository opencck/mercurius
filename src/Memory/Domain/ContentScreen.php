<?php

declare(strict_types=1);

namespace OpenCCK\Memory\Domain;

use Closure;

/**
 * Why a text must not go into memory, or null. Memory comes back into every later conversation, so one poisoned
 * fact is a standing instruction: no commands to the assistant, no role markup, no secrets, nothing invisible.
 *
 * spec: BRIEF.md#память-слои — memory is screened before it is stored and given as data when recalled
 * ref: references/hermes-plugin-holographic/screen.py@4a794f8:L872-L937 (_RE_ROLE_MARKUP, _RE_BLOB, _RE_IMAGE_EXFIL,
 *      _hidden_char, _fact_problem) — the same checks in the same order; injection phrases for Russian and English
 *      only, and the secret check is the one the tools use (passed in), not a second list of credential words
 */
final class ContentScreen {
    public const FACT_MAX = 1000;

    private const ROLE_MARKUP = '~(?im)^\s*(?:system|assistant|user|tool|система|ассистент)\s*[:：]|</?(?:tool_call|function_call|system|assistant)\b|<\|~u';
    private const BLOB = '~[A-Za-z0-9+/=_-]{200,}~';
    private const IMAGE = '~!\[[^\]\n]*\]\s*[(\[]|<\s*img\b~iu';
    private const QUOTED = '~"[^"\n]*"|\'[^\'\n]*\'|«[^»\n]*»|“[^”\n]*”~u';
    private const INJECTION = '~(?:ignore|disregard|forget|override)\s+(?:all\s+|any\s+)?(?:of\s+)?(?:your\s+(?:own\s+)?'
        . '(?:(?:previous|prior|above|earlier|system|original|all)\s+)?|(?:the\s+)?(?:previous|prior|above|earlier|system|original|all)\s+)'
        . '(?:instructions?|prompts?|rules|guidelines)|your\s+(?:new\s+)?instructions\s+are|you\s+are\s+now\s+(?:in\s+)?(?:dan|developer|jailbreak)'
        . '|(?:игнорируй|игнорируйте|забудь|забудьте|отмени|отмените|не\s+слушай|не\s+выполняй)\s+(?:все\s+|всё\s+)?'
        . '(?:свои\s+|твои\s+|ваши\s+|прежние\s+|предыдущие\s+|прошлые\s+|системные\s+)+(?:инструкци\w*|правил\w*|указани\w*|промпт\w*)'
        . '|(?:игнорируй|забудь)\s+(?:все|всё)\s+(?:инструкци\w*|правил\w*|указани\w*)'
        . '|(?:твои|ваши)\s+новые\s+(?:инструкци\w*|правила)|теперь\s+ты\s+(?:—\s*|-\s*)?(?:dan|разработчик|без\s+ограничений)~iu';

    /** @var Closure(string): bool */
    private readonly Closure $hasSecret;

    /** @param (Closure(string): bool)|null $hasSecret true when the text holds a password, key or token */
    public function __construct(?Closure $hasSecret = null) {
        $this->hasSecret = $hasSecret ?? static fn(string $text): bool => false;
    }

    public function problem(string $text, int $max = self::FACT_MAX): ?string {
        $text = trim($text);
        if ($text === '') {
            return 'пустой текст';
        }
        if (mb_strlen($text) > $max) {
            return sprintf('слишком длинно (%d символов, не больше %d): запиши вывод, а не исходные данные', mb_strlen($text), $max);
        }
        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            try {
                json_decode($text, flags: JSON_THROW_ON_ERROR);
                return 'похоже на JSON или вывод инструмента: запиши смысл предложением';
            } catch (\JsonException) {
            }
        }
        if (preg_match(self::ROLE_MARKUP, $text) === 1) {
            return 'содержит разметку ролей или вызова инструмента';
        }
        $unquoted = preg_replace(self::QUOTED, ' ', $text) ?? $text;
        if (preg_match(self::INJECTION, $unquoted) === 1) {
            return 'читается как указание ассистенту, а не как факт';
        }
        if (($this->hasSecret)($text)) {
            return 'похоже на секрет (пароль, ключ, токен): секреты в память не записываются';
        }
        if (self::hidden($text)) {
            return 'содержит невидимые символы форматирования';
        }
        if (preg_match(self::IMAGE, $text) === 1) {
            return 'содержит изображение: клиент, который его покажет, сам обратится по адресу';
        }
        if (preg_match(self::BLOB, $text) === 1) {
            return 'содержит длинную закодированную строку';
        }
        return null;
    }

    /** Format, control and private-use characters other than line breaks and tabs. */
    private static function hidden(string $text): bool {
        return preg_match('~[\p{Cf}\p{Co}]|[^\P{Cc}\n\r\t]~u', $text) === 1;
    }
}
