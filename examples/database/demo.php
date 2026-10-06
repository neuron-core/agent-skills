<?php

declare(strict_types=1);

use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

require __DIR__.'/../../vendor/autoload.php';

$database = $argv[1] ?? __DIR__.'/skills.sqlite';
if (!is_file($database)) {
    fwrite(STDERR, "Run php examples/database/setup.php first (using the same optional database path).\n");
    exit(1);
}

// The application supplies the connection; storage only discovers and reads text.
$pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$repository = new SkillRepository(new DatabaseSkillStorage('db://demo/', $pdo));
$toolkit = new SkillToolkit($repository);

echo $toolkit->guidelines().PHP_EOL.PHP_EOL;

// Copy the discovered location rather than deriving it from the declared name.
$catalog = $repository->catalog();
if ($catalog === []) {
    throw new RuntimeException('No usable skills found. Run the database setup first.');
}
$location = $catalog[0]->location();
[$skill, $resource] = $toolkit->tools();

$skill->setInputs(['location' => $location])->execute();
echo "SKILL.md:\n".$skill->getResult().PHP_EOL;

$resource->setInputs(['location' => $location, 'path' => 'references/guide.md'])->execute();
echo "references/guide.md:\n".$resource->getResult();
