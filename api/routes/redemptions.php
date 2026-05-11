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

function ha_route_redeem_code() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $data = ha_read_json_body();
    $code_id = $data['code_id'] ?? '';
    $reported_revenue_ron = $data['reported_revenue_ron'] ?? null;

    if (empty($code_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'ERROR', 'message' => 'Code ID is required.']);
        exit;
    }

    $update_data = [];
    $update_query_params = ['id' => 'eq.' . $code_id];
    $is_revenue_only_update = false;

    if ($reported_revenue_ron !== null && is_numeric($reported_revenue_ron)) {
        $update_data['reported_revenue_ron'] = (float) $reported_revenue_ron;
        $is_revenue_only_update = true;
    }

    $status_update_data = $update_data;
    $status_update_data['status'] = 'used';
    $status_update_data['used_at'] = gmdate('c');

    $status_update_params = $update_query_params;
    $status_update_params['status'] = 'eq.issued';

    $update_result = ha_supabase_update('codes', $status_update_data, $status_update_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

    if ($update_result['httpcode'] === 204) {
        http_response_code(200);
        echo json_encode(['status' => 'SUCCESS', 'message' => 'Code successfully redeemed and status updated.']);
        exit;
    }

    if ($is_revenue_only_update) {
        $revenue_only_params = $update_query_params;
        $revenue_only_params['status'] = 'eq.used';

        $revenue_result = ha_supabase_update('codes', $update_data, $revenue_only_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

        if ($revenue_result['httpcode'] === 204) {
            http_response_code(200);
            echo json_encode(['status' => 'SUCCESS', 'message' => 'Code was already used. Revenue updated.']);
            exit;
        }
    }

    $check_exists_params = ['select' => 'id', 'id' => 'eq.' . $code_id, 'limit' => 1];
    $check_result = ha_supabase_select('codes', $check_exists_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);
    $codes = json_decode($check_result['response'], true);

    if (empty($codes)) {
        http_response_code(404);
        echo json_encode(['status' => 'ERROR', 'message' => 'Code not found.']);
        exit;
    }

    error_log("Redeem Code Final Update Failure for ID {$code_id}. Status update HTTP: {$update_result['httpcode']}. Revenue update HTTP: " . ($revenue_result['httpcode'] ?? 'N/A'));
    http_response_code(500);
    echo json_encode(['status' => 'ERROR', 'message' => 'Failed to update code status or revenue due to an internal error.']);

    exit;
}

