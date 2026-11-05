<?php

declare(strict_types=1);

use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;

return [
    'output' => [
        new ConsoleOutput(true),
        new JsonOutput(__DIR__.'/evaluation-results.json'),
    ],
];
