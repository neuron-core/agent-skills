<?php

declare(strict_types=1);

// Application-owned setup, specific to this local SQLite demonstration.
$database = $argv[1] ?? __DIR__.'/skills.sqlite';
$pdo = new PDO('sqlite:'.$database);
$pdo->beginTransaction();
$pdo->exec('CREATE TABLE IF NOT EXISTS skills (
    skill_identifier TEXT COLLATE BINARY NOT NULL,
    path TEXT COLLATE BINARY NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (skill_identifier, path)
)');

$document = <<<'MARKDOWN'
---
name: dante
description: Scrivi brevi versi originali ispirati al viaggio allegorico e al tono della Commedia. Usa questa skill quando l'utente chiede versi in stile dantesco.
---
# Versi danteschi

Prima di scrivere, leggi `references/terzina.md` e segui le indicazioni per
questa prova. Scrivi versi originali: non citare la Commedia.
MARKDOWN;

$terzina = <<<'MARKDOWN'
# La terzina della prova

Scrivi una sola terzina su un viandante che attraversa una selva. Usa un tono
solenne, immagini concrete e una rima ABA. Nel secondo verso inserisci
esattamente le parole **lanterna di rame**. Questa immagine è il dettaglio che
permette di verificare che hai letto la risorsa.
MARKDOWN;

$insert = $pdo->prepare('INSERT OR REPLACE INTO skills (skill_identifier, path, content) VALUES (?, ?, ?)');
$pdo->exec("DELETE FROM skills WHERE skill_identifier = 'demo-errors'");
$insert->execute(['dante', 'SKILL.md', $document."\n"]);
$insert->execute(['dante', 'references/terzina.md', $terzina."\n"]);
$pdo->commit();

echo 'Prepared SQLite skills database: '.$database.PHP_EOL;
