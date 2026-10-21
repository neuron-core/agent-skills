<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Evaluators\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;

final class EvidenceIsValid extends AbstractAssertion
{
    public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_array($actual)) {
            throw new InvalidArgumentException('Expected named evidence checks.');
        }

        foreach ($actual as $description => $valid) {
            if ($valid !== true) {
                return AssertionResult::fail(0.0, 'Missing or incorrect evidence: '.$description);
            }
        }

        return AssertionResult::pass(1.0);
    }
}
