<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\AgentSkills\Tests\Fixtures\SessionSkillEvaluator;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SkillEvaluatorExecutionTest extends TestCase
{
    /** @dataProvider executionModes */
    public function test_execution_records_tools_and_artifacts_before_cleaning_all_prepared_directories(string $storage, string $mode): void
    {
        $catalog = SkillFixture::catalogFiles();
        $fixture = new SkillFixture('csv-check', $storage, expectedArtifacts: [
            'config/validation.json' => $catalog['csv-check']['config/validation.json'],
        ]);
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [ToolCall::make('skill', 'load', ['name' => 'csv-check'])]),
            new ToolCallMessage(tools: [
                ToolCall::make('skill_script_prepare', 'prepare', ['name' => 'csv-check']),
                ToolCall::make('skill_script_prepare', 'extra', ['name' => 'rollback-plan']),
            ]),
            new ToolCallMessage(tools: [ToolCall::make('bash', 'report', [
                'command' => 'printf completed > '.escapeshellarg($fixture->root.'/report.md'),
            ])]),
            new AssistantMessage('The report is ready.'),
        );

        $output = (new SessionSkillEvaluator($provider))->run([
            'fixture' => $fixture,
            'prompt' => 'Check the orders and write a report.',
            'mode' => $mode,
            'captureArtifacts' => static fn (): array => [
                'report was generated' => is_file($fixture->root.'/report.md')
                    && file_get_contents($fixture->root.'/report.md') === 'completed',
            ],
        ]);

        $trajectory = Trajectory::fromMessages($output['messages']);

        $this->assertSame('The report is ready.', $trajectory->finalAnswer());
        $this->assertNull($output['failure']);
        $this->assertSame($storage, $output['storage']);
        $this->assertSame('fake', $output['model']);
        $this->assertNotEmpty($output['instructions']['csv-check']);
        $this->assertCount(7, $output['instructions']);
        $this->assertSame([
            'complete preparation and script artifacts' => true,
            'report was generated' => true,
        ], $output['artifacts']);
        $this->assertStringContainsString('The report is ready.', $output['transcript']);
        $this->assertStringContainsString('Available skills:', $output['guidelines']);
        $provider->assertMethodCallCount($mode, 4);
        $provider->assertToolsConfigured(['bash', 'skill', 'skill_resource', 'skill_script_prepare']);
        $this->assertDirectoryDoesNotExist($fixture->root);

        foreach ($trajectory->toolCalls('skill_script_prepare') as $call) {
            $directory = json_decode($call->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
            $this->assertDirectoryDoesNotExist($directory);
        }

        $restored = unserialize(serialize($output));
        $this->assertSame($trajectory->toTranscript(), Trajectory::fromMessages($restored['messages'])->toTranscript());
    }

    public static function executionModes(): iterable
    {
        foreach (['filesystem', 'database'] as $storage) {
            foreach (['chat', 'stream'] as $mode) {
                yield $storage.' '.$mode => [$storage, $mode];
            }
        }
    }

    public function test_reusing_an_evaluator_starts_an_independent_conversation_for_each_item(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First answer.'), new AssistantMessage('Second answer.'));
        $evaluator = new SessionSkillEvaluator($provider);

        foreach (['chat' => 'First request.', 'stream' => 'Second request.'] as $mode => $prompt) {
            $output = $evaluator->run([
                'fixture' => new SkillFixture('risk-review', 'filesystem'),
                'prompt' => $prompt,
                'mode' => $mode,
            ]);
            $this->assertSame([$prompt], Trajectory::fromMessages($output['messages'])->userMessages());
        }

        foreach ($provider->getRecorded() as $request) {
            $this->assertCount(1, $request->messages);
            $this->assertInstanceOf(UserMessage::class, $request->messages[0]);
        }
    }

    /** @dataProvider executionModes */
    public function test_provider_errors_are_sanitized_and_partial_preparations_are_cleaned(string $storage, string $mode): void
    {
        $fixture = new SkillFixture('risk-review', $storage);
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
                'fixture' => $fixture,
                'prompt' => 'Plan a recovery.',
                'mode' => $mode,
            ]);
            $this->fail('Expected an execution error.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Evaluation execution error: RuntimeException (provider code: rate_limit_exceeded)',
                $exception->getMessage(),
            );
            $this->assertNull($exception->getPrevious());
        }

        $directory = json_decode($prepare->getResult(), true, flags: JSON_THROW_ON_ERROR)['directory'];
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertDirectoryDoesNotExist($fixture->root);
    }

    public function test_artifact_capture_errors_still_clean_the_fixture(): void
    {
        $fixture = new SkillFixture('risk-review', 'filesystem');
        $evaluator = new SessionSkillEvaluator(new FakeAIProvider(new AssistantMessage('Done.')));

        try {
            $evaluator->run([
                'fixture' => $fixture,
                'prompt' => 'Review this plan.',
                'mode' => 'chat',
                'captureArtifacts' => static function (): array {
                    throw new RuntimeException('Sensitive artifact contents.');
                },
            ]);
            $this->fail('Expected an execution error.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Evaluation execution error: RuntimeException', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($fixture->root);
    }

    public function test_exceeding_the_tool_limit_records_a_behavior_failure(): void
    {
        $provider = new FakeAIProvider();

        for ($index = 0; $index < 20; $index++) {
            $provider->addResponses(new ToolCallMessage(tools: [
                ToolCall::make('skill', (string) $index, ['name' => 'risk-review']),
            ]));
        }

        $fixture = new SkillFixture('risk-review', 'filesystem');
        $output = (new SessionSkillEvaluator($provider))->run([
            'fixture' => $fixture,
            'prompt' => 'Review this plan.',
            'mode' => 'chat',
        ]);

        $this->assertSame('The agent exceeded the tool execution limit.', $output['failure']);
        $this->assertNotEmpty(Trajectory::fromMessages($output['messages'])->toolCalls('skill'));
        $this->assertDirectoryDoesNotExist($fixture->root);
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

            $this->assertNotContains($root.'/evaluators/Assertions/EvidenceIsValid.php', $dependencies);
        }
    }
}
