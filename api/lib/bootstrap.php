<?php

if (!function_exists('curl_init')) {
    http_response_code(500);
    error_log('FATAL ERROR: PHP cURL extension is not installed or enabled.');
    echo json_encode(['error' => 'The PHP cURL extension is not installed or enabled.']);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (empty($appBaseUrl)) {
    $appBaseUrl = 'https://haiacolo.ro';
}

// E3: Resolved absolute path to the web root, used by route handlers to locate
// email templates (e.g. templates/welcome_email.html).
$projectRoot = realpath(__DIR__ . '/../..');

function ha_require_secrets(array $required_keys) {
    foreach ($required_keys as $key) {
        if (empty($GLOBALS[$key])) {
            http_response_code(500);
            error_log('FATAL ERROR: One or more secret keys are missing or empty. Check your secrets.php file.');
            echo json_encode(['error' => 'API key configuration error.']);
            exit;
        }
    }
}

