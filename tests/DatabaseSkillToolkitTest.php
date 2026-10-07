<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseSkillToolkitTest extends TestCase
{
    public function test_agent_discovers_and_reads_original_database_document_and_resource(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->createSkillsTable($pdo);
        $document = "---\r\nname: writing\r\ndescription: Write clearly\r\nmetadata: {author: Human}\r\n---\r\nRead references/guide.md.  \r\n";
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['editorial', 'SKILL.md', $document]);
        $insert->execute(['editorial', 'references/guide.md', 'Read references/my %20 città.md.']);
        $insert->execute(['editorial', 'references/my %20 città.md', "Prefer concrete words.\n"]);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        $toolkit = new SkillToolkit($repository);
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('skill', 'activate', ['name' => 'writing'])]),
            new ToolCallMessage(null, [new ToolCall('skill_resources', 'guide', [
                'name' => 'writing', 'paths' => ['references/guide.md', 'references/my %20 città.md'],
            ])]),
            new ToolCallMessage(null, [new ToolCall('skill_script_prepare', 'prepare', ['name' => 'writing'])]),
            new AssistantMessage('Ready to write.'),
        );
        Agent::make()->setThreadId('database-skills-test')->setAiProvider($provider)->addTool($toolkit)
            ->chat(new UserMessage('Help me write.'))->getMessage();

        $prompt = $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '';
        $this->assertStringContainsString('writing: Write clearly', $prompt);
        $this->assertStringNotContainsString('db://', $prompt);
        $this->assertStringNotContainsString('Prefer concrete words.', $prompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasResult($record, $document));
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasResult($record, json_encode(['resources' => [
            ['path' => 'references/guide.md', 'content' => 'Read references/my %20 città.md.'],
            ['path' => 'references/my %20 città.md', 'content' => "Prefer concrete words.\n"],
        ]], JSON_THROW_ON_ERROR)));
        $provider->assertToolsConfigured(['skill', 'skill_resources', 'skill_script_prepare']);
        $prepared = null;
        foreach ($provider->getRecorded() as $record) {
            foreach ($record->messages as $message) {
                if ($message instanceof ToolResultMessage) {
                    foreach ($message->getToolCalls() as $tool) {
                        if ($tool->getName() === 'skill_script_prepare') {
                            $prepared = json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR);
                        }
                    }
                }
            }
        }
        $this->assertNotNull($prepared);
        try {
            $this->assertSame($document, file_get_contents($prepared['directory'].'/SKILL.md'));
            $this->assertSame(['SKILL.md', 'references/guide.md', 'references/my %20 città.md'], $prepared['files']);
            $this->assertSame("Prefer concrete words.\n", file_get_contents($prepared['directory'].'/references/my %20 città.md'));
        } finally {
            foreach ($prepared['files'] as $path) {
                unlink($prepared['directory'].'/'.$path);
            }
            rmdir($prepared['directory'].'/references');
            rmdir($prepared['directory']);
        }
        $this->assertCount(1, $repository->catalog());
        $this->assertEquals((object) ['author' => 'Human'], $repository->get('db://team/editorial')->readFrontmatter()->metadata);
    }

    public function test_catalog_is_lazy_and_retained_while_content_is_read_on_demand(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        $this->createSkillsTable($pdo);
        $original = "---\nname: writing\ndescription: Original summary\n---\nOriginal body";
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['writing', 'SKILL.md', $original]);
        $skill = $repository->get('db://team/writing');
        $toolkit = new SkillToolkit($repository);
        $guidelines = $toolkit->guidelines();
        [$activation, $resource] = $toolkit->tools();
        $insert->execute(['writing', 'guide.md', 'Newly available resource']);
        $updated = "---\nname: writing\ndescription: Updated summary\n---\nUpdated body";
        $pdo->prepare('UPDATE skills SET content = ? WHERE path = ?')->execute([$updated, 'SKILL.md']);
        $insert->execute(['new', 'SKILL.md', "---\nname: new\ndescription: Added later\n---\nNew"]);

        $this->assertSame('Original summary', $skill->description());
        $this->assertSame($updated, $skill->readDocument());
        $this->assertSame('Newly available resource', $skill->readResource('guide.md'));
        $this->assertSame(['writing'], $repository->names());
        $this->assertSame($guidelines, $toolkit->guidelines());
        $activation->setInputs(['name' => 'writing'])->execute();
        $this->assertSame($updated, $activation->getResult());
        $pdo->prepare('UPDATE skills SET content = ? WHERE path = ?')->execute(['Updated guide', 'guide.md']);
        $resource->setInputs(['name' => 'writing', 'paths' => ['guide.md']])->execute();
        $this->assertSame($this->resourceResult('guide.md', 'Updated guide'), $resource->getResult());
        $this->assertSame('Updated guide', $skill->readResource('guide.md'));
        $pdo->exec("DELETE FROM skills WHERE path = 'guide.md'");
        $resource->execute();
        $this->assertStringContainsString('was not found', (string) $resource->getResult());
        try {
            $skill->readResource('guide.md');
            $this->fail('Deleted resource must fail on demand.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('was not found', $exception->getMessage());
        }
        $pdo->exec("DELETE FROM skills WHERE skill_identifier = 'writing'");
        $activation->execute();
        $this->assertStringContainsString('was not found', $activation->getResult());
        $this->assertSame($guidelines, $toolkit->guidelines());
        $this->assertSame('Original summary', $repository->get('db://team/writing')->description());
        $this->expectException(RuntimeException::class);
        $skill->readDocument();
    }

    public function test_unusable_documents_produce_diagnostics_and_empty_tables_produce_no_tools(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->createSkillsTable($pdo);
        $empty = new SkillToolkit(new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/')));
        $this->assertNull($empty->guidelines());
        $this->assertSame([], $empty->tools());
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['orphan', 'guide.md', 'No document']);
        $insert->execute(['malformed', 'SKILL.md', 'No frontmatter']);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));

        $this->assertSame([], $repository->catalog());
        $diagnostics = $repository->diagnostics();
        $this->assertCount(2, $diagnostics);
        $messages = implode(' ', array_column($diagnostics, 'message'));
        $this->assertStringContainsString('was not found', $messages);
    }

    public function test_tools_report_expected_failures_and_direct_access_throws(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->createSkillsTable($pdo);
        $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute([
            'writing', 'SKILL.md', "---\nname: writing\ndescription: Write clearly\n---\nInstructions",
        ]);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        [$activation, $resource] = (new SkillToolkit($repository))->tools();
        $resource->setInputs(['name' => 'writing', 'paths' => ['missing.md']])->execute();
        $this->assertStringContainsString('was not found', (string) $resource->getResult());
        $resource->setInputs(['name' => 'writing', 'paths' => ['../other.md']])->execute();
        $this->assertStringContainsString('escapes', (string) $resource->getResult());
        $activation->setInputs(['name' => 'unknown'])->execute();
        $result = $activation->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('must be one of', $result->getText());
        $pdo->exec('DROP TABLE skills');
        $activation->setInputs(['name' => 'writing'])->execute();
        $this->assertStringContainsString('Database skill table "skills" could not be read', $activation->getResult());
        $resource->setInputs(['name' => 'writing', 'paths' => ['guide.md']])->execute();
        $this->assertStringContainsString('Database skill table "skills" could not be read', (string) $resource->getResult());

        $this->expectException(RuntimeException::class);
        $repository->get('db://team/writing')->readResource('guide.md');
    }

    public function test_mixed_sources_keep_batch_reads_with_the_selected_document_without_fallback(): void
    {
        $directory = sys_get_temp_dir().'/neuron-batch-'.bin2hex(random_bytes(8));
        mkdir($directory.'/folder-id', 0o700, true);
        $localDocument = "---\nname: writing\ndescription: Local writing\n---\nLocal instructions.";
        $databaseDocument = "---\nname: writing\ndescription: Database writing\n---\nDatabase instructions.";
        file_put_contents($directory.'/folder-id/SKILL.md', $localDocument);
        file_put_contents($directory.'/folder-id/guide.md', 'Local guide');
        file_put_contents($directory.'/folder-id/local-only.md', 'Only local');
        $pdo = new PDO('sqlite::memory:');
        $this->createSkillsTable($pdo);
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['database-id', 'SKILL.md', $databaseDocument]);
        $insert->execute(['database-id', 'guide.md', 'Database guide']);
        $insert->execute(['database-id', 'database-only.md', 'Only database']);
        $local = new FileSystemSkillStorage($directory);
        $database = new DatabaseSkillStorage($pdo);

        try {
            foreach ([
                [[$local, $database], $localDocument, 'Local guide', 'database-only.md'],
                [[$database, $local], $databaseDocument, 'Database guide', 'local-only.md'],
            ] as [$storages, $document, $guide, $missing]) {
                $toolkit = new SkillToolkit(new SkillRepository(...$storages));
                [$load, $resources, $prepare] = $toolkit->tools();
                $this->assertSame(['skill', 'skill_resources', 'skill_script_prepare'], array_map(
                    static fn ($tool): string => $tool->getName(), $toolkit->tools(),
                ));
                $this->assertStringNotContainsString('location:', $toolkit->guidelines() ?? '');
                $load->setInputs(['name' => 'writing'])->execute();
                $this->assertSame($document, $load->getResult());
                $this->assertSame(['writing'], $resources->getProperties()[0]->getJsonSchema()['enum']);
                $resources->setInputs(['name' => 'writing', 'paths' => ['SKILL.md', 'guide.md']])->execute();
                $this->assertSame(['resources' => [
                    ['path' => 'SKILL.md', 'content' => $document],
                    ['path' => 'guide.md', 'content' => $guide],
                ]], json_decode($resources->getResult(), true, flags: JSON_THROW_ON_ERROR));
                $resources->setInputs(['name' => 'writing', 'paths' => ['guide.md', $missing]])->execute();
                $result = $resources->getResult();
                $this->assertInstanceOf(ToolOutput::class, $result);
                $this->assertTrue($result->isError());
                $this->assertStringContainsString($missing, $result->getText());
                $this->assertStringNotContainsString($guide, $result->getText());
                $prepare->setInputs(['name' => 'writing'])->execute();
                $prepared = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($document, file_get_contents($prepared['directory'].'/SKILL.md'));
                $this->assertSame($guide, file_get_contents($prepared['directory'].'/guide.md'));
                $this->assertFileDoesNotExist($prepared['directory'].'/'.$missing);
                if ($storages[0] === $database) {
                    foreach ($prepared['files'] as $path) {
                        unlink($prepared['directory'].'/'.$path);
                    }
                    rmdir($prepared['directory']);
                } else {
                    $this->assertSame($directory.'/folder-id', $prepared['directory']);
                }
                $this->assertSame(['SKILL.md', 'guide.md', 'local-only.md'], array_values(array_diff(scandir($directory.'/folder-id'), ['.', '..'])));
            }
        } finally {
            unlink($directory.'/folder-id/SKILL.md');
            unlink($directory.'/folder-id/guide.md');
            unlink($directory.'/folder-id/local-only.md');
            rmdir($directory.'/folder-id');
            rmdir($directory);
        }
    }

    private function createSkillsTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE skills (skill_identifier TEXT, path TEXT, content TEXT, UNIQUE(skill_identifier, path))');
    }

    private function resourceResult(string $path, string $content): string
    {
        return json_encode(['resources' => [['path' => $path, 'content' => $content]]], JSON_THROW_ON_ERROR);
    }

    private function hasResult(RequestRecord $record, string $expected): bool
    {
        foreach ($record->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    if ((string) $tool->getResult() === $expected) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
