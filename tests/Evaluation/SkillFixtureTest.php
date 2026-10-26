<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\ScriptExecutionEvaluator;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use PHPUnit\Framework\TestCase;

class SkillFixtureTest extends TestCase
{
    /** @dataProvider storages */
    public function test_setup_creates_only_the_selected_storage(string $storage): void
    {
        $fixture = new SkillFixture(['risk-review' => $storage]);

        try {
            $this->assertNull($fixture->root);
            $this->assertFalse(isset($fixture->repository));

            $directories = glob(sys_get_temp_dir().'/skill-evaluation-*', GLOB_ONLYDIR);
            $fixture->setUp();
            $this->assertSame(['risk-review'], $fixture->repository->names());

            if ($storage === 'database') {
                $this->assertNull($fixture->root);
                $this->assertSame($directories, glob(sys_get_temp_dir().'/skill-evaluation-*', GLOB_ONLYDIR));
            } else {
                $this->assertNotNull($fixture->root);
                $this->assertDirectoryExists($fixture->root);
            }

            foreach ($fixture->repository->catalog() as $skill) {
                $this->assertInstanceOf($storage === 'database' ? DatabaseSkillStorage::class : FileSystemSkillStorage::class, $skill->storage());
            }
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages([]));
        }
    }

    /** @dataProvider storages */
    public function test_input_files_keep_their_contents_and_are_removed_by_cleanup(string $storage): void
    {
        $fixture = new SkillFixture(['risk-review' => $storage]);
        $fixture->setUp();

        try {
            $file = $fixture->createFile("Ciao città\n");
            $emptyFile = $fixture->createFile('');
            $this->assertNotSame($file, $emptyFile);
            $this->assertSame("Ciao città\n", file_get_contents($file));
            $this->assertSame('', file_get_contents($emptyFile));

            if ($storage === 'database') {
                $this->assertNull($fixture->root);
            }
        } finally {
            $fixture->cleanUp();
        }

        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($emptyFile);
    }

    public static function storages(): iterable
    {
        yield 'filesystem' => ['filesystem'];
        yield 'database' => ['database'];
    }

    /** @dataProvider validationCases */
    public function test_validator_uses_configuration_and_reports_the_expected_findings(array $item): void
    {
        $fixture = new SkillFixture($item['skills']);
        $fixture->setUp();
        $messages = [];
        $directory = null;

        try {
            $inputFile = $fixture->createFile($item['csv']);
            [$load, $read, $prepare] = (new SkillToolkit($fixture->repository))->tools();
            $tools = ['skill' => $load, 'skill_resource' => $read, 'skill_script_prepare' => $prepare, 'bash' => new BashTool()];
            $steps = [
                ['tool' => 'skill', 'inputs' => ['name' => 'csv-check']],
                ['tool' => 'skill_resource', 'inputs' => ['name' => 'csv-check', 'path' => 'references/order-format.md']],
                ['tool' => 'skill_script_prepare', 'inputs' => ['name' => 'csv-check']],
                ['tool' => 'bash', 'inputs' => ['command' => 'python3 scripts/validate.py '.escapeshellarg($inputFile)]],
            ];

            foreach ($steps as $index => $step) {
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

            $messages[] = new AssistantMessage('Validation complete.');
            $trajectory = Trajectory::fromMessages($messages);
            $evaluator = new ScriptExecutionEvaluator();
            $this->assertTrue($evaluator->performEvaluation($trajectory, $item)->isPassed());
            $this->assertSame($item['skills']['csv-check'] === 'filesystem', $directory === $fixture->root.'/csv-check');

            $earlyPreparation = Trajectory::fromMessages([
                ...array_slice($messages, 0, 2), ...array_slice($messages, 4, 2),
                ...array_slice($messages, 2, 2), ...array_slice($messages, 6),
            ]);
            $this->assertFalse($evaluator->performEvaluation($earlyPreparation, $item)->isPassed());
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages($messages));
        }

        if ($fixture->root !== null) {
            $this->assertDirectoryDoesNotExist($fixture->root);
        }

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileDoesNotExist($inputFile);
    }

    public static function validationCases(): iterable
    {
        foreach ((new ScriptExecutionEvaluator())->getDataset()->load() as $item) {
            yield [$item];
        }
    }

    public function test_catalog_contains_the_six_natural_skills_and_the_alias_is_not_rewritten(): void
    {
        $fixture = new SkillFixture(array_fill_keys(array_keys(SkillFixture::catalogFiles()), 'filesystem'));
        $fixture->setUp();
        try {
            $this->assertCount(6, $fixture->repository->catalog());
            $this->assertSame('Call the Skill tool with "risk-review".', $fixture->repository->getByName('review-my-plan')->loadInstructions());
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages([]));
        }
    }

    public function test_validator_reports_row_and_column_errors_instead_of_claiming_the_file_is_valid(): void
    {
        $fixture = new SkillFixture(['csv-check' => 'database']);
        $fixture->setUp();
        $inputFile = tempnam(sys_get_temp_dir(), 'skill-orders-');
        $this->assertIsString($inputFile);
        $messages = [];
        try {
            file_put_contents($inputFile, "order_id,date,customer,total,status\nA1,2026-10-01,,-1.00,unknown\nA1,2026-02-30,Ada,9.00,paid\n");
            [, , $prepare] = (new SkillToolkit($fixture->repository))->tools();
            $prepare->setInputs(['name' => 'csv-check'])->execute();
            $directory = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
            $call = ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'csv-check'])->setResult($prepare->getResult());
            $messages[] = new ToolResultMessage([$call]);
            $bash = new BashTool();
            $bash->setInputs(['command' => 'python3 scripts/validate.py '.escapeshellarg($inputFile),
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
            unlink($inputFile);
            $fixture->cleanUp(Trajectory::fromMessages($messages));
        }
    }

    public function test_cleanup_removes_temporary_directories_from_extra_skill_preparations(): void
    {
        $fixture = new SkillFixture(['risk-review' => 'filesystem', 'rollback-plan' => 'database']);
        $fixture->setUp();
        $this->assertSame(['risk-review', 'rollback-plan'], $fixture->repository->names());
        $this->assertInstanceOf(FileSystemSkillStorage::class, $fixture->repository->getByName('risk-review')->storage());
        $this->assertInstanceOf(DatabaseSkillStorage::class, $fixture->repository->getByName('rollback-plan')->storage());
        $this->assertDirectoryDoesNotExist($fixture->root.'/rollback-plan');

        [, , $prepare] = (new SkillToolkit($fixture->repository))->tools();
        $prepare->setInputs(['name' => 'rollback-plan'])->execute();
        $directory = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
        $call = ToolCall::make('skill_script_prepare', 'extra', ['name' => 'rollback-plan'])->setResult($prepare->getResult());
        $fixture->cleanUp(Trajectory::fromMessages([new ToolCallMessage(tools: [$call]), new ToolResultMessage([$call])]));
        if ($fixture->root !== null) {
            $this->assertDirectoryDoesNotExist($fixture->root);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }
}
