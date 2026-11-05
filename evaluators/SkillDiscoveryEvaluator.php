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

final class SkillDiscoveryEvaluator extends BaseSkillEvaluator
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
                'expectedSkill' => 'risk-review',
                'prompt' => 'Before we implement a database column rename while old application instances are still running, identify failure modes, hidden assumptions and operational risks.',
            ],
            [
                'skills' => [
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'risk-review',
                'prompt' => 'Before we implement a database column rename while old application instances are still running, identify failure modes, hidden assumptions and operational risks.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'risk-review',
                'prompt' => 'We want to rename a database column while older application instances are still running. What could go wrong, which assumptions need checking, and how could we reduce the operational risks?',
            ],
            [
                'skills' => [
                    'risk-review' => 'database',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'risk-review',
                'prompt' => 'We want to rename a database column while older application instances are still running. What could go wrong, which assumptions need checking, and how could we reduce the operational risks?',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'filesystem',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'rollback-plan',
                'prompt' => 'We are deploying a new application image with no database changes. The previous image is available. Define when to restore it, the recovery steps and how to verify service is healthy afterward.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'rollback-plan',
                'prompt' => 'We are deploying a new application image with no database changes. The previous image is available. Define when to restore it, the recovery steps and how to verify service is healthy afterward.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'filesystem',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'rollback-plan',
                'prompt' => 'Prepare a recovery procedure for a failed application deployment. There are no database changes and the previous image is available. Include recovery triggers, restoration steps and checks that service has recovered.',
            ],
            [
                'skills' => [
                    'risk-review' => 'filesystem',
                    'rollback-plan' => 'database',
                    'ticket-triage' => 'filesystem',
                ],
                'expectedSkill' => 'rollback-plan',
                'prompt' => 'Prepare a recovery procedure for a failed application deployment. There are no database changes and the previous image is available. Include recovery triggers, restoration steps and checks that service has recovered.',
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
            new SkillWasLoaded($datasetItem['expectedSkill']),
            $trajectory,
            'relevant skill discovered and loaded',
        );
    }
}
