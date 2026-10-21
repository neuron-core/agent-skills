<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Fixtures;

use NeuronAI\AgentSkills\Evaluators\BaseSkillEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Providers\AIProviderInterface;

final class SessionSkillEvaluator extends BaseSkillEvaluator
{
    public function __construct(AIProviderInterface $provider)
    {
        parent::__construct();

        $this->provider = $provider;
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([]);
    }

    public function run(array $datasetItem): array
    {
        return $this->runSession(
            fixture: $datasetItem['fixture'],
            prompt: $datasetItem['prompt'],
            mode: $datasetItem['mode'],
            captureArtifacts: $datasetItem['captureArtifacts'] ?? null,
        );
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
    }
}
