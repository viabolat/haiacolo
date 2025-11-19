<?php

// Include credentials from the secrets file located outside the web root.
// require __DIR__ . '/../../secrets.php';
require __DIR__ . '/../secrets.php';
// Check for cURL extension
if (!function_exists('curl_init')) {
    http_response_code(500);
    error_log('FATAL ERROR: PHP cURL extension is not installed or enabled.');
    echo json_encode(['error' => 'The PHP cURL extension is not installed or enabled.']);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to users
ini_set('log_errors', 1);

// Sanity check for necessary environment variables
if (empty($supabaseUrl) || empty($supabaseAnonKey) || empty($supabaseKey)) {
    http_response_code(500);
    error_log("FATAL ERROR: One or more secret keys are missing or empty. Check your secrets.php file.");
    echo json_encode(['error' => 'API key configuration error.']);
    exit;
}

// --- API Functions (Supabase Wrappers) ---

/**
 * Executes a cURL request and handles cURL errors.
 * @return array ['httpcode' => int, 'response' => string, 'curl_error' => string|null]
 */
function execute_curl_request($url, $method, $headers, $payload = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
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
        return ['httpcode' => 500, 'response' => json_encode(['error' => 'cURL connection failure']), 'curl_error' => $curl_error];
    }
    
    if ($httpcode >= 400) {
        error_log("API RESPONSE ERROR: HTTP {$httpcode} on {$method} to {$url}. Response Body: {$response}");
    }
    
    return ['httpcode' => $httpcode, 'response' => $response, 'curl_error' => null];
}

// Function to send a GET request (SELECT) to Supabase
function supabase_select($table, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . http_build_query($query_params);
    $headers = [
        'Content-Type: application/json', 
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey // Use service key for admin access
    ];
    
    return execute_curl_request($url, 'GET', $headers);
}

// Function to call a Supabase RPC function
function supabase_rpc($function_name, $params, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $url = $supabaseUrl . '/rest/v1/rpc/' . $function_name;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey // Use service key for admin access
    ];

    return execute_curl_request($url, 'POST', $headers, json_encode($params));
}


// --- API Routing ---

$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? $_GET['route'] : '';

// Set default headers
header('Content-Type: application/json');

// Handle providing config to the frontend
if ($method === 'GET' && $route === '/api/config') {
    // Expose only the necessary, public-safe credentials
    $config = [
        'supabaseUrl' => $supabaseUrl,
        'supabaseAnonKey' => $supabaseAnonKey
    ];
    http_response_code(200);
    echo json_encode($config);
    exit;
}

// --- Loyalty API Endpoints ---

// Handle a new customer joining a loyalty program
if ($method === 'POST' && $route === '/api/join-loyalty') {
    $data = json_decode(file_get_contents('php://input'), true);
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
    $result = supabase_rpc('create_customer_and_loyalty_card', $rpc_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

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


// Handle punching a card via QR Code scan
if ($method === 'POST' && $route === '/api/punch-card') {
    $data = json_decode(file_get_contents('php://input'), true);
    $loyalty_card_id = $data['loyalty_card_id'] ?? '';

    if (empty($loyalty_card_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Loyalty card ID is required.']);
        exit;
    }

    // Call the new PostgreSQL function `punch_loyalty_card`
    $rpc_params = ['p_loyalty_card_id' => $loyalty_card_id];
    $result = supabase_rpc('punch_loyalty_card', $rpc_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $response_data = json_decode($result['response'], true);
        // The RPC returns an array with a single object, so we extract it.
        if (isset($response_data[0])) {
            // The broadcast is now handled by a database trigger,
            // so we just return the success message.
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


// Handle redeeming a loyalty code
if ($method === 'POST' && $route === '/api/redeem-loyalty-code') {
    $data = json_decode(file_get_contents('php://input'), true);
    $code = $data['code'] ?? '';

    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Code is required.']);
        exit;
    }

    // Call the PostgreSQL function `process_code_redemption`
    $rpc_params = ['p_code_string' => $code];
    $result = supabase_rpc('process_code_redemption', $rpc_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        $response_data = json_decode($result['response'], true);
        // The RPC returns an array with a single object, so we extract it.
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

// Handle fetching loyalty program details for a merchant
if ($method === 'GET' && $route === '/api/loyalty-program') {
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
    $result = supabase_select('loyalty_programs', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

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

// Handle fetching all loyalty cards for a merchant
if ($method === 'GET' && $route === '/api/loyalty-cards') {
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
    $result = supabase_select('loyalty_cards', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] === 200) {
        echo $result['response'];
    } else {
        http_response_code($result['httpcode']);
        echo $result['response'];
    }
    exit;
}


// Handle fetching a single loyalty card
if ($method === 'GET' && $route === '/api/loyalty-card') {
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
    $result = supabase_select('loyalty_cards', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

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


// If no route is matched, return a 404 error
http_response_code(404);
echo json_encode(['error' => 'Loyalty API route not found.']);

?>
