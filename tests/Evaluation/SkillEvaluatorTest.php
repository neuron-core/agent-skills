<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\AgentSkills\Evaluators\ScriptExecutionEvaluator;
use NeuronAI\AgentSkills\Evaluators\ScriptWorkflowEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDependencyEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDiscoveryEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillLoadingEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillResourceEvaluator;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use PHPUnit\Framework\TestCase;

class SkillEvaluatorTest extends TestCase
{
    public function test_successful_skill_loading_is_accepted_without_a_model(): void
    {
        $output = $this->recordExecution('risk-review', ['risk-review'], 'What change are you planning?');
        $this->assertTrue((new SkillLoadingEvaluator())->performEvaluation($output, [])->isPassed());
    }

    public function test_a_claimed_result_cannot_replace_an_actual_skill_load(): void
    {
        $output = $this->recordExecution('risk-review', [], 'I reviewed the plan.');
        $this->assertFalse((new SkillLoadingEvaluator())->performEvaluation($output, [])->isPassed());
    }

    public function test_discovery_accepts_loading_the_expected_skill_for_each_request(): void
    {
        $evaluator = new SkillDiscoveryEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            $output = $this->recordExecution($item['skill'], [$item['skill']], 'Here is the requested assessment.');
            $this->assertTrue($evaluator->performEvaluation($output, $item)->isPassed());
        }
    }

    public function test_discovery_rejects_a_plausible_answer_without_the_expected_skill(): void
    {
        $evaluator = new SkillDiscoveryEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            foreach ([[], ['sales-report']] as $calls) {
                $output = $this->recordExecution($item['skill'], $calls, 'Here is the requested assessment.');
                $this->assertFalse($evaluator->performEvaluation($output, $item)->isPassed());
            }
        }
    }

    public function test_final_answer_cannot_replace_loading_a_required_skill(): void
    {
        foreach (['review-my-plan', 'release-readiness'] as $parent) {
            $output = $this->recordExecution($parent, [$parent], 'The deployment has risks and needs a rollback plan.');
            $this->assertFalse((new SkillDependencyEvaluator())->performEvaluation($output, [])->isPassed());
        }
    }

    public function test_repeated_alias_loads_without_the_dependency_fail(): void
    {
        $output = $this->recordExecution('review-my-plan', array_fill(0, 5, 'review-my-plan'), 'What plan would you like to discuss?');
        $result = (new SkillDependencyEvaluator())->performEvaluation($output, []);
        $this->assertGreaterThanOrEqual(2, $result->failedCount);
    }

    public function test_repeated_successful_load_fails_even_when_the_dependency_is_eventually_used(): void
    {
        $output = $this->recordExecution('review-my-plan', ['review-my-plan', 'review-my-plan', 'risk-review'], 'What change are you planning?');
        $result = (new SkillDependencyEvaluator())->performEvaluation($output, []);
        $this->assertSame(1, $result->failedCount);
        $this->assertStringContainsString('loaded again', $result->failures[0]->getMessage());
    }

    public function test_an_independent_extra_call_does_not_replace_or_invalidate_the_required_work(): void
    {
        $output = $this->recordExecution('risk-review', ['risk-review', 'bash'], 'What change are you planning?');
        $this->assertTrue((new SkillLoadingEvaluator())->performEvaluation($output, [])->isPassed());
    }

    public function test_unnecessary_preparation_fails_even_when_the_answer_is_correct(): void
    {
        $output = $this->recordExecution('risk-review', ['risk-review', 'skill_script_prepare'], 'What change are you planning?');
        $result = (new SkillLoadingEvaluator())->performEvaluation($output, []);
        $this->assertSame(1, $result->failedCount);
        $this->assertStringContainsString('skill_script_prepare', $result->failures[0]->getMessage());
    }

    public function test_native_discovery_finds_six_evaluators_in_alphabetical_order(): void
    {
        $classes = (new EvaluatorDiscovery())->discover(dirname(__DIR__, 2).'/evaluators');
        $this->assertSame([
            ScriptExecutionEvaluator::class,
            ScriptWorkflowEvaluator::class,
            SkillDependencyEvaluator::class,
            SkillDiscoveryEvaluator::class,
            SkillLoadingEvaluator::class,
            SkillResourceEvaluator::class,
        ], $classes);
    }

    private function recordExecution(string $skill, array $calls, string $answer): array
    {
        $fixture = new SkillFixture($skill, 'filesystem');
        $messages = [];
        try {
            $instructions = $fixture->instructions();
            $names = match ($skill) {
                'review-my-plan' => [$skill, 'risk-review'],
                'release-readiness' => [$skill, 'risk-review', 'rollback-plan'],
                default => [$skill],
            };
            $steps = [];
            foreach ($names as $name) {
                $steps[] = ['tool' => 'skill', 'inputs' => ['name' => $name], 'result' => $instructions[$name]];
            }
            [$load, , $prepare] = (new SkillToolkit($fixture->repository))->tools();
            foreach ($calls as $index => $name) {
                if ($name === 'bash') {
                    $tool = new BashTool();
                    $toolName = 'bash';
                    $inputs = ['command' => 'printf extra'];
                } elseif ($name === 'skill_script_prepare') {
                    $tool = $prepare;
                    $toolName = $name;
                    $inputs = ['name' => $skill];
                } else {
                    $tool = $load;
                    $toolName = 'skill';
                    $inputs = ['name' => $name];
                }
                $tool->setInputs($inputs)->execute();
                $call = ToolCall::make($toolName, (string) $index, $inputs)->setResult($tool->getResult());
                $messages[] = new ToolCallMessage(tools: [$call]);
                $messages[] = new ToolResultMessage([$call]);
            }
            $messages[] = new AssistantMessage($answer);

            return ['messages' => $messages, 'steps' => $steps, 'instructions' => $instructions,
                'artifacts' => [], 'failure' => null];
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages($messages));
        }
    }
}
