<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\SkillResourceEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDiscoveryWithResourceEvaluator;
use NeuronAI\AgentSkills\Evaluators\ScriptWorkflowEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDependencyEvaluator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class EvaluatorOrderTest extends TestCase
{
    public function test_resource_read_requires_the_skill_instructions_to_be_available(): void
    {
        $steps = [
            ['tool' => 'skill', 'inputs' => ['name' => 'ticket-triage'], 'result' => 'Read guide.'],
            ['tool' => 'skill_resource', 'inputs' => ['name' => 'ticket-triage', 'path' => 'references/priorities.md'], 'result' => 'TOKEN'],
        ];
        $load = ToolCall::make('skill', 'load', ['name' => 'ticket-triage'])->setResult('Read guide.');
        $read = ToolCall::make('skill_resource', 'read', ['name' => 'ticket-triage', 'path' => 'references/priorities.md'])->setResult('TOKEN');
        $this->assertTrue($this->passes([[$load], [$read]], $steps, new SkillResourceEvaluator()));
        $this->assertFalse($this->passes([[$load, $read]], $steps, new SkillResourceEvaluator()));
        $this->assertFalse($this->passes([[$read], [$load]], $steps, new SkillResourceEvaluator()));
        $this->assertFalse($this->passes([[$load], [$read], [$read]], $steps, new SkillResourceEvaluator()));
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

    public function test_discovery_with_resource_requires_the_skill_result_before_selecting_the_reference(): void
    {
        $load = ToolCall::make('skill', 'load', ['name' => 'ticket-triage'])->setResult('Read the policy.');
        $read = ToolCall::make('skill_resource', 'read', ['name' => 'ticket-triage', 'path' => 'references/priorities.md'])
            ->setResult('Policy contents.');
        $evaluator = new SkillDiscoveryWithResourceEvaluator();
        $item = ['expectedSkill' => 'ticket-triage'];

        $sequential = Trajectory::fromMessages([
            new ToolCallMessage(tools: [$load]),
            new ToolResultMessage([$load]),
            new ToolCallMessage(tools: [$read]),
            new ToolResultMessage([$read]),
        ]);
        $this->assertTrue($evaluator->performEvaluation($sequential, $item)->isPassed());

        $batched = Trajectory::fromMessages([
            new ToolCallMessage(tools: [$load, $read]),
            new ToolResultMessage([$load, $read]),
        ]);
        $this->assertFalse($evaluator->performEvaluation($batched, $item)->isPassed());
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
        $this->assertTrue($this->passes([[$parent], [$second], [$first]], $steps));
        $this->assertFalse($this->passes([[$parent], [$first, $first]], $steps));
    }

    public function test_reporting_requires_configuration_preparation_and_separate_execution_after_analysis(): void
    {
        $steps = [['tool' => 'skill', 'inputs' => ['name' => 'text-stats'], 'result' => 'Read configuration, analyze, inspect, then report.']];
        $load = ToolCall::make('skill', 'load', ['name' => 'text-stats'])->setResult($steps[0]['result']);
        $config = ToolCall::make('skill_resource', 'config', ['name' => 'text-stats', 'path' => 'config/analysis.yaml'])
            ->setResult('max_words: 12');
        $prepare = ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'text-stats'])
            ->setResult(['directory' => '/tmp/example', 'files' => []]);
        $counts = '{"words":2,"lines":1,"characters":10,"max_words":12}';
        $analyze = ToolCall::make('bash', 'analyze', ['command' => 'python3 scripts/analyze.py --max-words 12', 'working_directory' => '/tmp/example'])
            ->setResult(['exit_code' => 0, 'output' => $counts]);
        $report = ToolCall::make('bash', 'report', ['command' => 'python3 scripts/report.py', 'working_directory' => '/tmp/example'])
            ->setResult(['exit_code' => 0, 'output' => 'PASS: 2 words, limit 12.']);
        $prefix = [[$load], [$config], [$prepare]];
        $evaluator = new ScriptWorkflowEvaluator();
        $this->assertTrue($this->passes([...$prefix, [$analyze], [$report]], $steps, $evaluator));
        $this->assertFalse($this->passes([...$prefix, [$analyze, $report]], $steps, $evaluator));
        $this->assertFalse($this->passes([[$load], [$prepare], [$analyze], [$report]], $steps, $evaluator));
        $this->assertFalse($this->passes([[$load], [$config, $prepare], [$analyze], [$report]], $steps, $evaluator));

        $combined = ToolCall::make('bash', 'combined', ['command' => 'python3 scripts/analyze.py --max-words 12 | python3 scripts/report.py'])
            ->setResult(['exit_code' => 0, 'output' => 'PASS: 2 words, limit 12.']);
        $this->assertFalse($this->passes([...$prefix, [$combined]], $steps, $evaluator));
        $claimed = ToolCall::make('bash', 'claimed', ['command' => 'echo '.escapeshellarg($counts)])
            ->setResult(['exit_code' => 0, 'output' => $counts]);
        $this->assertFalse($this->passes([...$prefix, [$claimed], [$report]], $steps, $evaluator));

        $report->setResult(['exit_code' => 0, 'output' => $counts]);
        $this->assertFalse($this->passes([...$prefix, [$analyze], [$report]], $steps, $evaluator));
        $report->setResult(['exit_code' => 0, 'output' => 'PASS: 2 words, limit 12.']);
        $analyze->setResult(['exit_code' => 0, 'output' => '{"words":2,"lines":1,"characters":10,"max_words":11}']);
        $this->assertFalse($this->passes([...$prefix, [$analyze], [$report]], $steps, $evaluator));
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

        if ($evaluator instanceof SkillResourceEvaluator) {
            return $evaluator->performEvaluation(Trajectory::fromMessages($messages), ['expectedPriority' => 'TOKEN'])->isPassed();
        }

        if ($evaluator instanceof SkillDependencyEvaluator) {
            return $evaluator->performEvaluation(Trajectory::fromMessages($messages), [
                'expectedSkill' => $steps[0]['inputs']['name'],
                'expectedDependencies' => array_column(array_column(array_slice($steps, 1), 'inputs'), 'name'),
            ])->isPassed();
        }

        return $evaluator->performEvaluation(Trajectory::fromMessages($messages), [
            'expectedAnalysis' => '{"words":2,"lines":1,"characters":10,"max_words":12}',
            'expectedReport' => 'PASS: 2 words, limit 12.',
        ])->isPassed();
    }
}
