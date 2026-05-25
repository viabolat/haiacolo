<?php

function ha_route_update_redemptions() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $data = ha_read_json_body();
    $deal_id = $data['deal_id'] ?? '';
    $redemptions = $data['redemptions'] ?? null;

    if (empty($deal_id) || !is_numeric($redemptions)) {
        http_response_code(400);
        echo json_encode(['error' => 'Deal ID and a number of redemptions are required.']);
        exit;
    }

    $update_data = ['reported_redemptions' => (int) $redemptions];
    $query_params = ['id' => 'eq.' . $deal_id];

    $result = ha_supabase_update('deal_history', $update_data, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        http_response_code(200);
        echo json_encode(['message' => 'Redemptions successfully updated.']);
    } else {
        error_log("Redemption Update Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred during update.']);
    }
    exit;
}

function ha_route_process_redemption() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $code = isset($_GET['code']) ? $_GET['code'] : '';

    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['status' => 'INVALID', 'message' => 'Code is required.']);
        exit;
    }

    $result = ha_process_code_redemption($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    http_response_code($result['httpcode']);
    echo json_encode($result['response']);
    exit;
}

function ha_route_check_code() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $code = isset($_GET['code']) ? $_GET['code'] : '';

    $status_result = ha_check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($status_result['status'] === 'ERROR') {
        http_response_code(500);
        echo json_encode(['status' => 'ERROR', 'message' => 'A network or database error occurred. Please try again.']);
    } else {
        http_response_code(200);
        echo json_encode($status_result);
    }
    exit;
}


