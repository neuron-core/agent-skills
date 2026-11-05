<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__).'/vendor/autoload.php';

if (is_file(dirname(__DIR__).'/examples/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/examples/.env');
}
