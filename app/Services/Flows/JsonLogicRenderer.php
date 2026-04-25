<?php

declare(strict_types=1);

namespace App\Services\Flows;

/**
 * Render a JsonLogic expression tree into a plain-English sentence.
 *
 * The LLM prompt needs transition conditions stated in natural
 * language so the model can reason about them without parsing JSON.
 * The editor stores conditions as JsonLogic; this renderer produces
 * the English version that goes into the prompt.
 *
 * JsonLogic structure (subset we support):
 *   - {"==": [a, b]}   → "a is equal to b"
 *   - {"!=": [a, b]}   → "a is not equal to b"
 *   - {">": [a, b]} etc.
 *   - {"and": [...]}   → "A AND B"
 *   - {"or":  [...]}   → "A OR B"
 *   - {"!": [a]}       → "not A"
 *   - {"!!": [a]}      → "a is set"     (truthy)
 *   - {"var": "path"}  → "`path`"
 *   - {"in":   [needle, haystack]} → contains
 *   - {"startsWith": [str, prefix]} (custom op the editor writes)
 *   - literal values → their string form
 *
 * Output is a single human sentence — no Markdown, no newlines.
 * The compiler embeds it in the prompt as "If <rendered> →
 * flow <name>".
 */
class JsonLogicRenderer
{
    /** @param array<string, mixed>|null $logic */
    public function render(?array $logic): string
    {
        if ($logic === null || $logic === []) {
            return 'always';
        }

        return $this->renderNode($logic);
    }

    /** @param mixed $node */
    protected function renderNode(mixed $node): string
    {
        // Literal scalar.
        if (! is_array($node)) {
            return $this->renderLiteral($node);
        }

        // Array of literals (operand list passed raw).
        if (array_is_list($node)) {
            $parts = array_map(fn ($n) => $this->renderNode($n), $node);
            return implode(', ', $parts);
        }

        // Single-key operator map: {"op": [args...]}.
        $op = array_key_first($node);
        $args = $node[$op];
        if (! is_array($args)) {
            $args = [$args];
        }

        return match ($op) {
            '==', '===' => sprintf('%s is %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            '!=', '!==' => sprintf('%s is not %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            '<'         => sprintf('%s is less than %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            '<='        => sprintf('%s is at most %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            '>'         => sprintf('%s is greater than %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            '>='        => sprintf('%s is at least %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            '!!'        => sprintf('%s is set', $this->renderNode($args[0] ?? null)),
            '!'         => sprintf('%s is not set', $this->renderNode($args[0] ?? null)),
            'in'        => sprintf('%s contains %s', $this->renderNode($args[1] ?? null), $this->renderNode($args[0] ?? null)),
            'startsWith' => sprintf('%s starts with %s', $this->renderNode($args[0] ?? null), $this->renderNode($args[1] ?? null)),
            'and', 'AND' => $this->joinParts($args, ' and '),
            'or',  'OR'  => $this->joinParts($args, ' or '),
            'var'        => $this->renderVar($args[0] ?? ''),
            default      => sprintf('(%s: %s)', $op, implode(', ', array_map(fn ($a) => $this->renderNode($a), $args))),
        };
    }

    /** @param array<int, mixed> $parts */
    protected function joinParts(array $parts, string $sep): string
    {
        $rendered = array_map(fn ($p) => $this->renderNode($p), $parts);
        return implode($sep, $rendered);
    }

    protected function renderVar(mixed $path): string
    {
        return sprintf('`%s`', is_string($path) ? $path : (string) json_encode($path));
    }

    protected function renderLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'empty';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return '"'.$value.'"';
        }
        return (string) $value;
    }
}
