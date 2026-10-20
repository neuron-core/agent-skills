<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Storage\LocalSkillStorageInterface;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use PDO;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\AgentSkills\Tests\Fixtures\TableSkillStorage;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

class SkillScriptPrepareToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/skill-prepare-'.bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_local_preparation_returns_original_native_directory_and_files(): void
    {
        $directory = $this->root.'/città %20 skill';
        mkdir($directory.'/assets', 0o700, true);
        $document = "---\nname: declared\ndescription: Local skill\n---\nInstructions.\n";
        file_put_contents($directory.'/SKILL.md', $document);
        file_put_contents($directory.'/assets/data.bin', "\0\xff");
        $tools = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->root))))->tools();
        self::assertCount(3, $tools);
        $tool = $tools[2];
        self::assertSame(['name'], $tool->getRequiredProperties());
        self::assertSame(['name'], array_keys($tool->getInputSchema()['properties']));
        self::assertSame(['declared'], $tool->getInputSchema()['properties']['name']['enum']);
        $tool->setInputs(['name' => 'declared'])->execute();
        self::assertSame(['directory' => $directory, 'files' => ['SKILL.md', 'assets/data.bin']], json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($document, file_get_contents($directory.'/SKILL.md'));
        self::assertSame("\0\xff", file_get_contents($directory.'/assets/data.bin'));
    }

    public function test_custom_local_storage_uses_its_directory_without_copying_resource_contents(): void
    {
        $directory = $this->root.'/native %20 café';
        mkdir($directory);
        $document = "---\nname: declared\ndescription: Custom local skill\n---\nInstructions.";
        file_put_contents($directory.'/SKILL.md', $document);
        file_put_contents($directory.'/asset.bin', "\0\xff");
        $storage = new class ($directory, $document) implements LocalSkillStorageInterface {
            /** @var list<array{string, string}> */
            public array $reads = [];

            /** @var list<string> */
            public array $directoryRequests = [];

            public function __construct(private string $directory, private string $document)
            {
            }

            public function list(): array
            {
                return ['local-id'];
            }

            public function resources(string $skillIdentifier): array
            {
                return ['SKILL.md', 'asset.bin'];
            }

            public function directory(string $skillIdentifier): string
            {
                $this->directoryRequests[] = $skillIdentifier;

                return $this->directory;
            }

            public function read(string $skillIdentifier, string $path): string
            {
                $this->reads[] = [$skillIdentifier, $path];
                if ($path !== 'SKILL.md') {
                    throw new \LogicException('Local assets must not be copied.');
                }

                return $this->document;
            }
        };
        $tool = (new SkillToolkit(new SkillRepository($storage)))->tools()[2];
        self::assertSame([
            'directory' => $directory, 'files' => ['SKILL.md', 'asset.bin'],
        ], $tool('declared'));
        self::assertSame(['local-id'], $storage->directoryRequests);
        self::assertSame([['local-id', 'SKILL.md']], $storage->reads);
        self::assertSame("\0\xff", file_get_contents($directory.'/asset.bin'));
    }

    /** @dataProvider invalidLocalDirectories */
    public function test_invalid_local_directory_returns_an_error_without_materialization(string $directory): void
    {
        $storage = new class ([
            ['skill_identifier' => 'local-id', 'path' => 'SKILL.md', 'content' => "---\nname: declared\ndescription: Local skill\n---\nInstructions."],
        ], $directory) extends TableSkillStorage implements LocalSkillStorageInterface {
            /** @param list<array{skill_identifier: string, path: string, content: string}> $rows */
            public function __construct(array $rows, private string $directory)
            {
                parent::__construct($rows);
            }

            public function directory(string $skillIdentifier): string
            {
                return $this->directory;
            }
        };
        $tool = (new SkillToolkit(new SkillRepository($storage)))->tools()[2];
        $result = $tool('declared');
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertSame('The local skill directory is invalid or unavailable.', $result->getText());
    }

    /** @return array<string, array{string}> */
    public static function invalidLocalDirectories(): array
    {
        return [
            'relative directory' => ['relative/skill'],
            'URI instead of native directory' => ['file:///tmp/skill'],
            'missing directory' => [sys_get_temp_dir().'/missing-skill-'.bin2hex(random_bytes(16))],
        ];
    }

    public function test_preparation_schema_exposes_local_and_database_names(): void
    {
        mkdir($this->root.'/identifier');
        file_put_contents($this->root.'/identifier/SKILL.md', "---\nname: local\ndescription: Local skill\n---\nInstructions.");
        $remote = $this->databaseStorage([
            'SKILL.md' => "---\nname: remote\ndescription: Remote skill\n---\nInstructions.",
        ]);
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->root), $remote)))->tools()[2];
        self::assertSame(['local', 'remote'], $tool->getInputSchema()['properties']['name']['enum']);
    }

    public function test_database_preparation_copies_complete_inventory_from_first_selected_source(): void
    {
        $files = [
            'SKILL.md' => "---\nname: declared\ndescription: First source\n---\nInstructions.\n",
            'scripts/first.py' => 'First script',
            'scripts/second.py' => 'Second script',
            'config/settings.json' => '{"enabled": true}',
            'references/città %20.md' => "Guide\n",
            'assets/image.bin' => "\0\xff\x80",
        ];
        $storage = $this->databaseStorage($files);
        $other = $this->databaseStorage([
            'SKILL.md' => "---\nname: declared\ndescription: Other source\n---\nOther instructions.",
            'other.txt' => 'Must not appear',
        ]);
        $tool = (new SkillToolkit(new SkillRepository($storage, $other)))->tools()[2];
        self::assertSame(['declared'], $tool->getInputSchema()['properties']['name']['enum']);
        $tool->setInputs(['name' => 'declared'])->execute();
        $prepared = json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['SKILL.md', 'assets/image.bin', 'config/settings.json', 'references/città %20.md', 'scripts/first.py', 'scripts/second.py'], $prepared['files']);

        try {
            foreach ($files as $path => $content) {
                self::assertSame($content, file_get_contents($prepared['directory'].'/'.$path));
            }
            self::assertFileDoesNotExist($prepared['directory'].'/other.txt');
        } finally {
            $this->removeDirectory($prepared['directory']);
        }

        $again = $tool('declared');
        self::assertIsArray($again);
        self::assertNotSame($prepared['directory'], $again['directory']);
        $this->removeDirectory($again['directory']);
    }

    public function test_missing_selected_resource_does_not_fall_back_to_another_source(): void
    {
        $document = "---\nname: declared\ndescription: First source\n---\nInstructions.";
        $storage = new class ([
            ['skill_identifier' => 'identifier', 'path' => 'SKILL.md', 'content' => $document],
        ]) extends TableSkillStorage {
            public function resources(string $skillIdentifier): array
            {
                return ['SKILL.md', 'missing.txt'];
            }
        };
        $other = $this->databaseStorage(['SKILL.md' => $document, 'missing.txt' => 'Other source']);
        $tool = (new SkillToolkit(new SkillRepository($storage, $other)))->tools()[2];
        $result = $tool('declared');
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertStringContainsString('missing.txt', $result->getText());
        self::assertStringNotContainsString('directory', $result->getText());
    }

    public function test_unknown_name_returns_an_explicit_error(): void
    {
        $tool = (new SkillToolkit(new SkillRepository($this->databaseStorage([
            'SKILL.md' => "---\nname: declared\ndescription: Skill\n---\nInstructions.",
        ]))))->tools()[2];
        $result = $tool('unknown');
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertStringContainsString('Skill "unknown" is not available.', $result->getText());
    }

    public function test_write_failure_returns_an_error_without_a_prepared_directory(): void
    {
        $tool = (new SkillToolkit(new SkillRepository($this->databaseStorage([
            'SKILL.md' => "---\nname: declared\ndescription: Skill\n---\nInstructions.",
            'conflict' => 'A file cannot also be a directory',
            'conflict/nested.txt' => 'Cannot be written',
        ]))))->tools()[2];
        $before = glob(sys_get_temp_dir().'/skill-script-*') ?: [];

        try {
            $result = $tool('declared');
            self::assertInstanceOf(ToolOutput::class, $result);
            self::assertTrue($result->isError());
            self::assertStringContainsString('conflict/nested.txt', $result->getText());
            self::assertStringNotContainsString('"directory":', $result->getText());
        } finally {
            foreach (array_diff(glob(sys_get_temp_dir().'/skill-script-*') ?: [], $before) as $directory) {
                $this->removeDirectory($directory);
            }
        }
    }

    public function test_invalid_inventory_paths_fail_preparation(): void
    {
        $tool = (new SkillToolkit(new SkillRepository($this->databaseStorage([
            'SKILL.md' => "---\nname: declared\ndescription: Skill\n---\nInstructions.",
            '../outside.txt' => 'Must not be copied',
        ]))))->tools()[2];
        $result = $tool('declared');
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertStringContainsString('../outside.txt', $result->getText());
    }

    public function test_agent_loop_receives_prepared_directory_after_name_based_call(): void
    {
        mkdir($this->root.'/identifier');
        file_put_contents($this->root.'/identifier/SKILL.md', "---\nname: declared\ndescription: Skill\n---\nInstructions.");
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->root)));
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall('skill_script_prepare', 'prepare_1'))->setInputs(['name' => 'declared']),
            ]),
            new AssistantMessage('Prepared.'),
        );
        $agent = Agent::make()->setThreadId('skill-prepare-test')->setAiProvider($provider)->addTool($toolkit);
        self::assertSame('Prepared.', $agent->chat(new UserMessage('Prepare declared.'))->getMessage()->getContent());
        $provider->assertToolsConfigured(['skill', 'skill_resource', 'skill_script_prepare']);
        $found = false;
        foreach ($provider->getRecorded()[1]->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    self::assertSame([
                        'directory' => $this->root.'/identifier', 'files' => ['SKILL.md'],
                    ], json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR));
                    $found = true;
                }
            }
        }
        self::assertTrue($found);
    }

    /** @param array<string, string> $files */
    private function databaseStorage(array $files): DatabaseSkillStorage
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_identifier TEXT, path TEXT, content BLOB, UNIQUE(skill_identifier, path))');
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        foreach ($files as $path => $content) {
            $insert->execute(['identifier', $path, $content]);
        }

        return new DatabaseSkillStorage($pdo);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
