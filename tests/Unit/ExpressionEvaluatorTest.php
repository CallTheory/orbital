<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Flows\ExpressionEvaluator;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * JS-like expression parser + evaluator. Tests cover:
 *
 *   - identifier resolution (slots first, then context fallback)
 *   - dotted paths (`call.did`, `caller.matched`)
 *   - arithmetic + operator precedence
 *   - string concat via `+` (when either side is non-numeric)
 *   - comparison + boolean operators + short-circuit
 *   - ternary
 *   - function call dispatch (snake_case + camelCase alias)
 *   - common library functions (format_phone, format_date, upper,
 *     left, right, mid, index_of, is_empty, iif, round, currency)
 *   - parse error on syntax
 */
class ExpressionEvaluatorTest extends TestCase
{
    private function evaluator(): ExpressionEvaluator
    {
        return new ExpressionEvaluator;
    }

    private function context(): array
    {
        return [
            'slots' => [
                'caller_name' => 'Pat',
                'caller_phone' => '5005551212',
                'amount_due' => 1234.5,
                'is_member' => true,
                'birthday' => '1990-06-15',
            ],
            'context' => [
                'now' => new DateTimeImmutable('2026-04-23T14:30:00', new DateTimeZone('UTC')),
                'call' => ['did' => '+15005551000'],
                'agent' => ['name' => 'Ava'],
                'caller' => ['id' => '42', 'matched' => true],
            ],
        ];
    }

    public function test_string_literal(): void
    {
        $this->assertSame('hello', $this->evaluator()->evaluate('"hello"', $this->context()));
    }

    public function test_number_literal(): void
    {
        $this->assertSame(42, $this->evaluator()->evaluate('42', $this->context()));
        $this->assertSame(3.14, $this->evaluator()->evaluate('3.14', $this->context()));
    }

    public function test_bool_and_null_literals(): void
    {
        $eval = $this->evaluator();
        $this->assertTrue($eval->evaluate('true', $this->context()));
        $this->assertFalse($eval->evaluate('false', $this->context()));
        $this->assertNull($eval->evaluate('null', $this->context()));
    }

    public function test_bare_identifier_resolves_slot(): void
    {
        $this->assertSame('Pat', $this->evaluator()->evaluate('caller_name', $this->context()));
    }

    public function test_dotted_identifier_resolves_context(): void
    {
        $this->assertSame('+15005551000', $this->evaluator()->evaluate('call.did', $this->context()));
        $this->assertTrue($this->evaluator()->evaluate('caller.matched', $this->context()));
    }

    public function test_missing_identifier_returns_null(): void
    {
        $this->assertNull($this->evaluator()->evaluate('does_not_exist', $this->context()));
    }

    public function test_string_concatenation_via_plus(): void
    {
        $this->assertSame(
            'Hello Pat!',
            $this->evaluator()->evaluate('"Hello " + caller_name + "!"', $this->context()),
        );
    }

    public function test_arithmetic_precedence(): void
    {
        $this->assertSame(14, $this->evaluator()->evaluate('2 + 3 * 4', $this->context()));
        $this->assertSame(20, $this->evaluator()->evaluate('(2 + 3) * 4', $this->context()));
    }

    public function test_unary_minus(): void
    {
        $this->assertSame(-2, $this->evaluator()->evaluate('-5 + 3', $this->context()));
    }

    public function test_comparison_and_logic(): void
    {
        $eval = $this->evaluator();
        $this->assertTrue($eval->evaluate('amount_due > 100 && is_member', $this->context()));
        $this->assertFalse($eval->evaluate('amount_due > 100 && !is_member', $this->context()));
    }

    public function test_short_circuit_or(): void
    {
        $this->assertTrue($this->evaluator()->evaluate('true || does_not_exist.blow_up', $this->context()));
    }

    public function test_ternary(): void
    {
        $this->assertSame('yes', $this->evaluator()->evaluate('is_member ? "yes" : "no"', $this->context()));
    }

    public function test_fn_format_phone(): void
    {
        $this->assertSame(
            '(500) 555-1212',
            $this->evaluator()->evaluate('format_phone(caller_phone)', $this->context()),
        );
    }

    public function test_fn_upper_and_lower(): void
    {
        $eval = $this->evaluator();
        $this->assertSame('PAT', $eval->evaluate('upper(caller_name)', $this->context()));
        $this->assertSame('pat', $eval->evaluate('lower(caller_name)', $this->context()));
    }

    public function test_fn_format_date_with_now(): void
    {
        $this->assertSame(
            '4/23/2026',
            $this->evaluator()->evaluate('format_date(now(), "ShortDate")', $this->context()),
        );
    }

    public function test_fn_left_right_mid(): void
    {
        $eval = $this->evaluator();
        $this->assertSame('Orb', $eval->evaluate('left("Orbital", 3)', $this->context()));
        $this->assertSame('tal', $eval->evaluate('right("Orbital", 3)', $this->context()));
        $this->assertSame('rbi', $eval->evaluate('mid("Orbital", 2, 3)', $this->context()));
    }

    public function test_fn_index_of(): void
    {
        $this->assertSame(
            7,
            $this->evaluator()->evaluate('index_of("hello world", "world")', $this->context()),
        );
    }

    public function test_fn_is_empty(): void
    {
        $eval = $this->evaluator();
        $this->assertTrue($eval->evaluate('is_empty(does_not_exist)', $this->context()));
        $this->assertFalse($eval->evaluate('is_empty(caller_name)', $this->context()));
    }

    public function test_fn_iif_and_round_and_currency(): void
    {
        $eval = $this->evaluator();
        $this->assertSame('yes', $eval->evaluate('iif(is_member, "yes", "no")', $this->context()));
        $this->assertEquals(1234.5, $eval->evaluate('round(amount_due, 2)', $this->context()));
        $this->assertSame('$1,234.50', $eval->evaluate('currency(amount_due)', $this->context()));
    }

    public function test_fn_get_age(): void
    {
        $this->assertSame(
            35,
            $this->evaluator()->evaluate('get_age(birthday)', $this->context()),
        );
    }

    public function test_camelcase_alias_for_functions(): void
    {
        $eval = $this->evaluator();
        $this->assertSame('PAT', $eval->evaluate('UCase(caller_name)', $this->context()));
        $this->assertSame('(500) 555-1212', $eval->evaluate('FormatPhone(caller_phone)', $this->context()));
    }

    public function test_syntax_error_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->evaluator()->evaluate('1 + + 2', []);
    }

    public function test_unknown_function_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->evaluator()->evaluate('does_not_exist()', []);
    }
}
