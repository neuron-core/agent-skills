<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseSkillIdentityTest extends TestCase
{
    public function test_case_distinct_identifiers_and_paths_remain_independently_selectable(): void
    {
        $pdo = $this->database();
        $lower = "---\nname: caveman\ndescription: Lowercase source\n---\nLowercase document";
        $upper = "---\nname: caveman\ndescription: Uppercase source\n---\nUppercase document";
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['caveman', 'SKILL.md', $lower]);
        $insert->execute(['Caveman', 'SKILL.md', $upper]);
        $insert->execute(['caveman', 'guide.md', 'Lowercase path']);
        $insert->execute(['caveman', 'Guide.md', 'Uppercase path']);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo));
        $toolkit = new SkillToolkit($repository);

        $this->assertSame(['Caveman', 'caveman'], array_map(
            static fn (Skill $skill): string => $skill->identifier(), $repository->catalog(),
        ));
        $this->assertStringContainsString('caveman: Uppercase source', $toolkit->guidelines() ?? '');
        $this->assertStringNotContainsString('Lowercase source', $toolkit->guidelines() ?? '');
        $this->assertSame($upper, $repository->catalog()[0]->loadDocument());
        $this->assertSame($lower, $repository->catalog()[1]->loadDocument());
        $this->assertSame('Uppercase path', $repository->catalog()[1]->resource('Guide.md')->load());
        $this->assertSame('Lowercase path', $repository->catalog()[1]->resource('guide.md')->load());
    }

    public function test_wrong_capitalization_cannot_select_another_identifier_or_resource(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['caveman', 'SKILL.md', "---\nname: caveman\ndescription: Exact spelling\n---\nDocument"]);
        $insert->execute(['caveman', 'guide.md', 'Lowercase guide']);
        $insert->execute(['orphan', 'skill.md', "---\nname: orphan\ndescription: Wrong document case\n---\nWrong"]);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo));
        $this->assertSame(['caveman'], $repository->names());
        $this->assertCount(1, $repository->diagnostics());
        [, $resource] = (new SkillToolkit($repository))->tools();
        $resource->setInputs(['name' => 'caveman', 'path' => 'Guide.md'])->execute();
        $this->assertStringContainsString('was not found', (string) $resource->getResult());
        try {
            $repository->getByName('caveman')->resource('Guide.md')->load();
            $this->fail('Capitalization must not select another resource.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('was not found', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "Caveman" is not available.');
        $repository->getByName('Caveman');
    }

    public function test_literal_identifiers_and_percent_paths_round_trip_through_tools(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $documents = [
            'team caveman' => ['team caveman', "---\nname: caveman\ndescription: Space\n---\nSpace document"],
            'literal%20name' => ['literal%20name', "---\nname: caveman\ndescription: Percent\n---\nPercent document"],
            'café' => ['café', "---\nname: caveman\ndescription: Unicode\n---\nUnicode document"],
        ];
        foreach ($documents as $identifier => [$skillIdentifier, $document]) {
            $insert->execute([$identifier, 'SKILL.md', $document]);
            $insert->execute([$identifier, 'notes%20.md', 'Literal percent resource for '.$identifier]);
            $insert->execute([$identifier, 'notes .md', 'Space resource must not be selected']);
        }
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo));
        $toolkit = new SkillToolkit($repository);
        $this->assertCount(3, $repository->catalog());
        [$activation, $resource] = $toolkit->tools();
        $matches = [];
        foreach ($repository->catalog() as $skill) {
            $matches[$skill->identifier()] = $skill;
        }
        foreach ($documents as $identifier => [$skillIdentifier, $document]) {
            $this->assertSame($skillIdentifier, $matches[$identifier]->identifier());
            $this->assertStringNotContainsString('db://', $toolkit->guidelines() ?? '');
            $activation->setInputs(['name' => 'caveman'])->execute();
            $this->assertSame("Instructions loaded.\n\nUnicode document", $activation->getResult());
            $resource->setInputs(['name' => 'caveman', 'path' => 'notes%20.md'])->execute();
            $this->assertSame('Literal percent resource for '.$identifier, $matches[$identifier]->resource('notes%20.md')->load());
            $this->assertSame('Literal percent resource for café', $resource->getResult());
        }
        $this->expectException(RuntimeException::class);
        $repository->getByName('literal%20name');
    }

    public function test_storage_instances_with_equal_identifiers_keep_their_table_contents(): void
    {
        $pdo = $this->database();
        $document = "---\nname: writing\ndescription: Shared table\n---\nShared document";
        $archived = "---\nname: writing\ndescription: Archive table\n---\nArchived document";
        $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute(['writing', 'SKILL.md', $document]);
        $pdo->exec("INSERT INTO skills VALUES ('writing', 'guide.md', 'Shared guide')");
        $pdo->exec('CREATE TABLE archived_skills (skill_identifier TEXT NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, UNIQUE(skill_identifier, path))');
        $pdo->prepare('INSERT INTO archived_skills VALUES (?, ?, ?)')->execute(['writing', 'SKILL.md', $archived]);
        $pdo->exec("INSERT INTO archived_skills VALUES ('writing', 'guide.md', 'Archive guide')");
        $repository = new SkillRepository(
            new DatabaseSkillStorage($pdo),
            new DatabaseSkillStorage($pdo),
            new DatabaseSkillStorage($pdo, table: 'archived_skills'),
        );
        $toolkit = new SkillToolkit($repository);
        $this->assertCount(3, $repository->catalog());
        [$activation, $resource] = $toolkit->tools();
        $this->assertStringContainsString('writing: Shared table', $toolkit->guidelines() ?? '');
        $this->assertStringNotContainsString('Archive table', $toolkit->guidelines() ?? '');
        $activation->setInputs(['name' => 'writing'])->execute();
        $this->assertSame("Instructions loaded.\n\nShared document", $activation->getResult());
        $resource->setInputs(['name' => 'writing', 'path' => 'guide.md'])->execute();
        $this->assertSame('Shared guide', $resource->getResult());
        $this->assertSame($document, $repository->catalog()[1]->loadDocument());
        $this->assertSame($archived, $repository->catalog()[2]->loadDocument());
        $this->assertSame('Archive guide', $repository->catalog()[2]->resource('guide.md')->load());
    }

    public function test_separate_database_registrations_can_expose_the_same_identifier(): void
    {
        $pdo = $this->database();
        $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute([
            'writing', 'SKILL.md', "---\nname: writing\ndescription: Shared table\n---\nDocument",
        ]);
        $first = new DatabaseSkillStorage($pdo);
        $second = new DatabaseSkillStorage($pdo);
        $repository = new SkillRepository($first, $second);
        $this->assertCount(2, $repository->catalog());
        $this->assertSame($first, $repository->getByName('writing')->storage());
        $this->assertSame($second, $repository->catalog()[1]->storage());
        $this->assertSame('writing', $repository->catalog()[1]->identifier());
    }

    public function test_deleted_identifier_cannot_read_a_differently_capitalized_replacement(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['Caveman', 'SKILL.md', "---\nname: caveman\ndescription: Original\n---\nOriginal document"]);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo));
        $skill = $repository->getByName('caveman');
        $pdo->exec('DELETE FROM skills');
        $insert->execute(['caveman', 'SKILL.md', "---\nname: caveman\ndescription: Replacement\n---\nWrong document"]);
        $this->assertSame('Original', $skill->description());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');
        $skill->loadDocument();
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_identifier TEXT COLLATE BINARY NOT NULL,
            path TEXT COLLATE BINARY NOT NULL, content TEXT NOT NULL,
            UNIQUE (skill_identifier, path))');
        return $pdo;
    }
}
