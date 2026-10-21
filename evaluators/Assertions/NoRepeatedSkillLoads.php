<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;

/** A successful skill load is reusable throughout one user turn; failed attempts may be retried. */
final class NoRepeatedSkillLoads extends TrajectoryAssertion
{
    /** @param array<string, string> $instructions */
    public function __construct(private readonly array $instructions)
    {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $loaded = [];

        foreach ($trajectory->messages() as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                $loaded = [];
            }

            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getToolCalls() as $call) {
                if ($call->getName() !== 'skill' || !$call->hasResult()) {
                    continue;
                }

                $name = $call->getInput('name');
                $result = $call->getResult();

                if ($result instanceof ToolOutput && $result->isError()) {
                    continue;
                }

                $text = $result instanceof ToolOutput ? $result->getText() : $result;

                if (!isset($this->instructions[$name]) || $text !== $this->instructions[$name]) {
                    continue;
                }

                if (isset($loaded[$name])) {
                    return AssertionResult::fail(0.0, 'Skill "'.$name.'" was loaded again after its instructions were already available in this turn.');
                }

                $loaded[$name] = true;
            }
        }

        return AssertionResult::pass(1.0);
    }
}
