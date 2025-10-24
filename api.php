<?php

// Include credentials from the secrets file located outside the web root.
require __DIR__ . '/../secrets.php';
// require __DIR__ . '/secrets.php';

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
if (empty($supabaseUrl) || empty($supabaseAnonKey) || empty($supabaseKey) || empty($recaptchaSecretKey) || empty($resendApiKey)) {
    http_response_code(500);
    error_log("FATAL ERROR: One or more secret keys are missing or empty. Check your secrets.php file.");
    echo json_encode(['error' => 'API key configuration error.']);
    exit;
}

// Define app base URL (add this to your secrets.php or keep it here)
$appBaseUrl = $appBaseUrl ?? 'https://haiacolo.ro';

// --- Validation Functions ---

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_phone($phone) {
    // A universal phone number validation (+ followed by 7 to 15 digits).
    $cleanedPhone = preg_replace('/[^\d+]/', '', $phone);
    return preg_match('/^\+\d{7,15}$/', $cleanedPhone);
}

// --- reCAPTCHA Verification ---

function verify_recaptcha($token, $secretKey) {
    $debug = [];
    
    $debug['token_received'] = !empty($token);
    $debug['token_length'] = $token ? strlen($token) : 0;
    
    if (empty($token)) {
        $debug['error'] = 'No token provided';
        error_log("reCAPTCHA Debug: " . json_encode($debug));
        return false;
    }
    
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $secretKey,
        'response' => $token
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    
    $debug['http_code'] = $httpcode;
    
    // Log to error log AND keep for response
    error_log("reCAPTCHA Debug: " . json_encode($debug));

    if (!isset($result['success']) || !$result['success']) {
        // Store debug info globally so we can return it
        if (json_encode($debug)) { // Avoid storing non-serializable data
            $GLOBALS['recaptcha_debug'] = $debug;
        }
        return false;
    }

    return true;
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
        return ['httpcode' => 500, 'response' => json_encode(['error' => 'cURL connection failure']), 'curl_error' => $curl_error];
    }
    
    if ($httpcode !== 200 && $httpcode !== 201 && $httpcode !== 204 && $httpcode >= 400) {
        error_log("API RESPONSE ERROR: HTTP {$httpcode} on {$method} to {$url}. Response Body: {$response}");
    }
    
    return ['httpcode' => $httpcode, 'response' => $response, 'curl_error' => null];
}

// Function to send a POST request (INSERT) to Supabase
function supabase_insert($table, $data, $supabaseUrl, $supabaseAnonKey, $supabaseKey, $prefer_header = null) {
    $url = $supabaseUrl . '/rest/v1/' . $table;
    $headers = [
        'Content-Type: application/json', 
        'apikey: ' . $supabaseAnonKey, 
        // Use Service Role Key for elevated permissions
        'Authorization: Bearer ' . $supabaseKey
    ];
    if ($prefer_header) {
        $headers[] = 'Prefer: ' . $prefer_header;
    }
    
    return execute_curl_request($url, 'POST', $headers, json_encode($data, JSON_UNESCAPED_UNICODE));
}

// Function to send a GET request (SELECT) to Supabase
function supabase_select($table, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey = null) {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . http_build_query($query_params);
    $headers = [
        'Content-Type: application/json', 
        'apikey: ' . $supabaseAnonKey
    ];
    if ($supabaseKey) {
        // Use Service Role Key for RLS bypass/admin select
        $headers[] = 'Authorization: Bearer ' . $supabaseKey;
    }
    
    return execute_curl_request($url, 'GET', $headers);
}

// Function to send an UPDATE request (PATCH) to Supabase
function supabase_update($table, $data, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, $prefer_header = null) {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . http_build_query($query_params);
    $headers = [
        'Content-Type: application/json', 
        'apikey: ' . $supabaseAnonKey, 
        // Use Service Role Key for update permissions
        'Authorization: Bearer ' . $supabaseKey
    ];
    if ($prefer_header) {
        $headers[] = 'Prefer: ' . $prefer_header;
    }
    
    return execute_curl_request($url, 'PATCH', $headers, json_encode($data, JSON_UNESCAPED_UNICODE));
}


// Function to check the status of a deal code - Used by /api/check-code
function check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    if (empty($code)) {
        return ['status' => 'INVALID', 'message' => 'Code is required.'];
    }

    $query_params = [
        'select' => 'id,status,used_at,merchants(offer_description)',
        'code_string' => 'eq.' . $code, 
        'limit' => 1
    ];
    // Use the Service Role Key ($supabaseKey) here for reliable RLS bypass
    $result = supabase_select('codes', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey); 

    if ($result['httpcode'] !== 200) {
        error_log("Supabase Code Check Failure in check_deal_code_status: HTTP {$result['httpcode']}. Response: {$result['response']}");
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
        // Return the code ID for subsequent redemption
        return ['status' => 'VALID', 'offer' => $offer_description, 'code_id' => $code_data['id']];
    }

    return ['status' => 'INVALID', 'message' => 'Code has an invalid status.'];
}

/**
 * Robust function to check and record sent email using a single INSERT operation.
 * Requires a UNIQUE constraint on the 'recipient_email' column in 'sent_outreach_emails' table.
 * @return `true` if email was already sent (INSERT failed with 409) or an error occurred.
 * @return `false` if the email was just recorded (INSERT succeeded with 201).
 */
function supabase_check_and_record_sent_email($recipient_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $insert_data = [
        'recipient_email' => $recipient_email
    ];
    
    // We expect the insert to either succeed (201) or conflict (409) due to UNIQUE constraint
    // We pass null for AnonKey because this is an administrative action using $supabaseKey
    $insert_result = supabase_insert('sent_outreach_emails', $insert_data, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($insert_result['httpcode'] === 201) {
        return false; // Email successfully recorded, OK to send now.
    } elseif ($insert_result['httpcode'] === 409) {
        error_log("Email skipped: {$recipient_email} already recorded (409 Conflict).");
        return true; // Conflict: already recorded, DO NOT send.
    } else {
        // Log other errors and prevent sending to be safe.
        error_log("Error recording sent email to Supabase: HTTP {$insert_result['httpcode']}. Response: {$insert_result['response']}");
        return true; // Error occurred, DO NOT send.
    }
}

// Function to send an email with Resend
function send_resend_email($from_address, $to, $subject, $html, $resendApiKey) {
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

    $result = execute_curl_request($url, 'POST', $headers, json_encode($data));
    
    if ($result['curl_error']) {
         error_log("Resend cURL failure for {$to}: {$result['curl_error']}");
    }
    
    return ['httpcode' => $result['httpcode'], 'response' => $result['response']];
}

/**
 * Notifies the Edge Function about a new customer signup
 */
function notify_new_customer_edge_function($customer_data, $supabaseUrl, $supabaseKey) {
    // Robust URL construction for Edge Functions
    $base_url = rtrim($supabaseUrl, '/rest/v1'); 
    
    $edge_function_url = $base_url . '/functions/v1/user-signup-alert';
    
    error_log("🔔 Calling Edge Function at: {$edge_function_url}");
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $supabaseKey
    ];
    
    $payload = [
        'type' => 'INSERT',
        'table' => 'customers',
        'record' => $customer_data
    ];
    
    $result = execute_curl_request($edge_function_url, 'POST', $headers, json_encode($payload));
    
    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        error_log("✅ Successfully notified Edge Function about new customer");
        return true;
    } else {
        error_log("❌ Failed to notify Edge Function: HTTP {$result['httpcode']}. Response: {$result['response']}");
        return false;
    }
}

/**
 * Notifies the Edge Function about a new merchant application
 */
function notify_merchant_application_edge_function($merchant_data, $supabaseUrl, $supabaseKey) {
    // Robust URL construction for Edge Functions
    $base_url = rtrim($supabaseUrl, '/rest/v1'); 
    
    $edge_function_url = $base_url . '/functions/v1/merchant-application-alert';
    
    error_log("🔔 Calling Edge Function at: {$edge_function_url}");
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $supabaseKey
    ];
    
    $payload = [
        'type' => 'INSERT',
        'table' => 'merchants',
        'record' => $merchant_data
    ];
    
    $result = execute_curl_request($edge_function_url, 'POST', $headers, json_encode($payload));
    
    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        error_log("✅ Successfully notified Edge Function about new merchant");
        return true;
    } else {
        error_log("❌ Failed to notify Edge Function: HTTP {$result['httpcode']}. Response: {$result['response']}");
        return false;
    }
}


// --- API Routing ---

$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? $_GET['route'] : '';

// Set default headers
header('Content-Type: application/json');

// --- User Signup, Business Signup, Merchants ---

// Handle user signup
if ($method === 'POST' && $route === '/api/user-signup') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['g-recaptcha-response']) || !verify_recaptcha($data['g-recaptcha-response'], $recaptchaSecretKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'reCAPTCHA verification failed.']);
        exit;
    }

    // --- Dynamically fetch the Welcome Offer Merchant from the database ---
    $merchant_params = ['is_welcome_offer' => 'eq.true', 'select' => 'id,name,offer_description', 'limit' => 1];
    $merchant_result = supabase_select('merchants', $merchant_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($merchant_result['httpcode'] !== 200 || empty(json_decode($merchant_result['response'], true))) {
        error_log("FATAL: No merchant is configured as the welcome offer (is_welcome_offer = true).");
        http_response_code(500);
        echo json_encode(['error' => 'Welcome offer configuration error.']);
        exit;
    }
    $welcome_offer_merchant = json_decode($merchant_result['response'], true)[0];
    $initial_offer_merchant_id = $welcome_offer_merchant['id'];
    $offer_partner_name = $welcome_offer_merchant['name'];
    $offer_description = $welcome_offer_merchant['offer_description'] ?? 'a special welcome offer!';

    if (empty($data['phone_number']) || empty($data['zone']) || empty($data['interests'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields.']);
        exit;
    }

    $phone_number = htmlspecialchars($data['phone_number']);
    if (!validate_phone($phone_number)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid phone number format.']);
        exit;
    }

    $zones = $data['zone'];
    $customer_email = null;
    if (!empty($data['email']) && validate_email($data['email'])) {
        $customer_email = htmlspecialchars($data['email']);
    }

    $customer_data = [
        'phone_number' => $phone_number,
        'zone' => $zones,
        'interests' => $data['interests'] ?? []
    ];
    if (isset($data['full_name']) && !empty($data['full_name'])) {
        $customer_data['full_name'] = htmlspecialchars($data['full_name']);
    }
    if ($customer_email) {
        $customer_data['email'] = $customer_email;
    }

    // --- Upsert customer data and get their ID back (Atomic operation) ---
    $customer_id = null;
    $upsert_prefer_header = 'return=representation,resolution=merge-duplicates';

    // Use the on_conflict query parameter for upsert
    $upsert_url = $supabaseUrl . '/rest/v1/customers?on_conflict=phone_number';
    
    $upsert_headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey,
        'Prefer: ' . $upsert_prefer_header
    ];

    $upsert_result = execute_curl_request($upsert_url, 'POST', $upsert_headers, json_encode($customer_data, JSON_UNESCAPED_UNICODE));

    if ($upsert_result['httpcode'] === 200 || $upsert_result['httpcode'] === 201) {
        $upserted_data = json_decode($upsert_result['response'], true);
        if (!empty($upserted_data) && isset($upserted_data[0]['id'])) {
            $customer_id = $upserted_data[0]['id'];
        } else {
            error_log("User Signup - Upsert succeeded but no customer ID was returned. Response: " . $upsert_result['response']);
            http_response_code(500);
            echo json_encode(['error' => 'Error retrieving user ID after upsert.']);
            exit;
        }
    } else {
        error_log("User Signup - Upsert Error: HTTP {$upsert_result['httpcode']}. Response: {$upsert_result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An error occurred during user verification.']);
        exit;
    }

    // --- Post-Signup Actions ---
    if ($customer_id) {
        // Prepare customer data for notification
        $customer_notification_data = [
            'id' => $customer_id,
            'full_name' => $customer_data['full_name'] ?? null,
            'phone_number' => $customer_data['phone_number'],
            'zone' => $customer_data['zone'],
            'interests' => $customer_data['interests'],
            'email' => $customer_email
        ];
        
        // Notify the Edge Function about new signup
        notify_new_customer_edge_function($customer_notification_data, $supabaseUrl, $supabaseKey);
        
        // --- Generate and save the initial offer code ---
        $code_string = 'URANIA-' . substr(str_shuffle(str_repeat('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', 6)), 0, 6);

        $code_data = [
            'customer_id' => $customer_id,
            'merchant_id' => $initial_offer_merchant_id,
            'code_string' => $code_string,
            'status' => 'issued'
        ];

        // Use supabase_insert which uses Service Role Key
        $code_insert_result = supabase_insert('codes', $code_data, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

        if ($code_insert_result['httpcode'] !== 201) {
            error_log("FATAL: Could not create initial offer code for customer {$customer_id}. DB Response: " . $code_insert_result['response']);
            http_response_code(500);
            echo json_encode([
                'error' => 'Registration successful, but an error occurred while generating the offer code.',
                'db_error' => json_decode($code_insert_result['response'])
            ]);
            exit;
        }

        // --- Send Welcome Email (if applicable) ---
        if ($customer_email) {
            // Using the new, robust check-and-record function
            if (!supabase_check_and_record_sent_email($customer_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
                $subject = 'Bun venit la HaiAcolo! 🥐 Oferta ta de la ' . $offer_partner_name;
                $verification_url = $appBaseUrl . '/offer.html?code=' . $code_string;
                $html_body = file_get_contents(__DIR__ . '/templates/welcome_email.html');
                $html_body = str_replace('{{offer_partner_name}}', $offer_partner_name, $html_body);
                $html_body = str_replace('{{offer_description}}', $offer_description, $html_body);
                $html_body = str_replace('{{qr_code_url}}', $verification_url, $html_body);

                $email_result = send_resend_email('HaiAcolo <noreply@haiacolo.ro>', $customer_email, $subject, $html_body, $resendApiKey);
                if ($email_result['httpcode'] < 200 || $email_result['httpcode'] >= 300) {
                    error_log("Failed to send welcome email to {$customer_email}. Resend HTTP Code: {$email_result['httpcode']}. Response: {$email_result['response']}");
                }
            }
        }

        // --- Return success response with QR data ---
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'User successfully registered.',
            'qr_data' => 'haiacolo://verify?code=' . $code_string,
            'code_string' => $code_string
        ]);

    } else {
        error_log("User Signup - DB Operation Failed. Could not retrieve customer ID.");
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred while saving data.']);
    }
    exit;
}

// Handle business signup
if ($method === 'POST' && $route === '/api/business-signup') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['g-recaptcha-response']) || !verify_recaptcha($data['g-recaptcha-response'], $recaptchaSecretKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'reCAPTCHA verification failed.']);
        exit;
    }

    $requiredFields = ['business_name', 'contact_person', 'contact_email', 'contact_phone', 'business_type', 'business_location', 'business_address'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields.']);
            exit;
        }
    }

    if (!validate_email($data['contact_email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address format.']);
        exit;
    }

    if (!validate_phone($data['contact_phone'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid phone number format.']);
        exit;
    }

    $businessData = [
        'name' => htmlspecialchars($data['business_name']),
        'owner_contact' => htmlspecialchars($data['contact_person']),
        'contact_email' => htmlspecialchars($data['contact_email']),
        'contact_phone' => htmlspecialchars($data['contact_phone']),
        'business_type' => htmlspecialchars($data['business_type']),
        'location' => htmlspecialchars($data['business_location']),
        'address' => htmlspecialchars($data['business_address']),
        'business_description' => isset($data['business_description']) ? htmlspecialchars($data['business_description']) : '',
        'offer_description' => isset($data['offer_description']) ? htmlspecialchars($data['offer_description']) : '',
        'subscription_status' => 'pending_approval',
        'is_welcome_offer' => false // Explicitly set default
    ];

    // Use supabase_insert which uses Service Role Key
    $result = supabase_insert('merchants', $businessData, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=representation');

    if ($result['httpcode'] === 201) {
        // Notify Edge Function about new merchant application
        $inserted_merchants = json_decode($result['response'], true);
        if (!empty($inserted_merchants) && isset($inserted_merchants[0])) {
            notify_merchant_application_edge_function($inserted_merchants[0], $supabaseUrl, $supabaseKey);
        }

        // --- Send Welcome Email to Business ---
        $business_email = $businessData['contact_email'];
        $business_name = $businessData['name'];

        // Using the new, robust check-and-record function
        if (!supabase_check_and_record_sent_email($business_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
            $subject = 'Aplicația ta HaiAcolo a fost primită!';
            $html_body = file_get_contents(__DIR__ . '/templates/business_welcome_email.html');
            $html_body = str_replace('{{business_name}}', $business_name, $html_body);
            
            $email_result = send_resend_email('HaiAcolo <noreply@haiacolo.ro>', $business_email, $subject, $html_body, $resendApiKey);
            if ($email_result['httpcode'] < 200 || $email_result['httpcode'] >= 300) {
                error_log("Failed to send business welcome email to {$business_email}. Resend HTTP Code: {$email_result['httpcode']}. Response: {$email_result['response']}");
            }
        }
        
        http_response_code(200);
        echo json_encode(['message' => 'Business successfully registered.']);
    } else {
        error_log("Business Signup Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred.']);
    }
    exit;
}

// Handle fetching merchants
if ($method === 'GET' && $route === '/api/merchants') {
    $query_params = [
        'select' => 'id,name,location,business_type,offer_description,is_founding_member',
        'subscription_status' => 'eq.active',
        'order' => 'is_founding_member.desc,name.asc'
    ];
    // Use the Anon Key for public read
    $result = supabase_select('merchants', $query_params, $supabaseUrl, $supabaseAnonKey);

    if ($result['httpcode'] === 200) {
        http_response_code(200);
        echo $result['response']; // Directly echo the JSON response from Supabase
    } else {
        error_log("Merchant Fetch Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred.']);
    }
    exit;
}

// Handle fetching merchant history
if ($method === 'GET' && $route === '/api/merchant-history') {
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

    // Use the Service Role Key ($supabaseKey) for sensitive history data
    $result = supabase_select('deal_history', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

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

// Handle updating redemptions
if ($method === 'POST' && $route === '/api/update-redemptions') {
    $data = json_decode(file_get_contents('php://input'), true);
    $deal_id = $data['deal_id'] ?? '';
    $redemptions = $data['redemptions'] ?? null;

    if (empty($deal_id) || !is_numeric($redemptions)) {
        http_response_code(400);
        echo json_encode(['error' => 'Deal ID and a number of redemptions are required.']);
        exit;
    }

    $update_data = ['reported_redemptions' => (int)$redemptions];
    $query_params = ['id' => 'eq.' . $deal_id];

    // Use the Service Role Key ($supabaseKey) for updates
    $result = supabase_update('deal_history', $update_data, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

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


// Handle sending outreach emails
if ($method === 'POST' && $route === '/api/send-outreach') {
    $data = json_decode(file_get_contents('php://input'), true);
    $recipients = isset($data['data']) ? $data['data'] : [];

    if (empty($recipients)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data provided.']);
        exit;
    }

    $html_template = '<div style="font-family: Arial, sans-serif; font-size: 16px; color: #333; line-height: 1.6; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
    <div style="text-align: center; padding-bottom: 20px;">
        <img src="https://haiacolo.ro/public/logo.png" alt="HaiAcolo Logo" style="max-width: 150px; height: auto;">
        <h1 style="color: #007bff; margin-top: 10px;">Opportunity for your business in Cluj</h1>
    </div>
    
    <p>Welcome! We help you attract new customers and grow sales in Cluj, especially during less busy periods, **without the hassle**.</p>
    
    <p>We are a platform dedicated to connecting local businesses with thousands of customers eager to discover new places. All you have to do is tell us what offer you want to promote, and we deliver it directly to interested customers via WhatsApp notifications.</p>

    <div style="border-bottom: 1px solid #eee; margin: 20px 0;"></div>

    <h3 style="color: #007bff; text-align: center;">Ready to attract new customers?</h3>
    <p style="text-align: center;">Find out how you can list your business on HaiAcolo and start driving customer traffic right to your door.</p>
    
    <div style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
            <tbody>
                <tr>
                    <td align="center" style="background-color: #007bff; border-radius: 5px;">
                        <a href="https://haiacolo.ro/founder" target="_blank" style="padding: 12px 24px; border: 1px solid #007bff; display: inline-block; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; mso-padding-alt: 0; text-underline-on: none;">
                            <span style="mso-text-raise: 10pt; mso-hide: all;">&nbsp;</span>
                            <span style="display:inline-block; vertical-align:middle; line-height:1.2; padding:0; mso-text-raise: -12pt;">
                                Find out more and Register!
                            </span>
                            <span style="mso-text-raise: 10pt; mso-hide: all;">&nbsp;</span>
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <p style="text-align: center; font-size: 14px; color: #888;">If you have questions, feel free to contact us at <a href="mailto:contact@haiacolo.ro" style="color:#007bff; font-weight:bold; text-decoration:underline;">contact@haiacolo.ro</a>.</p>
</div>';

    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;

    foreach ($recipients as $recipient) {
        $email = $recipient['email'] ?? '';
        $business_name = $recipient['business_name'] ?? '';
        $contact_name = $recipient['contact_name'] ?? '';

        if (validate_email($email)) {
            // Use the robust check-and-record function (relies on DB UNIQUE constraint)
            if (supabase_check_and_record_sent_email($email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
                $skipped_count++;
                continue; // Skip if already recorded (409) or error occurred
            }

            $subject = "Special opportunity for {$business_name} - Become a HaiAcolo Founding Member";
            $personalized_template = str_replace('[Numele afacerii]', $business_name, $html_template);
            $personalized_template = str_replace('[Numele persoanei de contact]', $contact_name, $personalized_template);
            $personalized_template = str_replace('[Business Name]', $business_name, $personalized_template);
            $personalized_template = str_replace('[Contact Person\'s Name]', $contact_name, $personalized_template);

            $result = send_resend_email('HaiAcolo <noreply@haiacolo.ro>', $email, $subject, $personalized_template, $resendApiKey);
            if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
                $success_count++;
            } else {
                error_log("Resend Email Failure for {$email}: HTTP {$result['httpcode']}. Response: {$result['response']}");
                $error_count++;
            }
        }
    }

    if ($error_count > 0) {
        http_response_code(500);
    } else {
        http_response_code(200);
    }
    echo json_encode(['message' => "Emails sent: {$success_count} successful, {$error_count} failed, {$skipped_count} skipped (already sent/error)."]);
    exit;
}

function process_code_redemption_php($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $query_params = [
        'p_code_string' => $code
    ];

    $url = $supabaseUrl . '/rest/v1/rpc/process_code_redemption';
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey
    ];

    $result = execute_curl_request($url, 'POST', $headers, json_encode($query_params));

    error_log("Supabase process_code_redemption response: " . print_r($result, true));

    if ($result['httpcode'] !== 200) {
        return [
            'httpcode' => $result['httpcode'],
            'response' => ['status' => 'ERROR', 'message' => 'Failed to process redemption.']
        ];
    }

    $response_data = json_decode($result['response'], true);

    if (empty($response_data)) {
        return [
            'httpcode' => 404,
            'response' => ['status' => 'INVALID', 'message' => 'Code not found.']
        ];
    }

    return [
        'httpcode' => $result['httpcode'],
        'response' => $response_data[0]
    ];
}

// Handle processing a redemption
if ($method === 'GET' && $route === '/api/process-redemption') {
    $code = isset($_GET['code']) ? $_GET['code'] : '';

    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['status' => 'INVALID', 'message' => 'Code is required.']);
        exit;
    }

    $result = process_code_redemption_php($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    http_response_code($result['httpcode']);
    echo json_encode($result['response']);
    exit;
}

// --- Deal Code Endpoints ---

// Handle checking a deal code status (GET endpoint)
if ($method === 'GET' && $route === '/api/check-code') {
    // Note: PHP automatically decodes URL parameters, so we use it directly.
    $code = isset($_GET['code']) ? $_GET['code'] : '';

    $status_result = check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($status_result['status'] === 'ERROR') {
        http_response_code(500);
        echo json_encode(['status' => 'ERROR', 'message' => 'A network or database error occurred. Please try again.']);
    } else {
        http_response_code(200);
        echo json_encode($status_result);
    }
    exit;
}

// Handle redeeming a deal code (POST endpoint)
if ($method === 'POST' && $route === '/api/redeem-code') {
    $data = json_decode(file_get_contents('php://input'), true);
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

    // Determine the update type
    if ($reported_revenue_ron !== null && is_numeric($reported_revenue_ron)) {
        $update_data['reported_revenue_ron'] = (float)$reported_revenue_ron;
        $is_revenue_only_update = true;
    }

    // Attempt to update status from 'issued' to 'used'
    // This is an atomic update that also allows setting revenue.
    $status_update_data = $update_data;
    $status_update_data['status'] = 'used';
    $status_update_data['used_at'] = gmdate('c'); // ISO 8601 format (UTC)
    
    // Attempt the atomic status and revenue update. 
    // It will only succeed if the code status is 'issued'
    $status_update_params = $update_query_params;
    $status_update_params['status'] = 'eq.issued';

    $update_result = supabase_update('codes', $status_update_data, $status_update_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

    if ($update_result['httpcode'] === 204) {
        // Status update succeeded (from issued to used)
        http_response_code(200);
        echo json_encode(['status' => 'SUCCESS', 'message' => 'Code successfully redeemed and status updated.']);
        exit;
    } 
    
    // If the status update failed (not 204), it means either:
    // 1. The code was already 'used' (filter prevented update).
    // 2. A database error occurred.
    // 3. The ID was not found.

    // If the attempt was revenue only, AND the status update failed, we try a revenue-only update
    if ($is_revenue_only_update) {
        // Fallback: If status update failed, attempt a PATCH where status='used' just to update revenue
        // This handles the case where the status was already 'used'.
        $revenue_only_params = $update_query_params;
        $revenue_only_params['status'] = 'eq.used';

        $revenue_result = supabase_update('codes', $update_data, $revenue_only_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

        if ($revenue_result['httpcode'] === 204) {
            http_response_code(200);
            echo json_encode(['status' => 'SUCCESS', 'message' => 'Code was already used. Revenue updated.']);
            exit;
        }
    }
    
    // If we reach here, neither update succeeded, so we check for generic errors or ID not found.
    
    // A quick check to see if the ID exists (similar to initial select in old version, but only on failure)
    $check_exists_params = ['select' => 'id', 'id' => 'eq.' . $code_id, 'limit' => 1];
    $check_result = supabase_select('codes', $check_exists_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);
    $codes = json_decode($check_result['response'], true);

    if (empty($codes)) {
        http_response_code(404);
        echo json_encode(['status' => 'ERROR', 'message' => 'Code not found.']);
        exit;
    }
    
    // If it exists but we couldn't update, it's a server or unknown status error.
    error_log("Redeem Code Final Update Failure for ID {$code_id}. Status update HTTP: {$update_result['httpcode']}. Revenue update HTTP: " . ($revenue_result['httpcode'] ?? 'N/A'));
    http_response_code(500);
    echo json_encode(['status' => 'ERROR', 'message' => 'Failed to update code status or revenue due to an internal error.']);
    
    exit;
}


// Handle providing config to the sender console
if ($method === 'GET' && $route === '/api/get-sender-config') {
    // Expose only the necessary, public-safe credentials
    $config = [
        'supabaseUrl' => $supabaseUrl,
        'supabaseAnonKey' => $supabaseAnonKey
    ];
    http_response_code(200);
    echo json_encode($config);
    exit;
}

// Handle saving push subscriptions to Supabase
if ($method === 'POST' && $route === '/api/save-push-subscription') {
    $subscription_data = json_decode(file_get_contents('php://input'), true);

    // Extract relevant data from the subscription object
    $endpoint = $subscription_data['endpoint'] ?? null;
    $p256dh = $subscription_data['keys']['p256dh'] ?? null;
    $auth = $subscription_data['keys']['auth'] ?? null;
    $expiration_time = $subscription_data['expirationTime'] ?? null; // This is a timestamp in milliseconds

    if (empty($endpoint) || empty($p256dh) || empty($auth)) {
        http_response_code(400);
        echo json_encode(['error' => 'Incomplete subscription data.']);
        exit;
    }

    // Prepare data for Supabase insert
    $insert_data = [
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        // Convert ms to ISO 8601, Supabase JWT is not used here (pass null)
        'expiration_time' => $expiration_time ? gmdate('c', $expiration_time / 1000) : null 
    ];

    // Use the Anon Key for public insert. Note: $supabaseKey is passed as null.
    $result = supabase_insert('push_subscriptions', $insert_data, $supabaseUrl, $supabaseAnonKey, null, 'return=minimal'); 

    if ($result['httpcode'] === 201) {
        http_response_code(200);
        echo json_encode(['message' => 'Subscription saved to Supabase.']);
    } elseif ($result['httpcode'] === 409) { // Conflict, subscription already exists (due to UNIQUE constraints)
        http_response_code(200);
        echo json_encode(['message' => 'Subscription already exists in Supabase.']);
    } else {
        error_log("Supabase Subscription Save Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'An error occurred while saving the subscription.']);
    }
    exit;
}

// If no route is matched, return a 404 error
http_response_code(404);
echo json_encode(['error' => 'Page not found.']);

?>