<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Assertions\ResourceWasRead;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class ResourceWasReadTest extends TestCase
{
    public function test_requires_the_literal_resource_and_a_successful_nonempty_result_after_loading_the_skill(): void
    {
        $rule = new ResourceWasRead('example', 'references/my guide.md');
        $load = ToolCall::make('skill', 'load', ['name' => 'example'])->setResult('Read the guide.');
        $read = ToolCall::make('skill_resource', 'read', ['name' => 'example', 'path' => 'references/my guide.md'])->setResult('TOKEN');
        $messages = [new ToolCallMessage(tools: [$load]), new ToolResultMessage([$load]),
            new ToolCallMessage(tools: [$read]), new ToolResultMessage([$read])];
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $read->setInputs(['name' => 'example', 'path' => 'references/my%20guide.md']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $read->setInputs(['name' => 'example', 'path' => 'references/my guide.md'])->setResult(ToolOutput::error('TOKEN'));
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $read->setResult('');
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $read->setResult('   ');
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $read->setResult(ToolOutput::text('Resource contents.'));
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $load->setResult(ToolOutput::error('Unavailable'));
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $load->setResult('');
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
    }
}
