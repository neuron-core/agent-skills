<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\AgentSkills\Tests\Fixtures\SessionSkillEvaluator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SkillEvaluatorExecutionTest extends TestCase
{
    /** @dataProvider storages */
    public function test_session_executes_tools_and_caller_cleans_prepared_directories(string $storage): void
    {
        $fixture = new SkillFixture(['csv-check' => $storage, 'rollback-plan' => $storage]);
        $fixture->setUp();
        $reportFile = tempnam(sys_get_temp_dir(), 'skill-report-');
        $this->assertIsString($reportFile);
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [ToolCall::make('skill', 'load', ['name' => 'csv-check'])]),
            new ToolCallMessage(tools: [
                ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'csv-check']),
                ToolCall::make('skill_script_prepare', 'extra', ['name' => 'rollback-plan']),
            ]),
            new ToolCallMessage(tools: [ToolCall::make('bash', 'report', [
                'command' => 'printf completed > '.escapeshellarg($reportFile),
            ])]),
            new AssistantMessage('The report is ready.'),
        );

        try {
            $trajectory = (new SessionSkillEvaluator($provider))->run([
                'repository' => $fixture->repository,
                'prompt' => 'Check the orders and write a report.',
                'onToolCalled' => $fixture->recordPreparation(...),
            ]);
            foreach ($trajectory->toolCalls('skill_script_prepare') as $call) {
                $directory = json_decode($call->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
                $this->assertDirectoryExists($directory);
            }
            $this->assertSame('completed', file_get_contents($reportFile));
        } finally {
            $fixture->cleanUp();
            unlink($reportFile);
        }

        $this->assertSame('The report is ready.', $trajectory->finalAnswer());
        $provider->assertMethodCallCount('chat', 4);
        $provider->assertToolsConfigured(['bash', 'skill', 'skill_resource', 'skill_script_prepare']);

        if ($fixture->root !== null) {
            $this->assertDirectoryDoesNotExist($fixture->root);
        }

        foreach ($trajectory->toolCalls('skill_script_prepare') as $call) {
            $directory = json_decode($call->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
            $this->assertDirectoryDoesNotExist($directory);
        }

        $restored = unserialize(serialize($trajectory));
        $this->assertSame($trajectory->toTranscript(), $restored->toTranscript());
    }

    public static function storages(): iterable
    {
        yield 'filesystem' => ['filesystem'];
        yield 'database' => ['database'];
    }

    /** @dataProvider storages */
    public function test_multiple_requests_keep_supplied_input_in_the_same_conversation(string $storage): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('File received.'), new AssistantMessage('Validation complete.'));
        $prompts = ['Here is the file for my next request: orders.csv', 'Use csv-check to validate the file I provided.'];
        $fixture = new SkillFixture(['csv-check' => $storage]);
        $fixture->setUp();

        try {
            $trajectory = (new SessionSkillEvaluator($provider))->run([
                'repository' => $fixture->repository,
                'prompt' => $prompts,
                'onToolCalled' => $fixture->recordPreparation(...),
            ]);
        } finally {
            $fixture->cleanUp();
        }

        $this->assertSame($prompts, $trajectory->userMessages());
        $records = $provider->getRecorded();
        $this->assertCount(2, $records);
        $this->assertNotEmpty(array_filter($records[1]->messages, static fn (Message $message): bool =>
            $message instanceof UserMessage && $message->getContent() === $prompts[0]));
    }

    public function test_reusing_an_evaluator_starts_an_independent_conversation_for_each_item(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First answer.'), new AssistantMessage('Second answer.'));
        $evaluator = new SessionSkillEvaluator($provider);

        foreach (['First request.', 'Second request.'] as $prompt) {
            $fixture = new SkillFixture(['risk-review' => 'filesystem']);
            $fixture->setUp();

            try {
                $trajectory = $evaluator->run([
                    'repository' => $fixture->repository,
                    'prompt' => $prompt,
                ]);
                $this->assertSame([$prompt], $trajectory->userMessages());
            } finally {
                $fixture->cleanUp();
            }
        }

        foreach ($provider->getRecorded() as $record) {
            $this->assertCount(1, $record->messages);
            $this->assertInstanceOf(UserMessage::class, $record->messages[0]);
        }
    }

    /** @dataProvider storages */
    public function test_provider_errors_are_sanitized_and_the_caller_can_clean_preparations(string $storage): void
    {
        $fixture = new SkillFixture(['risk-review' => $storage, 'rollback-plan' => $storage]);
        $fixture->setUp();
        $inputFile = $fixture->createFile('Input for the interrupted run.');
        $prepare = ToolCall::make('skill_script_prepare', 'extra', ['name' => 'rollback-plan']);
        $provider = new class(new ToolCallMessage(tools: [$prepare])) extends FakeAIProvider {
            protected function nextResponse(): Message
            {
                if ($this->responseQueue === []) {
                    throw new RuntimeException('secret-key request body {"code":"rate_limit_exceeded"}');
                }

                return parent::nextResponse();
            }
        };

        try {
            (new SessionSkillEvaluator($provider))->run([
                'repository' => $fixture->repository,
                'prompt' => 'Plan a recovery.',
                'onToolCalled' => $fixture->recordPreparation(...),
            ]);
            $this->fail('Expected an execution error.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Evaluation execution error: RuntimeException (provider code: rate_limit_exceeded)',
                $exception->getMessage(),
            );
            $this->assertNull($exception->getPrevious());
        } finally {
            $fixture->cleanUp();
        }

        $directory = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileDoesNotExist($inputFile);

        if ($fixture->root !== null) {
            $this->assertDirectoryDoesNotExist($fixture->root);
        }
    }

    public function test_session_leaves_the_fixture_available_for_the_caller(): void
    {
        $fixture = new SkillFixture(['risk-review' => 'filesystem']);
        $fixture->setUp();

        try {
            $trajectory = (new SessionSkillEvaluator(new FakeAIProvider(new AssistantMessage('Done.'))))->run([
                'repository' => $fixture->repository,
                'prompt' => 'Review this plan.',
            ]);
            $this->assertInstanceOf(Trajectory::class, $trajectory);
            $this->assertDirectoryExists($fixture->root);
        } finally {
            $fixture->cleanUp();
        }

        $this->assertDirectoryDoesNotExist($fixture->root);
    }

    public function test_exceeding_the_tool_limit_reaches_the_native_runner_as_an_error(): void
    {
        $provider = new FakeAIProvider();

        for ($index = 0; $index < 20; $index++) {
            $provider->addResponses(new ToolCallMessage(tools: [
                ToolCall::make('skill', (string) $index, ['name' => 'risk-review']),
            ]));
        }

        $fixture = new SkillFixture(['risk-review' => 'filesystem']);
        $fixture->setUp();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Evaluation execution error: NeuronAI\\Exceptions\\ToolRunsExceededException');

        try {
            (new SessionSkillEvaluator($provider))->run([
                'repository' => $fixture->repository,
                'prompt' => 'Review this plan.',
            ]);
        } finally {
            $fixture->cleanUp();
            $this->assertDirectoryDoesNotExist($fixture->root);
        }
    }

    public function test_all_evaluators_declare_execution_sources_and_fixture_resources_as_cache_dependencies(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ((new EvaluatorDiscovery())->discover($root.'/evaluators') as $class) {
            $dependencies = (new $class())->cacheDependencies();
            $this->assertContains($root.'/evaluators/BaseSkillEvaluator.php', $dependencies);
            $this->assertContains($root.'/evaluators/Fixtures/SkillFixture.php', $dependencies);
            $this->assertContains($root.'/src/Tools/SkillToolkit.php', $dependencies);

            foreach (SkillFixture::catalogFiles() as $skill => $files) {
                foreach (array_keys($files) as $path) {
                    $this->assertContains($root.'/evaluators/Fixtures/skills/'.$skill.'/'.$path, $dependencies);
                }
            }

            $this->assertNotContains($root.'/evaluators/Assertions/ScriptWasExecuted.php', $dependencies);
        }
    }
}
