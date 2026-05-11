<?php

function ha_check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    if (empty($code)) {
        return ['status' => 'INVALID', 'message' => 'Code is required.'];
    }

    $query_params = [
        'select' => 'id,status,used_at,merchants(offer_description)',
        'code_string' => 'eq.' . $code,
        'limit' => 1
    ];

    $result = ha_supabase_select('codes', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] !== 200) {
        error_log("Supabase Code Check Failure in ha_check_deal_code_status: HTTP {$result['httpcode']}. Response: {$result['response']}");
        return ['status' => 'ERROR', 'message' => 'System error retrieving code.'];
    }

    $codes = json_decode($result['response'], true);

    if (empty($codes)) {
        return ['status' => 'INVALID', 'message' => 'Code not found.'];
    }

    $code_data = $codes[0];

    if ($code_data['status'] === 'used') {
        return ['status' => 'ALREADY_USED', 'used_at' => $code_data['used_at']];
    }

    if ($code_data['status'] === 'issued') {
        $offer_description = $code_data['merchants']['offer_description'] ?? 'General offer';
        return ['status' => 'VALID', 'offer' => $offer_description, 'code_id' => $code_data['id']];
    }

    return ['status' => 'INVALID', 'message' => 'Code has an invalid status.'];
}

function ha_process_code_redemption($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $code_details = ha_check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($code_details['status'] === 'INVALID') {
        return ['httpcode' => 200, 'response' => ['status' => 'INVALID', 'message' => $code_details['message']]];
    }

    if ($code_details['status'] === 'ALREADY_USED') {
        return ['httpcode' => 200, 'response' => ['status' => 'ALREADY_USED', 'used_at' => $code_details['used_at']]];
    }

    if ($code_details['status'] === 'ERROR') {
        return ['httpcode' => 500, 'response' => ['status' => 'ERROR', 'message' => $code_details['message']]];
    }

    $update_data = [
        'status' => 'used',
        'used_at' => gmdate('c')
    ];
    $update_query_params = [
        'id' => 'eq.' . $code_details['code_id'],
        'status' => 'eq.issued'
    ];

    $update_result = ha_supabase_update('codes', $update_data, $update_query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

    if ($update_result['httpcode'] === 204 || $update_result['httpcode'] === 200) {
        return ['httpcode' => 200, 'response' => [
            'status' => 'SUCCESS',
            'code_id' => $code_details['code_id'],
            'offer' => $code_details['offer']
        ]];
    }

    error_log("Atomic redemption failed for code ID {$code_details['code_id']} even after check. HTTP: {$update_result['httpcode']}. Response: {$update_result['response']}");
    return [
        'httpcode' => 500,
        'response' => ['status' => 'ERROR', 'message' => 'Failed to save redemption status after validation.']
    ];
}

