<?php

function ha_route_loyalty_config() {
    global $supabaseUrl, $supabaseAnonKey;

    $config = [
        'supabaseUrl' => $supabaseUrl,
        'supabaseAnonKey' => $supabaseAnonKey
    ];
    http_response_code(200);
    echo json_encode($config);
    exit;
}

function ha_route_join_loyalty() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $data = ha_read_json_body();
    $merchant_id = $data['merchant_id'] ?? '';
    $full_name = $data['full_name'] ?? '';
    $phone_number = $data['phone_number'] ?? '';

    if (empty($merchant_id) || empty($phone_number)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Merchant ID and phone number are required.']);
        exit;
    }

    $rpc_params = [
        'p_merchant_id' => $merchant_id,
        'p_full_name' => $full_name,
        'p_phone_number' => $phone_number
    ];
    $result = ha_supabase_rpc('create_customer_and_loyalty_card', $rpc_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $response_data = json_decode($result['response'], true);
        if (isset($response_data[0])) {
            http_response_code(200);
            echo json_encode($response_data[0]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Unexpected response from database function.']);
        }
    } else {
        http_response_code($result['httpcode']);
        echo json_encode(['status' => 'error', 'message' => 'Failed to create loyalty card.', 'details' => json_decode($result['response'])]);
    }
    exit;
}

function ha_route_punch_card() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $data = ha_read_json_body();
    $loyalty_card_id = $data['loyalty_card_id'] ?? '';

    if (empty($loyalty_card_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Loyalty card ID is required.']);
        exit;
    }

    $rpc_params = ['p_loyalty_card_id' => $loyalty_card_id];
    $result = ha_supabase_rpc('punch_loyalty_card', $rpc_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $response_data = json_decode($result['response'], true);
        if (isset($response_data[0])) {
            http_response_code(200);
            echo json_encode($response_data[0]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Unexpected response from database function.']);
        }
    } else {
        http_response_code($result['httpcode']);
        echo json_encode(['status' => 'error', 'message' => 'Failed to process punch.', 'details' => json_decode($result['response'])]);
    }
    exit;
}

function ha_route_redeem_loyalty_code() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $data = ha_read_json_body();
    $code = $data['code'] ?? '';

    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Code is required.']);
        exit;
    }

    $rpc_params = ['p_code_string' => $code];
    $result = ha_supabase_rpc('process_code_redemption', $rpc_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $response_data = json_decode($result['response'], true);
        if (isset($response_data[0])) {
            http_response_code(200);
            echo json_encode($response_data[0]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Unexpected response from database function.']);
        }
    } else {
        http_response_code($result['httpcode']);
        echo json_encode(['status' => 'error', 'message' => 'Failed to process code.', 'details' => json_decode($result['response'])]);
    }
    exit;
}

function ha_route_loyalty_program() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $merchant_id = $_GET['merchant_id'] ?? '';
    if (empty($merchant_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Merchant ID is required.']);
        exit;
    }

    $query_params = [
        'merchant_id' => 'eq.' . $merchant_id,
        'select' => '*,merchants(name)',
        'limit' => 1
    ];
    $result = ha_supabase_select('loyalty_programs', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $data = json_decode($result['response'], true);
        if (!empty($data)) {
            echo json_encode($data[0]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'No loyalty program found for this merchant.']);
        }
    } else {
        http_response_code($result['httpcode']);
        echo $result['response'];
    }
    exit;
}

function ha_route_loyalty_cards() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $merchant_id = $_GET['merchant_id'] ?? '';
    if (empty($merchant_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Merchant ID is required.']);
        exit;
    }

    $query_params = [
        'merchant_id' => 'eq.' . $merchant_id,
        'select' => '*,customers(full_name,phone_number)',
        'order' => 'id.desc'
    ];
    $result = ha_supabase_select('loyalty_cards', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        echo $result['response'];
    } else {
        http_response_code($result['httpcode']);
        echo $result['response'];
    }
    exit;
}

function ha_route_loyalty_card() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey;

    $card_id = $_GET['card_id'] ?? '';
    if (empty($card_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Card ID is required.']);
        exit;
    }

    $query_params = [
        'id' => 'eq.' . $card_id,
        'select' => '*,loyalty_programs(*,merchants(name)),customers(full_name,phone_number)',
        'limit' => 1
    ];
    $result = ha_supabase_select('loyalty_cards', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $data = json_decode($result['response'], true);
        if (!empty($data)) {
            echo json_encode($data[0]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Loyalty card not found.']);
        }
    } else {
        http_response_code($result['httpcode']);
        echo $result['response'];
    }
    exit;
}

