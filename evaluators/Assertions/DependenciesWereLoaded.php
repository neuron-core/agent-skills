<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;

final class DependenciesWereLoaded extends TrajectoryAssertion
{
    /** @param array<string, string> $dependencies Skill names mapped to expected instructions; any sibling order is accepted. */
    public function __construct(
        private readonly string $parent,
        private readonly string $instructions,
        private readonly array $dependencies,
    ) {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $parentLoaded = false;
        $loaded = [];

        foreach ($trajectory->messages() as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            $wasParentLoaded = $parentLoaded;

            foreach ($message->getToolCalls() as $call) {
                if ($call->getName() !== 'skill' || !$call->hasResult()) {
                    continue;
                }

                $result = $call->getResult();

                if ($result instanceof ToolOutput && $result->isError()) {
                    continue;
                }

                $text = $result instanceof ToolOutput ? $result->getText() : $result;
                $name = $call->getInput('name');

                if ($name === $this->parent && $text === $this->instructions) {
                    $parentLoaded = true;
                } elseif (isset($this->dependencies[$name]) && !isset($loaded[$name]) && $text === $this->dependencies[$name]) {
                    if (!$wasParentLoaded) {
                        return AssertionResult::fail(0.0, 'Dependency "'.$name.'" was selected before parent "'.$this->parent.'" returned its instructions.');
                    }

                    $loaded[$name] = true;
                }
            }
        }

        if (!$parentLoaded) {
            return AssertionResult::fail(0.0, 'Parent skill "'.$this->parent.'" did not return its expected instructions.');
        }

        $missing = array_diff_key($this->dependencies, $loaded);

        if ($missing !== []) {
            return AssertionResult::fail(0.0, 'Dependencies did not return their expected instructions: '.implode(', ', array_keys($missing)).'.');
        }

        return AssertionResult::pass(1.0);
    }
}
