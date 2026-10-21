<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolOutput;

/** Scripts require the workflow reference and preparation; each subsequent command follows the previous result. */
final class ScriptsWereExecuted extends TrajectoryAssertion
{
    /** @param array<string, string> $scripts Script paths mapped to expected output fragments, in execution order. */
    public function __construct(
        private readonly string $skill,
        private readonly string $instructions,
        private readonly string $referencePath,
        private readonly string $referenceContents,
        private readonly array $scripts,
    ) {
        if ($scripts === []) {
            throw new InvalidArgumentException('At least one expected script is required.');
        }
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $loaded = false;
        $referenceRead = false;
        $prepared = false;
        $executed = [];
        $paths = array_keys($this->scripts);

        foreach ($trajectory->messages() as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            $wasLoaded = $loaded;
            $wasReady = $referenceRead && $prepared;
            $previouslyExecuted = $executed;

            foreach ($message->getToolCalls() as $call) {
                if (!$call->hasResult()) {
                    continue;
                }

                $result = $call->getResult();

                if ($result instanceof ToolOutput && $result->isError()) {
                    continue;
                }

                $text = $result instanceof ToolOutput ? $result->getText() : $result;

                if (!$loaded && $call->getName() === 'skill' && $call->getInput('name') === $this->skill
                    && $text === $this->instructions) {
                    $loaded = true;
                } elseif (!$referenceRead && $call->getName() === 'skill_resource'
                    && $call->getInput('name') === $this->skill && $call->getInput('path') === $this->referencePath
                    && $text === $this->referenceContents) {
                    if (!$wasLoaded) {
                        return AssertionResult::fail(0.0, 'The execution reference was selected before skill "'.$this->skill.'" returned its instructions.');
                    }

                    $referenceRead = true;
                } elseif (!$prepared && $call->getName() === 'skill_script_prepare' && $call->getInput('name') === $this->skill) {
                    $data = json_decode($text, true);

                    if (is_array($data) && is_string($data['directory'] ?? null) && is_array($data['files'] ?? null)) {
                        if (!$wasLoaded) {
                            return AssertionResult::fail(0.0, 'Script preparation was selected before skill "'.$this->skill.'" returned its instructions.');
                        }

                        $prepared = true;
                    }
                } elseif ($call->getName() === 'bash') {
                    $data = json_decode($text, true);

                    if (!is_array($data) || ($data['exit_code'] ?? null) !== 0) {
                        continue;
                    }

                    foreach ($paths as $index => $path) {
                        if (isset($executed[$index]) || !str_contains($call->getInput('command') ?? '', $path)
                            || !str_contains($data['output'] ?? '', $this->scripts[$path])) {
                            continue;
                        }

                        $prerequisitesAvailable = $index === 0 ? $wasReady : isset($previouslyExecuted[$index - 1]);

                        if (!$prerequisitesAvailable) {
                            return AssertionResult::fail(0.0, 'Script "'.$path.'" was selected before its prerequisite results were available.');
                        }

                        $executed[$index] = true;

                        // One command cannot replace observing a result before selecting the next.
                        break;
                    }
                }
            }
        }

        if (!$loaded || !$referenceRead || !$prepared) {
            return AssertionResult::fail(0.0, 'Skill "'.$this->skill.'" did not complete loading, reading its execution reference and preparing its files.');
        }

        foreach ($paths as $index => $path) {
            if (!isset($executed[$index])) {
                return AssertionResult::fail(0.0, 'Script "'.$path.'" did not execute successfully with its expected output.');
            }
        }

        return AssertionResult::pass(1.0);
    }
}
