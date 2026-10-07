<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use LogicException;
use RuntimeException;
use stdClass;
use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function sprintf;
use function str_repeat;
use function array_keys;

class SkillRepositoryTest extends TestCase
{
    /** @dataProvider discoveryAccessors */
    public function test_discovery_is_deferred_until_first_access_and_cached(string $accessor): void
    {
        $storage = new InMemorySkillStorage([]);
        $repository = new SkillRepository($storage);
        $this->assertSame(0, $storage->listCalls);
        $this->assertCount(0, $storage->reads);
        $storage->files['writing'] = ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nBody"];

        if ($accessor === 'get') {
            $repository->get('memory://skills/writing/');
        } elseif ($accessor === 'findByName') {
            $repository->findByName('writing');
        } else {
            $repository->{$accessor}();
        }

        $selected = $repository->get('memory://skills/writing/');
        $repository->catalog();
        $repository->names();
        $repository->diagnostics();
        $this->assertSame(1, $storage->listCalls);
        $this->assertSame([['writing', 'SKILL.md']], $storage->reads);

        $later = new InMemorySkillStorage([
            'other-writing' => ['SKILL.md' => "---\nname: writing\ndescription: Other\n---\nOther"],
            'extra' => ['SKILL.md' => "---\nname: extra\ndescription: Extra\n---\nExtra"],
        ]);
        $repository->addStorage($later);
        $this->assertSame(0, $later->listCalls);
        $this->assertCount(0, $later->reads);
        $this->assertSame(['writing', 'extra', 'writing'], $repository->names());
        $this->assertSame($selected, $repository->get('memory://skills/writing/'));
        $this->assertSame([
            $selected,
            $repository->get('memory://skills/other-writing/'),
        ], $repository->findByName('writing'));
        $this->assertSame(1, $storage->listCalls);
        $this->assertSame([['writing', 'SKILL.md']], $storage->reads);
        $this->assertSame(1, $later->listCalls);
        $this->assertCount(2, $later->reads);
        $this->assertSame([], $repository->diagnostics());
    }

    /** @return array<string, array{string}> */
    public static function discoveryAccessors(): array
    {
        return [
            'catalog' => ['catalog'],
            'names' => ['names'],
            'get' => ['get'],
            'findByName' => ['findByName'],
            'diagnostics' => ['diagnostics'],
        ];
    }

    public function test_failed_discovery_can_be_retried_without_partial_catalog_or_duplicate_diagnostics(): void
    {
        $storage = new class ([]) extends InMemorySkillStorage {
            public bool $broken = true;

            public function read(string $location, string $path): string
            {
                if ($location === 'memory://skills/z-broken/' && $path === 'SKILL.md' && $this->broken) {
                    throw new LogicException('Temporary storage failure.');
                }

                return parent::read($location, $path);
            }
        };
        $storage->files = [
            'a-source' => ['SKILL.md' => "---\nname: first\ndescription: First\n---\nBody"],
            'z-broken' => ['SKILL.md' => "---\nname: second\ndescription: Second\n---\nBody"],
        ];
        $repository = new SkillRepository($storage);
        try {
            $repository->catalog();
            $this->fail('Expected discovery to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Temporary storage failure.', $exception->getMessage());
        }

        $storage->broken = false;
        $this->assertSame(['first', 'second'], $repository->names());
        $this->assertSame([], $repository->diagnostics());
        $repository->catalog();
        $this->assertSame(2, $storage->listCalls);
    }

    public function test_duplicate_locations_fail_without_publishing_a_partial_catalog(): void
    {
        $document = "---\nname: shared\ndescription: Shared\n---\nBody";
        $original = new InMemorySkillStorage(['z-shared' => ['SKILL.md' => $document]]);
        $conflicting = new InMemorySkillStorage([
            'a-new' => ['SKILL.md' => $document],
            'z-shared' => ['SKILL.md' => $document],
        ]);
        $repository = new SkillRepository($original);
        $selected = $repository->get('memory://skills/z-shared/');
        $repository->addStorage($conflicting);

        foreach (['catalog', 'names', 'diagnostics', 'get'] as $accessor) {
            try {
                $accessor === 'get' ? $repository->get('memory://skills/a-new/') : $repository->{$accessor}();
                $this->fail('A conflicting discovery must remain a configuration failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Duplicate skill location "memory://skills/z-shared/".', $exception->getMessage());
            }
        }

        unset($conflicting->files['z-shared']);
        $this->assertSame(['shared', 'shared'], $repository->names());
        $this->assertSame($selected, $repository->get('memory://skills/z-shared/'));
        $this->assertSame($document, $repository->get('memory://skills/a-new/')->readDocument());
        $this->assertSame([], $repository->diagnostics());
    }

    /** @dataProvider duplicateDocuments */
    public function test_duplicate_locations_cannot_be_hidden_by_unusable_documents(bool $sameAdapter, ?string $contents): void
    {
        $first = new class (['shared' => $contents === null ? [] : ['SKILL.md' => $contents]], $sameAdapter) extends InMemorySkillStorage {
            /** @param array<string, array<string, string>> $files */
            public function __construct(array $files, private bool $duplicate)
            {
                parent::__construct($files);
            }

            public function list(): array
            {
                return $this->duplicate
                    ? ['memory://skills/shared/', 'memory://skills/shared/']
                    : ['memory://skills/shared/'];
            }
        };
        $storages = [$first];
        if (!$sameAdapter) {
            $storages[] = new InMemorySkillStorage([
                'shared' => ['SKILL.md' => "---\nname: shared\ndescription: Valid\n---\nBody"],
            ]);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate skill location "memory://skills/shared/".');
        (new SkillRepository(...$storages))->catalog();
    }

    /** @return array<string, array{bool, ?string}> */
    public static function duplicateDocuments(): array
    {
        return [
            'same adapter valid' => [true, "---\nname: shared\ndescription: Valid\n---\nBody"],
            'same adapter unreadable' => [true, null],
            'same adapter invalid' => [true, 'Invalid'],
            'across adapters valid' => [false, "---\nname: shared\ndescription: Valid\n---\nBody"],
            'across adapters unreadable' => [false, null],
            'across adapters invalid' => [false, 'Invalid'],
        ];
    }

    public function test_reads_normalized_instructions_and_location(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Write clear prose\n---\n# Writing instructions\n\nPrefer direct sentences.\n",
            ],
        ]));

        $this->assertSame(
            "# Writing instructions\n\nPrefer direct sentences.",
            $repository->get('memory://skills/writing/')->readInstructions(),
        );
        $this->assertSame('memory://skills/writing/', $repository->get('memory://skills/writing/')->location());
    }

    public function test_reads_complete_frontmatter_without_losing_custom_fields(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => <<<'SKILL'
                ---
                name: writing
                description: Write clear prose
                license: MIT
                user-invocable: false
                metadata:
                  author: Valerio
                custom:
                  tags: [writing, review]
                ---
                Write directly.
                SKILL],
        ]);
        $skill = (new SkillRepository($storage))->get('memory://skills/writing/');
        $frontmatter = $skill->readFrontmatter();

        $this->assertSame('writing', $frontmatter->name);
        $this->assertSame('MIT', $frontmatter->license);
        $this->assertFalse($frontmatter->{'user-invocable'});
        $this->assertInstanceOf(stdClass::class, $frontmatter->metadata);
        $this->assertSame('Valerio', $frontmatter->metadata->author);
        $this->assertInstanceOf(stdClass::class, $frontmatter->custom);
        $this->assertSame(['writing', 'review'], $frontmatter->custom->tags);

        $frontmatter->metadata->author = 'Changed locally';
        $this->assertSame('Valerio', $skill->readFrontmatter()->metadata->author);

        $storage->files['writing']['SKILL.md'] = "---\nname: writing\ndescription: Updated\nlicense: Apache-2.0\n---\nBody";
        $this->assertSame('Apache-2.0', $skill->readFrontmatter()->license);

        $storage->files['writing']['SKILL.md'] = 'Invalid manifest';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "writing" has invalid frontmatter.');
        $skill->readFrontmatter();
    }

    public function test_declared_name_is_not_a_lookup_alias(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([
            'folder' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nBody"],
        ]));
        $this->assertSame('writing', $repository->get('memory://skills/folder/')->name());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "writing" is not available.');
        $repository->get('writing');
    }

    /**
     * @dataProvider nameSearches
     * @param list<string> $locations
     */
    public function test_find_by_name_returns_all_exact_declared_name_matches(string $name, array $locations): void
    {
        $repository = new SkillRepository(
            new InMemorySkillStorage([
                'a-unrelated' => ['SKILL.md' => "---\nname: review\ndescription: Review\n---\nOther"],
                'folder' => ['SKILL.md' => "---\nname: caveman\ndescription: First\n---\nFirst"],
            ]),
            new InMemorySkillStorage([
                'z-copy' => ['SKILL.md' => "---\nname: caveman\ndescription: Second\n---\nSecond"],
            ]),
        );

        $matches = $repository->findByName($name);

        $this->assertSame($locations, array_map(static fn (Skill $skill): string => $skill->location(), $matches));
        foreach ($matches as $skill) {
            $this->assertSame($repository->get($skill->location()), $skill);
        }
    }

    /** @return array<string, array{string, list<string>}> */
    public static function nameSearches(): array
    {
        return [
            'missing name' => ['missing', []],
            'single match' => ['review', ['memory://skills/a-unrelated/']],
            'matches across storages' => ['caveman', ['memory://skills/folder/', 'memory://skills/z-copy/']],
            'case sensitive' => ['Caveman', []],
            'identifier is not the declared name' => ['folder', []],
        ];
    }

    public function test_get_rejects_an_unknown_skill(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "memory://skills/missing/" is not available.');

        $repository->get('memory://skills/missing/');
    }

    public function test_builds_a_deterministic_catalog_and_preserves_complete_instructions(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: \"Write clearly: for humans\"\nlicense: MIT\n---\n\nWrite directly.\n",
            ],
            'analysis' => [
                'SKILL.md' => "---\r\nname: analysis\r\ndescription: Analyse evidence\r\n---\r\nAnalyse carefully.\r\n",
            ],
        ]);
        $repository = new SkillRepository($storage);

        $this->assertSame([
            ['name' => 'analysis', 'description' => 'Analyse evidence'],
            ['name' => 'writing', 'description' => 'Write clearly: for humans'],
        ], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame(['analysis', 'writing'], $repository->names());
        $this->assertSame($repository->get('memory://skills/analysis/'), $repository->catalog()[0]);
        $this->assertSame($repository->get('memory://skills/writing/'), $repository->catalog()[1]);
        $this->assertSame($storage->files['writing']['SKILL.md'], $repository->get('memory://skills/writing/')->readDocument());
        $this->assertSame($storage->files['analysis']['SKILL.md'], $repository->get('memory://skills/analysis/')->readDocument());
    }

    /** @dataProvider invalidSkills */
    public function test_excludes_unusable_skills_with_diagnostics(string $skill, string $contents): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([$skill => ['SKILL.md' => $contents]]));

        $this->assertSame([], $repository->catalog());
        $this->assertNotEmpty($repository->diagnostics());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSkills(): array
    {
        return [
            'missing opening delimiter' => ['missing-open', "name: missing-open\ndescription: Missing delimiter\n---\nBody"],
            'missing closing delimiter' => ['missing-close', "---\nname: missing-close\ndescription: Missing delimiter\nBody"],
            'missing name' => ['missing-name', "---\ndescription: Missing name\n---\nBody"],
            'missing description' => ['missing-description', "---\nname: missing-description\n---\nBody"],
            'empty name' => ['empty-name', "---\nname: \ndescription: Empty name\n---\nBody"],
            'empty description' => ['empty-description', "---\nname: empty-description\ndescription: \n---\nBody"],
            'indented name' => ['indented-name', "---\n name: indented-name\ndescription: Indented\n---\nBody"],
            'indented description' => ['indented-description', "---\nname: indented-description\n description: Indented\n---\nBody"],
        ];
    }

    public function test_distinct_locations_with_the_same_declared_name_keep_their_documents(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([
            'z-last' => ['SKILL.md' => "---\nname: shared\ndescription: Last\n---\nLast body"],
            'a-invalid' => ['SKILL.md' => "---\nname: shared\ndescription: []\n---\nInvalid"],
            'b-first' => [
                'SKILL.md' => "---\nname: shared\ndescription: First\n---\nFirst body",
                'guide.md' => 'First guide',
            ],
        ]));
        $this->assertSame([['name' => 'shared', 'description' => 'First'], ['name' => 'shared', 'description' => 'Last']], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame("---\nname: shared\ndescription: First\n---\nFirst body", $repository->get('memory://skills/b-first/')->readDocument());
        $this->assertSame('First guide', $repository->get('memory://skills/b-first/')->readResource('guide.md'));
        $diagnostics = $repository->diagnostics();
        $this->assertSame('memory://skills/a-invalid/', $diagnostics[0]['skillLocation']);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('Last body', $repository->get('memory://skills/z-last/')->readInstructions());
    }

    public function test_catalog_is_snapshotted_while_instruction_and_resource_reads_are_lazy(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Original description\n---\nOriginal body.",
                'guide.md' => 'Original guide.',
            ],
        ]);
        $repository = new SkillRepository($storage);
        $repository->catalog();
        $storage->files['writing']['SKILL.md'] = "---\nname: writing\ndescription: Changed description\n---\nChanged body.";
        $storage->files['writing']['guide.md'] = 'Changed guide.';
        $storage->files['added']['SKILL.md'] = "---\nname: added\ndescription: Added later\n---\nAdded.";

        $this->assertSame([
            ['name' => 'writing', 'description' => 'Original description'],
        ], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame($storage->files['writing']['SKILL.md'], $repository->get('memory://skills/writing/')->readDocument());
        $this->assertSame('Changed guide.', $repository->get('memory://skills/writing/')->readResource('guide.md'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "memory://skills/added/" is not available.');
        $repository->get('memory://skills/added/')->readDocument();
    }

    /** @dataProvider failingReads */
    public function test_propagates_expected_storage_failures(string $path, bool $instructions): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $repository = new SkillRepository($storage);
        $repository->catalog();
        $storage->failures['writing'][$path] = 'Read failed.';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Read failed.');

        if ($instructions) {
            $repository->get('memory://skills/writing/')->readDocument();
        } else {
            $repository->get('memory://skills/writing/')->readResource($path);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function failingReads(): array
    {
        return [
            'instructions' => ['SKILL.md', true],
            'resource' => ['guide.md', false],
        ];
    }

    public function test_rejects_instructions_with_frontmatter_that_became_invalid(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $repository = new SkillRepository($storage);
        $repository->catalog();
        $storage->files['writing']['SKILL.md'] = 'Invalid document.';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "writing" has invalid frontmatter.');

        $repository->get('memory://skills/writing/')->readDocument();
    }

    public function test_rejects_resources_from_an_unknown_skill(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "memory://skills/unknown/" is not available.');

        $repository->get('memory://skills/unknown/')->readResource('guide.md');
    }

    public function test_expected_manifest_storage_failures_exclude_a_package_from_the_catalog(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $storage->failures['writing']['SKILL.md'] = 'Skill "writing" has an unavailable SKILL.md.';

        $this->assertSame([], (new SkillRepository($storage))->catalog());
    }

    public function test_unexpected_storage_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function list(): array
            {
                return ['memory://skills/broken/'];
            }

            public function read(string $location, string $path): string
            {
                throw new LogicException('Storage failed unexpectedly.');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        (new SkillRepository($storage))->catalog();
    }
}

class InMemorySkillStorage implements SkillStorageInterface
{
    /** @param array<string, array<string, string>> $files */
    public function __construct(public array $files)
    {
    }

    /** @var array<string, array<string, string>> */
    public array $failures = [];

    public int $listCalls = 0;

    /** @var list<array{string, string}> */
    public array $reads = [];

    public function list(): array
    {
        ++$this->listCalls;
        return array_map(static fn (string $name): string => 'memory://skills/'.$name.'/', array_keys($this->files));
    }

    public function read(string $location, string $path): string
    {
        $skill = substr($location, strlen('memory://skills/'), -1);
        $this->reads[] = [$skill, $path];
        if (isset($this->failures[$skill][$path])) {
            throw new RuntimeException($this->failures[$skill][$path]);
        }
        if (!array_key_exists($skill, $this->files)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $skill));
        }
        if (!array_key_exists($path, $this->files[$skill])) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skill));
        }

        return $this->files[$skill][$path];
    }
}
