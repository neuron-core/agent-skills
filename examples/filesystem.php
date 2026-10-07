<?php

declare(strict_types=1);

use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

require __DIR__.'/demo-chat.php';

$skillToolkit = SkillToolkit::make()->fromStorage(
    new FileSystemSkillStorage(__DIR__.'/skills'),
    new FileSystemSkillStorage(__DIR__.'/.agents/skills')
);

runSkillDemo($skillToolkit);
