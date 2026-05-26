<?php

// Resolves the secrets file path using file-relative traversal via dirname().
// Depths are fixed relative to THIS file's location (api/lib/secrets_loader.php).
//
// Local:      dirname(__DIR__, 2) → project root          → secrets.php
// Staging:    dirname(__DIR__, 5) → system root (above public_html) → secrets_staging.php
// Production: dirname(__DIR__, 4) → system root (above public_html) → secrets_production.php
//
// The staging branch is detected by the presence of '/staging' in __DIR__,
// which is true when the deployment lives inside a /staging/ subdirectory.

$_ha_host = $_SERVER['HTTP_HOST'] ?? '';

if (str_contains($_ha_host, 'localhost') || str_contains($_ha_host, '127.0.0.1')) {
    $secretsFile = dirname(__DIR__, 2) . '/secrets.php';
} elseif (str_contains(__DIR__, '/staging')) {
    $secretsFile = dirname(__DIR__, 5) . '/secrets_staging.php';
} else {
    $secretsFile = dirname(__DIR__, 4) . '/secrets_production.php';
}

unset($_ha_host);

if (!file_exists($secretsFile)) {
    http_response_code(500);
    error_log('FATAL ERROR: Secrets file not found: ' . $secretsFile);
    echo json_encode(['error' => 'Server configuration error.']);
    exit;
}

require $secretsFile;
