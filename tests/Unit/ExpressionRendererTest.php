<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Flows\TemplateEvaluator;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * TemplateEvaluator — `{{ expression }}` interpolation against a
 * slots+context map. Covers both `render()` (substitute actual
 * values) and `renderForPrompt()` (LLM-facing, keeps refs as
 * backticked placeholders).
 */
class ExpressionRendererTest extends TestCase
{
    private function templates(): TemplateEvaluator
    {
        return new TemplateEvaluator;
    }

    private function context(): array
    {
        return [
            'slots' => [
                'caller_name' => 'Pat',
                'caller_phone' => '5005551212',
            ],
            'context' => [
                'now' => new DateTimeImmutable('2026-04-23T14:30:00', new DateTimeZone('UTC')),
            ],
        ];
    }

    public function test_plain_text_passes_through(): void
    {
        $this->assertSame('hello', $this->templates()->render('hello', $this->context()));
    }

    public function test_single_placeholder(): void
    {
        $this->assertSame(
            'Hi Pat!',
            $this->templates()->render('Hi {{ caller_name }}!', $this->context()),
        );
    }

    public function test_multiple_placeholders_with_function(): void
    {
        $this->assertSame(
            'Pat at (500) 555-1212',
            $this->templates()->render('{{ caller_name }} at {{ format_phone(caller_phone) }}', $this->context()),
        );
    }

    public function test_expression_in_placeholder(): void
    {
        $this->assertSame(
            'Hello, PAT',
            $this->templates()->render('Hello, {{ upper(caller_name) }}', $this->context()),
        );
    }

    public function test_missing_value_renders_empty(): void
    {
        $this->assertSame(
            'Hi there',
            $this->templates()->render('Hi {{ does_not_exist }}there', $this->context()),
        );
    }

    public function test_render_for_prompt_preserves_refs(): void
    {
        $this->assertSame(
            'Hi `caller_name`, at `format_phone(caller_phone)`',
            $this->templates()->renderForPrompt('Hi {{ caller_name }}, at {{ format_phone(caller_phone) }}'),
        );
    }

    public function test_parse_error_preserves_placeholder(): void
    {
        $result = $this->templates()->render('{{ 1 + + 2 }}', $this->context());
        $this->assertStringContainsString('⚠', $result);
    }
}
