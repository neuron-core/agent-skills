<?php

declare(strict_types=1);

if (!isset($argv[1]) || !is_file($argv[1])) {
    throw new InvalidArgumentException('Usage: validate.php <csv-file>');
}

$rules = json_decode(file_get_contents(__DIR__.'/../config/validation.json'), true, flags: JSON_THROW_ON_ERROR);
$file = fopen($argv[1], 'r');
$header = fgetcsv($file, escape: '') ?: [];
$errors = [];
$rows = 0;
$ids = [];

foreach ($rules['required_columns'] as $column) {
    if (!in_array($column, $header, true)) {
        $errors[] = ['row' => 1, 'column' => $column, 'reason' => 'Required column is missing.'];
    }
}

while (($values = fgetcsv($file, escape: '')) !== false) {
    $rows++;

    if (count($header) !== count($values)) {
        $errors[] = ['row' => $rows + 1, 'column' => null, 'reason' => 'Column count does not match the header.'];
        continue;
    }

    $order = array_combine($header, $values);
    $id = $order['order_id'] ?? '';

    if ($id === '' || isset($ids[$id])) {
        $errors[] = ['row' => $rows + 1, 'column' => 'order_id', 'reason' => 'Order ID must be nonempty and unique.'];
    }

    $ids[$id] = true;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $order['date'] ?? '');

    if (!$date || $date->format('Y-m-d') !== ($order['date'] ?? '')) {
        $errors[] = ['row' => $rows + 1, 'column' => 'date', 'reason' => 'Expected a valid YYYY-MM-DD date.'];
    }

    if (trim($order['customer'] ?? '') === '') {
        $errors[] = ['row' => $rows + 1, 'column' => 'customer', 'reason' => 'Customer must be nonempty.'];
    }

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $order['total'] ?? '')) {
        $errors[] = ['row' => $rows + 1, 'column' => 'total', 'reason' => 'Expected a nonnegative decimal amount.'];
    }

    if (!in_array($order['status'] ?? '', $rules['statuses'], true)) {
        $errors[] = ['row' => $rows + 1, 'column' => 'status', 'reason' => 'Unrecognized order status.'];
    }
}

fclose($file);

echo json_encode(['valid' => $errors === [], 'rows' => $rows, 'errors' => $errors], JSON_THROW_ON_ERROR).PHP_EOL;
