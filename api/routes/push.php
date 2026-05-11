<?php

function ha_route_save_push_subscription() {
    global $supabaseUrl, $supabaseAnonKey;

    $subscription_data = ha_read_json_body();

    $endpoint = $subscription_data['endpoint'] ?? null;
    $p256dh = $subscription_data['keys']['p256dh'] ?? null;
    $auth = $subscription_data['keys']['auth'] ?? null;
    $expiration_time = $subscription_data['expirationTime'] ?? null;

    if (empty($endpoint) || empty($p256dh) || empty($auth)) {
        http_response_code(400);
        echo json_encode(['error' => 'Incomplete subscription data.']);
        exit;
    }

    $insert_data = [
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'expiration_time' => $expiration_time ? gmdate('c', $expiration_time / 1000) : null
    ];

    $result = ha_supabase_insert('push_subscriptions', $insert_data, $supabaseUrl, $supabaseAnonKey, null, 'return=minimal');

    if ($result['httpcode'] === 201) {
        http_response_code(200);
        echo json_encode(['message' => 'Subscription saved to Supabase.']);
    } elseif ($result['httpcode'] === 409) {
        http_response_code(200);
        echo json_encode(['message' => 'Subscription already exists in Supabase.']);
    } else {
        error_log("Supabase Subscription Save Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An error occurred while saving the subscription.']);
    }
    exit;
}

