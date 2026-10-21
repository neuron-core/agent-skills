<?php

declare(strict_types=1);

if (!isset($argv[1], $argv[3]) || $argv[2] !== '--output' || !is_file($argv[1])) {
    throw new InvalidArgumentException('Usage: analyze.php <orders.csv> --output <analysis.json>');
}

$config = json_decode(file_get_contents(__DIR__.'/../config/reporting.json'), true, flags: JSON_THROW_ON_ERROR);
$file = fopen($argv[1], 'r');
$header = fgetcsv($file, escape: '');
$sales = 0;
$refunds = 0;
$paidOrders = 0;
$cancelledOrders = 0;
$refundedOrders = 0;

while (($values = fgetcsv($file, escape: '')) !== false) {
    $order = array_combine($header, $values);
    $amount = (int) round((float) $order['total'] * 100);

    if ($order['status'] === $config['sale_status']) {
        $sales += $amount;
        $paidOrders++;
    } elseif ($order['status'] === $config['refund_status']) {
        $refunds += $amount;
        $refundedOrders++;
    } elseif ($order['status'] === $config['excluded_status']) {
        $cancelledOrders++;
    } else {
        throw new RuntimeException('Unrecognized order status.');
    }
}

fclose($file);

$analysis = [
    'currency' => $config['currency'],
    'net_sales' => number_format(($sales - $refunds) / 100, 2, '.', ''),
    'paid_orders' => $paidOrders,
    'cancelled_orders' => $cancelledOrders,
    'refunded_orders' => $refundedOrders,
];
file_put_contents($argv[3], json_encode($analysis, JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($analysis, JSON_THROW_ON_ERROR).PHP_EOL;
