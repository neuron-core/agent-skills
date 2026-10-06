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
        $this->runExample('setup.php');
        $repository = new SkillRepository(new DatabaseSkillStorage('db://demo/', new PDO('sqlite:'.$this->database)));

        $this->assertSame(['clear-writing'], $repository->names());
        $skill = $repository->get('db://demo/editorial/');
        $this->assertSame("---\nname: clear-writing\ndescription: Write clear, concrete prose.\n---\n\nRead references/guide.md before editing a draft.\n", $skill->readDocument());
        $this->assertSame("# Writing guide\n\nPrefer concrete words and short sentences.\n", $skill->readResource('references/guide.md'));
    }

    public function test_demo_shows_catalog_and_reads_document_and_resource_without_a_model(): void
    {
        $this->runExample('setup.php');

        $output = $this->runExample('demo.php');

        $this->assertStringContainsString('clear-writing: Write clear, concrete prose. (location: db://demo/editorial/)', $output);
        $this->assertStringContainsString("---\nname: clear-writing\ndescription: Write clear, concrete prose.\n---\n\nRead references/guide.md before editing a draft.\n", $output);
        $this->assertStringContainsString("# Writing guide\n\nPrefer concrete words and short sentences.\n", $output);
    }

    private function runExample(string $script): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../examples/database/'.$script, $this->database],
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
