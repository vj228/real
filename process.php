<?php
declare(strict_types=1);

function parseInventoryCsv(string $filePath): array
{
    if (!file_exists($filePath)) {
        throw new RuntimeException('CSV file not found.');
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file.');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('CSV appears to be empty.');
    }

    $normalizedHeader = array_map(static function ($column) {
        return strtolower(trim((string)$column));
    }, $header);

    $requiredColumns = [
        'sku',
        'product_name',
        'category',
        'quantity',
        'reorder_level',
        'unit_price',
    ];

    foreach ($requiredColumns as $requiredColumn) {
        if (!in_array($requiredColumn, $normalizedHeader, true)) {
            fclose($handle);
            throw new RuntimeException("Missing required column: {$requiredColumn}");
        }
    }

    $columnIndexes = array_flip($normalizedHeader);
    $inventory = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, static fn($value) => trim((string)$value) !== '')) === 0) {
            continue;
        }

        $sku = trim((string)($row[$columnIndexes['sku']] ?? ''));
        $productName = trim((string)($row[$columnIndexes['product_name']] ?? ''));
        $category = trim((string)($row[$columnIndexes['category']] ?? ''));
        $quantity = (int)trim((string)($row[$columnIndexes['quantity']] ?? '0'));
        $reorderLevel = (int)trim((string)($row[$columnIndexes['reorder_level']] ?? '0'));
        $unitPrice = (float)trim((string)($row[$columnIndexes['unit_price']] ?? '0'));

        $inventory[] = [
            'sku' => $sku,
            'product_name' => $productName,
            'category' => $category,
            'quantity' => $quantity,
            'reorder_level' => $reorderLevel,
            'unit_price' => $unitPrice,
        ];
    }

    fclose($handle);

    return $inventory;
}