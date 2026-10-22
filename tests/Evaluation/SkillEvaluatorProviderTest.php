<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Evaluation;

use NeuronAI\AgentSkills\Evaluators\BaseSkillEvaluator;
use NeuronAI\AgentSkills\Evaluators\SkillDiscoveryEvaluator;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class SkillEvaluatorProviderTest extends TestCase
{
    /** @dataProvider reasoningSettings */
    public function test_provider_forwards_optional_reasoning_without_changing_the_model(
        string $api,
        string $effort,
        string $expectedProvider,
        array $expectedParameters,
    ): void {
        $environment = $_ENV;
        $_ENV['OPENAI_API_KEY'] = 'test-key';
        $_ENV['OPENAI_MODEL'] = 'gpt-5.4-nano';
        $_ENV['OPENAI_REASONING_EFFORT'] = $effort;
        $_ENV['OPENAI_API'] = $api;

        try {
            $provider = (new ReflectionMethod(BaseSkillEvaluator::class, 'provider'))
                ->invoke(new SkillDiscoveryEvaluator());

            $this->assertInstanceOf($expectedProvider, $provider);
            $this->assertSame('gpt-5.4-nano', $provider->getModel());
            $this->assertSame(
                $expectedParameters,
                (new ReflectionProperty($expectedProvider, 'parameters'))->getValue($provider),
            );
        } finally {
            $_ENV = $environment;
        }
    }

    public static function reasoningSettings(): iterable
    {
        yield 'chat default' => ['', '', OpenAI::class, []];
        yield 'chat low' => ['', ' low ', OpenAI::class, ['reasoning_effort' => 'low']];
        yield 'responses default' => ['responses', '', OpenAIResponses::class, []];
        yield 'responses low' => ['responses', ' low ', OpenAIResponses::class, ['reasoning' => ['effort' => 'low']]];
    }
}
