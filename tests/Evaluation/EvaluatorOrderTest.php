<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\SkillResourceEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDiscoveryWithResourceEvaluator;
use NeuronAI\AgentSkills\Evaluators\ScriptExecutionEvaluator;
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

    public function test_single_script_evaluation_allows_execution_retries_after_reading_and_preparation(): void
    {
        $load = ToolCall::make('skill', 'load', ['name' => 'csv-check'])->setResult('Read the format, then validate.');
        $read = ToolCall::make('skill_resource', 'read', ['name' => 'csv-check', 'path' => 'references/order-format.md'])
            ->setResult('Required CSV columns.');
        $prepare = ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'csv-check'])
            ->setResult(['directory' => '/tmp/example', 'files' => ['scripts/validate.py']]);
        $inputs = ['command' => 'python3 scripts/validate.py orders.csv', 'working_directory' => '/tmp/example'];
        $failed = ToolCall::make('bash', 'failed', $inputs)->setResult(ToolOutput::error('File not found.'));
        $wrong = ToolCall::make('bash', 'wrong', $inputs)->setResult(['exit_code' => 0, 'output' => 'WRONG']);
        $correct = ToolCall::make('bash', 'correct', $inputs)->setResult(['exit_code' => 0, 'output' => 'TOKEN']);
        $unrelated = ToolCall::make('bash', 'unrelated', ['command' => 'echo TOKEN'])
            ->setResult(['exit_code' => 0, 'output' => 'TOKEN']);
        $evaluator = new ScriptExecutionEvaluator();

        $this->assertTrue($this->passes([[$load], [$read], [$prepare], [$correct]], [], $evaluator));
        $this->assertTrue($this->passes([[$load], [$read], [$prepare], [$failed], [$correct]], [], $evaluator));
        $this->assertTrue($this->passes([[$load], [$read], [$prepare], [$wrong], [$correct]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$read], [$prepare], [$failed]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$read], [$prepare], [$correct], [$failed]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$read], [$prepare], [$unrelated], [$correct]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$read, $prepare], [$correct]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$read], [$prepare], [$failed, $correct]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$load], [$read], [$prepare], [$correct]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$read], [$failed], [$prepare], [$correct]], [], $evaluator));
        $this->assertFalse($this->passes([[$load], [$prepare], [$failed], [$correct]], [], $evaluator));
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
            'expectedOutput' => 'TOKEN',
        ])->isPassed();
    }
}
