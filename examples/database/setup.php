<?php

declare(strict_types=1);

// Application-owned setup, specific to this local SQLite demonstration.
$database = $argv[1] ?? __DIR__.'/skills.sqlite';
$pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$pdo->exec('CREATE TABLE IF NOT EXISTS skills (
    skill_name TEXT COLLATE BINARY NOT NULL,
    path TEXT COLLATE BINARY NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (skill_name, path)
)');

$document = <<<'MARKDOWN'
---
name: clear-writing
description: Write clear, concrete prose.
---

Read references/guide.md before editing a draft.
MARKDOWN;

$guide = <<<'MARKDOWN'
# Writing guide

Prefer concrete words and short sentences.
MARKDOWN;

$insert = $pdo->prepare('INSERT OR REPLACE INTO skills (skill_name, path, content) VALUES (?, ?, ?)');
$insert->execute(['editorial', 'SKILL.md', $document."\n"]);
$insert->execute(['editorial', 'references/guide.md', $guide."\n"]);
$pdo->commit();

echo 'Prepared SQLite skills database: '.$database.PHP_EOL;
