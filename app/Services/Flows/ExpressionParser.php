<?php

declare(strict_types=1);

namespace App\Services\Flows;

use RuntimeException;

/**
 * Tokenize + parse a single expression string into an AST.
 *
 * Syntax (JS-like subset):
 *
 *   literal    := NUMBER | STRING | 'true' | 'false' | 'null'
 *   variable   := IDENT ('.' IDENT)*          // slot name OR context path
 *   call       := IDENT '(' args? ')'
 *   group      := '(' expr ')'
 *   unary      := ('!' | '-') expr
 *   mul        := expr (('*' | '/' | '%') expr)*
 *   add        := expr (('+' | '-') expr)*
 *   cmp        := expr (('<' | '<=' | '>' | '>=') expr)*
 *   eq         := expr (('==' | '!=') expr)*
 *   and        := expr ('&&' expr)*
 *   or         := expr ('||' expr)*
 *   ternary    := expr '?' expr ':' expr
 *
 * Returns a plain-array AST. Each node is:
 *
 *   ['type' => 'literal',  'value' => mixed]
 *   ['type' => 'ident',    'name' => string]           // bare identifier
 *   ['type' => 'member',   'object' => node, 'prop' => string]
 *   ['type' => 'call',     'name' => string, 'args' => node[]]
 *   ['type' => 'unary',    'op' => '!' | '-', 'arg' => node]
 *   ['type' => 'binary',   'op' => string, 'left' => node, 'right' => node]
 *   ['type' => 'ternary',  'test' => node, 'then' => node, 'else' => node]
 *
 * The parser is pure — no evaluation. Evaluation happens in
 * {@see ExpressionEvaluator} against a context of slots + call
 * metadata.
 */
class ExpressionParser
{
    /** @var array<int, array{kind: string, value: mixed, pos: int}> */
    private array $tokens = [];

    private int $pos = 0;

    private string $source = '';

    /** @return array<string, mixed> */
    public function parse(string $source): array
    {
        $this->source = $source;
        $this->tokens = $this->tokenize($source);
        $this->pos = 0;

        $node = $this->parseExpression();

        if ($this->peek() !== null) {
            throw $this->syntax('Unexpected trailing input');
        }

        return $node;
    }

    // ── Tokenizer ────────────────────────────────────────────────

    /** @return array<int, array{kind: string, value: mixed, pos: int}> */
    private function tokenize(string $src): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            // Skip whitespace.
            if (ctype_space($c)) {
                $i++;

                continue;
            }

            // String literal.
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $start = $i;
                $i++;
                $buf = '';
                while ($i < $n && $src[$i] !== $quote) {
                    if ($src[$i] === '\\' && $i + 1 < $n) {
                        $next = $src[$i + 1];
                        $buf .= match ($next) {
                            'n' => "\n",
                            't' => "\t",
                            'r' => "\r",
                            '\\' => '\\',
                            '"' => '"',
                            "'" => "'",
                            default => $next,
                        };
                        $i += 2;

                        continue;
                    }
                    $buf .= $src[$i];
                    $i++;
                }
                if ($i >= $n) {
                    throw new RuntimeException("Unterminated string literal at position {$start}");
                }
                $i++; // closing quote
                $tokens[] = ['kind' => 'STRING', 'value' => $buf, 'pos' => $start];

                continue;
            }

            // Number literal.
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
                $start = $i;
                while ($i < $n && (ctype_digit($src[$i]) || $src[$i] === '.')) {
                    $i++;
                }
                $raw = substr($src, $start, $i - $start);
                $tokens[] = [
                    'kind' => 'NUMBER',
                    'value' => str_contains($raw, '.') ? (float) $raw : (int) $raw,
                    'pos' => $start,
                ];

                continue;
            }

            // Identifier / keyword.
            if (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $n && (ctype_alnum($src[$i]) || $src[$i] === '_')) {
                    $i++;
                }
                $word = substr($src, $start, $i - $start);
                $kind = match ($word) {
                    'true', 'false', 'null' => 'KEYWORD',
                    default => 'IDENT',
                };
                $tokens[] = ['kind' => $kind, 'value' => $word, 'pos' => $start];

                continue;
            }

            // Multi-char operators.
            $two = substr($src, $i, 2);
            if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
                $tokens[] = ['kind' => 'OP', 'value' => $two, 'pos' => $i];
                $i += 2;

                continue;
            }

            // Single-char operators / punctuation.
            if (str_contains('+-*/%<>!?:().,', $c)) {
                $tokens[] = ['kind' => 'OP', 'value' => $c, 'pos' => $i];
                $i++;

                continue;
            }

            throw new RuntimeException("Unexpected character '{$c}' at position {$i}");
        }

        return $tokens;
    }

    // ── Parser (recursive descent) ───────────────────────────────

    private function parseExpression(): array
    {
        return $this->parseTernary();
    }

    private function parseTernary(): array
    {
        $test = $this->parseOr();
        if ($this->matchOp('?')) {
            $then = $this->parseExpression();
            $this->expectOp(':');
            $else = $this->parseExpression();

            return ['type' => 'ternary', 'test' => $test, 'then' => $then, 'else' => $else];
        }

        return $test;
    }

    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->matchOp('||')) {
            $right = $this->parseAnd();
            $left = ['type' => 'binary', 'op' => '||', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseAnd(): array
    {
        $left = $this->parseEquality();
        while ($this->matchOp('&&')) {
            $right = $this->parseEquality();
            $left = ['type' => 'binary', 'op' => '&&', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseEquality(): array
    {
        $left = $this->parseComparison();
        while ($this->peekOp('==') || $this->peekOp('!=')) {
            $op = $this->advance()['value'];
            $right = $this->parseComparison();
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseComparison(): array
    {
        $left = $this->parseAdditive();
        while ($this->peekOp('<') || $this->peekOp('<=') || $this->peekOp('>') || $this->peekOp('>=')) {
            $op = $this->advance()['value'];
            $right = $this->parseAdditive();
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseAdditive(): array
    {
        $left = $this->parseMultiplicative();
        while ($this->peekOp('+') || $this->peekOp('-')) {
            $op = $this->advance()['value'];
            $right = $this->parseMultiplicative();
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseMultiplicative(): array
    {
        $left = $this->parseUnary();
        while ($this->peekOp('*') || $this->peekOp('/') || $this->peekOp('%')) {
            $op = $this->advance()['value'];
            $right = $this->parseUnary();
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseUnary(): array
    {
        if ($this->matchOp('!')) {
            return ['type' => 'unary', 'op' => '!', 'arg' => $this->parseUnary()];
        }
        if ($this->matchOp('-')) {
            return ['type' => 'unary', 'op' => '-', 'arg' => $this->parseUnary()];
        }

        return $this->parsePostfix();
    }

    /**
     * Chain of `.member` accesses + the call syntax `IDENT(args)`.
     * We only allow calls on bare identifiers (no method calls on
     * member expressions) — that matches the LLM-friendly syntax.
     */
    private function parsePostfix(): array
    {
        $node = $this->parsePrimary();
        while (true) {
            if ($this->matchOp('.')) {
                $prop = $this->expect('IDENT')['value'];
                $node = ['type' => 'member', 'object' => $node, 'prop' => $prop];

                continue;
            }
            break;
        }

        return $node;
    }

    private function parsePrimary(): array
    {
        $tok = $this->peek();
        if (! $tok) {
            throw $this->syntax('Unexpected end of expression');
        }

        if ($tok['kind'] === 'NUMBER' || $tok['kind'] === 'STRING') {
            $this->advance();

            return ['type' => 'literal', 'value' => $tok['value']];
        }

        if ($tok['kind'] === 'KEYWORD') {
            $this->advance();

            return ['type' => 'literal', 'value' => match ($tok['value']) {
                'true' => true,
                'false' => false,
                'null' => null,
            }];
        }

        if ($tok['kind'] === 'IDENT') {
            $this->advance();
            // Function call?
            if ($this->peekOp('(')) {
                $this->advance();
                $args = [];
                if (! $this->peekOp(')')) {
                    $args[] = $this->parseExpression();
                    while ($this->matchOp(',')) {
                        $args[] = $this->parseExpression();
                    }
                }
                $this->expectOp(')');

                return ['type' => 'call', 'name' => $tok['value'], 'args' => $args];
            }

            return ['type' => 'ident', 'name' => $tok['value']];
        }

        if ($this->matchOp('(')) {
            $node = $this->parseExpression();
            $this->expectOp(')');

            return $node;
        }

        throw $this->syntax("Unexpected token '{$tok['value']}'");
    }

    // ── Token helpers ────────────────────────────────────────────

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function peekOp(string $op): bool
    {
        $tok = $this->peek();

        return $tok !== null && $tok['kind'] === 'OP' && $tok['value'] === $op;
    }

    private function advance(): array
    {
        if (! isset($this->tokens[$this->pos])) {
            throw $this->syntax('Unexpected end of expression');
        }

        return $this->tokens[$this->pos++];
    }

    private function matchOp(string $op): bool
    {
        if ($this->peekOp($op)) {
            $this->advance();

            return true;
        }

        return false;
    }

    private function expect(string $kind): array
    {
        $tok = $this->peek();
        if (! $tok || $tok['kind'] !== $kind) {
            throw $this->syntax("Expected {$kind}");
        }

        return $this->advance();
    }

    private function expectOp(string $op): array
    {
        if (! $this->peekOp($op)) {
            throw $this->syntax("Expected '{$op}'");
        }

        return $this->advance();
    }

    private function syntax(string $msg): RuntimeException
    {
        $tok = $this->peek();
        $where = $tok ? " at position {$tok['pos']}" : '';

        return new RuntimeException("Expression parse error: {$msg}{$where} — in: {$this->source}");
    }
}
