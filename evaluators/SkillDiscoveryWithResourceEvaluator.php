<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use NeuronAI\AgentSkills\Evaluators\Assertions\ResourceWasRead;
use NeuronAI\AgentSkills\Evaluators\Assertions\SkillWasLoaded;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillDiscoveryWithResourceEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'ticket-triage',
                'prompt' => 'A customer reports that the service is unavailable to all users, with no workaround. Classify this support ticket by priority and explain the classification.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'database',
                ],
                'expectedSkill' => 'ticket-triage',
                'prompt' => 'A customer reports that the service is unavailable to all users, with no workaround. Classify this support ticket by priority and explain the classification.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'ticket-triage',
                'prompt' => 'All customers are unable to access the service and no workaround is available. What priority should this support incident receive, and why?',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'database',
                ],
                'expectedSkill' => 'ticket-triage',
                'prompt' => 'All customers are unable to access the service and no workaround is available. What priority should this support incident receive, and why?',
            ],
        ]);
    }

    public function run(array $datasetItem): Trajectory
    {
        $fixture = new SkillFixture($datasetItem['skills']);
        $fixture->setUp();

        try {
            return $this->runSession(
                repository: $fixture->repository,
                prompt: $datasetItem['prompt'],
            );
        } finally {
            $fixture->cleanUp();
        }
    }

    public function evaluate(mixed $trajectory, array $datasetItem): void
    {
        $this->assert(
            new TrajectoryMatches(['skill', 'skill_resource'], Mode::Strict),
            $trajectory,
            'exactly one skill load followed by one resource read',
        );

        $this->assert(
            new SkillWasLoaded($datasetItem['expectedSkill']),
            $trajectory,
            'relevant skill discovered and loaded',
        );

        $this->assert(
            new ResourceWasRead('ticket-triage', 'references/priorities.md'),
            $trajectory,
            'required policy read successfully after the discovered skill',
        );
    }
}
