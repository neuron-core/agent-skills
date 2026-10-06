<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use Symfony\Component\Dotenv\Dotenv;

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, 'Dependencies not installed. Run composer install from the repository root.'.PHP_EOL);
    exit(1);
}

require $autoload;

$envFile = __DIR__.'/.env';
if (is_file($envFile)) {
    (new Dotenv())->bootEnv($envFile);
}

$key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, 'OPENAI_API_KEY not configured. Copy examples/.env.example to examples/.env and add your key.'.PHP_EOL);
    exit(1);
}

$model = $_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL');
if (!is_string($model) || trim($model) === '') {
    $model = 'gpt-5.4-nano';
}

$skills = new SkillRepository(
    // Bundled skills first; skills installed by the CLI in examples/ second.
    new FileSystemSkillStorage(__DIR__.'/skills'),
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);

$agent = Agent::make()
    ->setThreadId(bin2hex(random_bytes(16)))
    ->setAiProvider(new OpenAI(key: $key, model: $model))
    ->addTool(new SkillToolkit($skills))
    ->addTool(new BashTool());

echo 'Available skills: '.implode(', ', $skills->names()).PHP_EOL;

foreach ($skills->diagnostics() as $diagnostic) {
    fwrite(STDERR, sprintf("[skill: %s] %s".PHP_EOL, $diagnostic['skill'], $diagnostic['message']));
}

echo PHP_EOL;
echo "Try php-check for runtime checks or caveman for terse answers.".PHP_EOL;
echo "See examples/README.md for setup and both scenarios.".PHP_EOL.PHP_EOL;
echo "Type a message, or 'exit' to quit.".PHP_EOL;

while (true) {
    echo "\n> ";
    $input = fgets(STDIN);
    if ($input === false) {
        break;
    }

    $input = trim($input);
    if ($input === '') {
        continue;
    }
    if (in_array(strtolower($input), ['exit', 'quit'], true)) {
        break;
    }

    echo PHP_EOL;

    foreach ($agent->stream(new UserMessage($input)) as $event) {
        if ($event instanceof ToolCallChunk) {
            echo sprintf(
                "[tool: %s %s]".PHP_EOL.PHP_EOL,
                $event->tool->getName(),
                json_encode($event->tool->getInputs(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        } elseif ($event instanceof TextChunk) {
            echo $event->content;
            flush();
        }
    }

    echo PHP_EOL;
}
