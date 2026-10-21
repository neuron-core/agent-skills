<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Assertions\ScriptsWereExecuted;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class ScriptsWereExecutedTest extends TestCase
{
    public function test_requires_the_script_command_successful_exit_and_expected_output(): void
    {
        $rule = new ScriptsWereExecuted('example', ['scripts/check.py' => 'TOKEN'], 'references/run.md');
        $calls = [
            ToolCall::make('skill', 'load', ['name' => 'example'])->setResult('Read reference.'),
            ToolCall::make('skill_resource', 'read', ['name' => 'example', 'path' => 'references/run.md'])->setResult('Run script.'),
            ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'example'])->setResult(['directory' => '/tmp/example', 'files' => []]),
            ToolCall::make('bash', 'execute', ['command' => 'python3 scripts/check.py', 'working_directory' => '/tmp/example'])
                ->setResult(['exit_code' => 0, 'output' => 'TOKEN']),
        ];
        $messages = [];
        foreach ($calls as $call) {
            $messages[] = new ToolCallMessage(tools: [$call]);
            $messages[] = new ToolResultMessage([$call]);
        }
        $this->assertTrue($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $calls[3]->setResult(['exit_code' => 1, 'output' => 'TOKEN']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $calls[3]->setResult(['exit_code' => 0, 'output' => 'WRONG']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $calls[3]->setResult(['exit_code' => 0, 'output' => 'TOKEN'])->setInputs(['command' => 'echo TOKEN']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $calls[3]->setInputs(['command' => 'python3 scripts/check.py', 'working_directory' => '/tmp/example'])
            ->setResult(ToolOutput::error('TOKEN'));
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
        $calls[3]->setResult(['exit_code' => 0, 'output' => 'TOKEN'])
            ->setInputs(['command' => 'python3 scripts/check.py', 'working_directory' => '/tmp/unprepared']);
        $this->assertFalse($rule->evaluate(Trajectory::fromMessages($messages))->passed);
    }
}
