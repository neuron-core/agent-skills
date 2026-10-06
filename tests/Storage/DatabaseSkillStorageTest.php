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
        $this->pdo->exec('CREATE TABLE skills (skill_name TEXT NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, UNIQUE(skill_name, path))');
        $this->pdo->exec("INSERT INTO skills VALUES ('writing', 'SKILL.md', 'Document'), ('writing', 'references/guide.md', 'Guide')");
    }

    public function test_configured_table_uses_supplied_connection(): void
    {
        $this->pdo->exec('ALTER TABLE skills RENAME TO team_skills');
        $storage = new DatabaseSkillStorage('db://team/project/', $this->pdo, 'team_skills');
        $this->assertSame(['db://team/project/writing/'], $storage->list());
        $this->assertSame('Guide', $storage->read('db://team/project/writing/', 'references/guide.md'));
    }

    public function test_connection_column_case_setting_is_preserved(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $storage = new DatabaseSkillStorage('db://team/', $this->pdo);
        $this->assertSame(['db://team/writing/'], $storage->list());
        $this->assertSame('Guide', $storage->read('db://team/writing/', 'references/guide.md'));
        $this->assertSame(PDO::CASE_UPPER, $this->pdo->getAttribute(PDO::ATTR_CASE));
    }

    /** @dataProvider databaseFailureModes */
    public function test_missing_tables_fail_clearly_without_creating_them(int $mode, bool $read): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $storage = new DatabaseSkillStorage('db://team/', $this->pdo, 'missing');
        // Repeating the public operation must still fail: no implicit schema provisioning.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $read ? $storage->read('db://team/writing/', 'SKILL.md') : $storage->list();
                $this->fail('A missing table must fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Database skill table "missing" could not be read', $exception->getMessage());
            }
        }
    }

    /** @return array<string, array{int, bool}> */
    public static function databaseFailureModes(): array
    {
        return [
            'exception list' => [PDO::ERRMODE_EXCEPTION, false],
            'exception read' => [PDO::ERRMODE_EXCEPTION, true],
            'silent list' => [PDO::ERRMODE_SILENT, false],
            'silent read' => [PDO::ERRMODE_SILENT, true],
            'warning list' => [PDO::ERRMODE_WARNING, false],
            'warning read' => [PDO::ERRMODE_WARNING, true],
        ];
    }

    /** @dataProvider unsupportedContent */
    public function test_binary_content_is_rejected(string $content): void
    {
        $insert = $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');
        $insert->execute(['writing', 'asset.bin', $content]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported binary content');
        (new DatabaseSkillStorage('db://team/', $this->pdo))->read('db://team/writing/', 'asset.bin');
    }

    /** @return array<string, array{string}> */
    public static function unsupportedContent(): array
    {
        return ['nul' => ["binary\0content"], 'invalid utf8' => ["invalid\xff"]];
    }

    public function test_empty_text_is_readable(): void
    {
        $this->pdo->exec("INSERT INTO skills VALUES ('writing', 'empty.md', '')");
        $this->assertSame('', (new DatabaseSkillStorage('db://team/', $this->pdo))->read('db://team/writing/', 'empty.md'));
    }

    public function test_empty_text_is_readable_without_changing_connection_null_conversion(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_EMPTY_STRING);
        $this->pdo->exec("INSERT INTO skills VALUES ('writing', 'empty.md', '')");
        $storage = new DatabaseSkillStorage('db://team/', $this->pdo);

        $this->assertSame('', $storage->read('db://team/writing/', 'empty.md'));
        $this->assertSame(PDO::NULL_EMPTY_STRING, $this->pdo->getAttribute(PDO::ATTR_ORACLE_NULLS));
    }

    /** @dataProvider dotIdentifiers */
    public function test_dot_identifiers_have_canonical_readable_locations(string $identifier, string $location): void
    {
        $this->pdo->exec('DELETE FROM skills');
        $this->pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)')->execute([$identifier, 'SKILL.md', 'Dot document']);
        $storage = new DatabaseSkillStorage('db://team/', $this->pdo);

        $this->assertSame([$location], $storage->list());
        $this->assertSame('Dot document', $storage->read($location, 'SKILL.md'));
    }

    /** @return array<string, array{string, string}> */
    public static function dotIdentifiers(): array
    {
        return ['dot' => ['.', 'db://team/%2E/'], 'parent' => ['..', 'db://team/%2E%2E/']];
    }

    /** @dataProvider nullConversionModes */
    public function test_empty_identifiers_fail_discovery_clearly(int $mode): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, $mode);
        $this->pdo->exec("INSERT INTO skills VALUES ('', 'SKILL.md', 'Unaddressable document')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill identifiers must be non-empty strings.');
        (new DatabaseSkillStorage('db://team/', $this->pdo))->list();
    }

    /** @return array<string, array{int}> */
    public static function nullConversionModes(): array
    {
        return ['natural' => [PDO::NULL_NATURAL], 'empty string' => [PDO::NULL_EMPTY_STRING]];
    }

    /** @dataProvider relativePaths */
    public function test_relative_paths_stay_within_selected_skill(string $path): void
    {
        $storage = new DatabaseSkillStorage('db://team/', $this->pdo);
        $this->assertSame('Guide', $storage->read('db://team/writing/', $path));
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
        (new DatabaseSkillStorage('db://team/', $this->pdo))->read('db://team/writing/', $path);
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
        (new DatabaseSkillStorage('db://team/', $this->pdo))->read($location, 'SKILL.md');
    }

    /** @return array<string, array{string}> */
    public static function unknownLocations(): array
    {
        return [
            'another mount' => ['db://else/writing/'],
            'missing skill' => ['db://team/missing/'],
            'encoded alias' => ['db://team/%77riting/'],
            'missing slash' => ['db://team/writing'],
            'full document address' => ['db://team/writing/SKILL.md'],
        ];
    }

    /** @dataProvider invalidConfiguration */
    public function test_invalid_configuration_fails(string $mount, string $table): void
    {
        $this->expectException(RuntimeException::class);
        new DatabaseSkillStorage($mount, $this->pdo, $table);
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
            'dot segment' => ['db://team/../', 'skills'],
            'empty table' => ['db://team/', ''],
            'qualified table' => ['db://team/', 'main.skills'],
            'sql in table' => ['db://team/', 'skills; DROP TABLE skills'],
        ];
    }
}
