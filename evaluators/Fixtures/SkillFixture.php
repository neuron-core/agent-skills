<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Fixtures;

use FilesystemIterator;
use InvalidArgumentException;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Fresh data for each conversation, independent of installed skills and demo databases. */
final class SkillFixture
{
    public ?string $root = null;
    public SkillRepository $repository;
    /** @var list<string> */
    private array $preparations = [];
    /** @var list<string> */
    private array $inputFiles = [];

    /**
     * @param array<string, 'filesystem'|'database'> $skills Skill names mapped to their storage.
     */
    public function __construct(
        public readonly array $skills,
    ) {
    }

    public function setUp(): void
    {
        try {
            $files = self::catalogFiles(array_keys($this->skills));
            $filesystem = [];
            $database = [];

            foreach ($this->skills as $name => $storage) {
                if (!isset($files[$name])) {
                    throw new InvalidArgumentException('Unknown fixture skill "'.$name.'".');
                }

                match ($storage) {
                    'filesystem' => $filesystem[$name] = $files[$name],
                    'database' => $database[$name] = $files[$name],
                    default => throw new InvalidArgumentException('Expected filesystem or database storage.'),
                };
            }

            $storages = [];

            if ($filesystem !== []) {
                $storages[] = $this->setUpFilesystem($filesystem);
            }

            if ($database !== []) {
                $storages[] = $this->setUpDatabase($database);
            }

            $this->repository = new SkillRepository(...$storages);
        } catch (\Throwable $exception) {
            if ($this->root !== null) {
                self::removeDirectory($this->root);
            }

            throw $exception;
        }
    }

    /** @param array<string, array<string, string>> $files */
    private function setUpDatabase(array $files): DatabaseSkillStorage
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE skills (skill_identifier TEXT, path TEXT, content TEXT, PRIMARY KEY (skill_identifier, path))');
        $insert = $pdo->prepare('INSERT INTO skills VALUES (?, ?, ?)');

        foreach ($files as $name => $resources) {
            foreach ($resources as $path => $content) {
                $insert->execute([$name, $path, $content]);
            }
        }

        return new DatabaseSkillStorage($pdo);
    }

    /** @param array<string, array<string, string>> $files */
    private function setUpFilesystem(array $files): FileSystemSkillStorage
    {
        $this->root = sys_get_temp_dir().'/skill-evaluation-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0o700);

        foreach ($files as $name => $resources) {
            foreach ($resources as $path => $content) {
                $destination = $this->root.'/'.$name.'/'.$path;

                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0o700, true);
                }

                file_put_contents($destination, $content);
            }
        }

        return new FileSystemSkillStorage($this->root);
    }

    /**
     * @param list<string>|null $skills
     * @return array<string, array<string, string>>
     */
    public static function catalogFiles(?array $skills = null): array
    {
        $root = __DIR__.'/skills';
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            [$skill, $path] = explode('/', substr($file->getPathname(), strlen($root) + 1), 2);

            if ($skills !== null && !in_array($skill, $skills, true)) {
                continue;
            }

            $files[$skill][$path] = file_get_contents($file->getPathname());
        }

        return $files;
    }

    public function createFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'skill-input-');

        if ($file === false) {
            throw new RuntimeException('Could not create the input file.');
        }

        $this->inputFiles[] = $file;

        if (file_put_contents($file, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write the input file.');
        }

        return $file;
    }

    /** @return list<string> */
    private function preparedDirectories(Trajectory $trajectory): array
    {
        $directories = [];

        foreach ($trajectory->toolCalls('skill_script_prepare') as $call) {
            if (!$call->hasResult()) {
                continue;
            }

            $result = $call->getResult();

            if ($result instanceof ToolOutput && $result->isError()) {
                continue;
            }

            $data = json_decode($result instanceof ToolOutput ? $result->getText() : $result, true);

            if (is_array($data) && is_string($data['directory'] ?? null)) {
                $directories[] = $data['directory'];
            }
        }

        return $directories;
    }

    public function recordPreparation(ToolCalled $event): void
    {
        if ($event->tool->getName() !== 'skill_script_prepare' || !$event->tool->hasResult()) {
            return;
        }

        $result = $event->tool->getResult();

        if ($result instanceof ToolOutput && $result->isError()) {
            return;
        }

        $data = json_decode($result instanceof ToolOutput ? $result->getText() : $result, true);

        if (is_array($data) && is_string($data['directory'] ?? null)) {
            $this->preparations[] = $data['directory'];
        }
    }

    public function cleanUp(?Trajectory $trajectory = null): void
    {
        foreach ($this->inputFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->inputFiles = [];
        $directories = $this->preparations;

        if ($trajectory !== null) {
            $directories = [...$directories, ...$this->preparedDirectories($trajectory)];
        }

        foreach (array_unique($directories) as $directory) {
            if (str_starts_with($directory, sys_get_temp_dir().'/skill-script-')) {
                self::removeDirectory($directory);
            }
        }

        if ($this->root !== null) {
            self::removeDirectory($this->root);
        }
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
