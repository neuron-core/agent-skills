<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\ScriptExecutionEvaluator;
use NeuronAI\AgentSkills\Evaluators\ScriptWorkflowEvaluator;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use PHPUnit\Framework\TestCase;

class SkillFixtureTest extends TestCase
{
    /** @dataProvider storageWorkflows */
    public function test_real_scripts_use_configuration_and_generate_the_expected_reports(string $storage, string $skill, bool $prepareEarly): void
    {
        $catalog = SkillFixture::catalogFiles();
        $configPath = $skill === 'csv-check' ? 'config/validation.json' : 'config/reporting.json';
        $referencePath = $skill === 'csv-check' ? 'references/order-format.md' : 'references/reporting-rules.md';
        $fixture = new SkillFixture($skill, $storage, expectedArtifacts: [$configPath => $catalog[$skill][$configPath]]);
        $messages = [];
        $directory = null;
        try {
            file_put_contents($fixture->root.'/orders.csv', "order_id,date,customer,total,status\nA1,2026-10-01,Ada,12.50,paid\nA2,2026-10-02,Grace,2.00,refunded\n");
            $analysis = '{"currency":"USD","net_sales":"10.50","paid_orders":1,"cancelled_orders":0,"refunded_orders":1}';
            $report = "# Sales report\n\nNet sales: USD 10.50\nPaid orders: 1\nCancelled orders excluded: 0\nRefunded orders: 1\n";
            $instructions = $fixture->instructions();
            $steps = [
                ['tool' => 'skill', 'inputs' => ['name' => $skill], 'result' => $instructions[$skill]],
                ['tool' => 'skill_resource', 'inputs' => ['name' => $skill, 'path' => $referencePath], 'result' => $catalog[$skill][$referencePath]],
                ['tool' => 'skill_script_prepare', 'inputs' => ['name' => $skill]],
            ];
            $commands = $skill === 'csv-check'
                ? ['php scripts/validate.php '.escapeshellarg($fixture->root.'/orders.csv') => '{"valid":true,"rows":2,"errors":[]}']
                : [
                    'php scripts/analyze.php '.escapeshellarg($fixture->root.'/orders.csv').' --output analysis.json' => $analysis,
                    'php scripts/report.php analysis.json --output report.md' => $report,
                ];
            foreach ($commands as $command => $expected) {
                $steps[] = ['tool' => 'bash', 'inputs' => ['command' => $command], 'output' => $expected];
            }
            $execution = $steps;
            if ($prepareEarly) {
                [$execution[1], $execution[2]] = [$execution[2], $execution[1]];
            }
            [$load, $read, $prepare] = (new SkillToolkit($fixture->repository))->tools();
            $tools = ['skill' => $load, 'skill_resource' => $read, 'skill_script_prepare' => $prepare, 'bash' => new BashTool()];
            foreach ($execution as $index => $step) {
                $inputs = $step['inputs'];
                if ($step['tool'] === 'bash') {
                    $inputs['working_directory'] = $directory;
                }
                $tool = $tools[$step['tool']];
                $tool->setInputs($inputs)->execute();
                $call = ToolCall::make($step['tool'], (string) $index, $inputs)->setResult($tool->getResult());
                $messages[] = new ToolCallMessage(tools: [$call]);
                $messages[] = new ToolResultMessage([$call]);
                if ($step['tool'] === 'skill_script_prepare') {
                    $directory = json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
                }
            }
            $messages[] = new AssistantMessage($skill === 'csv-check' ? 'Both orders are valid.' : $report);
            $trajectory = Trajectory::fromMessages($messages);
            $evidence = $fixture->artifacts($trajectory);
            $this->assertNotEmpty($evidence);
            $this->assertNotContains(false, $evidence);
            $evaluator = $skill === 'csv-check' ? new ScriptExecutionEvaluator() : new ScriptWorkflowEvaluator();
            $output = ['messages' => $messages, 'steps' => $steps, 'instructions' => $instructions,
                'expected_total' => '10.50', 'artifacts' => $evidence, 'failure' => null];
            $this->assertTrue($evaluator->performEvaluation($output, [])->isPassed());
            $this->assertSame($storage === 'filesystem', $directory === $fixture->root.'/'.$skill);
            if ($skill === 'sales-report') {
                $this->assertSame($analysis."\n", file_get_contents($directory.'/analysis.json'));
                $this->assertSame($report, file_get_contents($directory.'/report.md'));
            }
            file_put_contents($directory.'/'.$configPath, '{}');
            $this->assertContains(false, $fixture->artifacts($trajectory));
            $output['artifacts'] = $fixture->artifacts($trajectory);
            $this->assertFalse($evaluator->performEvaluation($output, [])->isPassed());
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages($messages));
        }
        $this->assertDirectoryDoesNotExist($fixture->root);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public static function storageWorkflows(): iterable
    {
        foreach (['filesystem', 'database'] as $storage) {
            foreach (['csv-check', 'sales-report'] as $skill) {
                yield $storage.' '.$skill => [$storage, $skill, false];
                yield $storage.' '.$skill.' early preparation' => [$storage, $skill, true];
            }
        }
    }

    public function test_catalog_contains_the_seven_natural_skills_and_the_alias_is_not_rewritten(): void
    {
        $fixture = new SkillFixture('review-my-plan', 'filesystem');
        try {
            $this->assertCount(7, $fixture->instructions());
            $this->assertSame('Call the Skill tool with "risk-review".', $fixture->instructions()['review-my-plan']);
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages([]));
        }
    }

    public function test_validator_reports_row_and_column_errors_instead_of_claiming_the_file_is_valid(): void
    {
        $fixture = new SkillFixture('csv-check', 'database');
        $messages = [];
        try {
            file_put_contents($fixture->root.'/invalid.csv', "order_id,date,customer,total,status\nA1,2026-10-01,,-1.00,unknown\nA1,2026-02-30,Ada,9.00,paid\n");
            [, , $prepare] = (new SkillToolkit($fixture->repository))->tools();
            $prepare->setInputs(['name' => 'csv-check'])->execute();
            $directory = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
            $call = ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'csv-check'])->setResult($prepare->getResult());
            $messages[] = new ToolResultMessage([$call]);
            $bash = new BashTool();
            $bash->setInputs(['command' => 'php scripts/validate.php '.escapeshellarg($fixture->root.'/invalid.csv'),
                'working_directory' => $directory])->execute();
            $execution = json_decode($bash->getResult(), true, flags: JSON_THROW_ON_ERROR);
            $validation = json_decode($execution['output'], true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $execution['exit_code']);
            $this->assertFalse($validation['valid']);
            $this->assertSame(2, $validation['rows']);
            $this->assertCount(5, $validation['errors']);
            $this->assertEqualsCanonicalizing(['customer', 'total', 'status', 'order_id', 'date'], array_column($validation['errors'], 'column'));
            $this->assertSame([2, 2, 2, 3, 3], array_column($validation['errors'], 'row'));
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages($messages));
        }
    }

    public function test_cleanup_removes_temporary_directories_from_extra_skill_preparations(): void
    {
        $fixture = new SkillFixture('risk-review', 'filesystem', databaseSkills: ['rollback-plan']);
        [, , $prepare] = (new SkillToolkit($fixture->repository))->tools();
        $prepare->setInputs(['name' => 'rollback-plan'])->execute();
        $directory = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
        $call = ToolCall::make('skill_script_prepare', 'extra', ['name' => 'rollback-plan'])->setResult($prepare->getResult());
        $fixture->cleanUp(Trajectory::fromMessages([new ToolCallMessage(tools: [$call]), new ToolResultMessage([$call])]));
        $this->assertDirectoryDoesNotExist($fixture->root);
        $this->assertDirectoryDoesNotExist($directory);
    }
}
