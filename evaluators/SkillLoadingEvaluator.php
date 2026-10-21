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

final class SkillLoadingEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['execution' => 1, 'mode' => 'chat', 'prompt' => 'Use the risk-review skill.'],
            ['execution' => 2, 'mode' => 'stream', 'prompt' => 'Please activate risk-review.'],
            ['execution' => 3, 'mode' => 'stream', 'prompt' => 'risk-review'],
        ]);
    }

    public function run(array $datasetItem): array
    {
        $fixture = new SkillFixture('risk-review', 'filesystem');

        $instructions = $fixture->instructions();
        $steps = [
            [
                'tool' => 'skill',
                'inputs' => ['name' => 'risk-review'],
                'result' => $instructions['risk-review'],
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

        $step = $output['steps'][0];

        $this->assert(
            new SkillWasLoaded($step['inputs']['name'], $step['result']),
            $trajectory,
            'requested skill instructions loaded',
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
