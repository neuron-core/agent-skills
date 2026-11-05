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
    $pdo->exec('DELETE FROM skills');

    return $pdo;
}

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
description: Count words, lines and characters when the user requests text statistics.
---

# Text statistics

1. Read `config/analysis.yaml` for the `max_words` limit.
2. Run `python3 scripts/analyze.py --max-words <max_words>`, supplying the exact text through stdin.
3. Inspect the analysis JSON, then supply it through stdin to `python3 scripts/report.py`.
4. Return the report's PASS or FAIL result and its word count and limit.
MARKDOWN;

$textStatsScript = <<<'PYTHON'
import argparse
import json
import sys

parser = argparse.ArgumentParser()
parser.add_argument("--max-words", type=int, required=True)
args = parser.parse_args()
text = sys.stdin.buffer.read().decode("utf-8")
print(json.dumps({
    "words": len(text.split()),
    "lines": len(text.splitlines()),
    "characters": len(text),
    "max_words": args.max_words,
}))
PYTHON;

$textStatsReport = <<<'PYTHON'
import json
import sys

counts = json.loads(sys.stdin.buffer.read().decode("utf-8"))
status = "PASS" if counts["words"] <= counts["max_words"] else "FAIL"
print(f"{status}: {counts['words']} words, limit {counts['max_words']}.")
PYTHON;

$textStatsConfig = <<<'YAML'
max_words: 12
YAML;

$pdo = createDemoDatabase($database);
$insert = $pdo->prepare('INSERT INTO skills (skill_identifier, path, content) VALUES (?, ?, ?)');
$insert->execute(['dante', 'SKILL.md', $document."\n"]);
$insert->execute(['dante', 'references/terzina.md', $terzina."\n"]);
$pdo->commit();

echo 'Prepared SQLite skills database: '.$database.PHP_EOL;

$pdo = createDemoDatabase($textStatsDatabase);
$insert = $pdo->prepare('INSERT INTO skills (skill_identifier, path, content) VALUES (?, ?, ?)');
$insert->execute(['text-stats', 'SKILL.md', $textStatsDocument."\n"]);
$insert->execute(['text-stats', 'scripts/analyze.py', $textStatsScript."\n"]);
$insert->execute(['text-stats', 'scripts/report.py', $textStatsReport."\n"]);
$insert->execute(['text-stats', 'config/analysis.yaml', $textStatsConfig."\n"]);
$pdo->commit();

echo 'Prepared SQLite skills database: '.$textStatsDatabase.PHP_EOL;
