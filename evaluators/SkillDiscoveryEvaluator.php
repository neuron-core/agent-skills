<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Evaluators\Assertions\EvidenceIsValid;
use NeuronAI\AgentSkills\Evaluators\Assertions\NoRepeatedSkillLoads;
use NeuronAI\AgentSkills\Evaluators\Assertions\SkillWasLoaded;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillDiscoveryEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skill' => 'risk-review',
                'mode' => 'chat',
                'prompt' => 'Before we implement a database column rename while old application instances are still running, identify failure modes, hidden assumptions and operational risks.',
            ],
            [
                'skill' => 'risk-review',
                'mode' => 'stream',
                'prompt' => 'We want to rename a database column while older application instances are still running. What could go wrong, which assumptions need checking, and how could we reduce the operational risks?',
            ],
            [
                'skill' => 'rollback-plan',
                'mode' => 'chat',
                'prompt' => 'We are deploying a new application image with no database changes. The previous image is available. Define when to restore it, the recovery steps and how to verify service is healthy afterward.',
            ],
            [
                'skill' => 'rollback-plan',
                'mode' => 'stream',
                'prompt' => 'Prepare a recovery procedure for a failed application deployment. There are no database changes and the previous image is available. Include recovery triggers, restoration steps and checks that service has recovered.',
            ],
            [
                'skill' => 'ticket-triage',
                'mode' => 'chat',
                'prompt' => 'A customer reports that the service is unavailable to all users, with no workaround. Classify this support ticket by priority and explain the classification.',
            ],
            [
                'skill' => 'ticket-triage',
                'mode' => 'stream',
                'prompt' => 'All customers are unable to access the service and no workaround is available. What priority should this support incident receive, and why?',
            ],
        ]);
    }

    public function run(array $datasetItem): array
    {
        $fixture = new SkillFixture($datasetItem['skill'], 'filesystem');

        $instructions = $fixture->instructions();
        $steps = [
            [
                'tool' => 'skill',
                'inputs' => ['name' => $datasetItem['skill']],
                'result' => $instructions[$datasetItem['skill']],
            ],
        ];

        $prompt = $datasetItem['prompt'];

        return $this->runSession(
            fixture: $fixture,
            prompt: $prompt,
            mode: $datasetItem['mode'],
        ) + ['steps' => $steps];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        if (!is_array($output) || !is_array($output['messages'] ?? null)) {
            throw new InvalidArgumentException('Expected recorded conversation messages and execution evidence.');
        }

        $trajectory = Trajectory::fromMessages($output['messages']);

        $this->assert(
            new NoRepeatedSkillLoads($output['instructions']),
            $trajectory,
            'successful skill loads are reused',
        );

        $this->assert(
            new ToolWasNotCalled('skill_script_prepare'),
            $trajectory,
            'no script preparation needed',
        );

        $skill = $datasetItem['skill'];

        $this->assert(
            new SkillWasLoaded($skill, $output['instructions'][$skill]),
            $trajectory,
            'relevant skill discovered and loaded',
        );

        $this->assert(
            new StringLengthBetween(1, 100000),
            $trajectory->finalAnswer(),
            'response provided',
        );

        $this->assert(
            new EvidenceIsValid(),
            ['execution completed' => $output['failure'] === null],
        );
    }
}
