<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\ToolOutput;
use Symfony\Component\Dotenv\Dotenv;

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, 'Dependencies not installed. Run composer install from the repository root.'.PHP_EOL);
    exit(1);
}

require $autoload;

function runSkillDemo(SkillToolkit $skillToolkit): void
{
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

    $agent = Agent::make()
        ->setThreadId(bin2hex(random_bytes(16)))
        ->setAiProvider(new OpenAI(key: $key, model: $model))
        ->addTool(new BashTool())
        ->addTool($skillToolkit);

    echo "See examples/README.md for setup and scenarios.".PHP_EOL.PHP_EOL;
    echo "Type a message, or 'exit' to quit.".PHP_EOL;

    while (true) {
        echo PHP_EOL."> ";
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
            } elseif ($event instanceof ToolResultChunk && $event->tool->getName() === 'bash') {
                $result = $event->tool->getResult();
                $status = $result instanceof ToolOutput
                    ? ($result->isError() ? 'error' : 'success')
                    : (json_decode($result, true)['status'] ?? 'returned');
                echo "tool: bash {$status}".PHP_EOL.PHP_EOL;
            } elseif ($event instanceof TextChunk) {
                echo $event->content;
                flush();
            }
        }

        echo PHP_EOL;
    }
}
