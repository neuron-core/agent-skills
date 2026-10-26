<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use Closure;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

abstract class BaseSkillEvaluator extends BaseEvaluator
{
    /**
     * @param string|list<string> $prompt
     * @param Closure(ToolCalled): void|null $onToolCalled
     */
    protected function runSession(
        SkillRepository $repository,
        string|array $prompt,
        ?Closure $onToolCalled = null,
    ): Trajectory {
        $agent = Agent::make()
            ->setThreadId(bin2hex(random_bytes(16)))
            ->setAiProvider($this->provider())
            ->addTool(new BashTool())
            ->addTool(new SkillToolkit($repository));

        if ($onToolCalled !== null) {
            $agent->subscribe(ToolCalled::class, $onToolCalled);
        }

        try {
            foreach (is_array($prompt) ? $prompt : [$prompt] as $turn) {
                $agent->chat(new UserMessage($turn));
            }

            return Trajectory::fromChatHistory($agent->getChatHistory());
        } catch (Throwable $exception) {
            $code = '';

            if (preg_match('/"code"\\s*:\\s*"([a-zA-Z0-9_-]+)"/', $exception->getMessage(), $match)) {
                $code = ' (provider code: '.$match[1].')';
            }

            throw new RuntimeException('Evaluation execution error: '.$exception::class.$code);
        }
    }

    protected function provider(): AIProviderInterface
    {
        $key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        $model = $_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL');
        $effort = $_ENV['OPENAI_REASONING_EFFORT'] ?? getenv('OPENAI_REASONING_EFFORT');
        $responses = ($_ENV['OPENAI_API'] ?? getenv('OPENAI_API')) === 'responses';

        if (!is_string($key) || trim($key) === '') {
            throw new RuntimeException('Set OPENAI_API_KEY in examples/.env or the environment.');
        }

        $parameters = [];

        if (is_string($effort) && trim($effort) !== '') {
            $parameters = $responses
                ? ['reasoning' => ['effort' => trim($effort)]]
                : ['reasoning_effort' => trim($effort)];
        }

        $provider = $responses ? OpenAIResponses::class : OpenAI::class;

        return new $provider(
            key: $key,
            model: is_string($model) && trim($model) !== '' ? $model : 'gpt-5.4-nano',
            parameters: $parameters,
        );
    }

    /** @return list<string> Sources that affect recorded runs, excluding assertions. */
    public function cacheDependencies(): array
    {
        $files = [__FILE__, __DIR__.'/bootstrap.php'];

        foreach ([dirname(__DIR__).'/src', __DIR__.'/Fixtures'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
