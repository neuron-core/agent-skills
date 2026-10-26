<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Fixtures;

use NeuronAI\AgentSkills\Evaluators\BaseSkillEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Providers\AIProviderInterface;

final class SessionSkillEvaluator extends BaseSkillEvaluator
{
    public function __construct(private readonly AIProviderInterface $testProvider)
    {
        parent::__construct();

    }

    protected function provider(): AIProviderInterface
    {
        return $this->testProvider;
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([]);
    }

    public function run(array $datasetItem): Trajectory
    {
        return $this->runSession(
            repository: $datasetItem['repository'],
            prompt: $datasetItem['prompt'],
            onToolCalled: $datasetItem['onToolCalled'] ?? null,
        );
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
    }
}
