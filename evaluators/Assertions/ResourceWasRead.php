<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;

/** The resource must be selected after its skill's instructions have returned. */
final class ResourceWasRead extends TrajectoryAssertion
{
    public function __construct(
        private readonly string $skill,
        private readonly string $path,
        private readonly string $contents,
        private readonly string $instructions,
    ) {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $loaded = false;

        foreach ($trajectory->messages() as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            $wasLoaded = $loaded;

            foreach ($message->getToolCalls() as $call) {
                if (!$call->hasResult()) {
                    continue;
                }

                $result = $call->getResult();

                if ($result instanceof ToolOutput && $result->isError()) {
                    continue;
                }

                $text = $result instanceof ToolOutput ? $result->getText() : $result;

                if ($call->getInput('name') !== $this->skill) {
                    continue;
                }

                if ($call->getName() === 'skill' && $text === $this->instructions) {
                    $loaded = true;
                } elseif ($call->getName() === 'skill_resource' && $call->getInput('path') === $this->path
                    && $text === $this->contents) {
                    return $wasLoaded
                        ? AssertionResult::pass(1.0)
                        : AssertionResult::fail(0.0, 'Resource "'.$this->path.'" was selected before skill "'.$this->skill.'" returned its instructions.');
                }
            }
        }

        return AssertionResult::fail(0.0, 'Resource "'.$this->path.'" was not successfully read from skill "'.$this->skill.'" with the expected contents.');
    }
}
