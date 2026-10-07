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
        if (is_file($this->database)) {
            unlink($this->database);
        }
    }

    public function test_setup_populates_a_skill_and_supporting_text_for_public_storage_reads(): void
    {
        $this->runExample('sqlite-setup.php');
        $repository = new SkillRepository(new DatabaseSkillStorage(new PDO('sqlite:'.$this->database)));

        $this->assertSame(['dante'], $repository->names());
        $skill = $repository->get('db://skills/dante');
        $this->assertStringContainsString('references/terzina.md', $skill->readDocument());
        $this->assertStringNotContainsString('lanterna di rame', $skill->readDocument());
        $this->assertStringContainsString('lanterna di rame', $skill->readResource('references/terzina.md'));
    }

    private function runExample(string $script): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../examples/'.$script, $this->database],
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
