<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Evaluators\Assertions\EvidenceIsValid;
use NeuronAI\AgentSkills\Evaluators\Assertions\NoRepeatedSkillLoads;
use NeuronAI\AgentSkills\Evaluators\Assertions\ResourceWasRead;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class SkillResourceEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['execution' => 1, 'mode' => 'chat', 'priority' => 'P1', 'prompt' => 'Use ticket-triage: the service is unavailable to all users.'],
            ['execution' => 2, 'mode' => 'stream', 'priority' => 'P2', 'prompt' => 'Use ticket-triage: some users cannot complete payments and there is no workaround.'],
            ['execution' => 3, 'mode' => 'stream', 'priority' => 'P3', 'prompt' => 'ticket-triage: CSV export fails, but users can export the same data as JSON.'],
        ]);
    }

    public function run(array $datasetItem): array
    {
        $fixture = new SkillFixture('ticket-triage', 'filesystem');

        $instructions = $fixture->instructions();
        $reference = $fixture->repository->getByName('ticket-triage')->resource('references/priorities.md')->load();

        $steps = [
            [
                'tool' => 'skill',
                'inputs' => ['name' => 'ticket-triage'],
                'result' => $instructions['ticket-triage'],
            ],
            [
                'tool' => 'skill_resource',
                'inputs' => ['name' => 'ticket-triage', 'path' => 'references/priorities.md'],
                'result' => $reference,
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

        $this->assert(
            new ResourceWasRead(
                skill: $output['steps'][0]['inputs']['name'],
                path: $output['steps'][1]['inputs']['path'],
                contents: $output['steps'][1]['result'],
                instructions: $output['steps'][0]['result'],
            ),
            $trajectory,
            'priority policy read after the skill instructions',
        );

        $this->assert(
            new StringContainsAll([$datasetItem['priority']]),
            $trajectory->finalAnswer(),
            'priority matches the ticket impact',
        );

        $this->assert(
            new EvidenceIsValid(),
            ['execution completed' => $output['failure'] === null],
        );
    }
}
