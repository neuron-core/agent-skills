<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseExampleTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'skills-example-');
        self::assertNotFalse($path);
        $this->database = $path;
        unlink($path);
    }

    protected function tearDown(): void
    {
        foreach ([$this->database, $this->database.'-text-stats'] as $database) {
            if (is_file($database)) {
                unlink($database);
            }
        }
    }

    public function test_setup_populates_a_skill_and_supporting_text_for_public_storage_reads(): void
    {
        $this->runExample('sqlite-setup.php');
        $repository = new SkillRepository(new DatabaseSkillStorage(new PDO('sqlite:'.$this->database)));

        $this->assertSame(['dante'], $repository->names());
        $skill = $repository->get('db://skills/dante');
        $this->assertStringContainsString('references/terzina.md', $skill->readDocument());
        $this->assertStringNotContainsString('copper lantern', $skill->readDocument());
        $this->assertStringContainsString('copper lantern', $skill->readResource('references/terzina.md'));
    }

    public function test_database_python_resource_can_be_executed_from_a_temporary_file(): void
    {
        $this->runExample('sqlite-setup.php');
        $repository = new SkillRepository(new DatabaseSkillStorage(new PDO('sqlite:'.$this->database.'-text-stats'), baseUri: 'db://text-stats/'));
        $this->assertSame(['text-stats'], $repository->names());
        $skill = $repository->get('db://text-stats/text-stats');
        $this->assertStringContainsString('scripts/analyze.py', $skill->readDocument());

        $script = tempnam(sys_get_temp_dir(), 'text-stats-');
        self::assertNotFalse($script);

        try {
            file_put_contents($script, $skill->readResource('scripts/analyze.py'));

            foreach ([
                ["Ciao città\nseconda riga", ['words' => 4, 'lines' => 2, 'characters' => 23]],
                ['', ['words' => 0, 'lines' => 0, 'characters' => 0]],
                ["ciao\n", ['words' => 1, 'lines' => 1, 'characters' => 5]],
            ] as [$text, $expected]) {
                $process = proc_open(
                    ['python3', $script],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fwrite($pipes[0], $text);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), (string) $error);
                self::assertIsString($output);
                self::assertSame($expected, json_decode($output, true, flags: JSON_THROW_ON_ERROR));
            }
        } finally {
            unlink($script);
        }
    }

    private function runExample(string $script): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../examples/'.$script, $this->database, $this->database.'-text-stats'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error."\n".$output);
        self::assertIsString($output);

        return $output;
    }
}
