<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\AgentSkills\Tools\SkillScriptPrepareTool;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
        $skill = $repository->getByName('dante');
        $this->assertStringContainsString('references/terzina.md', $skill->loadDocument());
        $this->assertStringNotContainsString('copper lantern', $skill->loadDocument());
        $this->assertStringContainsString('copper lantern', $skill->resource('references/terzina.md')->load());
    }

    public function test_setup_resets_all_skill_rows_in_both_databases(): void
    {
        $this->runExample('sqlite-setup.php');
        $databases = [];
        $expected = [];
        foreach ([$this->database, $this->database.'-text-stats'] as $path) {
            $pdo = new PDO('sqlite:'.$path);
            $expected[] = $pdo->query('SELECT * FROM skills ORDER BY skill_identifier, path')->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("UPDATE skills SET content = 'Edited'");
            $pdo->exec("INSERT INTO skills VALUES ('custom', 'SKILL.md', 'Custom skill')");
            $pdo->exec("INSERT INTO skills VALUES ('text-stats', 'scripts/metrics.py', 'Obsolete script')");
            $pdo->exec('CREATE TABLE unrelated (content TEXT)');
            $pdo->exec("INSERT INTO unrelated VALUES ('Keep this')");
            $databases[] = $pdo;
        }

        $this->runExample('sqlite-setup.php');

        foreach ($databases as $index => $pdo) {
            self::assertSame($expected[$index], $pdo->query('SELECT * FROM skills ORDER BY skill_identifier, path')->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame('Keep this', $pdo->query('SELECT content FROM unrelated')->fetchColumn());
        }
    }

    public function test_database_scripts_and_configuration_work_together_after_preparation(): void
    {
        $this->runExample('sqlite-setup.php');
        $repository = new SkillRepository(new DatabaseSkillStorage(new PDO('sqlite:'.$this->database.'-text-stats')));
        $this->assertSame(['text-stats'], $repository->names());
        $skill = $repository->getByName('text-stats');
        $this->assertStringContainsString('scripts/analyze.py', $skill->loadDocument());

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
            self::assertSame($skill->resource('scripts/analyze.py')->load(), file_get_contents($script));
            self::assertSame($skill->resource('scripts/report.py')->load(), file_get_contents($prepared['directory'].'/scripts/report.py'));
            self::assertSame($skill->resource('config/analysis.yaml')->load(), file_get_contents($prepared['directory'].'/config/analysis.yaml'));
            self::assertSame($supportContent, file_get_contents($prepared['directory'].'/'.$supportPath));

            $config = Yaml::parse($skill->resource('config/analysis.yaml')->load());

            foreach ([
                ['oggi è bello', ['words' => 3, 'lines' => 1, 'characters' => 12], 'PASS: 3 words, limit 12.'],
                ["Ciao città\nseconda riga", ['words' => 4, 'lines' => 2, 'characters' => 23], 'PASS: 4 words, limit 12.'],
                ['', ['words' => 0, 'lines' => 0, 'characters' => 0], 'PASS: 0 words, limit 12.'],
                ["ciao\n", ['words' => 1, 'lines' => 1, 'characters' => 5], 'PASS: 1 words, limit 12.'],
                [str_repeat('word ', 12), ['words' => 12, 'lines' => 1, 'characters' => 60], 'PASS: 12 words, limit 12.'],
                [str_repeat('word ', 13), ['words' => 13, 'lines' => 1, 'characters' => 65], 'FAIL: 13 words, limit 12.'],
            ] as [$text, $expected, $expectedReport]) {
                $analysis = $this->runPython($script, $text, ['--max-words', (string) $config['max_words']]);
                self::assertSame($expected + ['max_words' => 12], json_decode($analysis, true, flags: JSON_THROW_ON_ERROR));
                self::assertSame($expectedReport, $this->runPython($prepared['directory'].'/scripts/report.py', $analysis));
            }

            foreach ([2 => 'FAIL: 3 words, limit 2.', 4 => 'PASS: 3 words, limit 4.'] as $limit => $expectedReport) {
                file_put_contents($prepared['directory'].'/config/analysis.yaml', 'max_words: '.$limit."\n");
                $config = Yaml::parse(file_get_contents($prepared['directory'].'/config/analysis.yaml'));
                $analysis = $this->runPython($script, 'oggi è bello', ['--max-words', (string) $config['max_words']]);
                self::assertSame(['words' => 3, 'lines' => 1, 'characters' => 12, 'max_words' => $limit], json_decode($analysis, true, flags: JSON_THROW_ON_ERROR));
                self::assertSame($expectedReport, $this->runPython($prepared['directory'].'/scripts/report.py', $analysis));
            }
        } finally {
            unlink($prepared['directory'].'/SKILL.md');
            unlink($script);
            unlink($prepared['directory'].'/scripts/report.py');
            unlink($prepared['directory'].'/config/analysis.yaml');
            unlink($prepared['directory'].'/'.$supportPath);
            rmdir($prepared['directory'].'/scripts');
            rmdir($prepared['directory'].'/config');
            rmdir($prepared['directory'].'/references');
            rmdir($prepared['directory']);
        }
    }

    private function runPython(string $script, string $text, array $arguments = []): string
    {
        $process = proc_open(
            ['python3', '-B', $script, ...$arguments],
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

        return trim($output);
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
