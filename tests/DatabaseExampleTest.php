<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\AgentSkills\Tools\SkillScriptPrepareTool;
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

    public function test_database_scripts_and_configuration_work_together_after_preparation(): void
    {
        $this->runExample('sqlite-setup.php');
        $repository = new SkillRepository(new DatabaseSkillStorage(new PDO('sqlite:'.$this->database.'-text-stats'), baseUri: 'db://text-stats/'));
        $this->assertSame(['text-stats'], $repository->names());
        $skill = $repository->get('db://text-stats/text-stats');
        $this->assertStringContainsString('scripts/analyze.py', $skill->readDocument());

        $pdo = new PDO('sqlite:'.$this->database.'-text-stats');
        $statement = $pdo->prepare('INSERT INTO skills (skill_identifier, path, content) VALUES (?, ?, ?)');
        $supportPath = 'references/my % guide.txt';
        $supportContent = "Ciao città\n";
        $statement->execute(['text-stats', $supportPath, $supportContent]);
        $toolkit = new SkillToolkit($repository);
        $prepare = $toolkit->tools()[2];
        self::assertInstanceOf(SkillScriptPrepareTool::class, $prepare);
        $prepared = $prepare('text-stats');
        self::assertIsArray($prepared);
        $script = $prepared['directory'].'/scripts/analyze.py';

        try {
            self::assertSame($skill->readResource('scripts/analyze.py'), file_get_contents($script));
            self::assertSame($skill->readResource('scripts/report.py'), file_get_contents($prepared['directory'].'/scripts/report.py'));
            self::assertSame($skill->readResource('config/statistics.json'), file_get_contents($prepared['directory'].'/config/statistics.json'));
            self::assertSame($supportContent, file_get_contents($prepared['directory'].'/'.$supportPath));

            foreach ([
                ['oggi è bello', ['words' => 3, 'lines' => 1, 'characters' => 12]],
                ["Ciao città\nseconda riga", ['words' => 4, 'lines' => 2, 'characters' => 23]],
                ['', ['words' => 0, 'lines' => 0, 'characters' => 0]],
                ["ciao\n", ['words' => 1, 'lines' => 1, 'characters' => 5]],
            ] as [$text, $expected]) {
                self::assertSame($expected, $this->runPython($prepared['directory'].'/scripts/report.py', json_encode($this->runPython($script, $text), JSON_THROW_ON_ERROR)));
            }

            file_put_contents($prepared['directory'].'/config/statistics.json', '{"include_whitespace": true, "metrics": ["characters"]}');
            $counts = $this->runPython($script, 'oggi è bello');
            self::assertSame(['words' => 3, 'lines' => 1, 'characters' => 12], $counts);
            self::assertSame(['characters' => 12], $this->runPython($prepared['directory'].'/scripts/report.py', json_encode($counts, JSON_THROW_ON_ERROR)));

            file_put_contents($prepared['directory'].'/config/statistics.json', '{"include_whitespace": false, "metrics": ["characters"]}');
            $counts = $this->runPython($script, 'oggi è bello');
            self::assertSame(['words' => 3, 'lines' => 1, 'characters' => 10], $counts);
            self::assertSame(['characters' => 10], $this->runPython($prepared['directory'].'/scripts/report.py', json_encode($counts, JSON_THROW_ON_ERROR)));
        } finally {
            unlink($prepared['directory'].'/SKILL.md');
            unlink($script);
            unlink($prepared['directory'].'/scripts/report.py');
            unlink($prepared['directory'].'/config/statistics.json');
            unlink($prepared['directory'].'/'.$supportPath);
            rmdir($prepared['directory'].'/scripts');
            rmdir($prepared['directory'].'/config');
            rmdir($prepared['directory'].'/references');
            rmdir($prepared['directory']);
        }
    }

    private function runPython(string $script, string $text): array
    {
        $process = proc_open(
            ['python3', '-B', $script],
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
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
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
