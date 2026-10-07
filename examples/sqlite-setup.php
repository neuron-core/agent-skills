<?php

declare(strict_types=1);

// Application-owned setup, specific to this local SQLite demonstration.
$database = $argv[1] ?? __DIR__.'/skills.sqlite';
$textStatsDatabase = $argv[2] ?? __DIR__.'/text-stats.sqlite';

function createDemoDatabase(string $database): PDO
{
    $pdo = new PDO('sqlite:'.$database);
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE IF NOT EXISTS skills (
        skill_identifier TEXT COLLATE BINARY NOT NULL,
        path TEXT COLLATE BINARY NOT NULL,
        content TEXT NOT NULL,
        PRIMARY KEY (skill_identifier, path)
    )');

    return $pdo;
}

$pdo = createDemoDatabase($database);

$document = <<<'MARKDOWN'
---
name: dante
description: Write short original verses inspired by the allegorical journey and tone of the Divine Comedy. Use when the user asks for Dante-inspired poetry.
---
# Dante-inspired poetry

Before writing, read `references/terzina.md` and follow its instructions.
Write original verses; do not quote the Divine Comedy.
MARKDOWN;

$terzina = <<<'MARKDOWN'
# The example tercet

Write a single tercet about a traveller crossing a forest. Use a solemn tone,
concrete imagery and an ABA rhyme scheme. Include the exact words
**copper lantern** in the second line. This image is the detail that lets the
reader verify you have read the resource.
MARKDOWN;

$textStatsDocument = <<<'MARKDOWN'
---
name: text-stats
description: Count words, lines and characters in a supplied text. Use when the user asks for text statistics.
---
# Text statistics

1. Read `config/statistics.json`: `include_whitespace` controls character counting;
   `metrics` selects the values to report.
2. Execute the script `scripts/analyze.py`. Supply the exact text as UTF-8
   through standard input, preserving whitespace and line endings. Read its JSON output.
3. Execute the script `scripts/report.py`, supplying the JSON from the first
   script through standard input. Report the JSON returned by this second script.
MARKDOWN;

$textStatsScript = <<<'PYTHON'
import json
import sys
from pathlib import Path

config_path = Path(__file__).resolve().parent.parent / "config" / "statistics.json"
config = json.loads(config_path.read_text(encoding="utf-8"))
text = sys.stdin.buffer.read().decode("utf-8")
characters = len(text) if config["include_whitespace"] else sum(not char.isspace() for char in text)
print(json.dumps({
    "words": len(text.split()),
    "lines": len(text.splitlines()),
    "characters": characters,
}))
PYTHON;

$textStatsReport = <<<'PYTHON'
import json
import sys
from pathlib import Path


config_path = Path(__file__).resolve().parent.parent / "config" / "statistics.json"
config = json.loads(config_path.read_text(encoding="utf-8"))
counts = json.loads(sys.stdin.buffer.read().decode("utf-8"))
print(json.dumps({metric: counts[metric] for metric in config["metrics"]}))
PYTHON;

$textStatsConfig = <<<'JSON'
{
    "include_whitespace": true,
    "metrics": ["words", "lines", "characters"]
}
JSON;

$insert = $pdo->prepare('INSERT OR REPLACE INTO skills (skill_identifier, path, content) VALUES (?, ?, ?)');
$pdo->exec("DELETE FROM skills WHERE skill_identifier = 'demo-errors'");
$insert->execute(['dante', 'SKILL.md', $document."\n"]);
$insert->execute(['dante', 'references/terzina.md', $terzina."\n"]);
$pdo->commit();

echo 'Prepared SQLite skills database: '.$database.PHP_EOL;

$pdo = createDemoDatabase($textStatsDatabase);
$insert = $pdo->prepare('INSERT OR REPLACE INTO skills (skill_identifier, path, content) VALUES (?, ?, ?)');
$insert->execute(['text-stats', 'SKILL.md', $textStatsDocument."\n"]);
$insert->execute(['text-stats', 'scripts/analyze.py', $textStatsScript."\n"]);
$insert->execute(['text-stats', 'scripts/report.py', $textStatsReport."\n"]);
$insert->execute(['text-stats', 'config/statistics.json', $textStatsConfig."\n"]);
$pdo->exec("DELETE FROM skills WHERE skill_identifier = 'text-stats' AND path = 'scripts/metrics.py'");
$pdo->commit();

echo 'Prepared SQLite skills database: '.$textStatsDatabase.PHP_EOL;
