<?php

declare(strict_types=1);

namespace OpenCCK\Memory\Domain;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Keys of a text for search: stems of meaningful words (Russian and English Snowball), identifiers whole. The quality
 * of recall rests on this more than on vectors.
 *
 * spec: BRIEF.md#анализ-текста
 * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L380 (_coverage_rank) — keys are word stems and
 *      identifiers; stop words carry no information
 */
final class TextAnalyzer {
    /** Words that say nothing about the subject. */
    private const STOP = [
        'и', 'в', 'во', 'не', 'что', 'он', 'на', 'я', 'с', 'со', 'как', 'а', 'то', 'все', 'она', 'так', 'его', 'но', 'да',
        'ты', 'к', 'у', 'же', 'вы', 'за', 'бы', 'по', 'только', 'ее', 'мне', 'было', 'вот', 'от', 'меня', 'еще', 'нет',
        'о', 'из', 'ему', 'теперь', 'когда', 'даже', 'ну', 'вдруг', 'ли', 'если', 'уже', 'или', 'ни', 'быть', 'был', 'него',
        'до', 'вас', 'нибудь', 'опять', 'уж', 'вам', 'ведь', 'там', 'потом', 'себя', 'ничего', 'ей', 'может', 'они', 'тут',
        'где', 'есть', 'надо', 'ней', 'для', 'мы', 'тебя', 'их', 'чем', 'была', 'сам', 'чтоб', 'без', 'будто', 'чего', 'раз',
        'тоже', 'себе', 'под', 'будет', 'ж', 'тогда', 'кто', 'этот', 'того', 'потому', 'этого', 'какой', 'совсем', 'ним',
        'здесь', 'этом', 'один', 'почти', 'мой', 'тем', 'чтобы', 'нее', 'сейчас', 'были', 'куда', 'зачем', 'всех', 'никогда',
        'можно', 'при', 'наконец', 'два', 'об', 'другой', 'хоть', 'после', 'над', 'больше', 'тот', 'через', 'эти', 'нас',
        'про', 'всего', 'них', 'какая', 'много', 'разве', 'три', 'эту', 'моя', 'впрочем', 'хорошо', 'свою', 'этой', 'перед',
        'иногда', 'лучше', 'чуть', 'том', 'нельзя', 'такой', 'им', 'более', 'всегда', 'конечно', 'всю', 'между', 'это',
        'еще', 'ещё', 'пожалуйста', 'спасибо', 'привет', 'ок', 'окей', 'ага', 'угу',
        'the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'to',
        'of', 'in', 'on', 'at', 'for', 'with', 'as', 'by', 'from', 'so', 'do', 'does', 'did', 'not', 'no', 'yes', 'ok',
        'i', 'you', 'he', 'she', 'we', 'they', 'me', 'my', 'your', 'our', 'their', 'what', 'which', 'who', 'please', 'thanks',
    ];
    /** An identifier: has a digit, a dot between parts, a slash, an underscore or a hyphen between letters and digits. */
    private const IDENTIFIER = '~^(?:/[\w.\-/]+|[\w]+(?:[._\-][\w]+)+|[a-z]+\d+[\w]*)$~u';

    /** @var array<string, true> */
    private readonly array $stop;
    private ?Stemmer $russian = null;
    private ?Stemmer $english = null;

    public function __construct() {
        $this->stop = array_fill_keys(self::STOP, true);
    }

    /** @return list<string> unique keys in the order they appear */
    public function keys(string $text): array {
        $text = str_replace(['ё', 'Ё'], ['е', 'Е'], $text);
        preg_match_all('~/[\w.\-/]+|[\p{L}\p{N}_]+(?:[.\-][\p{L}\p{N}_]+)*~u', $text, $m);
        $keys = [];
        foreach ($m[0] as $token) {
            $word = mb_strtolower($token);
            if (isset($this->stop[$word]) || mb_strlen($word) < 2) {
                continue;
            }
            $key = preg_match(self::IDENTIFIER, $word) === 1 || preg_match('~\d~', $word) === 1 ? $word : $this->stem($word);
            if ($key !== '' && !isset($this->stop[$key])) {
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }

    private function stem(string $word): string {
        if (preg_match('~\p{Cyrillic}~u', $word) === 1) {
            $this->russian ??= StemmerFactory::create('ru');
            return $this->russian->stem($word);
        }
        $this->english ??= StemmerFactory::create('en');
        return $this->english->stem($word);
    }
}
