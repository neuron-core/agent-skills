<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
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
        $pdo->exec('CREATE TABLE skills (skill_name TEXT, path TEXT, content TEXT, UNIQUE(skill_name, path))');
        $document = "---\r\nname: writing\r\ndescription: Write clearly\r\nmetadata: {author: Human}\r\n---\r\nRead references/guide.md.  \r\n";
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['editorial', 'SKILL.md', $document]);
        $insert->execute(['editorial', 'references/guide.md', 'Prefer concrete words.']);
        $repository = new SkillRepository(new DatabaseSkillStorage('db://team/', $pdo));
        $toolkit = new SkillToolkit($repository);
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('skill', 'activate', ['location' => 'db://team/editorial/'])]),
            new ToolCallMessage(null, [new ToolCall('skill_resource', 'guide', [
                'location' => 'db://team/editorial/', 'path' => 'references/guide.md',
            ])]),
            new AssistantMessage('Ready to write.'),
        );
        Agent::make()->setThreadId('database-skills-test')->setAiProvider($provider)->addTool($toolkit)
            ->chat(new UserMessage('Help me write.'))->getMessage();

        $prompt = $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '';
        $this->assertStringContainsString('writing: Write clearly', $prompt);
        $this->assertStringContainsString('location: db://team/editorial/', $prompt);
        $this->assertStringNotContainsString('Prefer concrete words.', $prompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasResult($record, $document));
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasResult($record, 'Prefer concrete words.'));
        $this->assertCount(1, $repository->catalog());
        $this->assertEquals((object) ['author' => 'Human'], $repository->get('db://team/editorial/')->readFrontmatter()->metadata);
    }

    public function test_catalog_is_lazy_and_retained_while_content_is_read_on_demand(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $repository = new SkillRepository(new DatabaseSkillStorage('db://team/', $pdo));
        $pdo->exec('CREATE TABLE skills (skill_name TEXT, path TEXT, content TEXT, UNIQUE(skill_name, path))');
        $original = "---\nname: writing\ndescription: Original summary\n---\nOriginal body";
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['writing', 'SKILL.md', $original]);
        $skill = $repository->get('db://team/writing/');
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
        $activation->setInputs(['location' => 'db://team/writing/'])->execute();
        $this->assertSame($updated, $activation->getResult());
        $pdo->prepare('UPDATE skills SET content = ? WHERE path = ?')->execute(['Updated guide', 'guide.md']);
        $resource->setInputs(['location' => 'db://team/writing/', 'path' => 'guide.md'])->execute();
        $this->assertSame('Updated guide', $resource->getResult());
        $this->assertSame('Updated guide', $skill->readResource('guide.md'));
        $pdo->exec("DELETE FROM skills WHERE path = 'guide.md'");
        $resource->execute();
        $this->assertStringContainsString('was not found', $resource->getResult());
        try {
            $skill->readResource('guide.md');
            $this->fail('Deleted resource must fail on demand.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('was not found', $exception->getMessage());
        }
        $pdo->exec("DELETE FROM skills WHERE skill_name = 'writing'");
        $activation->execute();
        $this->assertStringContainsString('was not found', $activation->getResult());
        $this->assertSame($guidelines, $toolkit->guidelines());
        $this->assertSame('Original summary', $repository->get('db://team/writing/')->description());
        $this->expectException(RuntimeException::class);
        $skill->readDocument();
    }

    public function test_unusable_documents_produce_diagnostics_and_empty_tables_produce_no_tools(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_name TEXT, path TEXT, content TEXT, UNIQUE(skill_name, path))');
        $empty = new SkillToolkit(new SkillRepository(new DatabaseSkillStorage('db://team/', $pdo)));
        $this->assertNull($empty->guidelines());
        $this->assertSame([], $empty->tools());
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['orphan', 'guide.md', 'No document']);
        $insert->execute(['malformed', 'SKILL.md', 'No frontmatter']);
        $insert->execute(['binary', 'SKILL.md', "bad\0document"]);
        $repository = new SkillRepository(new DatabaseSkillStorage('db://team/', $pdo));

        $this->assertSame([], $repository->catalog());
        $diagnostics = $repository->diagnostics();
        $this->assertCount(3, $diagnostics);
        $messages = implode(' ', array_column($diagnostics, 'message'));
        $this->assertStringContainsString('unsupported binary content', $messages);
        $this->assertStringContainsString('was not found', $messages);
    }

    public function test_tools_report_expected_failures_and_direct_access_throws(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_name TEXT, path TEXT, content TEXT, UNIQUE(skill_name, path))');
        $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute([
            'writing', 'SKILL.md', "---\nname: writing\ndescription: Write clearly\n---\nInstructions",
        ]);
        $repository = new SkillRepository(new DatabaseSkillStorage('db://team/', $pdo));
        [$activation, $resource] = (new SkillToolkit($repository))->tools();
        $resource->setInputs(['location' => 'db://team/writing/', 'path' => 'missing.md'])->execute();
        $this->assertStringContainsString('was not found', $resource->getResult());
        $resource->setInputs(['location' => 'db://team/writing/', 'path' => '../other.md'])->execute();
        $this->assertStringContainsString('escapes', $resource->getResult());
        $activation->setInputs(['location' => 'db://team/unknown/'])->execute();
        $result = $activation->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('must be one of', $result->getText());
        $pdo->exec('DROP TABLE skills');
        $activation->setInputs(['location' => 'db://team/writing/'])->execute();
        $this->assertStringContainsString('Database skill table "skills" could not be read', $activation->getResult());
        $resource->setInputs(['location' => 'db://team/writing/', 'path' => 'guide.md'])->execute();
        $this->assertStringContainsString('Database skill table "skills" could not be read', $resource->getResult());

        $this->expectException(RuntimeException::class);
        $repository->get('db://team/writing/')->readResource('guide.md');
    }

    private function hasResult(RequestRecord $record, string $expected): bool
    {
        foreach ($record->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    if ($tool->getResult() === $expected) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
