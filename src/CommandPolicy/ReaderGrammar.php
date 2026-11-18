<?php

declare(strict_types=1);

namespace OpenCCK\CommandPolicy;

/**
 * Whether every argument of a reader is an option its grammar knows, a value such an option takes, or an operand of
 * the allowed shape. A reader is a read only when this holds: an option the grammar does not list — a misspelling,
 * an abbreviation, a short cluster with an unknown letter — makes the command a change. Abbreviated long options are
 * not accepted: GNU tools read `--out` as `--output` and `--compress-prog` as `--compress-program`, so only the exact
 * names listed pass.
 *
 * Grammar: `['args' => 'any']` — the arguments are data whatever they are; or `['args' => 'getopt'|'exact',
 * 'flags' => [...], 'value' => [...], 'optional' => [...], 'numericShort' => bool, 'optionsBeforeOperands' => bool,
 * 'operands' => ['max' => int, 'first' => [...], 'each' => regex]]`. `getopt` reads short clusters letter by letter
 * and splits `--name=value`; `exact` (find, ip) matches whole words and keeps reading options after `--`.
 *
 * ref: references/ssh-mcp/src/policy/reader-grammar.ts@e04ebf2:L81-L219 (matchesGrammar), L247-L284
 *      (matchShortCluster), L286-L325 (checkOperands) — the same matcher, ported; the ambiguity checks of a name in
 *      two lists are left to the tests of the table, which is ours
 */
final class ReaderGrammar {
    /**
     * @param list<string> $words the arguments after the command name
     * @param array{args: string, flags?: list<string>, value?: list<string>, optional?: list<string>, numericShort?: bool, optionsBeforeOperands?: bool, operands?: array{max?: int, first?: list<string>, each?: non-empty-string}} $grammar
     * @return string|null the first word the grammar does not accept, null when all of them pass
     */
    public static function refused(array $words, array $grammar): ?string {
        if ($grammar['args'] === 'any') {
            return null;
        }
        $getopt = $grammar['args'] === 'getopt';
        $flags = array_flip($grammar['flags'] ?? []);
        $value = array_flip($grammar['value'] ?? []);
        $optional = array_flip($grammar['optional'] ?? []);
        $operands = [];
        $ended = false;
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $word = $words[$i];
            $option = !$ended && strlen($word) > 1 && $word[0] === '-';
            if (($grammar['optionsBeforeOperands'] ?? false) && $operands !== [] && $option) {
                return $word;
            }
            if ($getopt && !$ended && $word === '--') {
                $ended = true;
                continue;
            }
            if (!$option) {
                $operands[] = $word;
                continue;
            }
            if ($getopt && ($grammar['numericShort'] ?? false) && preg_match('~^-\d+$~', $word) === 1) {
                continue;
            }
            if ($getopt && !str_starts_with($word, '--')) {
                // a short cluster, letter by letter as getopt reads it
                for ($c = 1, $length = strlen($word); $c < $length; $c++) {
                    $letter = '-' . $word[$c];
                    if (isset($flags[$letter])) {
                        continue;
                    }
                    if (isset($value[$letter])) {
                        if ($c + 1 < $length) {
                            continue 2;
                        }
                        if ($i + 1 >= $count) {
                            return $letter;
                        }
                        $i++;
                        continue 2;
                    }
                    if (isset($optional[$letter])) {
                        continue 2;
                    }
                    return $word;
                }
                continue;
            }
            $equals = $getopt ? strpos($word, '=') : false;
            $name = $equals === false ? $word : substr($word, 0, $equals);
            if (isset($value[$name])) {
                if ($equals !== false) {
                    continue;
                }
                if ($i + 1 >= $count) {
                    return $name;
                }
                $i++;
                continue;
            }
            if (isset($optional[$name]) || (isset($flags[$name]) && $equals === false)) {
                continue;
            }
            return $word;
        }
        $rules = $grammar['operands'] ?? [];
        foreach ($operands as $n => $operand) {
            if ($n === 0 && isset($rules['first']) && !in_array($operand, $rules['first'], true)) {
                return $operand;
            }
            if (isset($rules['each']) && preg_match($rules['each'], $operand) !== 1) {
                return $operand;
            }
        }
        if (isset($rules['max']) && count($operands) > $rules['max']) {
            return $operands[$rules['max']];
        }
        return null;
    }
}
