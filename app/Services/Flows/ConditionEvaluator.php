<?php

declare(strict_types=1);

namespace App\Services\Flows;

/**
 * Evaluate a JsonLogic expression tree against a context of slots +
 * call metadata.
 *
 * V1 usage is limited to dry-run testing (editor "will this trigger?"
 * preview) and eventual server-side audit of LLM-initiated
 * transitions. The LLM itself reads transitions in plain English via
 * {@see JsonLogicRenderer} — this class is for *us* to verify the
 * LLM's choices match the rule the author wrote, not for runtime
 * routing enforcement.
 *
 * Supported operators mirror the renderer's vocabulary:
 *   - ==, ===, !=, !==, <, <=, >, >=
 *   - and, or, !, !!
 *   - in (needle in haystack — string-contains or array-in)
 *   - startsWith
 *   - var (path lookup against context)
 *
 * Context is a flat array with two top-level keys: `slots` (values
 * the call has collected) and `context` (call metadata: now, call
 * did, caller matched_id, etc.). Paths are dot-separated —
 * `slots.caller_name`, `context.now.hour`.
 */
class ConditionEvaluator
{
    /**
     * @param  array<string, mixed>|null  $logic
     * @param  array<string, mixed>  $context
     */
    public function evaluate(?array $logic, array $context): bool
    {
        if ($logic === null || $logic === []) {
            return true; // unconditional = always matches
        }

        return (bool) $this->run($logic, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function run(mixed $node, array $context): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (array_is_list($node)) {
            return array_map(fn ($n) => $this->run($n, $context), $node);
        }

        $op = array_key_first($node);
        $args = $node[$op];
        if (! is_array($args)) {
            $args = [$args];
        }
        $evaluated = array_map(fn ($a) => $this->run($a, $context), $args);

        return match ($op) {
            '==', '===' => ($evaluated[0] ?? null) === ($evaluated[1] ?? null),
            '!=', '!==' => ($evaluated[0] ?? null) !== ($evaluated[1] ?? null),
            '<' => ($evaluated[0] ?? null) < ($evaluated[1] ?? null),
            '<=' => ($evaluated[0] ?? null) <= ($evaluated[1] ?? null),
            '>' => ($evaluated[0] ?? null) > ($evaluated[1] ?? null),
            '>=' => ($evaluated[0] ?? null) >= ($evaluated[1] ?? null),
            '!' => ! ($evaluated[0] ?? null),
            '!!' => ! empty($evaluated[0]),
            'and', 'AND' => ! in_array(false, array_map(fn ($v) => (bool) $v, $evaluated), true),
            'or',  'OR' => in_array(true, array_map(fn ($v) => (bool) $v, $evaluated), true),
            'in' => $this->opIn($evaluated[0] ?? null, $evaluated[1] ?? null),
            'startsWith' => is_string($evaluated[0] ?? null)
                              && is_string($evaluated[1] ?? null)
                              && str_starts_with($evaluated[0], $evaluated[1]),
            'var' => $this->lookup((string) ($args[0] ?? ''), $context),
            default => null,
        };
    }

    /**
     * Look up a dot-path in the context. Returns null if any segment
     * is missing — matches JsonLogic's reference implementation.
     *
     * @param  array<string, mixed>  $context
     */
    protected function lookup(string $path, array $context): mixed
    {
        if ($path === '') {
            return null;
        }
        $cursor = $context;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    protected function opIn(mixed $needle, mixed $haystack): bool
    {
        if (is_array($haystack)) {
            return in_array($needle, $haystack, true);
        }
        if (is_string($haystack) && is_string($needle)) {
            return $needle !== '' && str_contains($haystack, $needle);
        }

        return false;
    }
}
