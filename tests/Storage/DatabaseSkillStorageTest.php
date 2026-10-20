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

    public function test_resources_use_the_selected_identifier_and_table(): void
    {
        $this->pdo->exec("INSERT INTO skills VALUES ('other', 'secret.md', 'Other')");
        $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute(['writing', 'assets/città %20.bin', "\0\xff"]);
        $this->pdo->exec('ALTER TABLE skills RENAME TO team_skills');
        $storage = new DatabaseSkillStorage($this->pdo, table: 'team_skills');

        $this->assertSame(['SKILL.md', 'assets/città %20.bin', 'references/guide.md'], $storage->resources('writing'));
    }

    public function test_resource_enumeration_rejects_unknown_skills(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not available');
        (new DatabaseSkillStorage($this->pdo))->resources('unknown');
    }

    public function test_resource_enumeration_rejects_uri_identifiers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('single path segments');
        (new DatabaseSkillStorage($this->pdo))->resources('db://other/writing');
    }

    /** @dataProvider invalidPaths */
    public function test_resource_enumeration_rejects_invalid_stored_paths(string $path): void
    {
        $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute(['writing', $path, 'Invalid']);
        $this->expectException(RuntimeException::class);
        (new DatabaseSkillStorage($this->pdo))->resources('writing');
    }

    public function test_resource_enumeration_reports_database_failures(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be read');
        (new DatabaseSkillStorage($this->pdo, table: 'missing'))->resources('writing');
    }

    public function test_lists_and_reads_storage_identifiers(): void
    {
        $storage = new DatabaseSkillStorage($this->pdo);

        $this->assertSame(['writing'], $storage->list());
        $this->assertSame('Document', $storage->read('writing', 'SKILL.md'));
    }

    public function test_configured_table_uses_supplied_connection(): void
    {
        $this->pdo->exec('ALTER TABLE skills RENAME TO team_skills');
        $storage = new DatabaseSkillStorage($this->pdo, table: 'team_skills');
        $this->assertSame(['writing'], $storage->list());
        $this->assertSame('Guide', $storage->read('writing', 'references/guide.md'));
    }

    /** @dataProvider identifiersWithSpecialCharacters */
    public function test_identifiers_with_special_characters_round_trip(string $identifier): void
    {
        $insert = $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute([$identifier, 'SKILL.md', 'Found the skill.']);
        $insert->execute([$identifier, 'references/my #?% guide.md', 'Found the resource.']);

        $storage = new DatabaseSkillStorage($this->pdo);
        $skillIdentifier = $identifier;

        $this->assertContains($skillIdentifier, $storage->list());
        $this->assertSame('Found the skill.', $storage->read($skillIdentifier, 'SKILL.md'));
        $this->assertSame('Found the resource.', $storage->read($skillIdentifier, 'references/my #?% guide.md'));
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

        $storage = new DatabaseSkillStorage($this->pdo);

        $this->assertSame('Found the resource.', $storage->read('writing', $path));
    }

    /** @dataProvider databaseOperations */
    public function test_missing_tables_fail_clearly_without_creating_them(bool $read): void
    {
        $storage = new DatabaseSkillStorage($this->pdo, table: 'missing');
        // Repeating the public operation must still fail: no implicit schema provisioning.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $read ? $storage->read('writing', 'SKILL.md') : $storage->list();
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
            (new DatabaseSkillStorage($this->pdo))->read('writing', 'asset.bin'),
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
        $this->assertSame('', (new DatabaseSkillStorage($this->pdo))->read('writing', 'empty.md'));
    }

    public function test_numeric_identifiers_remain_literal_strings(): void
    {
        $this->pdo->exec("INSERT INTO skills VALUES ('123', 'SKILL.md', 'Numeric document')");
        $storage = new DatabaseSkillStorage($this->pdo);
        $this->assertContains('123', $storage->list());
        $this->assertSame('Numeric document', $storage->read('123', 'SKILL.md'));
    }

    public function test_empty_identifiers_fail_discovery_clearly(): void
    {
        $this->pdo->exec("INSERT INTO skills VALUES ('', 'SKILL.md', 'Unaddressable document')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill identifiers must be non-empty strings.');
        (new DatabaseSkillStorage($this->pdo))->list();
    }

    /** @dataProvider relativePaths */
    public function test_relative_paths_stay_within_selected_skill(string $path): void
    {
        $storage = new DatabaseSkillStorage($this->pdo);
        $this->assertSame('Guide', $storage->read('writing', $path));
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
        (new DatabaseSkillStorage($this->pdo))->read('writing', $path);
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

    /** @dataProvider unknownIdentifiers */
    public function test_rejects_unknown_or_invalid_identifiers(string $skillIdentifier): void
    {
        $this->expectException(RuntimeException::class);
        (new DatabaseSkillStorage($this->pdo))->read($skillIdentifier, 'SKILL.md');
    }

    /** @return array<string, array{string}> */
    public static function unknownIdentifiers(): array
    {
        return [
            'another base URI' => ['db://else/writing'],
            'missing skill' => ['missing'],
            'encoded alias' => ['%77riting'],
            'trailing slash' => ['writing/'],
            'encoded separator' => ['writing%2Fextra'],
        ];
    }

    /** @dataProvider invalidTables */
    public function test_invalid_table_configuration_fails(string $table): void
    {
        $this->expectException(RuntimeException::class);
        new DatabaseSkillStorage($this->pdo, table: $table);
    }

    /** @return array<string, array{string}> */
    public static function invalidTables(): array
    {
        return [
            'empty table' => [''],
            'qualified table' => ['main.skills'],
            'sql in table' => ['skills; DROP TABLE skills'],
        ];
    }
}
