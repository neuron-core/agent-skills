<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\Resource;
use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ResourceTest extends TestCase
{
    public function test_enumerated_resources_keep_their_source_when_names_and_identifiers_match(): void
    {
        $storages = [];
        foreach (['First', 'Second'] as $body) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE skills (skill_identifier TEXT, path TEXT, content BLOB)');
            $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
            $insert->execute(['folder', 'SKILL.md', "---\nname: shared\ndescription: Shared skill\n---\n".$body]);
            $insert->execute(['folder', 'references/città %20.bin', $body."\0\xff"]);
            $storages[] = new DatabaseSkillStorage($pdo);
        }

        $matches = (new SkillRepository(...$storages))->findByName('shared');
        self::assertCount(2, $matches);
        foreach ($matches as $index => $skill) {
            $resources = $skill->resources();
            self::assertSame(['SKILL.md', 'references/città %20.bin'], array_map(
                static fn (Resource $resource): string => $resource->path(), $resources,
            ));
            self::assertSame(($index === 0 ? 'First' : 'Second')."\0\xff", $resources[1]->load());
            self::assertSame($skill->loadDocument(), $resources[0]->load());
        }
    }

    public function test_resource_creation_is_lazy_and_each_load_reads_current_filesystem_contents(): void
    {
        $root = sys_get_temp_dir().'/skill-resource-'.bin2hex(random_bytes(8));
        mkdir($root.'/folder', 0o777, true);

        try {
            $skill = new Skill('declared', 'Description', 'folder', new FileSystemSkillStorage($root));
            $resource = $skill->resource('guide.md');
            self::assertSame('guide.md', $resource->path());
            file_put_contents($root.'/folder/guide.md', 'Added after resource creation');
            self::assertSame('Added after resource creation', $resource->load());
            file_put_contents($root.'/folder/guide.md', 'Updated');
            self::assertSame('Updated', $resource->load());
            file_put_contents($root.'/folder/guide.md', '');
            self::assertSame('', $resource->load());
            unlink($root.'/folder/guide.md');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('was not found');
            $resource->load();
        } finally {
            if (is_file($root.'/folder/guide.md')) {
                unlink($root.'/folder/guide.md');
            }
            rmdir($root.'/folder');
            rmdir($root);
        }
    }

    public function test_database_resource_normalizes_paths_at_load_and_observes_updates(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_identifier TEXT, path TEXT, content TEXT)');
        $pdo->exec("INSERT INTO skills VALUES ('folder', 'guide%20.md', 'Original')");
        $skill = new Skill('declared', 'Description', 'folder', new DatabaseSkillStorage($pdo));
        $resource = $skill->resource('docs/../guide%20.md');
        self::assertSame('Original', $resource->load());
        $pdo->exec("UPDATE skills SET content = 'Updated'");
        self::assertSame('Updated', $resource->load());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes the skill root');
        $skill->resource('../guide%20.md')->load();
    }
}
