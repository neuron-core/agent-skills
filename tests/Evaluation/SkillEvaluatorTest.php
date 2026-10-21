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
        $evaluator = new SkillLoadingEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            $output = $this->recordExecution('risk-review', ['risk-review'], 'What change are you planning?', $item['skills']);
            $this->assertTrue($evaluator->performEvaluation(Trajectory::fromMessages($output['messages']), $item)->isPassed());
        }
    }

    public function test_loading_does_not_require_a_final_answer(): void
    {
        $output = $this->recordExecution('risk-review', ['risk-review'], '');
        $this->assertTrue((new SkillLoadingEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), [])->isPassed());
    }

    public function test_a_claimed_result_cannot_replace_an_actual_skill_load(): void
    {
        $output = $this->recordExecution('risk-review', [], 'I reviewed the plan.');
        $this->assertFalse((new SkillLoadingEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), [])->isPassed());
    }

    public function test_discovery_accepts_loading_the_expected_skill_for_each_request(): void
    {
        $evaluator = new SkillDiscoveryEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            $output = $this->recordExecution($item['expectedSkill'], [$item['expectedSkill']], 'Here is the requested assessment.', $item['skills']);
            $this->assertTrue($evaluator->performEvaluation(Trajectory::fromMessages($output['messages']), $item)->isPassed());
        }
    }

    public function test_discovery_rejects_a_plausible_answer_without_the_expected_skill(): void
    {
        $evaluator = new SkillDiscoveryEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            foreach ([[], ['text-stats']] as $calls) {
                $output = $this->recordExecution($item['expectedSkill'], $calls, 'Here is the requested assessment.');
                $this->assertFalse($evaluator->performEvaluation(Trajectory::fromMessages($output['messages']), $item)->isPassed());
            }
        }
    }

    public function test_discovery_rejects_extra_calls(): void
    {
        $evaluator = new SkillDiscoveryEvaluator();

        foreach ([['bash', 'risk-review'], ['risk-review', 'bash'], ['risk-review', 'risk-review']] as $calls) {
            $output = $this->recordExecution('risk-review', $calls, 'Here is the requested assessment.');
            $this->assertFalse($evaluator->performEvaluation(
                Trajectory::fromMessages($output['messages']),
                ['expectedSkill' => 'risk-review'],
            )->isPassed());
        }
    }

    public function test_discovery_does_not_require_a_final_answer(): void
    {
        $output = $this->recordExecution('risk-review', ['risk-review'], '');
        $this->assertTrue((new SkillDiscoveryEvaluator())->performEvaluation(
            Trajectory::fromMessages($output['messages']),
            ['expectedSkill' => 'risk-review'],
        )->isPassed());
    }

    public function test_resource_cases_read_the_policy_and_check_the_priority_on_both_storages(): void
    {
        $evaluator = new SkillResourceEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            $fixture = new SkillFixture($item['skills']);
            $fixture->setUp();

            try {
                [$load, $resource] = (new SkillToolkit($fixture->repository))->tools();
                $load->setInputs(['name' => 'ticket-triage'])->execute();
                $resource->setInputs(['name' => 'ticket-triage', 'path' => 'references/priorities.md'])->execute();
                $loadCall = ToolCall::make('skill', 'load', ['name' => 'ticket-triage'])->setResult($load->getResult());
                $readCall = ToolCall::make('skill_resource', 'read', ['name' => 'ticket-triage', 'path' => 'references/priorities.md'])
                    ->setResult($resource->getResult());
                $messages = [
                    new ToolCallMessage(tools: [$loadCall]),
                    new ToolResultMessage([$loadCall]),
                    new ToolCallMessage(tools: [$readCall]),
                    new ToolResultMessage([$readCall]),
                ];

                $this->assertTrue($evaluator->performEvaluation(Trajectory::fromMessages([
                    ...$messages, new AssistantMessage($item['expectedPriority']),
                ]), $item)->isPassed());
                $this->assertFalse($evaluator->performEvaluation(Trajectory::fromMessages([
                    ...$messages, new AssistantMessage('P4'),
                ]), $item)->isPassed());
            } finally {
                $fixture->cleanUp();
            }
        }
    }

    public function test_dependency_cases_accept_the_required_loads_without_requiring_a_final_answer(): void
    {
        $evaluator = new SkillDependencyEvaluator();

        foreach ($evaluator->getDataset()->load() as $item) {
            $output = $this->recordExecution(
                $item['expectedSkill'],
                [$item['expectedSkill'], ...$item['expectedDependencies']],
                '',
                $item['skills'],
            );
            $this->assertTrue($evaluator->performEvaluation(Trajectory::fromMessages($output['messages']), $item)->isPassed());
        }
    }

    public function test_final_answer_cannot_replace_loading_a_required_skill(): void
    {
        foreach (['review-my-plan', 'release-readiness'] as $parent) {
            $output = $this->recordExecution($parent, [$parent], 'The deployment has risks and needs a rollback plan.');
            $this->assertFalse((new SkillDependencyEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), [
                'expectedSkill' => $parent,
                'expectedDependencies' => $parent === 'review-my-plan' ? ['risk-review'] : ['risk-review', 'rollback-plan'],
            ])->isPassed());
        }
    }

    public function test_repeated_alias_loads_without_the_dependency_fail(): void
    {
        $output = $this->recordExecution('review-my-plan', array_fill(0, 5, 'review-my-plan'), 'What plan would you like to discuss?');
        $result = (new SkillDependencyEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), [
            'expectedSkill' => 'review-my-plan',
            'expectedDependencies' => ['risk-review'],
        ]);
        $this->assertGreaterThanOrEqual(2, $result->failedCount);
    }

    public function test_repeated_successful_load_fails_even_when_the_dependency_is_eventually_used(): void
    {
        $output = $this->recordExecution('review-my-plan', ['review-my-plan', 'review-my-plan', 'risk-review'], 'What change are you planning?');
        $result = (new SkillDependencyEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), [
            'expectedSkill' => 'review-my-plan',
            'expectedDependencies' => ['risk-review'],
        ]);
        $this->assertSame(1, $result->failedCount);
        $this->assertStringContainsString('actual was [skill, skill, skill]', $result->failures[0]->getMessage());
    }

    public function test_loading_rejects_extra_calls_before_or_after_the_requested_skill(): void
    {
        foreach ([['bash', 'risk-review'], ['risk-review', 'bash'], ['risk-review', 'risk-review']] as $calls) {
            $output = $this->recordExecution('risk-review', $calls, 'What change are you planning?');
            $this->assertFalse((new SkillLoadingEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), [])->isPassed());
        }
    }

    public function test_loading_rejects_a_different_skill_or_a_failed_tool_result(): void
    {
        $evaluator = new SkillLoadingEvaluator();
        $output = $this->recordExecution('risk-review', ['rollback-plan'], 'What change are you planning?');
        $this->assertFalse($evaluator->performEvaluation(Trajectory::fromMessages($output['messages']), [])->isPassed());

        foreach ([null, '', '   ', \NeuronAI\Tools\ToolOutput::error('Could not load the skill.')] as $result) {
            $load = ToolCall::make('skill', 'failed', ['name' => 'risk-review']);

            if ($result !== null) {
                $load->setResult($result);
            }

            $output['messages'] = [new ToolCallMessage(tools: [$load]), new ToolResultMessage([$load]), new AssistantMessage('Done.')];
            $this->assertFalse($evaluator->performEvaluation(Trajectory::fromMessages($output['messages']), [])->isPassed());
        }
    }

    public function test_unnecessary_preparation_fails_even_when_the_answer_is_correct(): void
    {
        $output = $this->recordExecution('risk-review', ['risk-review', 'skill_script_prepare'], 'What change are you planning?');
        $result = (new SkillLoadingEvaluator())->performEvaluation(Trajectory::fromMessages($output['messages']), []);
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

    private function recordExecution(string $skill, array $calls, string $answer, ?array $skills = null): array
    {
        $fixture = new SkillFixture($skills ?? array_fill_keys(array_keys(SkillFixture::catalogFiles()), 'filesystem'));
        $fixture->setUp();
        $messages = [];
        try {
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

            return ['messages' => $messages];
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages($messages));
        }
    }
}
