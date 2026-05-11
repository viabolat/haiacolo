<?php

function ha_route_merchants() {
    global $supabaseUrl, $supabaseAnonKey;

    $query_params = [
        'select' => 'id,name,location,business_type,offer_description,is_founding_member',
        'subscription_status' => 'eq.active',
        'order' => 'is_founding_member.desc,name.asc'
    ];

    $result = ha_supabase_select('merchants', $query_params, $supabaseUrl, $supabaseAnonKey);

    if ($result['httpcode'] === 200) {
        http_response_code(200);
        echo $result['response'];
    } else {
        error_log("Merchant Fetch Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred.']);
    }
    exit;
}

function ha_route_merchant_history() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $merchant_id = isset($_GET['merchant_id']) ? $_GET['merchant_id'] : '';

    if (empty($merchant_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Merchant ID is required.']);
        exit;
    }

    $query_params = [
        'merchant_id' => 'eq.' . $merchant_id,
        'select' => '*,merchants(name)',
        'order' => 'created_at.desc'
    ];

    $result = ha_supabase_select('deal_history', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        http_response_code(200);
        echo json_encode(['data' => json_decode($result['response'], true)]);
    } else {
        error_log("Merchant History Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred while retrieving history.']);
    }
    exit;
}

