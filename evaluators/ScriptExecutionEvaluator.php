<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Evaluators\Assertions\EvidenceIsValid;
use NeuronAI\AgentSkills\Evaluators\Assertions\NoRepeatedSkillLoads;
use NeuronAI\AgentSkills\Evaluators\Assertions\ScriptsWereExecuted;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class ScriptExecutionEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['storage' => 'filesystem', 'execution' => 1, 'mode' => 'chat'],
            ['storage' => 'filesystem', 'execution' => 2, 'mode' => 'stream'],
            ['storage' => 'filesystem', 'execution' => 3, 'mode' => 'stream'],
            ['storage' => 'database', 'execution' => 1, 'mode' => 'chat'],
            ['storage' => 'database', 'execution' => 2, 'mode' => 'stream'],
            ['storage' => 'database', 'execution' => 3, 'mode' => 'stream'],
        ]);
    }

    public function run(array $datasetItem): array
    {
        $catalog = SkillFixture::catalogFiles();

        $fixture = new SkillFixture(
            'csv-check',
            $datasetItem['storage'],
            expectedArtifacts: ['config/validation.json' => $catalog['csv-check']['config/validation.json']],
        );

        $instructions = $fixture->instructions();
        $invalid = $datasetItem['execution'] === 2;
        $duplicate = $datasetItem['execution'] === 3;

        $csv = "order_id,date,customer,total,status\nA10,2026-10-01,Ada,12.50,paid\nA11,2026-10-02,Grace,9.00,paid\n"
            .($duplicate ? 'A10' : 'A12').',2026-10-03,Lin,'.($invalid ? '-7.00' : '7.00').",paid\n";

        file_put_contents($fixture->root.'/orders.csv', $csv);

        $errors = $invalid
            ? [['row' => 4, 'column' => 'total', 'reason' => 'Expected a nonnegative decimal amount.']]
            : ($duplicate ? [['row' => 4, 'column' => 'order_id', 'reason' => 'Order ID must be nonempty and unique.']] : []);

        $validation = json_encode(['valid' => $errors === [], 'rows' => 3, 'errors' => $errors], JSON_THROW_ON_ERROR);

        $steps = [
            [
                'tool' => 'skill',
                'inputs' => ['name' => 'csv-check'],
                'result' => $instructions['csv-check'],
            ],
            [
                'tool' => 'skill_resource',
                'inputs' => ['name' => 'csv-check', 'path' => 'references/order-format.md'],
                'result' => $catalog['csv-check']['references/order-format.md'],
            ],
            [
                'tool' => 'skill_script_prepare',
                'inputs' => ['name' => 'csv-check'],
            ],
            [
                'tool' => 'bash',
                'inputs' => [],
                'output' => $validation,
            ],
        ];

        $prompt = ($datasetItem['execution'] === 2 ? 'Use csv-check to check ' : 'Use csv-check to validate ')
            .$fixture->root.'/orders.csv';

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
            new ScriptsWereExecuted(
                skill: $output['steps'][0]['inputs']['name'],
                instructions: $output['steps'][0]['result'],
                referencePath: $output['steps'][1]['inputs']['path'],
                referenceContents: $output['steps'][1]['result'],
                scripts: ['scripts/validate.php' => $output['steps'][3]['output']],
            ),
            $trajectory,
            'scripts executed successfully in workflow order',
        );

        $this->assert(
            new EvidenceIsValid(),
            ['execution completed' => $output['failure'] === null] + $output['artifacts'],
            'prepared skill files',
        );

        $this->assert(
            new StringLengthBetween(1, 100000),
            $trajectory->finalAnswer(),
            'validation findings reported',
        );
    }
}
