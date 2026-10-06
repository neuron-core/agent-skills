<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Storage;

use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Both adapters must give the same meaning to literal resource paths. */
class SkillStoragePathTest extends TestCase
{
    private string $root;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/skill-uri-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/my skill/references', 0o777, true);
        file_put_contents($this->root.'/outside.md', 'Outside the skill.');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE skills (skill_identifier TEXT NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, UNIQUE(skill_identifier, path))');

        foreach ([
            'my guide.md' => 'Space',
            'my%20guide.md' => 'Literal percent',
            'café #1?.md' => 'Unicode and delimiters',
            'a+b.md' => 'Plus',
            '%2e%2e.md' => 'Literal encoded dots',
        ] as $name => $content) {
            file_put_contents($this->root.'/my skill/references/'.$name, $content);
            $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute(['my skill', 'references/'.$name, $content]);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/my skill/references/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root.'/my skill/references');
        rmdir($this->root.'/my skill');
        unlink($this->root.'/outside.md');
        rmdir($this->root);
    }

    /** @dataProvider resources */
    public function test_reads_literal_resource_names(string $backend, string $path, string $expected): void
    {
        $storage = $this->storage($backend);
        $location = $storage->list()[0];

        $this->assertStringEndsWith('/my%20skill/', $location);
        $this->assertSame($expected, $storage->read($location, $path));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function resources(): iterable
    {
        foreach (['filesystem', 'database'] as $backend) {
            foreach ([
                'references/my guide.md' => 'Space',
                'references/my%20guide.md' => 'Literal percent',
                'references/café #1?.md' => 'Unicode and delimiters',
                'references/a+b.md' => 'Plus',
                'references/%2e%2e.md' => 'Literal encoded dots',
                'references/../references/my guide.md' => 'Space',
            ] as $path => $content) {
                yield $backend.' '.$path => [$backend, $path, $content];
            }
        }
    }

    /** @dataProvider invalidReferences */
    public function test_rejects_invalid_or_escaping_references(string $backend, string $path): void
    {
        $storage = $this->storage($backend);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(str_contains($path, '..') ? 'escapes' : 'invalid');
        $storage->read($storage->list()[0], $path);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReferences(): iterable
    {
        foreach (['filesystem', 'database'] as $backend) {
            foreach ([
                '',
                '//elsewhere/guide.md',
                'references/guide\\file.md',
                "references/guide\0file.md",
                '../outside.md',
                'references/../../outside.md',
                '/outside.md',
                'file:///outside.md',
            ] as $path) {
                yield $backend.' '.$path => [$backend, $path];
            }
        }
    }

    private function storage(string $backend): SkillStorageInterface
    {
        return $backend === 'filesystem'
            ? new FileSystemSkillStorage($this->root)
            : new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/');
    }

}
