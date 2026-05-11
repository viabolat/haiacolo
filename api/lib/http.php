<?php

function ha_json_response($status_code, $payload) {
    http_response_code($status_code);
    echo json_encode($payload);
}

function ha_read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Executes a cURL request and handles cURL errors.
 * @return array ['httpcode' => int, 'response' => string, 'curl_error' => string|null]
 */
function ha_execute_curl_request($url, $method, $headers, $payload = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    error_log("CURL DEBUG: Attempting {$method} to {$url}");

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("CURL FATAL ERROR ({$curl_errno}) on {$method} to {$url}: {$curl_error}");
        return [
            'httpcode' => 500,
            'response' => json_encode(['error' => 'cURL connection failure']),
            'curl_error' => $curl_error
        ];
    }

    if ($httpcode !== 200 && $httpcode !== 201 && $httpcode !== 204 && $httpcode >= 400) {
        error_log("API RESPONSE ERROR: HTTP {$httpcode} on {$method} to {$url}. Response Body: {$response}");
    }

    return ['httpcode' => $httpcode, 'response' => $response, 'curl_error' => null];
}

