<?php

declare(strict_types=1);

use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

require __DIR__.'/demo-chat.php';

$skillToolkit = SkillToolkit::make()->fromStorage(
    new DatabaseSkillStorage(new PDO('sqlite:'.__DIR__.'/text-stats.sqlite'))
);

runSkillDemo($skillToolkit);
