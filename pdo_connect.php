<?php

declare(strict_types=1);

/**
 * Shared PDO connection using db.credentials.php
 */
function db_pdo_connect(): ?PDO
{
    $configPath = __DIR__ . '/db.credentials.php';
    if (!is_readable($configPath)) {
        return null;
    }
    /** @var array<string, mixed> $cfg */
    $cfg = require $configPath;

    $host = isset($cfg['host']) ? (string) $cfg['host'] : 'localhost';
    $port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
    $name = isset($cfg['name']) ? (string) $cfg['name'] : '';
    $user = isset($cfg['user']) ? (string) $cfg['user'] : '';
    $pass = isset($cfg['pass']) ? (string) $cfg['pass'] : '';

    if ($name === '' || $user === '') {
        return null;
    }

    $hosts = $host !== '' ? [$host] : ['localhost', '127.0.0.1', 'srv827.hstgr.io'];
    foreach ($hosts as $h) {
        $dsn = "mysql:host={$h};port={$port};dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            continue;
        }
    }

    return null;
}
