<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Evaluators\Assertions\EvidenceIsValid;
use NeuronAI\AgentSkills\Evaluators\Assertions\NoRepeatedSkillLoads;
use NeuronAI\AgentSkills\Evaluators\Assertions\ScriptsWereExecuted;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class ScriptWorkflowEvaluator extends BaseSkillEvaluator
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
            'sales-report',
            $datasetItem['storage'],
            expectedArtifacts: ['config/reporting.json' => $catalog['sales-report']['config/reporting.json']],
        );

        $instructions = $fixture->instructions();
        $analysis = '{"currency":"USD","net_sales":"144.50","paid_orders":2,"cancelled_orders":1,"refunded_orders":1}';
        $report = "# Sales report\n\nNet sales: USD 144.50\nPaid orders: 2\nCancelled orders excluded: 1\nRefunded orders: 1\n";

        file_put_contents($fixture->root.'/orders.csv', "order_id,date,customer,total,status\nA10,2026-10-01,Ada,100.00,paid\nA11,2026-10-02,Grace,64.50,paid\nA12,2026-10-03,Lin,500.00,cancelled\nA13,2026-10-04,Sam,20.00,refunded\n");

        $steps = [
            [
                'tool' => 'skill',
                'inputs' => ['name' => 'sales-report'],
                'result' => $instructions['sales-report'],
            ],
            [
                'tool' => 'skill_resource',
                'inputs' => ['name' => 'sales-report', 'path' => 'references/reporting-rules.md'],
                'result' => $catalog['sales-report']['references/reporting-rules.md'],
            ],
            [
                'tool' => 'skill_script_prepare',
                'inputs' => ['name' => 'sales-report'],
            ],
            [
                'tool' => 'bash',
                'inputs' => [],
                'output' => $analysis,
            ],
            [
                'tool' => 'bash',
                'inputs' => [],
                'output' => $report,
            ],
        ];

        $prompt = ($datasetItem['execution'] === 2 ? 'Please use sales-report to analyze ' : 'Use sales-report to analyze ')
            .$fixture->root.'/orders.csv. Save the analysis to '.$fixture->root.'/analysis.json and the report to '
            .$fixture->root.'/report.md.';

        return $this->runSession(
            fixture: $fixture,
            prompt: $prompt,
            mode: $datasetItem['mode'],
            captureArtifacts: static fn (): array => [
                'analysis matches the input orders' => is_file($fixture->root.'/analysis.json')
                    && json_decode(file_get_contents($fixture->root.'/analysis.json'), true) === json_decode($analysis, true),
                'report matches the analysis' => is_file($fixture->root.'/report.md')
                    && file_get_contents($fixture->root.'/report.md') === $report,
            ],
        ) + ['steps' => $steps, 'expected_total' => '144.50'];
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
                scripts: [
                    'scripts/analyze.php' => $output['steps'][3]['output'],
                    'scripts/report.php' => $output['steps'][4]['output'],
                ],
            ),
            $trajectory,
            'scripts executed successfully in workflow order',
        );

        $this->assert(
            new EvidenceIsValid(),
            ['execution completed' => $output['failure'] === null] + $output['artifacts'],
            'prepared files and generated sales reports',
        );

        $this->assert(
            new StringContainsAll([$output['expected_total']]),
            $trajectory->finalAnswer(),
            'report used in final answer',
        );
    }
}
