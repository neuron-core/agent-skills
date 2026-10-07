<?php

declare(strict_types=1);

use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

require __DIR__.'/demo-chat.php';

$skillToolkit = SkillToolkit::make()->fromStorage(
    new FileSystemSkillStorage(__DIR__.'/skills'),
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
    new DatabaseSkillStorage(new PDO('sqlite:'.__DIR__.'/skills.sqlite'), baseUri: 'db://skills'),
    new DatabaseSkillStorage(new PDO('sqlite:'.__DIR__.'/text-stats.sqlite'), baseUri: 'db://text-stats'),
);

runSkillDemo($skillToolkit);
