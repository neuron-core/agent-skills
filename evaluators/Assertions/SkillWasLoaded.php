<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;

final class SkillWasLoaded extends TrajectoryAssertion
{
    public function __construct(
        private readonly string $name,
        private readonly string $instructions
    ) {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $failure = 'No call loaded skill "'.$this->name.'".';

        foreach ($trajectory->toolCalls('skill') as $call) {
            if ($call->getInput('name') !== $this->name) {
                continue;
            }

            if (!$call->hasResult()) {
                $failure = 'The load of skill "'.$this->name.'" has no result.';
                continue;
            }

            $result = $call->getResult();

            if ($result instanceof ToolOutput && $result->isError()) {
                $failure = 'Loading skill "'.$this->name.'" returned a tool error.';
                continue;
            }

            $text = $result instanceof ToolOutput ? $result->getText() : $result;

            if ($text === $this->instructions) {
                return AssertionResult::pass(1.0);
            }

            $failure = 'Skill "'.$this->name.'" did not return the expected instructions.';
        }

        return AssertionResult::fail(0.0, $failure);
    }
}
