<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use NeuronAI\AgentSkills\Evaluators\Assertions\DependenciesWereLoaded;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillDependencyEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skills' => [
                    'review-my-plan' => 'filesystem',
                    'release-readiness' => 'filesystem',
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                ],
                'expectedSkill' => 'review-my-plan',
                'expectedDependencies' => ['risk-review'],
                'prompt' => 'Use the review-my-plan skill.',
            ],
            [
                'skills' => [
                    'review-my-plan' => 'database',
                    'release-readiness' => 'filesystem',
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                ],
                'expectedSkill' => 'review-my-plan',
                'expectedDependencies' => ['risk-review'],
                'prompt' => 'Please use the review-my-plan skill. We plan to rename a database column while old application instances are still running. There is no maintenance window.',
            ],
            [
                'skills' => [
                    'review-my-plan' => 'filesystem',
                    'release-readiness' => 'filesystem',
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                ],
                'expectedSkill' => 'review-my-plan',
                'expectedDependencies' => ['risk-review'],
                'prompt' => 'Use the review-my-plan skill. We plan to rename a database column while old application instances are still running. There is no maintenance window.',
            ],
            [
                'skills' => [
                    'review-my-plan' => 'filesystem',
                    'release-readiness' => 'filesystem',
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                ],
                'expectedSkill' => 'release-readiness',
                'expectedDependencies' => ['risk-review', 'rollback-plan'],
                'prompt' => 'Use the release-readiness skill. We plan to rename a database column while old application instances are still running. There is no maintenance window.',
            ],
            [
                'skills' => [
                    'review-my-plan' => 'filesystem',
                    'release-readiness' => 'database',
                    'risk-review' => 'database',
                    'rollback-plan' => 'filesystem',
                ],
                'expectedSkill' => 'release-readiness',
                'expectedDependencies' => ['risk-review', 'rollback-plan'],
                'prompt' => 'Please use the release-readiness skill. We plan to rename a database column while old application instances are still running. There is no maintenance window.',
            ],
            [
                'skills' => [
                    'review-my-plan' => 'filesystem',
                    'release-readiness' => 'filesystem',
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                ],
                'expectedSkill' => 'release-readiness',
                'expectedDependencies' => ['risk-review', 'rollback-plan'],
                'prompt' => 'Use the release-readiness skill. We plan to rename a database column while old application instances are still running. There is no maintenance window.',
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
            new TrajectoryMatches(
                array_fill(0, 1 + count($datasetItem['expectedDependencies']), 'skill'),
                Mode::Strict,
            ),
            $trajectory,
            'exactly one skill call per required skill',
        );

        $this->assert(
            new DependenciesWereLoaded(
                parent: $datasetItem['expectedSkill'],
                dependencies: $datasetItem['expectedDependencies'],
            ),
            $trajectory,
            'dependencies loaded successfully after parent instructions',
        );
    }
}
