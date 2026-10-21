<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use NeuronAI\AgentSkills\Evaluators\Assertions\ScriptsWereExecuted;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class ScriptExecutionEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skills' => [
                    'csv-check' => 'filesystem',
                    'text-stats' => 'filesystem',
                    'risk-review' => 'filesystem',
                ],
                'prompt' => 'Use csv-check to validate %s',
                'csv' => "order_id,date,customer,total,status\n"
                    ."A10,2026-10-01,Ada,12.50,paid\n"
                    ."A11,2026-10-02,Grace,9.00,paid\n"
                    ."A12,2026-10-03,Lin,7.00,paid\n",
                'expectedOutput' => '{"valid":true,"rows":3,"errors":[]}',
            ],
            [
                'skills' => [
                    'csv-check' => 'filesystem',
                    'text-stats' => 'filesystem',
                    'risk-review' => 'filesystem',
                ],
                'prompt' => 'Use csv-check to check %s',
                'csv' => "order_id,date,customer,total,status\n"
                    ."A10,2026-10-01,Ada,12.50,paid\n"
                    ."A11,2026-10-02,Grace,9.00,paid\n"
                    ."A12,2026-10-03,Lin,-7.00,paid\n",
                'expectedOutput' => '{"valid":false,"rows":3,"errors":[{"row":4,"column":"total","reason":"Expected a nonnegative decimal amount."}]}',
            ],
            [
                'skills' => [
                    'csv-check' => 'filesystem',
                    'text-stats' => 'filesystem',
                    'risk-review' => 'filesystem',
                ],
                'prompt' => 'Use csv-check to validate %s',
                'csv' => "order_id,date,customer,total,status\n"
                    ."A10,2026-10-01,Ada,12.50,paid\n"
                    ."A11,2026-10-02,Grace,9.00,paid\n"
                    ."A10,2026-10-03,Lin,7.00,paid\n",
                'expectedOutput' => '{"valid":false,"rows":3,"errors":[{"row":4,"column":"order_id","reason":"Order ID must be nonempty and unique."}]}',
            ],
            [
                'skills' => [
                    'csv-check' => 'database',
                    'text-stats' => 'database',
                    'risk-review' => 'database',
                ],
                'prompt' => 'Use csv-check to validate %s',
                'csv' => "order_id,date,customer,total,status\n"
                    ."A10,2026-10-01,Ada,12.50,paid\n"
                    ."A11,2026-10-02,Grace,9.00,paid\n"
                    ."A12,2026-10-03,Lin,7.00,paid\n",
                'expectedOutput' => '{"valid":true,"rows":3,"errors":[]}',
            ],
            [
                'skills' => [
                    'csv-check' => 'database',
                    'text-stats' => 'database',
                    'risk-review' => 'database',
                ],
                'prompt' => 'Use csv-check to check %s',
                'csv' => "order_id,date,customer,total,status\n"
                    ."A10,2026-10-01,Ada,12.50,paid\n"
                    ."A11,2026-10-02,Grace,9.00,paid\n"
                    ."A12,2026-10-03,Lin,-7.00,paid\n",
                'expectedOutput' => '{"valid":false,"rows":3,"errors":[{"row":4,"column":"total","reason":"Expected a nonnegative decimal amount."}]}',
            ],
            [
                'skills' => [
                    'csv-check' => 'database',
                    'text-stats' => 'database',
                    'risk-review' => 'database',
                ],
                'prompt' => 'Use csv-check to validate %s',
                'csv' => "order_id,date,customer,total,status\n"
                    ."A10,2026-10-01,Ada,12.50,paid\n"
                    ."A11,2026-10-02,Grace,9.00,paid\n"
                    ."A10,2026-10-03,Lin,7.00,paid\n",
                'expectedOutput' => '{"valid":false,"rows":3,"errors":[{"row":4,"column":"order_id","reason":"Order ID must be nonempty and unique."}]}',
            ],
        ]);
    }

    public function run(array $datasetItem): Trajectory
    {
        $fixture = new SkillFixture($datasetItem['skills']);

        try {
            $fixture->setUp();
            $csvFile = $fixture->createFile($datasetItem['csv']);

            return $this->runSession(
                repository: $fixture->repository,
                prompt: sprintf($datasetItem['prompt'], $csvFile),
                onToolCalled: $fixture->recordPreparation(...),
            );
        } finally {
            $fixture->cleanUp();
        }
    }

    public function evaluate(mixed $trajectory, array $datasetItem): void
    {
        $this->assert(
            new TrajectoryMatches(['skill', 'skill_resource', 'skill_script_prepare', 'bash'], Mode::Strict),
            $trajectory,
            'load, read, prepare and execute without extra calls',
        );

        $this->assert(
            new ScriptsWereExecuted(
                skill: 'csv-check',
                scripts: ['scripts/validate.py' => $datasetItem['expectedOutput']],
                referencePath: 'references/order-format.md',
            ),
            $trajectory,
            'validator executed successfully after reading and preparation',
        );
    }
}
