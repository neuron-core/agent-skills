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

        $this->assertSame(['file://'.$this->skillsRoot.'/analysis', 'file://'.$this->skillsRoot.'/nested', 'file://'.$this->skillsRoot.'/writing'], (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->list());
    }

    /** @dataProvider invalidBasePaths */
    public function test_rejects_invalid_base_paths(string $base): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('absolute local path');
        new FileSystemSkillStorage($base);
    }

    /** @return array<string, array{string}> */
    public static function invalidBasePaths(): array
    {
        return [
            'relative native path' => ['skills'],
            'wrong scheme' => ['db://team/'],
            'remote host' => ['file://server/skills/'],
            'relative path' => ['file:skills/'],
            'null byte' => ['file:///skills/%00/'],
            'backslash' => ['file:///skills/%5C/'],
            'network path' => ['file:////server/skills/'],
        ];
    }

    public function test_canonicalizes_special_characters_in_file_uri_input(): void
    {
        $root = $this->skillsRoot.'/café [draft] #? 100%';
        mkdir($root.'/writing', 0o777, true);
        file_put_contents($root.'/writing/SKILL.md', 'Found the skill.');

        $storage = new FileSystemSkillStorage('file://'.$root.'/');
        $location = 'file://'.$this->skillsRoot.'/caf%C3%A9%20%5Bdraft%5D%20%23%3F%20100%25/writing';

        $this->assertSame([$location], $storage->list());
        $this->assertSame('Found the skill.', $storage->read($location, 'SKILL.md'));
    }

    public function test_accepts_native_directory_and_encodes_catalog_locations(): void
    {
        $root = $this->skillsRoot.'/sources café #100%25';
        mkdir($root.'/writing', 0o777, true);
        file_put_contents($root.'/writing/SKILL.md', 'Original document.');

        $storage = new FileSystemSkillStorage($root);
        $location = 'file://'.$this->skillsRoot.'/sources%20caf%C3%A9%20%23100%2525/writing';

        $this->assertSame([$location], $storage->list());
        $this->assertSame('Original document.', $storage->read($location, 'SKILL.md'));
    }

    /** @dataProvider directoryNamesWithSpecialCharacters */
    public function test_directory_names_with_special_characters_round_trip(string $directoryName): void
    {
        $root = $this->skillsRoot.'/'.$directoryName;
        mkdir($root.'/my skill', 0o777, true);
        file_put_contents($root.'/my skill/SKILL.md', 'Found the skill.');

        $location = 'file://'.$this->skillsRoot.'/'.rawurlencode($directoryName).'/my%20skill';

        foreach ([$root, 'file://'.$this->skillsRoot.'/'.rawurlencode($directoryName).'/'] as $base) {
            $storage = new FileSystemSkillStorage($base);
            $this->assertSame([$location], $storage->list());
            $this->assertSame('Found the skill.', $storage->read($location, 'SKILL.md'));
        }
    }

    /** @return array<string, array{string}> */
    public static function directoryNamesWithSpecialCharacters(): array
    {
        return [
            'space' => ['team skills'],
            'URI delimiters' => ['team #1?'],
            'literal percent sequence' => ['team %20'],
            'Unicode and punctuation' => ["café [draft] + it's ready"],
        ];
    }

    public function test_encoded_separator_in_base_uri_is_canonicalized(): void
    {
        mkdir($this->skillsRoot.'/team/writing', 0o777, true);
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/team%2F');

        $this->assertSame(['file://'.$this->skillsRoot.'/team/writing'], $storage->list());
    }

    public function test_encoded_base_uri_and_skill_names_round_trip_without_double_decoding(): void
    {
        $root = $this->skillsRoot.'/sources café #100%25';
        mkdir($root.'/notes %20é#/references', 0o777, true);
        file_put_contents($root.'/notes %20é#/SKILL.md', 'Original document.');
        file_put_contents($root.'/notes %20é#/references/%2e%2e.md', 'Literal percent filename.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/sources%20caf%c3%a9%20%23100%2525/./');
        $location = 'file://'.$this->skillsRoot.'/sources%20caf%C3%A9%20%23100%2525/notes%20%2520%C3%A9%23';

        $this->assertSame([$location], $storage->list());
        $this->assertSame('Original document.', $storage->read($location, 'SKILL.md'));
        $this->assertSame('Literal percent filename.', $storage->read($location, 'references/%2e%2e.md'));
        $this->assertStorageError(
            'Skill location "'.str_replace('%C3%A9', '%c3%a9', $location).'" is not available.',
            fn (): string => $storage->read(str_replace('%C3%A9', '%c3%a9', $location), 'SKILL.md'),
        );
    }

    public function test_empty_root_has_no_skills(): void
    {
        $this->assertSame([], (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->list());
    }

    public function test_rejects_a_missing_root_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill directory must exist and be a directory.');

        new FileSystemSkillStorage($this->skillsRoot.'/missing');
    }

    public function test_rejects_a_file_as_the_root_directory(): void
    {
        file_put_contents($this->skillsRoot.'/file.txt', 'Not a directory.');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill directory must exist and be a directory.');

        new FileSystemSkillStorage('file://'.$this->skillsRoot.'/file.txt');
    }

    public function test_list_finds_new_packages_and_files_are_read_lazily_and_in_full(): void
    {
        mkdir($this->skillsRoot.'/writing');
        $path = $this->skillsRoot.'/writing/guide.md';
        file_put_contents($path, 'Original.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');
        mkdir($this->skillsRoot.'/added');
        file_put_contents($this->skillsRoot.'/added/guide.md', 'New guide.');
        $contents = str_repeat('Complete UTF-8 text: café. ', 10000);
        file_put_contents($path, $contents);

        $this->assertSame([
            'file://'.$this->skillsRoot.'/added',
            'file://'.$this->skillsRoot.'/writing',
        ], $storage->list());
        $this->assertSame('New guide.', $storage->read('file://'.$this->skillsRoot.'/added', 'guide.md'));
        $this->assertSame($contents, $storage->read('file://'.$this->skillsRoot.'/writing', 'guide.md'));
    }

    public function test_read_rejects_noncanonical_skill_segments(): void
    {
        mkdir($this->skillsRoot.'/nested/child', 0o777, true);
        file_put_contents($this->skillsRoot.'/nested/child/guide.md', 'Nested file.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertSame('Nested file.', $storage->read('file://'.$this->skillsRoot.'/nested', 'child/guide.md'));

        foreach (['nested/', 'nested/child', 'nested%2Fchild', '%2E', '%2E%2E', '%00'] as $identifier) {
            $location = 'file://'.$this->skillsRoot.'/'.$identifier;
            $this->assertStorageError(
                sprintf('Skill location "%s" is not available.', $location),
                fn (): string => $storage->read($location, 'guide.md'),
            );
        }
    }

    public function test_uses_a_linked_package_directory_as_its_canonical_boundary(): void
    {
        mkdir($this->outsideRoot.'/shared-skill');
        file_put_contents($this->outsideRoot.'/shared-skill/guide.md', 'Shared guide.');
        symlink($this->outsideRoot.'/shared-skill', $this->skillsRoot.'/shared-skill');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertSame(['file://'.$this->skillsRoot.'/shared-skill'], $storage->list());
        $this->assertSame('Shared guide.', $storage->read('file://'.$this->skillsRoot.'/shared-skill', 'guide.md'));
        file_put_contents($this->outsideRoot.'/secret.md', 'Outside the linked skill.');
        symlink($this->outsideRoot.'/secret.md', $this->outsideRoot.'/shared-skill/secret-link.md');
        $this->assertStorageError(
            'Resource "secret-link.md" escapes skill "file://'.$this->skillsRoot.'/shared-skill".',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/shared-skill', 'secret-link.md'),
        );
    }

    public function test_unknown_skill_location_is_an_expected_failure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "file://'.$this->skillsRoot.'/missing" is not available.');
        (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/missing', 'SKILL.md');
    }

    public function test_reads_scripts_as_text_without_executing_them(): void
    {
        mkdir($this->skillsRoot.'/writing/scripts', 0o777, true);
        $marker = $this->outsideRoot.'/executed';
        $script = "#!/bin/sh\ntouch {$marker}\n";
        file_put_contents($this->skillsRoot.'/writing/scripts/run.sh', $script);

        $this->assertSame(
            $script,
            (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', 'scripts/run.sh'),
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
            (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', 'guide-link.md'),
        );
    }

    public function test_rejects_a_file_symlink_that_escapes_the_package(): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->outsideRoot.'/secret.md', 'External target.');
        symlink($this->outsideRoot.'/secret.md', $this->skillsRoot.'/writing/secret-link.md');

        $this->assertStorageError(
            'Resource "secret-link.md" escapes skill "file://'.$this->skillsRoot.'/writing".',
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', 'secret-link.md'),
        );
    }

    public function test_resource_cannot_read_a_file_in_a_sibling_skill(): void
    {
        mkdir($this->skillsRoot.'/my skill');
        mkdir($this->skillsRoot.'/other skill');
        file_put_contents($this->skillsRoot.'/other skill/secret.md', 'Other skill content.');
        $storage = new FileSystemSkillStorage($this->skillsRoot);
        $location = 'file://'.$this->skillsRoot.'/my%20skill';

        $this->assertStorageError(
            'Resource path "../other skill/secret.md" escapes the skill root.',
            fn (): string => $storage->read($location, '../other skill/secret.md'),
        );
    }

    public function test_resource_cannot_follow_a_directory_symlink_outside_its_skill(): void
    {
        mkdir($this->skillsRoot.'/my skill');
        mkdir($this->outsideRoot.'/private notes');
        file_put_contents($this->outsideRoot.'/private notes/secret.md', 'Outside content.');
        symlink($this->outsideRoot.'/private notes', $this->skillsRoot.'/my skill/linked notes');
        $storage = new FileSystemSkillStorage($this->skillsRoot);
        $location = 'file://'.$this->skillsRoot.'/my%20skill';

        $this->assertStorageError(
            'Resource "linked notes/secret.md" escapes skill "'.$location.'".',
            fn (): string => $storage->read($location, 'linked notes/secret.md'),
        );
    }

    public function test_parent_segments_are_normalized_before_following_a_directory_symlink(): void
    {
        mkdir($this->skillsRoot.'/writing/references/nested', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/references/my guide.md', 'Symlink target.');
        file_put_contents($this->skillsRoot.'/writing/my guide.md', 'Normalized target.');
        symlink('references/nested', $this->skillsRoot.'/writing/docs');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertSame('Normalized target.', $storage->read(
            'file://'.$this->skillsRoot.'/writing',
            'docs/../my guide.md',
        ));
    }

    /** @dataProvider invalidPaths */
    public function test_rejects_invalid_paths(string $path): void
    {
        mkdir($this->skillsRoot.'/writing');

        $this->assertStorageError(
            sprintf('Resource path "%s" is invalid.', $path),
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', $path),
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
            'Resource path "../secret.md" escapes the skill root.',
            fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', '../secret.md'),
        );
    }

    public function test_parent_segments_within_the_selected_skill_remain_readable(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/guide.md', 'Confined guide.');
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertSame('Confined guide.', $storage->read(
            'file://'.$this->skillsRoot.'/writing',
            'references/../guide.md',
        ));
    }

    public function test_reports_unknown_packages_missing_files_and_directories(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        $storage = new FileSystemSkillStorage('file://'.$this->skillsRoot.'/');

        $this->assertStorageError(
            'Skill "file://'.$this->skillsRoot.'/missing" is not available.',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/missing', 'guide.md'),
        );
        $this->assertStorageError(
            'Resource "missing.md" was not found in skill "file://'.$this->skillsRoot.'/writing".',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/writing', 'missing.md'),
        );
        $this->assertStorageError(
            'Resource "references" in skill "file://'.$this->skillsRoot.'/writing" is not a file.',
            fn (): string => $storage->read('file://'.$this->skillsRoot.'/writing', 'references'),
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
                'Resource "locked.txt" in skill "file://'.$this->skillsRoot.'/writing" could not be read.',
                fn (): string => (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', 'locked.txt'),
            );
        } finally {
            chmod($path, 0o644);
        }
    }

    /** @dataProvider binaryContents */
    public function test_returns_binary_content_as_a_string(string $contents): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/writing/content.bin', $contents);

        $this->assertSame(
            $contents,
            (new FileSystemSkillStorage('file://'.$this->skillsRoot.'/'))->read('file://'.$this->skillsRoot.'/writing', 'content.bin'),
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
