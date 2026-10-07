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
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        $toolkit = new SkillToolkit($repository);

        $this->assertSame(['db://team/Caveman', 'db://team/caveman'], array_map(
            static fn (Skill $skill): string => $skill->location(), $repository->catalog(),
        ));
        $this->assertStringContainsString('Uppercase source (location: db://team/Caveman)', $toolkit->guidelines() ?? '');
        $this->assertStringContainsString('Lowercase source (location: db://team/caveman)', $toolkit->guidelines() ?? '');
        $this->assertSame($upper, $repository->get('db://team/Caveman')->readDocument());
        $this->assertSame($lower, $repository->get('db://team/caveman')->readDocument());
        $this->assertSame('Uppercase path', $repository->get('db://team/caveman')->readResource('Guide.md'));
        $this->assertSame('Lowercase path', $repository->get('db://team/caveman')->readResource('guide.md'));
    }

    public function test_wrong_capitalization_cannot_select_another_identifier_or_resource(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['caveman', 'SKILL.md', "---\nname: caveman\ndescription: Exact spelling\n---\nDocument"]);
        $insert->execute(['caveman', 'guide.md', 'Lowercase guide']);
        $insert->execute(['orphan', 'skill.md', "---\nname: orphan\ndescription: Wrong document case\n---\nWrong"]);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        $this->assertSame(['caveman'], $repository->names());
        $this->assertCount(1, $repository->diagnostics());
        [, $resource] = (new SkillToolkit($repository))->tools();
        $resource->setInputs(['location' => 'db://team/caveman', 'path' => 'Guide.md'])->execute();
        $this->assertStringContainsString('was not found', $resource->getResult());
        try {
            $repository->get('db://team/caveman')->readResource('Guide.md');
            $this->fail('Capitalization must not select another resource.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('was not found', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "db://team/Caveman" is not available.');
        $repository->get('db://team/Caveman');
    }

    public function test_encoded_identifiers_and_literal_percent_paths_round_trip_through_tools(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $documents = [
            'team caveman' => ['db://team/team%20caveman', "---\nname: caveman\ndescription: Space\n---\nSpace document"],
            'literal%20name' => ['db://team/literal%2520name', "---\nname: caveman\ndescription: Percent\n---\nPercent document"],
            'café' => ['db://team/caf%C3%A9', "---\nname: caveman\ndescription: Unicode\n---\nUnicode document"],
        ];
        foreach ($documents as $identifier => [$location, $document]) {
            $insert->execute([$identifier, 'SKILL.md', $document]);
            $insert->execute([$identifier, 'notes%20.md', 'Literal percent resource for '.$identifier]);
            $insert->execute([$identifier, 'notes .md', 'Space resource must not be selected']);
        }
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        $toolkit = new SkillToolkit($repository);
        $this->assertCount(3, $repository->catalog());
        [$activation, $resource] = $toolkit->tools();
        foreach ($documents as $identifier => [$location, $document]) {
            $this->assertSame($location, $repository->get($location)->location());
            $this->assertStringContainsString('location: '.$location.')', $toolkit->guidelines() ?? '');
            $activation->setInputs(['location' => $location])->execute();
            $this->assertSame($document, $activation->getResult());
            $resource->setInputs(['location' => $location, 'path' => 'notes%20.md'])->execute();
            $this->assertSame('Literal percent resource for '.$identifier, $resource->getResult());
        }
        $this->expectException(RuntimeException::class);
        $repository->get('db://team/literal%20name');
    }

    public function test_base_uris_label_the_same_rows_while_tables_isolate_content(): void
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
            new DatabaseSkillStorage($pdo, baseUri: 'db://first/'),
            new DatabaseSkillStorage($pdo, baseUri: 'db://second/'),
            new DatabaseSkillStorage($pdo, table: 'archived_skills', baseUri: 'db://archive/'),
        );
        $toolkit = new SkillToolkit($repository);
        $this->assertCount(3, $repository->catalog());
        [$activation, $resource] = $toolkit->tools();
        foreach (['db://first/writing', 'db://second/writing'] as $location) {
            $this->assertStringContainsString('Shared table (location: '.$location.')', $toolkit->guidelines() ?? '');
            $activation->setInputs(['location' => $location])->execute();
            $this->assertSame($document, $activation->getResult());
            $resource->setInputs(['location' => $location, 'path' => 'guide.md'])->execute();
            $this->assertSame('Shared guide', $resource->getResult());
        }
        $this->assertSame($archived, $repository->get('db://archive/writing')->readDocument());
        $this->assertSame('Archive guide', $repository->get('db://archive/writing')->readResource('guide.md'));
    }

    public function test_database_registrations_cannot_claim_the_same_public_location(): void
    {
        $pdo = $this->database();
        $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute([
            'writing', 'SKILL.md', "---\nname: writing\ndescription: Shared table\n---\nDocument",
        ]);
        $toolkit = SkillToolkit::make()->fromStorage(
            new DatabaseSkillStorage($pdo, baseUri: 'db://team/'),
            new DatabaseSkillStorage($pdo, baseUri: 'db://team/'),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate skill location "db://team/writing".');
        $toolkit->guidelines();
    }

    public function test_deleted_identifier_cannot_read_a_differently_capitalized_replacement(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['Caveman', 'SKILL.md', "---\nname: caveman\ndescription: Original\n---\nOriginal document"]);
        $repository = new SkillRepository(new DatabaseSkillStorage($pdo, baseUri: 'db://team/'));
        $skill = $repository->get('db://team/Caveman');
        $pdo->exec('DELETE FROM skills');
        $insert->execute(['caveman', 'SKILL.md', "---\nname: caveman\ndescription: Replacement\n---\nWrong document"]);
        $this->assertSame('Original', $skill->description());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');
        $skill->readDocument();
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
