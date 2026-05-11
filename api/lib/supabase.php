<?php

function ha_supabase_insert($table, $data, $supabaseUrl, $supabaseAnonKey, $supabaseKey, $prefer_header = null) {
    $url = $supabaseUrl . '/rest/v1/' . $table;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey
    ];
    if ($prefer_header) {
        $headers[] = 'Prefer: ' . $prefer_header;
    }

    return ha_execute_curl_request($url, 'POST', $headers, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function ha_supabase_select($table, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey = null) {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . http_build_query($query_params);
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey
    ];
    if ($supabaseKey) {
        $headers[] = 'Authorization: Bearer ' . $supabaseKey;
    }

    return ha_execute_curl_request($url, 'GET', $headers);
}

function ha_supabase_update($table, $data, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, $prefer_header = null) {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . http_build_query($query_params);
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey
    ];
    if ($prefer_header) {
        $headers[] = 'Prefer: ' . $prefer_header;
    }

    return ha_execute_curl_request($url, 'PATCH', $headers, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function ha_supabase_rpc($function_name, $params, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $url = $supabaseUrl . '/rest/v1/rpc/' . $function_name;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey
    ];

    return ha_execute_curl_request($url, 'POST', $headers, json_encode($params));
}

function ha_supabase_check_and_record_sent_email($recipient_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $insert_data = [
        'recipient_email' => $recipient_email
    ];

    $insert_result = ha_supabase_insert('sent_outreach_emails', $insert_data, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($insert_result['httpcode'] === 201) {
        return false;
    } elseif ($insert_result['httpcode'] === 409) {
        error_log("Email skipped: {$recipient_email} already recorded (409 Conflict).");
        return true;
    } else {
        error_log("Error recording sent email to Supabase: HTTP {$insert_result['httpcode']}. Response: {$insert_result['response']}");
        return true;
    }
}

function ha_send_resend_email($from_address, $to, $subject, $html, $resendApiKey) {
    $url = 'https://api.resend.com/emails';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $resendApiKey
    ];
    $data = [
        'from' => $from_address,
        'to' => $to,
        'subject' => $subject,
        'html' => $html
    ];

    $result = ha_execute_curl_request($url, 'POST', $headers, json_encode($data));

    if ($result['curl_error']) {
        error_log("Resend cURL failure for {$to}: {$result['curl_error']}");
    }

    return ['httpcode' => $result['httpcode'], 'response' => $result['response']];
}

function ha_notify_new_customer_edge_function($customer_data, $supabaseUrl, $supabaseKey) {
    $base_url = rtrim($supabaseUrl, '/rest/v1');
    $edge_function_url = $base_url . '/functions/v1/user-signup-alert';

    error_log("Calling Edge Function at: {$edge_function_url}");

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $supabaseKey
    ];

    $payload = [
        'type' => 'INSERT',
        'table' => 'customers',
        'record' => $customer_data
    ];

    $result = ha_execute_curl_request($edge_function_url, 'POST', $headers, json_encode($payload));

    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        error_log('Successfully notified Edge Function about new customer');
        return true;
    }

    error_log("Failed to notify Edge Function: HTTP {$result['httpcode']}. Response: {$result['response']}");
    return false;
}

function ha_notify_merchant_application_edge_function($merchant_data, $supabaseUrl, $supabaseKey) {
    $base_url = rtrim($supabaseUrl, '/rest/v1');
    $edge_function_url = $base_url . '/functions/v1/merchant-application-alert';

    error_log("Calling Edge Function at: {$edge_function_url}");

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $supabaseKey
    ];

    $payload = [
        'type' => 'INSERT',
        'table' => 'merchants',
        'record' => $merchant_data
    ];

    $result = ha_execute_curl_request($edge_function_url, 'POST', $headers, json_encode($payload));

    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        error_log('Successfully notified Edge Function about new merchant');
        return true;
    }

    error_log("Failed to notify Edge Function: HTTP {$result['httpcode']}. Response: {$result['response']}");
    return false;
}

