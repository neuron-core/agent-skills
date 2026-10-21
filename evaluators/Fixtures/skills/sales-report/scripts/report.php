<?php

declare(strict_types=1);

if (!isset($argv[1], $argv[3]) || $argv[2] !== '--output' || !is_file($argv[1])) {
    throw new InvalidArgumentException('Usage: report.php <analysis.json> --output <report.md>');
}

$config = json_decode(file_get_contents(__DIR__.'/../config/reporting.json'), true, flags: JSON_THROW_ON_ERROR);
$analysis = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

if ($analysis['currency'] !== $config['currency']) {
    throw new RuntimeException('Analysis currency does not match reporting configuration.');
}

$report = '# Sales report'.PHP_EOL.PHP_EOL
    .'Net sales: '.$analysis['currency'].' '.$analysis['net_sales'].PHP_EOL
    .'Paid orders: '.$analysis['paid_orders'].PHP_EOL
    .'Cancelled orders excluded: '.$analysis['cancelled_orders'].PHP_EOL
    .'Refunded orders: '.$analysis['refunded_orders'].PHP_EOL;

file_put_contents($argv[3], $report);
echo $report;
