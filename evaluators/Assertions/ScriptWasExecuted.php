<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

/** Load, read, prepare and execute one script; execution retries are allowed. */
final class ScriptWasExecuted extends TrajectoryAssertion
{
    public function __construct(
        private readonly string $skill,
        private readonly string $script,
        private readonly string $expectedOutput,
        private readonly string $referencePath,
    ) {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $steps = ['skill', 'skill_resource', 'skill_script_prepare', 'bash'];
        $step = 0;
        $pending = false;
        $directory = null;
        $attempts = 0;
        $succeeded = false;

        foreach ($trajectory->messages() as $message) {
            if ($message instanceof ToolCallMessage) {
                $calls = $message->getToolCalls();

                if ($pending || count($calls) !== 1 || $calls[0]->getName() !== $steps[$step]) {
                    return AssertionResult::fail(0.0, 'Complete load, read, prepare and execute in order, one call at a time.');
                }

                $pending = true;
            }

            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            $calls = $message->getToolCalls();

            if (!$pending || count($calls) !== 1) {
                return AssertionResult::fail(0.0, 'Each tool call must return its result before the next call.');
            }

            $pending = false;
            $call = $calls[0];
            $text = $this->successfulResult($call);

            if ($step < 3) {
                if ($call->getName() !== $steps[$step] || $call->getInput('name') !== $this->skill || $text === null) {
                    return AssertionResult::fail(0.0, 'The required skill, reference and preparation must succeed.');
                }

                if ($step === 1 && $call->getInput('path') !== $this->referencePath) {
                    return AssertionResult::fail(0.0, 'Read the required reference through skill_resource.');
                }

                if ($step === 2) {
                    $prepared = json_decode($text, true);
                    $directory = $prepared['directory'] ?? null;

                    if (!is_string($directory) || $directory === '' || !is_array($prepared['files'] ?? null)) {
                        return AssertionResult::fail(0.0, 'Preparation must return a directory and file list.');
                    }
                }

                $step++;
                continue;
            }

            if ($call->getName() !== 'bash' || !str_contains($call->getInput('command') ?? '', $this->script)) {
                return AssertionResult::fail(0.0, 'Only execution attempts of the required script may follow preparation.');
            }

            $attempts++;
            $execution = json_decode($text ?? '', true);
            $succeeded = is_array($execution) && ($execution['exit_code'] ?? null) === 0
                && ($execution['working_directory'] ?? $call->getInput('working_directory')) === $directory
                && str_contains($execution['output'] ?? '', $this->expectedOutput);
        }

        if ($pending || !$succeeded) {
            return AssertionResult::fail(0.0, 'The script did not finish successfully with the expected output.');
        }

        return AssertionResult::pass(1 / $attempts, context: ['attempts' => $attempts, 'retries' => $attempts - 1]);
    }

    private function successfulResult(ToolCall $call): ?string
    {
        if (!$call->hasResult()) {
            return null;
        }

        $result = $call->getResult();

        if ($result instanceof ToolOutput && $result->isError()) {
            return null;
        }

        $text = $result instanceof ToolOutput ? $result->getText() : $result;

        return trim($text) === '' ? null : $text;
    }
}
