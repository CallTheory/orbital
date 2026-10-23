<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * The shared "AI intake → live operator handoff" orchestration.
 *
 * The AI answers, collects every intake field the client defines, then
 * hands the caller to the client's outside operator, whose system fetches
 * the fields through the handoff API (GET /api/v1/handoffs/lookup).
 * Applied to a client from Orchestrations → Apply to client, which adds
 * it to the client's Inbound Phone channel and sets the handoff number.
 */
class IntakeHandoffTemplateSeeder extends AiIntakeTemplateSeeder
{
    public const NAME = 'AI Intake → Live Operator Handoff';

    protected function name(): string
    {
        return self::NAME;
    }

    protected function description(): string
    {
        return 'The AI answers, collects the client\'s intake fields, then transfers the caller to the client\'s live operator, whose system fetches the fields through the handoff API.';
    }

    protected function flowName(): string
    {
        return 'Intake & handoff';
    }

    protected function flowDescription(): string
    {
        return 'Collect the intake fields, then hand off to the live operator.';
    }

    protected function followUpSteps(): array
    {
        // Each client sets its own number when the template is applied.
        return [['transfer_call', ['destination_type' => 'external', 'destination' => '', 'mode' => 'cold']]];
    }
}
