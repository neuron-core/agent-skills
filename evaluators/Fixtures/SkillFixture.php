<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Fixtures;

use FilesystemIterator;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Fresh data for each conversation, independent of installed skills and demo databases. */
final class SkillFixture
{
    public readonly string $root;
    public readonly SkillRepository $repository;
    /** @var array<string, array<string, string>> */
    private array $files;

    /**
     * @param array<string, string> $expectedArtifacts
     * @param list<string> $databaseSkills Additional skills stored in SQLite in a filesystem case.
     */
    public function __construct(
        public readonly string $skill,
        public readonly string $storage,
        private readonly array $expectedArtifacts = [],
        array $databaseSkills = [],
    ) {
        $this->root = sys_get_temp_dir().'/skill-evaluation-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0o700);

        try {
            $this->files = self::catalogFiles();

            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE skills (skill_identifier TEXT, path TEXT, content TEXT, PRIMARY KEY (skill_identifier, path))');
            $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');

            foreach ($this->files as $name => $resources) {
                $database = $storage === 'database' || in_array($name, $databaseSkills, true);

                foreach ($resources as $path => $content) {
                    if ($database) {
                        $insert->execute([$name, $path, $content]);
                    } else {
                        $destination = $this->root.'/'.$name.'/'.$path;

                        if (!is_dir(dirname($destination))) {
                            mkdir(dirname($destination), 0o700, true);
                        }

                        file_put_contents($destination, $content);
                    }
                }
            }

            $this->repository = new SkillRepository(new FileSystemSkillStorage($this->root), new DatabaseSkillStorage($pdo));
        } catch (\Throwable $exception) {
            self::removeDirectory($this->root);

            throw $exception;
        }
    }

    /** @return array<string, array<string, string>> */
    public static function catalogFiles(): array
    {
        $root = __DIR__.'/skills';
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            [$skill, $path] = explode('/', substr($file->getPathname(), strlen($root) + 1), 2);
            $files[$skill][$path] = file_get_contents($file->getPathname());
        }

        return $files;
    }

    /** @return array<string, string> */
    public function instructions(): array
    {
        $instructions = [];

        foreach ($this->repository->catalog() as $skill) {
            $instructions[$skill->name()] = $skill->loadInstructions();
        }

        return $instructions;
    }

    /** Snapshot preparation and execution evidence before temporary directories are removed.
     * @return array<string, bool>
     */
    public function artifacts(Trajectory $trajectory): array
    {
        if ($this->expectedArtifacts === []) {
            return [];
        }

        $evidence = ['complete preparation and script artifacts' => false];

        foreach ($this->preparedDirectories($trajectory, $this->skill) as $directory => $inventory) {
            $valid = $this->storage === 'filesystem'
                ? $directory === $this->root.'/'.$this->skill
                : str_starts_with($directory, sys_get_temp_dir().'/skill-script-');

            foreach ($this->files[$this->skill] as $path => $content) {
                $valid = $valid && in_array($path, $inventory, true)
                    && is_file($directory.'/'.$path) && file_get_contents($directory.'/'.$path) === $content;
            }

            foreach ($this->expectedArtifacts as $path => $content) {
                $valid = $valid && is_file($directory.'/'.$path) && file_get_contents($directory.'/'.$path) === $content;
            }

            if ($valid) {
                $evidence['complete preparation and script artifacts'] = true;
                break;
            }
        }

        return $evidence;
    }

    /** @return array<string, list<string>> */
    private function preparedDirectories(Trajectory $trajectory, ?string $name = null): array
    {
        $directories = [];

        foreach ($trajectory->toolCalls('skill_script_prepare') as $call) {
            if (($name !== null && $call->getInput('name') !== $name) || !$call->hasResult()) {
                continue;
            }

            $result = $call->getResult();

            if ($result instanceof ToolOutput && $result->isError()) {
                continue;
            }

            $data = json_decode($result instanceof ToolOutput ? $result->getText() : $result, true);

            if (is_array($data) && is_string($data['directory'] ?? null) && is_array($data['files'] ?? null)) {
                $directories[$data['directory']] = $data['files'];
            }
        }

        return $directories;
    }

    public function cleanUp(Trajectory $trajectory): void
    {
        foreach ($this->preparedDirectories($trajectory) as $directory => $files) {
            if (str_starts_with($directory, sys_get_temp_dir().'/skill-script-')) {
                self::removeDirectory($directory);
            }
        }

        self::removeDirectory($this->root);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
