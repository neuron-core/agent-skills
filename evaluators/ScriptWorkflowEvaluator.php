<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators;

use NeuronAI\AgentSkills\Evaluators\Assertions\ScriptsWereExecuted;
use NeuronAI\AgentSkills\Evaluators\Fixtures\SkillFixture;
use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

final class ScriptWorkflowEvaluator extends BaseSkillEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'skills' => [
                    'text-stats' => 'filesystem',
                    'csv-check' => 'filesystem',
                    'risk-review' => 'filesystem',
                ],
                'prompt' => "Use text-stats on the following text:\n\nLondon has many parks.\nThe Thames flows through the city.",
                'expectedAnalysis' => '{"words": 10, "lines": 2, "characters": 57, "max_words": 12}',
                'expectedReport' => 'PASS: 10 words, limit 12.',
            ],
            [
                'skills' => [
                    'text-stats' => 'filesystem',
                    'csv-check' => 'filesystem',
                    'risk-review' => 'filesystem',
                ],
                'prompt' => [
                    "Here is the text for my next request:\n\nEdinburgh has a historic castle and lively streets.",
                    'Use the text-stats skill on the text I provided.',
                ],
                'expectedAnalysis' => '{"words": 8, "lines": 1, "characters": 51, "max_words": 12}',
                'expectedReport' => 'PASS: 8 words, limit 12.',
            ],
            [
                'skills' => [
                    'text-stats' => 'database',
                    'csv-check' => 'database',
                    'risk-review' => 'database',
                ],
                'prompt' => "Use text-stats on the following text:\n\nThe café in Bath opens early.\nVisitors stop there before exploring the city.",
                'expectedAnalysis' => '{"words": 13, "lines": 2, "characters": 76, "max_words": 12}',
                'expectedReport' => 'FAIL: 13 words, limit 12.',
            ],
            [
                'skills' => [
                    'text-stats' => 'database',
                    'csv-check' => 'database',
                    'risk-review' => 'database',
                ],
                'prompt' => [
                    "Here is the text for my next request:\n\nYork has ancient walls.\nIts narrow streets attract many visitors.\nThe station connects the city to London.",
                    'Use the text-stats skill on the text I provided.',
                ],
                'expectedAnalysis' => '{"words": 17, "lines": 3, "characters": 106, "max_words": 12}',
                'expectedReport' => 'FAIL: 17 words, limit 12.',
            ],
        ]);
    }

    public function run(array $datasetItem): Trajectory
    {
        $fixture = new SkillFixture($datasetItem['skills']);
        $fixture->setUp();

        try {
            return $this->runSession(
                repository: $fixture->repository,
                prompt: $datasetItem['prompt'],
                onToolCalled: $fixture->recordPreparation(...),
            );
        } finally {
            $fixture->cleanUp();
        }
    }

    public function evaluate(mixed $trajectory, array $datasetItem): void
    {
        $this->assert(
            new TrajectoryMatches(['skill', 'skill_resource', 'skill_script_prepare', 'bash', 'bash'], Mode::Strict),
            $trajectory,
            'load, read configuration, prepare, analyze and report without extra calls',
        );

        $this->assert(
            new ScriptsWereExecuted(
                skill: 'text-stats',
                scripts: [
                    'scripts/analyze.py' => $datasetItem['expectedAnalysis'],
                    'scripts/report.py' => $datasetItem['expectedReport'],
                ],
                referencePath: 'config/analysis.yaml',
            ),
            $trajectory,
            'scripts return the expected statistics after preparation and in order',
        );
    }
}
