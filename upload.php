<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_FILES['inventory_csv']) || $_FILES['inventory_csv']['error'] !== UPLOAD_ERR_OK) {
    exit('Upload failed. Please go back and try again.');
}

$uploadDir = __DIR__ . '/uploads';
$dataDir = __DIR__ . '/data';
$dataFile = $dataDir . '/inventory.json';

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    exit('Failed to create uploads directory.');
}

if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
    exit('Failed to create data directory.');
}

$tmpPath = $_FILES['inventory_csv']['tmp_name'];
$originalName = basename($_FILES['inventory_csv']['name']);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($extension !== 'csv') {
    exit('Only CSV files are allowed.');
}

$storedFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
$destinationPath = $uploadDir . '/' . $storedFileName;

if (!move_uploaded_file($tmpPath, $destinationPath)) {
    exit('Could not save uploaded file.');
}

require_once __DIR__ . '/process.php';

try {
    $inventory = parseInventoryCsv($destinationPath);
    file_put_contents($dataFile, json_encode($inventory, JSON_PRETTY_PRINT));
} catch (Throwable $e) {
    exit('Error processing CSV: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

header('Location: index.php');
exit;