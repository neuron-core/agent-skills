<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Assertions\NoRepeatedSkillLoads;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class NoRepeatedSkillLoadsTest extends TestCase
{
    public function test_a_failed_load_can_be_retried(): void
    {
        $failed = ToolCall::make('skill', 'failed', ['name' => 'review'])->setResult(ToolOutput::error('Unavailable'));
        $loaded = ToolCall::make('skill', 'loaded', ['name' => 'review'])->setResult('Review the plan.');
        $rule = new NoRepeatedSkillLoads(['review' => 'Review the plan.']);

        $this->assertTrue($rule->evaluate(Trajectory::fromMessages([
            new ToolCallMessage(tools: [$failed]), new ToolResultMessage([$failed]),
            new ToolCallMessage(tools: [$loaded]), new ToolResultMessage([$loaded]),
        ]))->passed);
    }

    public function test_successful_loads_can_be_reused_in_later_user_turns(): void
    {
        $first = ToolCall::make('skill', 'first', ['name' => 'review'])->setResult('Review the plan.');
        $second = ToolCall::make('skill', 'second', ['name' => 'review'])->setResult('Review the plan.');
        $rule = new NoRepeatedSkillLoads(['review' => 'Review the plan.']);

        $this->assertFalse($rule->evaluate(Trajectory::fromMessages([
            new ToolCallMessage(tools: [$first]), new ToolResultMessage([$first]),
            new ToolCallMessage(tools: [$second]), new ToolResultMessage([$second]),
        ]))->passed);
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages([
            new ToolCallMessage(tools: [$first]), new ToolResultMessage([$first]),
            new UserMessage('Review this revised plan.'),
            new ToolCallMessage(tools: [$second]), new ToolResultMessage([$second]),
        ]))->passed);
    }
}
