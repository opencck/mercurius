<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use Stringable;

use function Mercurius\I18n\t;

/**
 * Which webhooks of a job wake the model: `events=push,pull_request` — the type of the event (the X-GitHub-Event or
 * X-Webhook-Event header); `ref=refs/heads/master` — a field of the JSON body by its dotted path, `a|b` — one of
 * several values, `!=` — anything but; `merge=30` — events coming within 30 seconds of each other run the job once,
 * on the last of them. Conditions are joined by `;` and all must hold. An empty filter takes every event at once.
 *
 * spec: BRIEF.md#планировщик-и-проекты — a filter of webhook events and the merging of a burst
 * ref: references/hermes-agent/gateway/platforms/webhook.py@e96221fa:L708-L715 — `events` by the header, then field
 *      filters, an event that does not pass is answered and dropped without a run
 * ref: references/hermes-agent/gateway/platforms/webhook_filters.py@e96221fa:L134-L167 (filter_matches) — hermes has
 *      all/any/not and field operators in a structure; here one line the owner writes in a command, equality, a list
 *      of values and negation, all joined by "and"
 * ref: references/hermes-agent/gateway/platforms/webhook_coalesce.py@e96221fa:L1-L24 — a burst runs once on its
 *      latest event after a quiet window (30 s by default), bounded by a longest wait of 300 s
 */
final class HookFilter implements Stringable {
    public const MERGE_MAX = 3600;

    /**
     * @param list<string> $events
     * @param list<array{path: list<string>, values: list<string>, not: bool}> $fields
     */
    private function __construct(
        public readonly array $events,
        public readonly array $fields,
        public readonly int $merge,
    ) {}

    /** The filter, or why the text is not one. */
    public static function parse(string $text): self|string {
        $events = [];
        $fields = [];
        $merge = 0;
        foreach (explode(';', $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('~^([A-Za-z0-9_.\-]+)\s*(!=|=)\s*(.*)$~u', $part, $m) !== 1 || trim($m[3]) === '') {
                return sprintf(t('job.hook_filter.bad'), mb_substr($part, 0, 80));
            }
            [$key, $operator, $value] = [$m[1], $m[2], trim($m[3])];
            if ($key === 'events' && $operator === '=') {
                $events = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $e): bool => $e !== ''));
                continue;
            }
            if ($key === 'merge' && $operator === '=') {
                if (!ctype_digit($value) || (int) $value > self::MERGE_MAX) {
                    return sprintf(t('job.hook_filter.bad_merge'), self::MERGE_MAX);
                }
                $merge = (int) $value;
                continue;
            }
            $fields[] = ['path' => explode('.', $key), 'values' => array_map('trim', explode('|', $value)), 'not' => $operator === '!='];
        }
        return new self($events, $fields, $merge);
    }

    /** Whether an event of this type with this body wakes the job. */
    public function matches(string $eventType, mixed $payload): bool {
        if ($this->events !== [] && !in_array($eventType, $this->events, true)) {
            return false;
        }
        foreach ($this->fields as $field) {
            /** @var mixed $value */
            $value = $payload;
            foreach ($field['path'] as $step) {
                /** @var mixed $value */
                $value = is_array($value) && array_key_exists($step, $value) ? $value[$step] : null;
            }
            $text = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : null;
            $found = $text !== null && in_array($text, $field['values'], true);
            if ($found === $field['not']) {
                return false;
            }
        }
        return true;
    }

    public function __toString(): string {
        $parts = [];
        if ($this->events !== []) {
            $parts[] = 'events=' . implode(',', $this->events);
        }
        foreach ($this->fields as $field) {
            $parts[] = implode('.', $field['path']) . ($field['not'] ? '!=' : '=') . implode('|', $field['values']);
        }
        if ($this->merge > 0) {
            $parts[] = 'merge=' . $this->merge;
        }
        return implode('; ', $parts);
    }
}
