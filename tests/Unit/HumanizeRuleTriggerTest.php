<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\IntakeFlowRule;
use App\Services\Flows\AgentFlowCompiler;
use App\Services\Flows\JsonLogicRenderer;
use App\Services\Flows\TemplateEvaluator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Exercises the per-trigger humanizer on AgentFlowCompiler via
 * reflection. Locks down the short phrases the LLM sees when a rule
 * fires — they're user-facing in the prompt.
 */
class HumanizeRuleTriggerTest extends TestCase
{
    private function compiler(): AgentFlowCompiler
    {
        return new AgentFlowCompiler(new JsonLogicRenderer, new TemplateEvaluator);
    }

    private function humanize(string $event): string
    {
        $m = new ReflectionMethod(AgentFlowCompiler::class, 'humanizeRuleTrigger');
        $m->setAccessible(true);

        return $m->invoke($this->compiler(), $event);
    }

    public function test_on_field_set(): void
    {
        $this->assertSame('a slot is set', $this->humanize(IntakeFlowRule::TRIGGER_ON_FIELD_SET));
    }

    public function test_on_step_enter(): void
    {
        $this->assertSame('a step starts', $this->humanize(IntakeFlowRule::TRIGGER_ON_STEP_ENTER));
    }

    public function test_on_step_complete(): void
    {
        $this->assertSame('a step finishes', $this->humanize(IntakeFlowRule::TRIGGER_ON_STEP_COMPLETE));
    }

    public function test_on_flow_start(): void
    {
        $this->assertSame('the flow begins', $this->humanize(IntakeFlowRule::TRIGGER_ON_FLOW_START));
    }

    public function test_on_flow_end(): void
    {
        $this->assertSame('the flow ends', $this->humanize(IntakeFlowRule::TRIGGER_ON_FLOW_END));
    }

    public function test_unknown_falls_through(): void
    {
        $this->assertSame('weird_event', $this->humanize('weird_event'));
    }
}
