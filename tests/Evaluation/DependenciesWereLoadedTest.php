<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Assertions\DependenciesWereLoaded;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class DependenciesWereLoadedTest extends TestCase
{
    public function test_each_dependency_must_return_its_expected_instructions(): void
    {
        $rule = new DependenciesWereLoaded('parent', 'Use child.', ['child' => 'Child instructions.']);
        $parent = ToolCall::make('skill', 'parent', ['name' => 'parent'])->setResult('Use child.');
        $child = ToolCall::make('skill', 'child', ['name' => 'child'])->setResult('Child instructions.');
        $messages = [new ToolCallMessage(tools: [$parent]), new ToolResultMessage([$parent]),
            new ToolCallMessage(tools: [$child]), new ToolResultMessage([$child])];
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $child->setResult('Wrong instructions.');
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $child->setResult(ToolOutput::error('Child instructions.'));
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
    }
}
