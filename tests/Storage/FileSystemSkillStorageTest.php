<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Storage;

use FilesystemIterator;
use RuntimeException;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function bin2hex;
use function chmod;
use function file_put_contents;
use function is_dir;
use function is_readable;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

class FileSystemSkillStorageTest extends TestCase
{
    protected string $skillsRoot;
    protected string $outsideRoot;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->skillsRoot = sys_get_temp_dir().'/neuron-skills-'.$suffix;
        $this->outsideRoot = sys_get_temp_dir().'/neuron-outside-skills-'.$suffix;
        mkdir($this->skillsRoot, 0o777, true);
        mkdir($this->outsideRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->skillsRoot);
        $this->removeDirectory($this->outsideRoot);
    }

    public function test_enumerates_only_direct_child_packages_in_deterministic_order(): void
    {
        file_put_contents($this->skillsRoot.'/README.md', 'Not a package.');
        mkdir($this->skillsRoot.'/writing', 0o777, true);
        mkdir($this->skillsRoot.'/analysis', 0o777, true);
        mkdir($this->skillsRoot.'/nested/ignored', 0o777, true);

        $this->assertSame(['file://'.$this->skillsRoot.'/analysis/', 'file://'.$this->skillsRoot.'/nested/', 'file://'.$this->skillsRoot.'/writing/'], (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->list());
    }

    /** @dataProvider invalidMounts */
    public function test_rejects_invalid_mounts(string $mount): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local absolute file URI');
        new FileSystemSkillStorage($mount);
    }

    /** @return array<string, array{string}> */
    public static function invalidMounts(): array
    {
        return [
            'native path' => ['/tmp/skills'],
            'wrong scheme' => ['db://team/'],
            'remote host' => ['file://server/skills/'],
            'relative path' => ['file:skills/'],
            'query' => ['file:///skills/?x=1'],
            'fragment' => ['file:///skills/#section'],
            'malformed escape' => ['file:///skills/100%/'],
            'null byte' => ['file:///skills/%00/'],
            'backslash' => ['file:///skills/%5C/'],
            'network path' => ['file:////server/skills/'],
            'whitespace' => ['file:///skills/a b/'],
            'control character' => ["file:///skills/a\nb/"],
            'raw unicode' => ['file:///skills/café/'],
            'raw brackets' => ['file:///skills/[draft]/'],
        ];
    }

    public function test_encoded_mount_and_skill_names_round_trip_without_double_decoding(): void
    {
        $root = $this->skillsRoot.'/sources café #100%25';
        mkdir($root.'/notes %20é#/references', 0o777, true);
        file_put_contents($root.'/notes %20é#/SKILL.md', 'Original document.');
        file_put_contents($root.'/notes %20é#/references/%2e%2e.md', 'Literal percent filename.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/sources%20caf%c3%a9%20%23100%2525/./');
        $location = 'file://'.$this->skillsRoot.'/sources%20caf%C3%A9%20%23100%2525/notes%20%2520%C3%A9%23/';

        $this->assertSame([$location], $storage->list());
        $this->assertSame('Original document.', $storage->read($location, 'SKILL.md'));
        $this->assertSame('Literal percent filename.', $storage->read($location, 'references/%2e%2e.md'));
        $this->assertStorageError(
            'Skill "'.str_replace('%C3%A9', '%c3%a9', $location).'" is not available.',
            fn (): string => $storage->read(str_replace('%C3%A9', '%c3%a9', $location), 'SKILL.md'),
        );
    }

    public function test_empty_and_nonexistent_roots_have_no_skills(): void
    {
        $this->assertSame([], (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->list());
        $this->assertSame([], (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/missing'.'/'))->list());
    }

    public function test_package_names_are_snapshotted_while_files_are_read_lazily_and_in_full(): void
    {
        mkdir($this->skillsRoot.'/writing');
        $path = $this->skillsRoot.'/writing/guide.md';
        file_put_contents($path, 'Original.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');
        mkdir($this->skillsRoot.'/added');
        $contents = str_repeat('Complete UTF-8 text: café. ', 10000);
        file_put_contents($path, $contents);

        $this->assertSame(['file://'.$this->skillsRoot.'/writing/'], $storage->list());
        $this->assertSame($contents, $storage->read('file://'.$this->skillsRoot.'/writing/', 'guide.md'));
    }

    public function test_uses_a_linked_package_directory_as_its_canonical_boundary(): void
    {
        mkdir($this->outsideRoot.'/shared-skill');
        file_put_contents($this->outsideRoot.'/shared-skill/guide.md', 'Shared guide.');
        symlink($this->outsideRoot.'/shared-skill', $this->skillsRoot.'/shared-skill');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertSame(['file://'.$this->skillsRoot.'/shared-skill/'], $storage->list());
        $this->assertSame('Shared guide.', $storage->read('file://'.$this->skillsRoot.'/shared-skill/', 'guide.md'));
        file_put_contents($this->outsideRoot.'/secret.md', 'Outside the linked skill.');
        symlink($this->outsideRoot.'/secret.md', $this->outsideRoot.'/shared-skill/secret-link.md');
        $this->assertStorageError(
            'Resource "secret-link.md" escapes skill "file://'.$this->skillsRoot.'/shared-skill/".',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/shared-skill/', 'secret-link.md'),
        );
    }

    public function test_unknown_skill_location_is_an_expected_failure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "file://'.$this->skillsRoot.'/missing/" is not available.');
        (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/missing/', 'SKILL.md');
    }

    public function test_reads_scripts_as_text_without_executing_them(): void
    {
        mkdir($this->skillsRoot.'/writing/scripts', 0o777, true);
        $marker = $this->outsideRoot.'/executed';
        $script = "#!/bin/sh\ntouch {$marker}\n";
        file_put_contents($this->skillsRoot.'/writing/scripts/run.sh', $script);

        $this->assertSame(
            $script,
            (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', 'scripts/run.sh'),
        );
        $this->assertFileDoesNotExist($marker);
    }

    public function test_allows_confined_file_symlinks(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/references/guide.md', 'Confined target.');
        symlink('references/guide.md', $this->skillsRoot.'/writing/guide-link.md');

        $this->assertSame(
            'Confined target.',
            (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', 'guide-link.md'),
        );
    }

    public function test_rejects_a_file_symlink_that_escapes_the_package(): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->outsideRoot.'/secret.md', 'External target.');
        symlink($this->outsideRoot.'/secret.md', $this->skillsRoot.'/writing/secret-link.md');

        $this->assertStorageError(
            'Resource "secret-link.md" escapes skill "file://'.$this->skillsRoot.'/writing/".',
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', 'secret-link.md'),
        );
    }

    /** @dataProvider invalidPaths */
    public function test_rejects_invalid_paths(string $path): void
    {
        mkdir($this->skillsRoot.'/writing');

        $this->assertStorageError(
            sprintf('Resource path "%s" is invalid.', $path),
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', $path),
        );
    }

    /** @return array<string, array{string}> */
    public static function invalidPaths(): array
    {
        return [
            'empty' => [''],
            'null byte' => ["resource\0.md"],
            'native absolute path' => ['/tmp/secret.md'],
            'file URI' => ['file:///tmp/secret.md'],
            'remote URI' => ['https://example.test/secret.md'],
            'Windows absolute path' => ['C:\\skills\\secret.md'],
            'UNC path' => ['\\\\server\\skills\\secret.md'],
        ];
    }

    public function test_rejects_parent_traversal_that_escapes_the_package(): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/secret.md', 'External target.');

        $this->assertStorageError(
            'Resource "../secret.md" escapes skill "file://'.$this->skillsRoot.'/writing/".',
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', '../secret.md'),
        );
    }

    public function test_parent_segments_within_the_selected_skill_remain_readable(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/guide.md', 'Confined guide.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertSame('Confined guide.', $storage->read(
            'file://'.$this->skillsRoot.'/writing/',
            'references/../guide.md',
        ));
    }

    public function test_reports_unknown_packages_missing_files_and_directories(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertStorageError(
            'Skill "file://'.$this->skillsRoot.'/missing/" is not available.',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/missing/', 'guide.md'),
        );
        $this->assertStorageError(
            'Resource "missing.md" was not found in skill "file://'.$this->skillsRoot.'/writing/".',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/writing/', 'missing.md'),
        );
        $this->assertStorageError(
            'Resource "references" in skill "file://'.$this->skillsRoot.'/writing/" is not a file.',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/writing/', 'references'),
        );
    }

    public function test_reports_an_unreadable_file(): void
    {
        mkdir($this->skillsRoot.'/writing');
        $path = $this->skillsRoot.'/writing/locked.txt';
        file_put_contents($path, 'Locked content.');
        chmod($path, 0o000);
        if (is_readable($path)) {
            chmod($path, 0o644);
            $this->markTestSkipped('The current user can read files regardless of their permission bits.');
        }

        try {
            $this->assertStorageError(
                'Resource "locked.txt" in skill "file://'.$this->skillsRoot.'/writing/" could not be read.',
                fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', 'locked.txt'),
            );
        } finally {
            chmod($path, 0o644);
        }
    }

    /** @dataProvider binaryContents */
    public function test_rejects_binary_content(string $contents): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/writing/content.bin', $contents);

        $this->assertStorageError(
            'Resource "content.bin" in skill "file://'.$this->skillsRoot.'/writing/" contains unsupported binary content.',
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing/', 'content.bin'),
        );
    }

    /** @return array<string, array{string}> */
    public static function binaryContents(): array
    {
        return [
            'null byte' => ["text\0binary"],
            'invalid UTF-8' => ["invalid \xC3\x28"],
        ];
    }

    /** @param callable(): string $read */
    protected function assertStorageError(string $expected, callable $read): void
    {
        try {
            $read();
            $this->fail('Expected a storage exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception->getMessage());
        }
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isLink() || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }

        rmdir($directory);
    }
}
