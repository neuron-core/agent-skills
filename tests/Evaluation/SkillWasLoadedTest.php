<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Assertions\SkillWasLoaded;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class SkillWasLoadedTest extends TestCase
{
    public function test_requires_the_named_skill_to_return_the_expected_instructions_successfully(): void
    {
        $rule = new SkillWasLoaded('example', 'Expected instructions.');
        $call = ToolCall::make('skill', 'load', ['name' => 'example']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages([]))->passed);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages([
            new ToolCallMessage(tools: [$call]),
        ]))->passed);

        $messages = [new ToolCallMessage(tools: [$call]), new ToolResultMessage([$call])];
        $call->setResult(ToolOutput::error('Expected instructions.'));
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $call->setResult('Different instructions.');
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $call->setResult('Expected instructions.');
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $call->setResult(ToolOutput::text('Expected instructions.'));
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $call->setInputs(['name' => 'another-skill']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
    }

    public function test_accepts_a_successful_retry_even_with_other_skill_calls_afterwards(): void
    {
        $failed = ToolCall::make('skill', 'failed', ['name' => 'example'])->setResult(ToolOutput::error('Unavailable'));
        $retry = ToolCall::make('skill', 'retry', ['name' => 'example'])->setResult('Expected instructions.');
        $other = ToolCall::make('skill', 'other', ['name' => 'another-skill'])->setResult('Other instructions.');
        $messages = [];
        foreach ([$failed, $retry, $other] as $call) {
            $messages[] = new ToolCallMessage(tools: [$call]);
            $messages[] = new ToolResultMessage([$call]);
        }

        $this->assertTrue((new SkillWasLoaded('example', 'Expected instructions.'))
            ->evaluate(Trajectory::fromMessages($messages))->passed);
    }
}
