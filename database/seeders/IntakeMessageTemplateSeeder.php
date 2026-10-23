<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * The shared "AI intake → take a message" orchestration, for overflow.
 *
 * Same intake as the live operator handoff, but instead of transferring,
 * the AI confirms the message with the caller and ends the call. Orbital
 * saves it as a Message once the caller's name and reason are captured
 * (SessionMessageWriter), and operators see it in their Workspace.
 */
class IntakeMessageTemplateSeeder extends AiIntakeTemplateSeeder
{
    public const NAME = 'AI Intake → Take a Message';

    protected function name(): string
    {
        return self::NAME;
    }

    protected function description(): string
    {
        return 'The AI answers, collects the client\'s intake fields as a message, confirms it with the caller and hangs up. For overflow: nobody is transferred.';
    }

    protected function flowName(): string
    {
        return 'Intake & message';
    }

    protected function flowDescription(): string
    {
        return 'Collect the intake fields, confirm the message, then end the call.';
    }

    protected function followUpSteps(): array
    {
        return [['save_message', [
            'include_slots' => array_column(self::DEFAULT_SLOTS, 'name'),
            'destination' => 'inbox',
            'prompt' => 'Let me read that back to make sure I have it right.',
        ]]];
    }
}
