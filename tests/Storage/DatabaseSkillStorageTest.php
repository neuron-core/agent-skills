<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Storage;

use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseSkillStorageTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE skills (skill_identifier TEXT NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, UNIQUE(skill_identifier, path))');
        $this->pdo->exec("INSERT INTO skills VALUES ('writing', 'SKILL.md', 'Document'), ('writing', 'references/guide.md', 'Guide')");
    }

    public function test_default_base_uri_addresses_skills(): void
    {
        $storage = new DatabaseSkillStorage($this->pdo);

        $this->assertSame(['db://skills/writing'], $storage->list());
        $this->assertSame('Document', $storage->read('db://skills/writing', 'SKILL.md'));
    }

    public function test_configured_table_uses_supplied_connection(): void
    {
        $this->pdo->exec('ALTER TABLE skills RENAME TO team_skills');
        $storage = new DatabaseSkillStorage($this->pdo, table: 'team_skills', baseUri: 'db://team/project/');
        $this->assertSame(['db://team/project/writing'], $storage->list());
        $this->assertSame('Guide', $storage->read('db://team/project/writing', 'references/guide.md'));
    }

    /** @dataProvider identifiersWithSpecialCharacters */
    public function test_identifiers_with_special_characters_round_trip(string $identifier): void
    {
        $insert = $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute([$identifier, 'SKILL.md', 'Found the skill.']);
        $insert->execute([$identifier, 'references/my #?% guide.md', 'Found the resource.']);

        $storage = new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/');
        $location = 'db://team/'.rawurlencode($identifier);

        $this->assertContains($location, $storage->list());
        $this->assertSame('Found the skill.', $storage->read($location, 'SKILL.md'));
        $this->assertSame('Found the resource.', $storage->read($location, 'references/my #?% guide.md'));
    }

    /** @return array<string, array{string}> */
    public static function identifiersWithSpecialCharacters(): array
    {
        return [
            'space' => ['team skills'],
            'URI delimiters' => ['team #1?'],
            'literal percent sequence' => ['team %20'],
            'Unicode and punctuation' => ["café [draft] + it's ready"],
        ];
    }

    /** @dataProvider identifiersWithSpecialCharacters */
    public function test_resource_directory_segments_with_special_characters_round_trip(string $directoryName): void
    {
        $path = $directoryName.'/guide.md';
        $insert = $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['writing', $path, 'Found the resource.']);

        $storage = new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/');

        $this->assertSame('Found the resource.', $storage->read('db://team/writing', $path));
    }

    /** @dataProvider databaseOperations */
    public function test_missing_tables_fail_clearly_without_creating_them(bool $read): void
    {
        $storage = new DatabaseSkillStorage($this->pdo, table: 'missing', baseUri: 'db://team/');
        // Repeating the public operation must still fail: no implicit schema provisioning.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $read ? $storage->read('db://team/writing', 'SKILL.md') : $storage->list();
                $this->fail('A missing table must fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Database skill table "missing" could not be read', $exception->getMessage());
            }
        }
    }

    /** @return array<string, array{bool}> */
    public static function databaseOperations(): array
    {
        return [
            'list' => [false],
            'read' => [true],
        ];
    }

    /** @dataProvider binaryContents */
    public function test_binary_content_is_returned_as_a_string(string $content): void
    {
        $insert = $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['writing', 'asset.bin', $content]);

        $this->assertSame(
            $content,
            (new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/'))->read('db://team/writing', 'asset.bin'),
        );
    }

    /** @return array<string, array{string}> */
    public static function binaryContents(): array
    {
        return ['nul' => ["binary\0content"], 'invalid utf8' => ["invalid\xff"]];
    }

    public function test_empty_text_is_readable(): void
    {
        $this->pdo->exec("INSERT INTO skills VALUES ('writing', 'empty.md', '')");
        $this->assertSame('', (new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/'))->read('db://team/writing', 'empty.md'));
    }

    public function test_empty_identifiers_fail_discovery_clearly(): void
    {
        $this->pdo->exec("INSERT INTO skills VALUES ('', 'SKILL.md', 'Unaddressable document')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill identifiers must be non-empty strings.');
        (new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/'))->list();
    }

    /** @dataProvider relativePaths */
    public function test_relative_paths_stay_within_selected_skill(string $path): void
    {
        $storage = new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/');
        $this->assertSame('Guide', $storage->read('db://team/writing', $path));
    }

    /** @return array<string, array{string}> */
    public static function relativePaths(): array
    {
        return [
            'dot' => ['./references/guide.md'],
            'confined parent' => ['references/../references/guide.md'],
            'redundant separators' => ['references//guide.md'],
        ];
    }

    /** @dataProvider invalidPaths */
    public function test_rejects_invalid_or_escaping_paths(string $path): void
    {
        $insert = $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['writing', $path, 'Must not be selected']);
        $this->expectException(RuntimeException::class);
        (new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/'))->read('db://team/writing', $path);
    }

    /** @return array<string, array{string}> */
    public static function invalidPaths(): array
    {
        return [
            'empty' => [''], 'root' => ['/guide.md'], 'uri' => ['db://other/guide.md'],
            'drive' => ['C:/guide.md'], 'backslash' => ['\\guide.md'], 'nul' => ["guide\0.md"],
            'parent' => ['../guide.md'], 'effective escape' => ['references/../../guide.md'],
            'root itself' => ['references/..'],
        ];
    }

    /** @dataProvider unknownLocations */
    public function test_rejects_unknown_or_noncanonical_locations(string $location): void
    {
        $this->expectException(RuntimeException::class);
        (new DatabaseSkillStorage($this->pdo, baseUri: 'db://team/'))->read($location, 'SKILL.md');
    }

    /** @return array<string, array{string}> */
    public static function unknownLocations(): array
    {
        return [
            'another base URI' => ['db://else/writing'],
            'missing skill' => ['db://team/missing'],
            'encoded alias' => ['db://team/%77riting'],
            'trailing slash' => ['db://team/writing/'],
            'encoded separator' => ['db://team/writing%2Fextra'],
        ];
    }

    /** @dataProvider invalidConfiguration */
    public function test_invalid_configuration_fails(string $baseUri, string $table): void
    {
        $this->expectException(RuntimeException::class);
        new DatabaseSkillStorage($this->pdo, table: $table, baseUri: $baseUri);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidConfiguration(): array
    {
        return [
            'wrong scheme' => ['file:///skills/', 'skills'],
            'missing label' => ['db:///', 'skills'],
            'missing slash' => ['db://team', 'skills'],
            'credentials' => ['db://user:pass@team/', 'skills'],
            'query' => ['db://team/?tenant=1', 'skills'],
            'fragment' => ['db://team/#part', 'skills'],
            'space in base URI' => ['db://team skills/', 'skills'],
            'encoded space in base URI' => ['db://team%20skills/', 'skills'],
            'unicode in base URI' => ['db://café/', 'skills'],
            'dot segment' => ['db://team/../', 'skills'],
            'empty table' => ['db://team/', ''],
            'qualified table' => ['db://team/', 'main.skills'],
            'sql in table' => ['db://team/', 'skills; DROP TABLE skills'],
        ];
    }
}
