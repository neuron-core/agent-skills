<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use Closure;
use InvalidArgumentException;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

abstract class BaseSkillEvaluator extends BaseEvaluator
{
    protected AIProviderInterface $provider;

    public function setUp(): void
    {
        $key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        $model = $_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL');

        if (!is_string($key) || trim($key) === '') {
            throw new RuntimeException('Set OPENAI_API_KEY in examples/.env or the environment.');
        }

        $this->provider = new OpenAI(
            key: $key,
            model: is_string($model) && trim($model) !== '' ? $model : 'gpt-5.4-nano',
        );
    }

    /**
     * Takes ownership of fixture cleanup. Additional artifacts are captured before cleanup.
     *
     * @param Closure(): array<string, bool>|null $captureArtifacts
     * @return array<string, mixed>
     */
    protected function runSession(
        SkillFixture $fixture,
        string $prompt,
        string $mode,
        ?Closure $captureArtifacts = null,
    ): array {
        $preparations = [];
        $failure = null;

        try {
            if (!in_array($mode, ['chat', 'stream'], true)) {
                throw new InvalidArgumentException('Expected chat or stream execution mode.');
            }

            $instructions = $fixture->instructions();

            $toolkit = new SkillToolkit($fixture->repository);

            $agent = Agent::make()->setThreadId(bin2hex(random_bytes(16)))
                ->setAiProvider($this->provider)
                ->addTool(new BashTool())
                ->addTool($toolkit);

            $agent->subscribe(ToolCalled::class, static function (ToolCalled $event) use (&$preparations): void {
                if ($event->tool->getName() === 'skill_script_prepare' && $event->tool->hasResult()) {
                    // A provider error can precede committing these results to chat history.
                    $call = clone $event->tool;
                    $preparations[] = new ToolCallMessage(tools: [$call]);
                    $preparations[] = new ToolResultMessage([$call]);
                }
            });

            try {
                if ($mode === 'stream') {
                    foreach ($agent->stream(new UserMessage($prompt)) as $chunk) {
                        // Fully consume the stream before recording the conversation.
                    }
                } else {
                    $agent->chat(new UserMessage($prompt));
                }
            } catch (ToolRunsExceededException) {
                $failure = 'The agent exceeded the tool execution limit.';
            }

            $trajectory = Trajectory::fromChatHistory($agent->getChatHistory());

            return [
                'messages' => $trajectory->messages(),
                'transcript' => $trajectory->toTranscript(),
                'instructions' => $instructions,
                'artifacts' => $fixture->artifacts($trajectory) + ($captureArtifacts === null ? [] : $captureArtifacts()),
                'model' => $this->provider->getModel(),
                'storage' => $fixture->storage,
                'guidelines' => $toolkit->guidelines(),
                'failure' => $failure,
                'usage' => $trajectory->usage(),
            ];
        } catch (Throwable $exception) {
            $code = '';

            if (preg_match('/"code"\s*:\s*"([a-zA-Z0-9_-]+)"/', $exception->getMessage(), $match)) {
                $code = ' (provider code: '.$match[1].')';
            }

            throw new RuntimeException('Evaluation execution error: '.$exception::class.$code);
        } finally {
            $fixture->cleanUp(Trajectory::fromMessages($preparations));
        }
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
