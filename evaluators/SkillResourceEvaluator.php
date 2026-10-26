<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use NeuronAI\AgentSkills\Evaluators\Assertions\ResourceWasRead;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillResourceEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skills' => [
                    'ticket-triage' => 'filesystem',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedPriority' => 'P1',
                'prompt' => 'Use ticket-triage: the service is unavailable to all users.',
            ],
            [
                'skills' => [
                    'ticket-triage' => 'database',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedPriority' => 'P1',
                'prompt' => 'Use ticket-triage: the service is unavailable to all users.',
            ],
            [
                'skills' => [
                    'ticket-triage' => 'filesystem',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedPriority' => 'P2',
                'prompt' => 'Use ticket-triage: some users cannot complete payments and there is no workaround.',
            ],
            [
                'skills' => [
                    'ticket-triage' => 'database',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedPriority' => 'P2',
                'prompt' => 'Use ticket-triage: some users cannot complete payments and there is no workaround.',
            ],
            [
                'skills' => [
                    'ticket-triage' => 'filesystem',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedPriority' => 'P3',
                'prompt' => 'ticket-triage: CSV export fails, but users can export the same data as JSON.',
            ],
            [
                'skills' => [
                    'ticket-triage' => 'database',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedPriority' => 'P3',
                'prompt' => 'ticket-triage: CSV export fails, but users can export the same data as JSON.',
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
            new ResourceWasRead('ticket-triage', 'references/priorities.md'),
            $trajectory,
            'priority policy read successfully after the skill instructions',
        );

        $this->assert(
            new StringContainsAll([$datasetItem['expectedPriority']]),
            $trajectory->finalAnswer(),
            'priority matches the ticket impact',
        );
    }
}
