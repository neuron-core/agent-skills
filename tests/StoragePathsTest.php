<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\StoragePaths;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StoragePathsTest extends TestCase
{
    /** @dataProvider localPaths */
    public function test_recognizes_absolute_local_paths_per_platform(string $separator, string $path, bool $expected): void
    {
        $this->assertSame($expected, (new StoragePaths())->isAbsoluteLocalPath($path, $separator));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function localPaths(): array
    {
        return [
            'POSIX absolute' => ['/', '/srv/skills', true],
            'POSIX root' => ['/', '/', true],
            'POSIX relative' => ['/', 'skills', false],
            'POSIX network path' => ['/', '//server/skills', false],
            'POSIX backslash' => ['/', '/srv/skills\\team', false],
            'POSIX null byte' => ['/', "/srv/\0/skills", false],
            'POSIX file URI' => ['/', 'file:///srv/skills', false],
            'POSIX drive path' => ['/', 'C:\\skills', false],
            'Windows backslash drive path' => ['\\', 'C:\\skills', true],
            'Windows forward slash drive path' => ['\\', 'c:/skills', true],
            'Windows drive root' => ['\\', 'D:\\', true],
            'Windows drive-relative path' => ['\\', 'C:skills', false],
            'Windows rooted path without drive' => ['\\', '\\skills', false],
            'Windows forward slash rooted path' => ['\\', '/skills', false],
            'Windows relative path' => ['\\', 'skills', false],
            'Windows UNC path' => ['\\', '\\\\server\\share\\skills', false],
            'Windows forward slash UNC path' => ['\\', '//server/share/skills', false],
            'Windows device path' => ['\\', '\\\\?\\C:\\skills', false],
            'Windows null byte' => ['\\', "C:\\skills\0", false],
            'Windows file URI' => ['\\', 'file:///C:/skills', false],
        ];
    }

    /** @dataProvider identifiersWithSeparators */
    public function test_identifiers_cannot_contain_directory_separators(string $skillIdentifier): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill identifiers must be single path segments.');
        (new StoragePaths())->validateIdentifier($skillIdentifier);
    }

    /** @return array<string, array{string}> */
    public static function identifiersWithSeparators(): array
    {
        return [
            'forward slash' => ['team/writing'],
            'backslash' => ['team\\writing'],
            'backslash parent' => ['..\\outside'],
        ];
    }
}
