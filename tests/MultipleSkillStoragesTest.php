<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use RuntimeException;
use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\Tests\Fixtures\TableSkillStorage;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use PDO;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MultipleSkillStoragesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/neuron-multiple-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/project', 0o777, true);
        mkdir($this->root.'/user');
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function skill(string $source, string $identifier, string $name, string $description): string
    {
        $directory = $this->root.'/'.$source.'/'.$identifier;
        mkdir($directory);
        $document = "---\nname: {$name}\ndescription: {$description}\n---\n{$source} instructions.\n";
        file_put_contents($directory.'/SKILL.md', $document);
        file_put_contents($directory.'/guide.md', $source.' guide for '.$name);
        return $document;
    }

    public function test_local_and_database_skills_with_the_same_name_are_independently_selected(): void
    {
        $localDocument = $this->skill('project', 'caveman', 'caveman', 'Local caveman');
        file_put_contents($this->root.'/project/caveman/local-only.md', 'Local content must not leak.');
        $databaseDocument = "---\nname: caveman\ndescription: Database caveman\n---\nRead references/guide.md.\n";
        $localLocation = 'file://'.$this->root.'/project/caveman';
        $databaseLocation = 'db://team/team%20caveman';
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_identifier TEXT NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, UNIQUE(skill_identifier, path))');
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['team caveman', 'SKILL.md', $databaseDocument]);
        $insert->execute(['team caveman', 'references/guide.md', 'Database guide.']);
        $repository = new SkillRepository(
            new FileSystemSkillStorage('file://'.$this->root.'/project/'),
            new DatabaseSkillStorage($pdo, baseUri: 'db://team/'),
        );
        mkdir($this->root.'/project/caveman/references');
        file_put_contents($this->root.'/project/caveman/references/guide.md', 'Local guide.');
        $toolkit = new SkillToolkit($repository);
        $this->assertSame(['caveman', 'caveman'], $repository->names());
        $this->assertSame($localDocument, $repository->get($localLocation)->readDocument());
        $this->assertSame($databaseDocument, $repository->get($databaseLocation)->readDocument());
        $this->assertSame('Database guide.', $repository->get($databaseLocation)->readResource('references/guide.md'));
        [$skill, $resource] = $toolkit->tools();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($skill->getName(), 'local'))->setInputs(['location' => $localLocation]),
                (new ToolCall($skill->getName(), 'database'))->setInputs(['location' => $databaseLocation]),
            ]),
            new ToolCallMessage(null, [
                (new ToolCall($resource->getName(), 'local-guide'))->setInputs(['location' => $localLocation, 'path' => 'references/guide.md']),
                (new ToolCall($resource->getName(), 'database-guide'))->setInputs(['location' => $databaseLocation, 'path' => 'references/guide.md']),
                (new ToolCall($resource->getName(), 'missing-guide'))->setInputs(['location' => $databaseLocation, 'path' => 'local-only.md']),
            ]),
            new AssistantMessage('Both sources checked.'),
        );
        $agent = Agent::make()->setThreadId('same-name')->setAiProvider($provider)->addTool($toolkit);
        $this->assertSame('Both sources checked.', $agent->chat(new UserMessage('Compare the caveman sources.'))->getMessage()->getContent());
        $prompt = $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '';
        $this->assertStringContainsString('caveman: Local caveman (location: '.$localLocation.')', $prompt);
        $this->assertStringContainsString('caveman: Database caveman (location: '.$databaseLocation.')', $prompt);
        $expectedResults = [
            'local' => $localDocument,
            'database' => $databaseDocument,
            'local-guide' => 'Local guide.',
            'database-guide' => 'Database guide.',
            'missing-guide' => 'Resource "local-only.md" was not found in skill "'.$databaseLocation.'".',
        ];
        foreach ($expectedResults as $id => $expected) {
            $provider->assertSent(static function (RequestRecord $request) use ($id, $expected): bool {
                foreach ($request->messages as $message) {
                    if ($message instanceof ToolResultMessage) {
                        foreach ($message->getToolCalls() as $tool) {
                            if ($tool->getCallId() === $id && str_contains($tool->getResult(), $expected)) {
                                return true;
                            }
                        }
                    }
                }
                return false;
            });
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedResults['missing-guide']);
        $repository->get($databaseLocation)->readResource('local-only.md');
    }

    public function test_overlapping_base_uris_with_distinct_locations_route_to_their_owners(): void
    {
        $outer = "---\nname: shared\ndescription: Outer\n---\nOuter document";
        $inner = "---\nname: shared\ndescription: Inner\n---\nInner document";
        $toolkit = SkillToolkit::make()->fromStorage(
            new TableSkillStorage('db://team/', [
                ['skill_identifier' => 'nested', 'path' => 'SKILL.md', 'content' => $outer],
            ]),
            new TableSkillStorage('db://team/nested/', [
                ['skill_identifier' => 'child', 'path' => 'SKILL.md', 'content' => $inner],
            ]),
        );
        [$activation] = $toolkit->tools();
        foreach (['db://team/nested' => $outer, 'db://team/nested/child' => $inner] as $location => $document) {
            $this->assertStringContainsString('location: '.$location, $toolkit->guidelines() ?? '');
            $activation->setInputs(['location' => $location])->execute();
            $this->assertSame($document, $activation->getResult());
        }
    }

    public function test_toolkit_discovery_rejects_duplicate_locations_as_configuration_errors(): void
    {
        $storage = new TableSkillStorage('db://team/', [
            ['skill_identifier' => 'caveman', 'path' => 'SKILL.md', 'content' => "---\nname: caveman\ndescription: Caveman\n---\nBody"],
        ]);
        $toolkit = SkillToolkit::make()->fromStorage($storage, $storage);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate skill location "db://team/caveman".');
        $toolkit->tools();
    }

    public function test_numeric_directory_identifiers_remain_strings_across_storages(): void
    {
        $projectDocument = $this->skill('project', '123', "'123'", 'Project');
        $this->skill('user', '123', "'123'", 'User');
        $project = new FileSystemSkillStorage('file://'.$this->root.'/project'.'/');
        $user = new FileSystemSkillStorage('file://'.$this->root.'/user'.'/');
        $this->assertSame(['file://'.$this->root.'/project/123'], $project->list());
        foreach ([
            new SkillToolkit(new SkillRepository($project)),
            new SkillToolkit(new SkillRepository($project, $user)),
            SkillToolkit::make()->fromStorage($project),
            SkillToolkit::make()->fromStorage($project, $user),
        ] as $toolkit) {
            $this->assertStringContainsString('Project (location: file://'.$this->root.'/project/123)', $toolkit->guidelines() ?? '');
            [$activation, $resource] = $toolkit->tools();
            $activation->setInputs(['location' => 'file://'.$this->root.'/project/123'])->execute();
            $this->assertSame($projectDocument, $activation->getResult());
            $resource->setInputs(['location' => 'file://'.$this->root.'/project/123', 'path' => 'guide.md'])->execute();
            $this->assertSame("project guide for '123'", $resource->getResult());
        }
    }

    public function test_storage_configuration_extends_a_shared_repository_in_order(): void
    {
        $projectDocument = $this->skill('project', 'shared', 'shared', 'Project');
        $this->skill('user', 'shared', 'shared', 'User');
        $userDocument = $this->skill('user', 'extra', 'extra', 'Extra');
        $repository = new SkillRepository();
        $toolkit = SkillToolkit::make($repository);

        $this->assertSame([], $repository->catalog());
        $this->assertSame([], $repository->names());
        $this->assertSame([], $repository->diagnostics());
        $this->assertNull($toolkit->guidelines());
        $this->assertCount(0, $toolkit->tools());

        $repository->addStorage(new FileSystemSkillStorage('file://'.$this->root.'/project'.'/'));
        $selected = $repository->get('file://'.$this->root.'/project/shared');
        $this->assertSame($toolkit, $toolkit->fromStorage(new FileSystemSkillStorage('file://'.$this->root.'/user'.'/')));
        $this->assertSame($selected, $repository->get('file://'.$this->root.'/project/shared'));
        $this->assertSame($projectDocument, $selected->readDocument());
        $this->assertSame($userDocument, $repository->get('file://'.$this->root.'/user/extra')->readDocument());
        $this->assertSame(['shared', 'extra', 'shared'], $repository->names());
        $this->assertSame([], $repository->diagnostics());
        $this->assertStringContainsString('shared: Project', $toolkit->guidelines() ?? '');
        $this->assertStringContainsString('extra: Extra', $toolkit->guidelines() ?? '');
        $this->assertCount(2, $toolkit->tools());
    }

    public function test_repeated_storage_configuration_preserves_exact_selection(): void
    {
        $projectDocument = $this->skill('project', 'shared', 'shared', 'Project');
        $this->skill('user', 'shared', 'shared', 'User');
        $toolkit = SkillToolkit::make();

        $this->assertNull($toolkit->guidelines());
        $this->assertCount(0, $toolkit->tools());
        $toolkit->fromStorage(new FileSystemSkillStorage('file://'.$this->root.'/project'.'/'));
        $toolkit->fromStorage(new FileSystemSkillStorage('file://'.$this->root.'/user'.'/'));

        [$activation] = $toolkit->tools();
        $activation->setInputs(['location' => 'file://'.$this->root.'/project/shared'])->execute();
        $this->assertSame($projectDocument, $activation->getResult());
    }

    public function test_exact_selection_keeps_documents_locations_and_resources_together(): void
    {
        $projectDocument = $this->skill('project', 'folder', 'shared', 'Project');
        $userDocument = $this->skill('user', 'folder', 'shared', 'User');
        file_put_contents($this->root.'/user/folder/user-only.md', 'Must not leak');
        $project = new FileSystemSkillStorage('file://'.$this->root.'/project'.'/');
        $user = new FileSystemSkillStorage('file://'.$this->root.'/user'.'/');
        $repository = new SkillRepository();
        $repository->addStorage($project, $user);
        $this->assertSame([['name' => 'shared', 'description' => 'Project'], ['name' => 'shared', 'description' => 'User']], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame($projectDocument, $repository->get('file://'.$this->root.'/project/folder')->readDocument());
        $this->assertSame('file://'.$this->root.'/project/folder', $repository->get('file://'.$this->root.'/project/folder')->location());
        $this->assertSame('project guide for shared', $repository->get('file://'.$this->root.'/project/folder')->readResource('guide.md'));
        $messages = array_column($repository->diagnostics(), 'message');
        $this->assertSame([], $messages);
        $reversed = new SkillRepository($user, $project);
        $this->assertSame($userDocument, $reversed->get('file://'.$this->root.'/user/folder')->readDocument());
        $this->assertSame('file://'.$this->root.'/user/folder', $reversed->get('file://'.$this->root.'/user/folder')->location());
        $this->assertSame('user guide for shared', $reversed->get('file://'.$this->root.'/user/folder')->readResource('guide.md'));
        $this->expectException(RuntimeException::class);
        $repository->get('file://'.$this->root.'/project/folder')->readResource('user-only.md');
    }

    public function test_unusable_candidates_are_diagnosed_while_other_locations_remain_available(): void
    {
        $primary = new TrackedSkillStorage('memory://primary/', [
            'unreadable' => null,
            'invalid' => "---\nname: invalid\ndescription: []\n---\n",
            'z-last' => "---\nname: shared\ndescription: Last\n---\n",
            'a-first' => "---\nname: shared\ndescription: First\n---\n",
        ]);
        $fallback = new TrackedSkillStorage('memory://secondary/', [
            'unreadable' => "---\nname: unreadable\ndescription: Recovered\n---\n",
            'invalid' => "---\nname: invalid\ndescription: Recovered\n---\n",
            'shared' => "---\nname: shared\ndescription: Fallback\n---\n",
        ]);
        $repository = new SkillRepository($primary, $fallback);
        $toolkit = new SkillToolkit($repository);
        $guidelines = $toolkit->guidelines() ?? '';
        $this->assertSame(['a-first/SKILL.md', 'invalid/SKILL.md', 'unreadable/SKILL.md', 'z-last/SKILL.md'], $primary->reads);
        $this->assertSame(['invalid/SKILL.md', 'shared/SKILL.md', 'unreadable/SKILL.md'], $fallback->reads);
        $guidelines = $toolkit->guidelines() ?? '';
        $this->assertStringContainsString('shared: First', $guidelines);
        $this->assertStringContainsString('location: memory://primary/a-first', $guidelines);
        $this->assertStringContainsString('invalid: Recovered', $guidelines);
        $this->assertStringContainsString('unreadable: Recovered', $guidelines);
        [$activation, $resource] = $toolkit->tools();
        $activation->setInputs(['location' => 'memory://primary/a-first'])->execute();
        $this->assertStringContainsString('description: First', $activation->getResult());
        $resource->setInputs(['location' => 'memory://primary/a-first', 'path' => 'guide.md'])->execute();
        $this->assertSame('a-first/guide.md', $resource->getResult());
        $this->assertSame(['invalid/SKILL.md', 'shared/SKILL.md', 'unreadable/SKILL.md'], $fallback->reads);
        $primary->documents['a-first'] = "---\nname: shared\ndescription: Changed\n---\nNew body";
        $activation->execute();
        $this->assertStringContainsString('New body', $activation->getResult());
        $this->assertSame($guidelines, $toolkit->guidelines());
        $this->assertNotEmpty($repository->diagnostics());
    }

    public function test_multiple_empty_or_unusable_sources_provide_no_tools_or_guidelines(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(
            new TrackedSkillStorage('memory://empty/', []),
            new TrackedSkillStorage('memory://broken/', ['broken' => 'invalid']),
        ));
        $this->assertCount(0, $toolkit->tools());
        $this->assertNull($toolkit->guidelines());
    }

    public function test_neuron_loop_activates_and_reads_distinct_skills_from_both_roots(): void
    {
        $projectDocument = $this->skill('project', 'same-folder', 'writing', 'Write prose');
        $userDocument = $this->skill('user', 'same-folder', 'analysis', 'Analyse evidence');
        $toolkit = new SkillToolkit(new SkillRepository(
            new FileSystemSkillStorage('file://'.$this->root.'/project'.'/'),
            new FileSystemSkillStorage('file://'.$this->root.'/user'.'/'),
        ));
        [$skill, $resource] = $toolkit->tools();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($skill->getName(), 'writing'))->setInputs(['location' => 'file://'.$this->root.'/project/same-folder']),
                (new ToolCall($skill->getName(), 'analysis'))->setInputs(['location' => 'file://'.$this->root.'/user/same-folder']),
            ]),
            new ToolCallMessage(null, [
                (new ToolCall($resource->getName(), 'writing-guide'))->setInputs(['location' => 'file://'.$this->root.'/project/same-folder', 'path' => 'guide.md']),
                (new ToolCall($resource->getName(), 'analysis-guide'))->setInputs(['location' => 'file://'.$this->root.'/user/same-folder', 'path' => 'guide.md']),
            ]),
            new AssistantMessage('Both skills loaded.'),
        );
        $agent = Agent::make()->setThreadId('skills-test')->setAiProvider($provider)->addTool($toolkit);
        $this->assertSame('Both skills loaded.', $agent->chat(new UserMessage('Analyse and write.'))->getMessage()->getContent());
        $prompt = $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '';
        $this->assertStringContainsString($this->root.'/project/same-folder', $prompt);
        $this->assertStringContainsString($this->root.'/user/same-folder', $prompt);
        foreach ([$projectDocument, $userDocument, 'project guide for writing', 'user guide for analysis'] as $expected) {
            $provider->assertSent(static function (RequestRecord $request) use ($expected): bool {
                foreach ($request->messages as $message) {
                    if ($message instanceof ToolResultMessage) {
                        foreach ($message->getToolCalls() as $tool) {
                            if (str_contains($tool->getResult(), $expected)) {
                                return true;
                            }
                        }
                    }
                }
                return false;
            });
        }
    }
}

class TrackedSkillStorage implements SkillStorageInterface
{
    /** @var list<string> */
    public array $reads = [];

    /** @param array<string, ?string> $documents */
    public function __construct(private string $baseUri, public array $documents)
    {
    }

    public function list(): array
    {
        return array_map(fn (string $name): string => $this->baseUri.$name, array_keys($this->documents));
    }

    public function read(string $location, string $path): string
    {
        $skill = substr($location, strlen($this->baseUri));
        $this->reads[] = $skill.'/'.$path;
        if ($path !== 'SKILL.md') {
            return $skill.'/'.$path;
        }
        return $this->documents[$skill] ?? throw new RuntimeException('Document unreadable.');
    }
}
