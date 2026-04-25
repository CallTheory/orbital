<?php

declare(strict_types=1);

namespace App\Services\Flows;

/**
 * Interpolate `{{ expression }}` placeholders in a template string
 * against a context of slots + call metadata.
 *
 *   "Hi {{ caller_name }}, we've got you at {{ format_phone(caller_phone) }}."
 *
 * Handlebars-ish syntax kept deliberately simple: one expression per
 * placeholder, no block helpers, no conditionals outside the
 * expression grammar. Nested `{{` inside an expression is treated as
 * part of the expression (you'd need to escape with `{{{` / `}}}` if
 * we ever hit that case — not today).
 */
class TemplateEvaluator
{
    public function __construct(
        private readonly ExpressionEvaluator $evaluator = new ExpressionEvaluator,
    ) {}

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        if ($template === '' || ! str_contains($template, '{{')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            function (array $m) use ($context): string {
                $source = $m[1];
                if (trim($source) === '') {
                    return '';
                }
                try {
                    $value = $this->evaluator->evaluate($source, $context);
                } catch (\Throwable $e) {
                    // Preserve the placeholder on error so the author
                    // can see what's broken rather than a silent drop.
                    return '{{ '.$source.' ⚠ '.$e->getMessage().' }}';
                }

                return $this->evaluator->coerceToString($value);
            },
            $template,
        );
    }

    /**
     * Render a template for the LLM prompt: rather than evaluating
     * placeholders to their current values, replace each `{{ expr }}`
     * with a markdown-quoted representation the model can substitute
     * at run time. Plain slot refs become `` `slot_name` ``;
     * function calls become `` `upper(caller_name)` ``.
     *
     * This is the path we use when baking templates into the system
     * prompt — we want the LLM to see the template's *structure*,
     * not a concrete evaluation.
     */
    public function renderForPrompt(string $template): string
    {
        if ($template === '' || ! str_contains($template, '{{')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            fn (array $m): string => '`'.trim($m[1]).'`',
            $template,
        );
    }
}
