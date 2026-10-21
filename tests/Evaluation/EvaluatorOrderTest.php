<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\SkillResourceEvaluator;
use NeuronAI\AgentSkills\Evaluators\ScriptWorkflowEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDependencyEvaluator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class EvaluatorOrderTest extends TestCase
{
    public function test_resource_read_requires_the_skill_instructions_to_be_available(): void
    {
        $steps = [
            ['tool' => 'skill', 'inputs' => ['name' => 'eval-reader'], 'result' => 'Read guide.'],
            ['tool' => 'skill_resource', 'inputs' => ['name' => 'eval-reader', 'path' => 'references/guide.md'], 'result' => 'TOKEN'],
        ];
        $load = ToolCall::make('skill', 'load', ['name' => 'eval-reader'])->setResult('Read guide.');
        $read = ToolCall::make('skill_resource', 'read', ['name' => 'eval-reader', 'path' => 'references/guide.md'])->setResult('TOKEN');
        $this->assertTrue($this->passes([[$load], [$read]], $steps, new SkillResourceEvaluator()));
        $this->assertFalse($this->passes([[$load, $read]], $steps, new SkillResourceEvaluator()));
        $this->assertFalse($this->passes([[$read], [$load]], $steps, new SkillResourceEvaluator()));
        $read->setResult(ToolOutput::error('Unavailable'));
        $this->assertFalse($this->passes([[$load], [$read]], $steps, new SkillResourceEvaluator()));
    }

    public function test_dependencies_require_successful_results_before_dependent_actions(): void
    {
        $steps = [
            ['tool' => 'skill', 'inputs' => ['name' => 'parent'], 'result' => 'Use child.'],
            ['tool' => 'skill', 'inputs' => ['name' => 'child'], 'result' => 'Done.'],
        ];
        $parent = ToolCall::make('skill', 'parent', ['name' => 'parent'])->setResult('Use child.');
        $child = ToolCall::make('skill', 'child', ['name' => 'child'])->setResult('Done.');

        $this->assertTrue($this->passes([[$parent], [$child]], $steps));
        $this->assertFalse($this->passes([[$parent, $child]], $steps));
        $this->assertFalse($this->passes([[$child], [$parent]], $steps));
        $this->assertFalse($this->passes([[$parent]], $steps));
        $child->setResult(ToolOutput::error('Unavailable'));
        $this->assertFalse($this->passes([[$parent], [$child]], $steps));
    }

    public function test_independent_dependencies_can_be_loaded_together_in_either_order(): void
    {
        $steps = [
            ['tool' => 'skill', 'inputs' => ['name' => 'parent'], 'result' => 'Use both.'],
            ['tool' => 'skill', 'inputs' => ['name' => 'first'], 'result' => 'First.'],
            ['tool' => 'skill', 'inputs' => ['name' => 'second'], 'result' => 'Second.'],
        ];
        $parent = ToolCall::make('skill', 'parent', ['name' => 'parent'])->setResult('Use both.');
        $first = ToolCall::make('skill', 'first', ['name' => 'first'])->setResult('First.');
        $second = ToolCall::make('skill', 'second', ['name' => 'second'])->setResult('Second.');

        $this->assertTrue($this->passes([[$parent], [$first, $second]], $steps));
        $this->assertTrue($this->passes([[$parent], [$second, $first]], $steps));
    }

    public function test_reporting_requires_a_separate_execution_after_observing_analysis(): void
    {
        $steps = [
            ['tool' => 'skill', 'inputs' => ['name' => 'eval-runner'], 'result' => 'Read reference.'],
            ['tool' => 'skill_resource', 'inputs' => ['name' => 'eval-runner', 'path' => 'references/run.md'], 'result' => 'Run scripts.'],
            ['tool' => 'skill_script_prepare', 'inputs' => ['name' => 'eval-runner']],
            ['tool' => 'bash', 'inputs' => [], 'output' => 'ANALYZED'],
            ['tool' => 'bash', 'inputs' => [], 'output' => 'REPORT'],
        ];
        $load = ToolCall::make('skill', 'load', ['name' => 'eval-runner'])->setResult('Read reference.');
        $read = ToolCall::make('skill_resource', 'read', ['name' => 'eval-runner', 'path' => 'references/run.md'])->setResult('Run scripts.');
        $prepare = ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'eval-runner'])
            ->setResult(['directory' => '/tmp/example', 'files' => []]);
        $analyze = ToolCall::make('bash', 'analyze', ['command' => 'php scripts/analyze.php'])
            ->setResult(['exit_code' => 0, 'output' => 'ANALYZED']);
        $report = ToolCall::make('bash', 'report', ['command' => 'php scripts/report.php'])
            ->setResult(['exit_code' => 0, 'output' => 'REPORT']);
        $prefix = [[$load], [$read], [$prepare]];
        $this->assertTrue($this->passes([...$prefix, [$analyze], [$report]], $steps, new ScriptWorkflowEvaluator()));
        $this->assertFalse($this->passes([...$prefix, [$analyze, $report]], $steps, new ScriptWorkflowEvaluator()));

        $combined = ToolCall::make('bash', 'combined', ['command' => 'php scripts/analyze.php && php scripts/report.php'])
            ->setResult(['exit_code' => 0, 'output' => "ANALYZED\nREPORT"]);
        $this->assertFalse($this->passes([...$prefix, [$combined]], $steps, new ScriptWorkflowEvaluator()));
        $claimed = ToolCall::make('bash', 'claimed', ['command' => 'echo ANALYZED'])
            ->setResult(['exit_code' => 0, 'output' => 'ANALYZED']);
        $this->assertFalse($this->passes([...$prefix, [$claimed], [$report]], $steps, new ScriptWorkflowEvaluator()));
    }

    /** @param list<list<ToolCall>> $batches */
    private function passes(array $batches, array $steps, ?BaseEvaluator $evaluator = null): bool
    {
        $messages = [];
        foreach ($batches as $batch) {
            $messages[] = new ToolCallMessage(tools: $batch);
            $messages[] = new ToolResultMessage($batch);
        }
        $messages[] = new AssistantMessage('TOKEN');
        $evaluator ??= new SkillDependencyEvaluator();

        $instructions = [];
        foreach ($steps as $step) {
            if ($step['tool'] === 'skill') {
                $instructions[$step['inputs']['name']] = $step['result'];
            }
        }

        return $evaluator->performEvaluation([
            'messages' => $messages, 'steps' => $steps, 'instructions' => $instructions, 'expected_total' => 'TOKEN', 'artifacts' => [], 'failure' => null,
        ], ['priority' => 'TOKEN'])->isPassed();
    }
}
