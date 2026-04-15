<?php
$inventory = [];
$dataFile = __DIR__ . '/data/inventory.json';

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

$totalItems = count($inventory);
$totalUnits = 0;
$lowStockCount = 0;
$outOfStockCount = 0;

foreach ($inventory as $item) {
    $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
    $reorderLevel = isset($item['reorder_level']) ? (int)$item['reorder_level'] : 0;

    $totalUnits += $quantity;

    if ($quantity <= 0) {
        $outOfStockCount++;
    }

    if ($quantity > 0 && $quantity <= $reorderLevel) {
        $lowStockCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory MVP</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Inventory Dashboard</h1>
        <p>Upload a CSV, review stock, and get reorder suggestions.</p>
    </header>

    <section class="card">
        <h2>Upload Inventory CSV</h2>
        <form action="upload.php" method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="file" name="inventory_csv" accept=".csv" required>
            <button type="submit">Upload CSV</button>
        </form>

        <div class="note">
            <strong>Expected CSV columns:</strong><br>
            sku, product_name, category, quantity, reorder_level, unit_price
        </div>
    </section>

    <section class="stats">
        <div class="stat-card">
            <h3>Total Products</h3>
            <p><?= $totalItems ?></p>
        </div>
        <div class="stat-card">
            <h3>Total Units</h3>
            <p><?= $totalUnits ?></p>
        </div>
        <div class="stat-card warning">
            <h3>Low Stock</h3>
            <p><?= $lowStockCount ?></p>
        </div>
        <div class="stat-card danger">
            <h3>Out of Stock</h3>
            <p><?= $outOfStockCount ?></p>
        </div>
    </section>

    <section class="card">
        <div class="section-head">
            <h2>Current Inventory</h2>
            <a class="link-button" href="recommendations.php">View Reorder Suggestions</a>
        </div>

        <?php if (empty($inventory)): ?>
            <p>No inventory uploaded yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Reorder Level</th>
                        <th>Unit Price</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inventory as $item): ?>
                        <?php
                        $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
                        $reorderLevel = isset($item['reorder_level']) ? (int)$item['reorder_level'] : 0;

                        if ($quantity <= 0) {
                            $status = 'Out of Stock';
                            $statusClass = 'badge danger';
                        } elseif ($quantity <= $reorderLevel) {
                            $status = 'Low Stock';
                            $statusClass = 'badge warning';
                        } else {
                            $status = 'Healthy';
                            $statusClass = 'badge success';
                        }
                        ?>
                        <tr>
                            <td><?= h((string)($item['sku'] ?? '')) ?></td>
                            <td><?= h((string)($item['product_name'] ?? '')) ?></td>
                            <td><?= h((string)($item['category'] ?? '')) ?></td>
                            <td><?= $quantity ?></td>
                            <td><?= $reorderLevel ?></td>
                            <td>$<?= number_format((float)($item['unit_price'] ?? 0), 2) ?></td>
                            <td><span class="<?= $statusClass ?>"><?= h($status) ?></span></td>
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