<?php
declare(strict_types=1);

$dataFile = __DIR__ . '/data/inventory.json';
$inventory = [];

if (file_exists($dataFile)) {
    $json = file_get_contents($dataFile);
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $inventory = $decoded;
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$recommendations = [];

foreach ($inventory as $item) {
    $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
    $reorderLevel = isset($item['reorder_level']) ? (int)$item['reorder_level'] : 0;
    $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;

    if ($quantity <= $reorderLevel) {
        $suggestedOrderQty = max(($reorderLevel * 2) - $quantity, $reorderLevel);
        $estimatedCost = $suggestedOrderQty * $unitPrice;

        $urgency = 'Medium';
        $urgencyClass = 'badge warning';

        if ($quantity <= 0) {
            $urgency = 'High';
            $urgencyClass = 'badge danger';
        }

        $recommendations[] = [
            'sku' => (string)($item['sku'] ?? ''),
            'product_name' => (string)($item['product_name'] ?? ''),
            'category' => (string)($item['category'] ?? ''),
            'quantity' => $quantity,
            'reorder_level' => $reorderLevel,
            'suggested_order_qty' => $suggestedOrderQty,
            'unit_price' => $unitPrice,
            'estimated_cost' => $estimatedCost,
            'urgency' => $urgency,
            'urgency_class' => $urgencyClass,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reorder Suggestions</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Reorder Suggestions</h1>
        <p>Products that need attention based on current stock levels.</p>
        <a class="link-button" href="index.php">Back to Dashboard</a>
    </header>

    <section class="card">
        <?php if (empty($recommendations)): ?>
            <p>No reorder suggestions right now.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Current Qty</th>
                        <th>Reorder Level</th>
                        <th>Suggested Order</th>
                        <th>Est. Cost</th>
                        <th>Urgency</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recommendations as $item): ?>
                        <tr>
                            <td><?= h($item['sku']) ?></td>
                            <td><?= h($item['product_name']) ?></td>
                            <td><?= h($item['category']) ?></td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td><?= (int)$item['reorder_level'] ?></td>
                            <td><?= (int)$item['suggested_order_qty'] ?></td>
                            <td>$<?= number_format((float)$item['estimated_cost'], 2) ?></td>
                            <td><span class="<?= h($item['urgency_class']) ?>"><?= h($item['urgency']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
</body>
</html>