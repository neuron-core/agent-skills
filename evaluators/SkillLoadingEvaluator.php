<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use NeuronAI\AgentSkills\Evaluators\Assertions\SkillWasLoaded;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillLoadingEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'release-readiness' => 'filesystem',
                ],
                'prompt' => 'Use the risk-review skill.',
            ],
            [
                'skills' => [
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                    'release-readiness' => 'filesystem',
                ],
                'prompt' => 'Use the risk-review skill.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'release-readiness' => 'filesystem',
                ],
                'prompt' => 'Please activate risk-review.',
            ],
            [
                'skills' => [
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                    'release-readiness' => 'filesystem',
                ],
                'prompt' => 'Please activate risk-review.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'release-readiness' => 'filesystem',
                ],
                'prompt' => 'risk-review',
            ],
            [
                'skills' => [
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                    'release-readiness' => 'filesystem',
                ],
                'prompt' => 'risk-review',
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
            new TrajectoryMatches(['skill'], Mode::Strict),
            $trajectory,
            'exactly one skill call',
        );

        $this->assert(
            new SkillWasLoaded('risk-review'),
            $trajectory,
            'skill loaded successfully',
        );
    }
}
