<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Evaluators\Assertions\DependenciesWereLoaded;
use NeuronAI\AgentSkills\Evaluators\Assertions\EvidenceIsValid;
use NeuronAI\AgentSkills\Evaluators\Assertions\NoRepeatedSkillLoads;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillDependencyEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['dependencies' => 'single', 'execution' => 1, 'mode' => 'chat'],
            ['dependencies' => 'single', 'execution' => 2, 'mode' => 'stream'],
            ['dependencies' => 'single', 'execution' => 3, 'mode' => 'stream'],
            ['dependencies' => 'multiple', 'execution' => 1, 'mode' => 'chat'],
            ['dependencies' => 'multiple', 'execution' => 2, 'mode' => 'stream'],
            ['dependencies' => 'multiple', 'execution' => 3, 'mode' => 'stream'],
        ]);
    }

    public function run(array $datasetItem): array
    {
        $multiple = $datasetItem['dependencies'] === 'multiple';
        $parent = $multiple ? 'release-readiness' : 'review-my-plan';

        $fixture = new SkillFixture($parent, 'filesystem', databaseSkills: ['rollback-plan']);
        $instructions = $fixture->instructions();

        $steps = [
            [
                'tool' => 'skill',
                'inputs' => ['name' => $parent],
                'result' => $instructions[$parent],
            ],
            [
                'tool' => 'skill',
                'inputs' => ['name' => 'risk-review'],
                'result' => $instructions['risk-review'],
            ],
        ];

        if ($multiple) {
            $steps[] = [
                'tool' => 'skill',
                'inputs' => ['name' => 'rollback-plan'],
                'result' => $instructions['rollback-plan'],
            ];
        }

        $prompt = ($datasetItem['execution'] === 2 ? 'Please use the ' : 'Use the ').$parent.' skill.';

        if ($multiple || $datasetItem['execution'] !== 1) {
            $prompt .= ' We plan to rename a database column while old application instances are still running. There is no maintenance window.';
        }

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

        foreach ($output['steps'] as $step) {
            $this->assert(
                new ToolWasCalled('skill', $step['inputs']),
                $trajectory,
                'required skill loaded: '.$step['inputs']['name'],
            );
        }

        $dependencies = [];

        foreach (array_slice($output['steps'], 1) as $step) {
            $dependencies[$step['inputs']['name']] = $step['result'];
        }

        $this->assert(
            new DependenciesWereLoaded(
                parent: $output['steps'][0]['inputs']['name'],
                instructions: $output['steps'][0]['result'],
                dependencies: $dependencies,
            ),
            $trajectory,
            'dependencies loaded after parent instructions',
        );

        $this->assert(
            new StringLengthBetween(1, 100000),
            $trajectory->finalAnswer(),
            'review response provided',
        );

        $this->assert(
            new EvidenceIsValid(),
            ['execution completed' => $output['failure'] === null],
        );
    }
}
