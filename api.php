<?php

// Check for cURL extension
if (!function_exists('curl_init')) {
    http_response_code(500);
    error_log('FATAL ERROR: PHP cURL extension is not installed or enabled.');
    echo json_encode(['error' => 'Extensia cURL pentru PHP nu este instalată sau activată.']);
    exit;
}

// api.php

// Include credentials from the secrets file located outside the web root.
require __DIR__ . '/../secrets.php';
// require __DIR__ . '/secrets.php';

// Sanity check for necessary environment variables
if (empty($supabaseUrl) || empty($supabaseAnonKey) || empty($supabaseKey) || empty($recaptchaSecretKey) || empty($resendApiKey)) {
    http_response_code(500);
    error_log("FATAL ERROR: One or more secret keys are missing or empty. Check your secrets.php file.");
    echo json_encode(['error' => 'Eroare de configurare a cheilor API.']);
    exit;
}

// --- Validation Functions ---

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_phone($phone) {
    // A more universal phone number validation.
    // It requires a '+' at the beginning, followed by 7 to 15 digits.
    // This is a good practice for storing international numbers.
    $cleanedPhone = preg_replace('/[^\d+]/', '', $phone);
    return preg_match('/^\+\d{7,15}$/', $cleanedPhone);
}

// --- reCAPTCHA Verification ---

function verify_recaptcha($token, $secretKey) {
    $debug = [];
    
    $debug['token_received'] = !empty($token);
    $debug['token_length'] = $token ? strlen($token) : 0;
    $debug['secret_key_prefix'] = substr($secretKey, 0, 10);
    
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
    $debug['google_response'] = $result;
    
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
    
    // Log the request attempt
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
        // Log cURL failure specifically
        error_log("CURL FATAL ERROR ({$curl_errno}) on {$method} to {$url}: {$curl_error}");
        // Return 500 for a network/connection failure
        return ['httpcode' => 500, 'response' => json_encode(['error' => 'cURL connection failure']), 'curl_error' => $curl_error];
    }
    
    // Log non-success responses for API debugging (Supabase/Resend error)
    if ($httpcode !== 200 && $httpcode !== 201 && $httpcode !== 204 && $httpcode >= 400) {
        error_log("API RESPONSE ERROR: HTTP {$httpcode} on {$method} to {$url}. Response Body: {$response}");
    }
    
    return ['httpcode' => $httpcode, 'response' => $response, 'curl_error' => null];
}

// Function to send a POST request (INSERT) to Supabase
function supabase_insert($table, $data, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $url = $supabaseUrl . '/rest/v1/' . $table;
    $headers = [
        'Content-Type: application/json', 
        'apikey: ' . $supabaseAnonKey, 
        // Use Service Role Key for elevated permissions
        'Authorization: Bearer ' . $supabaseKey
    ];
    
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
function supabase_update($table, $data, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . http_build_query($query_params);
    $headers = [
        'Content-Type: application/json', 
        'apikey: ' . $supabaseAnonKey, 
        // Use Service Role Key for update permissions
        'Authorization: Bearer ' . $supabaseKey
    ];
    
    return execute_curl_request($url, 'PATCH', $headers, json_encode($data, JSON_UNESCAPED_UNICODE));
}


// Function to check the status of a deal code - Used by /api/check-code
function check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    if (empty($code)) {
        return ['status' => 'INVALID', 'message' => 'Codul este obligatoriu.'];
    }

    $query_params = [
        'select' => 'id,status,used_at,merchants(offer_description)',
        'code_string' => 'eq.' . $code, // $code is already decoded
        'limit' => 1
    ];
    // Use the Service Role Key ($supabaseKey) here for reliable RLS bypass
    $result = supabase_select('codes', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey); 

    if ($result['httpcode'] !== 200) {
        error_log("Supabase Code Check Failure in check_deal_code_status: HTTP {$result['httpcode']}. Response: {$result['response']}");
        return ['status' => 'ERROR', 'message' => 'Eroare de sistem la preluarea codului.'];
    }

    $codes = json_decode($result['response'], true);

    if (empty($codes)) {
        return ['status' => 'INVALID', 'message' => 'Cod negăsit.'];
    }

    $code_data = $codes[0];

    if ($code_data['status'] === 'used') {
        return ['status' => 'ALREADY_USED', 'used_at' => $code_data['used_at']];
    }

    if ($code_data['status'] === 'issued') {
        $offer_description = $code_data['merchants']['offer_description'] ?? 'Ofertă generală';
        // Return the code ID for subsequent redemption
        return ['status' => 'VALID', 'offer' => $offer_description, 'code_id' => $code_data['id']];
    }

    return ['status' => 'INVALID', 'message' => 'Codul are un status invalid.'];
}

// Function to check if an email has been sent and record it if not
// Returns `true` if the email should NOT be sent (already sent or error).
// Returns `false` if the email should be sent (was just recorded).
function supabase_check_and_record_sent_email($recipient_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey) {
    // Check if email already sent
    $query_params = [
        'recipient_email' => 'eq.' . $recipient_email,
        'select' => 'id',
        'limit' => 1
    ];
    
    $check_result = supabase_select('sent_outreach_emails', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($check_result['httpcode'] === 200) {
        $existing_records = json_decode($check_result['response'], true);
        if (!empty($existing_records)) {
            return true; // Email already sent, do not send again.
        }
    } else {
        // If the check fails for any reason, log it and assume we shouldn't send to be safe.
        error_log("Supabase check email query failed: HTTP {$check_result['httpcode']}. Response: {$check_result['response']}");
        return true; // Error occurred, do not send.
    }

    // If we reach here, the email was not found. Record it now.
    $insert_data = [
        'recipient_email' => $recipient_email
    ];
    $insert_result = supabase_insert('sent_outreach_emails', $insert_data, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($insert_result['httpcode'] === 201) {
        return false; // Email successfully recorded, OK to send now.
    } else {
        // If the insert fails, log it and prevent sending.
        error_log("Error recording sent email to Supabase: HTTP {$insert_result['httpcode']}. Response: {$insert_result['response']}");
        return true; // Error occurred, do not send.
    }
}

// Function to send an email with Resend
function send_resend_email($to, $subject, $html, $resendApiKey) {
    $url = 'https://api.resend.com/emails';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $resendApiKey
    ];
    $data = [
        'from' => 'HaiAcolo Alerts <alerts@haiacolo.ro>',
        'to' => $to,
        'subject' => $subject,
        'html' => $html
    ];

    $result = execute_curl_request($url, 'POST', $headers, json_encode($data));
    
    // If cURL failed, result['httpcode'] will be 500
    if ($result['curl_error']) {
         error_log("Resend cURL failure for {$to}: {$result['curl_error']}");
    }
    
    return ['httpcode' => $result['httpcode'], 'response' => $result['response']];
}

/**
 * Notifies the Edge Function about a new customer signup
 * This sends an internal notification email to the business owner
 */
function notify_new_customer_edge_function($customer_data, $supabaseUrl, $supabaseKey) {
    // Build the Edge Functions URL properly
    // Remove any trailing slashes and /rest/v1 if present
    $base_url = rtrim($supabaseUrl, '/');
    $base_url = str_replace('/rest/v1', '', $base_url);
    
    // Construct the full Edge Function URL - using existing user-signup-alert function
    $edge_function_url = $base_url . '/functions/v1/user-signup-alert';
    
    error_log("=== EDGE FUNCTION NOTIFICATION START ===");
    error_log("🔔 Calling Edge Function at: {$edge_function_url}");
    error_log("Customer Data: " . json_encode($customer_data));
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $supabaseKey
    ];
    
    $payload = [
        'type' => 'INSERT',
        'table' => 'customers',
        'record' => $customer_data
    ];
    
    error_log("Payload: " . json_encode($payload));
    
    $result = execute_curl_request($edge_function_url, 'POST', $headers, json_encode($payload));
    
    error_log("Response HTTP Code: {$result['httpcode']}");
    error_log("Response Body: {$result['response']}");
    
    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        error_log("✅ Successfully notified Edge Function about new customer");
        error_log("=== EDGE FUNCTION NOTIFICATION END ===");
        return true;
    } else {
        error_log("❌ Failed to notify Edge Function: HTTP {$result['httpcode']}");
        error_log("=== EDGE FUNCTION NOTIFICATION END ===");
        return false;
    }
}

/**
 * Notifies the Edge Function about a new merchant application
 * This sends an email with approval/rejection links to the business owner
 */
function notify_merchant_application_edge_function($merchant_data, $supabaseUrl, $supabaseAnonKey) {
    // Build the Edge Functions URL properly
    $base_url = rtrim($supabaseUrl, '/');
    $base_url = str_replace('/rest/v1', '', $base_url);
    
    $edge_function_url = $base_url . '/functions/v1/merchant-application-alert';
    
    error_log("=== MERCHANT NOTIFICATION START ===");
    error_log("🔔 Calling Edge Function at: {$edge_function_url}");
    error_log("Merchant Data: " . json_encode($merchant_data));
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $supabaseAnonKey // Use Anon Key for Edge Functions
    ];
    
    $payload = [
        'type' => 'INSERT',
        'table' => 'merchants',
        'record' => $merchant_data
    ];
    
    error_log("Payload: " . json_encode($payload));
    
    $result = execute_curl_request($edge_function_url, 'POST', $headers, json_encode($payload));
    
    error_log("Response HTTP Code: {$result['httpcode']}");
    error_log("Response Body: {$result['response']}");
    
    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        error_log("✅ Successfully notified Edge Function about new merchant");
        error_log("=== MERCHANT NOTIFICATION END ===");
        return true;
    } else {
        error_log("❌ Failed to notify Edge Function: HTTP {$result['httpcode']}");
        error_log("=== MERCHANT NOTIFICATION END ===");
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
        echo json_encode(['error' => 'Verificare reCAPTCHA eșuată.']);
        exit;
    }

    // --- Dynamically fetch the Welcome Offer Merchant from the database ---
    // This is more robust as the offer can be changed in the DB without code changes.
    $merchant_params = ['is_welcome_offer' => 'eq.true', 'select' => 'id,name,offer_description', 'limit' => 1];
    $merchant_result = supabase_select('merchants', $merchant_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($merchant_result['httpcode'] !== 200 || empty(json_decode($merchant_result['response'], true))) {
        error_log("FATAL: No merchant is configured as the welcome offer (is_welcome_offer = true).");
        http_response_code(500);
        echo json_encode(['error' => 'Eroare de configurare a ofertei de bun venit.']);
        exit;
    }
    $welcome_offer_merchant = json_decode($merchant_result['response'], true)[0];
    $initial_offer_merchant_id = $welcome_offer_merchant['id'];
    $offer_partner_name = $welcome_offer_merchant['name'];
    $offer_description = $welcome_offer_merchant['offer_description'] ?? 'o ofertă specială de bun venit!';

    if (empty($data['phone_number']) || empty($data['zone']) || empty($data['interests'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Câmpuri obligatorii lipsă.']);
        exit;
    }

    $phone_number = htmlspecialchars($data['phone_number']);
    if (!validate_phone($phone_number)) {
        http_response_code(400);
        echo json_encode(['error' => 'Formatul numărului de telefon este invalid.']);
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

    // --- Upsert Logic with returned customer ID ---
    $customer_id = null;
    
    // Add "Prefer: return=representation" to get the record back
    $upsert_headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey,
        'Prefer: return=representation' 
    ];

    $select_params = ['phone_number' => 'eq.' . $phone_number, 'select' => 'id'];
    $existing_customer_result = supabase_select('customers', $select_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($existing_customer_result['httpcode'] === 200) {
        $existing_customer = json_decode($existing_customer_result['response'], true);
        
        if (!empty($existing_customer)) {
            $update_params = ['phone_number' => 'eq.' . $phone_number];
            $update_url = $supabaseUrl . '/rest/v1/customers?' . http_build_query($update_params);
            $update_result = execute_curl_request($update_url, 'PATCH', $upsert_headers, json_encode($customer_data, JSON_UNESCAPED_UNICODE));
            
            if ($update_result['httpcode'] >= 200 && $update_result['httpcode'] < 300) {
                $updated_data = json_decode($update_result['response'], true);
                $customer_id = $updated_data[0]['id'] ?? null;
            }
        } else {
            $insert_url = $supabaseUrl . '/rest/v1/customers';
            $insert_result = execute_curl_request($insert_url, 'POST', $upsert_headers, json_encode($customer_data, JSON_UNESCAPED_UNICODE));

            if ($insert_result['httpcode'] === 201) {
                $inserted_data = json_decode($insert_result['response'], true);
                $customer_id = $inserted_data[0]['id'] ?? null;
            }
        }
    } else {
        error_log("User Signup - Initial Select Error: HTTP {$existing_customer_result['httpcode']}. Response: {$existing_customer_result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'A apărut o eroare la verificarea utilizatorului.']);
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

        $code_insert_result = supabase_insert('codes', $code_data, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

        if ($code_insert_result['httpcode'] !== 201) {
            error_log("FATAL: Could not create initial offer code for customer {$customer_id}. DB Response: " . $code_insert_result['response']);
            http_response_code(500);
            echo json_encode([
                'error' => 'Înregistrarea a reușit, dar a apărut o eroare la generarea codului ofertei.',
                'db_error' => json_decode($code_insert_result['response'])
            ]);
            exit;
        }

        // --- Send Welcome Email (if applicable) ---
        if ($customer_email) {
            if (!supabase_check_and_record_sent_email($customer_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
                // The partner name and offer description are already fetched from the dynamic query above.
                $subject = 'Bun venit la HaiAcolo! 🥐 Oferta ta de la ' . $offer_partner_name;
                // Define the verification URL for the email template
                $verification_url = ($appBaseUrl ?? 'https://haiacolo.ro') . '/offer.html?code=' . $code_string;
                $html_body = file_get_contents(__DIR__ . '/templates/welcome_email.html');
                $html_body = str_replace('{{offer_partner_name}}', $offer_partner_name, $html_body);
                $html_body = str_replace('{{offer_description}}', $offer_description, $html_body);
                $html_body = str_replace('{{qr_code_url}}', $verification_url, $html_body);
                
                $email_result = send_resend_email($customer_email, $subject, $html_body, $resendApiKey);
                if ($email_result['httpcode'] < 200 || $email_result['httpcode'] >= 300) {
                    error_log("Failed to send welcome email to {$customer_email}. Resend HTTP Code: {$email_result['httpcode']}. Response: {$email_result['response']}");
                }
            }
        }

        // --- Return success response with QR data ---
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Utilizator înregistrat cu succes.',
            'qr_data' => 'haiacolo://verify?code=' . $code_string,
            'code_string' => $code_string
        ]);

    } else {
        error_log("User Signup - DB Operation Failed. Could not retrieve customer ID.");
        http_response_code(500);
        echo json_encode(['error' => 'A apărut o eroare internă la salvarea datelor.']);
    }
    exit;
}

// Handle business signup
if ($method === 'POST' && $route === '/api/business-signup') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['g-recaptcha-response']) || !verify_recaptcha($data['g-recaptcha-response'], $recaptchaSecretKey)) {
        http_response_code(403);
        $debug_info = $GLOBALS['recaptcha_debug'] ?? ['error' => 'No debug information available.'];
        echo json_encode([
            'error' => 'Verificare reCAPTCHA eșuată.',
            'debug_info' => $debug_info
        ]);
        exit;
    }

    $requiredFields = ['business_name', 'contact_person', 'contact_email', 'contact_phone', 'business_type', 'business_location', 'business_address'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => 'Câmpuri obligatorii lipsă.']);
            exit;
        }
    }

    if (!validate_email($data['contact_email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Formatul adresei de email este invalid.']);
        exit;
    }

    if (!validate_phone($data['contact_phone'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Formatul numărului de telefon este invalid.']);
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

    // Add "Prefer: return=representation" to get the record back
    $insert_headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey,
        'Prefer: return=representation'
    ];

    $insert_url = $supabaseUrl . '/rest/v1/merchants';
    $result = execute_curl_request($insert_url, 'POST', $insert_headers, json_encode($businessData, JSON_UNESCAPED_UNICODE));

    if ($result['httpcode'] === 201) {
        // Notify Edge Function about new merchant application
        $inserted_merchants = json_decode($result['response'], true);
        if (!empty($inserted_merchants) && isset($inserted_merchants[0])) {
            notify_merchant_application_edge_function($inserted_merchants[0], $supabaseUrl, $supabaseAnonKey);
        }
        
        http_response_code(200);
        echo json_encode(['message' => 'Afacere înregistrată cu succes.']);
    } else {
        error_log("Business Signup Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'A apărut o eroare internă.']);
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
        echo json_encode(['data' => json_decode($result['response'], true)]);
    } else {
        error_log("Merchant Fetch Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'A apărut o eroare internă.']);
    }
    exit;
}

// Handle fetching merchant history
if ($method === 'GET' && $route === '/api/merchant-history') {
    $merchant_id = isset($_GET['merchant_id']) ? $_GET['merchant_id'] : '';

    if (empty($merchant_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID-ul comerciantului este obligatoriu.']);
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
        echo json_encode(['error' => 'A apărut o eroare internă la preluarea istoricului.']);
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
        echo json_encode(['error' => 'ID-ul ofertei și un număr de răscumpărări sunt obligatorii.']);
        exit;
    }

    $update_data = ['reported_redemptions' => (int)$redemptions];
    $query_params = ['id' => 'eq.' . $deal_id];

    // Use the Service Role Key ($supabaseKey) for updates
    $result = supabase_update('deal_history', $update_data, $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] >= 200 && $result['httpcode'] < 300) {
        http_response_code(200);
        echo json_encode(['message' => 'Răscumpărări actualizate cu succes.']);
    } else {
        error_log("Redemption Update Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'A apărut o eroare internă la actualizare.']);
    }
    exit;
}


// Handle sending outreach emails
if ($method === 'POST' && $route === '/api/send-outreach') {
    $data = json_decode(file_get_contents('php://input'), true);
    $recipients = isset($data['data']) ? $data['data'] : [];

    if (empty($recipients)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nu au fost furnizate date.']);
        exit;
    }

    $html_template = '<div style="font-family: Arial, sans-serif; font-size: 16px; color: #333; line-height: 1.6; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
    <div style="text-align: center; padding-bottom: 20px;">
        <img src="https://haiacolo.ro/public/logo.png" alt="HaiAcolo Logo" style="max-width: 150px; height: auto;">
        <h1 style="color: #007bff; margin-top: 10px;">Oportunitate pentru afacerea ta în Cluj</h1>
    </div>
    
    <p>Bun venit! Vă ajutăm să atrageți clienți noi și să creșteți vânzările în Cluj, în special în perioadele mai puțin aglomerate, <strong>fără bătăi de cap</strong>.</p>
    
    <p>Suntem o platformă dedicată conectării afacerilor locale cu mii de clienți dornici să descopere locuri noi. Tot ce trebuie să faceți este să ne spuneți ce ofertă doriți să promovați, iar noi o livrăm direct către clienții interesați prin notificări WhatsApp.</p>

    <div style="border-bottom: 1px solid #eee; margin: 20px 0;"></div>

    <h3 style="color: #007bff; text-align: center;">Ești gata să atragi clienți noi?</h3>
    <p style="text-align: center;">Află cum îți poți înscrie afacerea pe HaiAcolo și poți începe să crești traficul de clienți direct la ușa ta.</p>
    
    <div style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
            <tbody>
                <tr>
                    <td align="center" style="background-color: #007bff; border-radius: 5px;">
                        <a href="https://haiacolo.ro/founder" target="_blank" style="padding: 12px 24px; border: 1px solid #007bff; display: inline-block; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; mso-padding-alt: 0; text-underline-on: none;">
                            <span style="mso-text-raise: 10pt; mso-hide: all;">&nbsp;</span>
                            <span style="display:inline-block; vertical-align:middle; line-height:1.2; padding:0; mso-text-raise: -12pt;">
                                Află mai multe și Înscrie-te!
                            </span>
                            <span style="mso-text-raise: 10pt; mso-hide: all;">&nbsp;</span>
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <p style="text-align: center; font-size: 14px; color: #888;">Dacă aveți întrebări, nu ezitați să ne contactați la <a href="mailto:contact@haiacolo.ro" style="color:#007bff; font-weight:bold; text-decoration:underline;">contact@haiacolo.ro</a>.</p>
</div>';

    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;

    foreach ($recipients as $recipient) {
        $email = $recipient['email'] ?? '';
        $business_name = $recipient['business_name'] ?? '';
        $contact_name = $recipient['contact_name'] ?? '';

        if (validate_email($email)) {
            if (supabase_check_and_record_sent_email($email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
                $skipped_count++;
                continue; // Skip if already sent
            }

            $subject = "Oportunitate specială pentru {$business_name} - Devino membru fondator HaiAcolo";
            $personalized_template = str_replace('[Numele afacerii]', $business_name, $html_template);
            $personalized_template = str_replace('[Numele persoanei de contact]', $contact_name, $personalized_template);
            $personalized_template = str_replace('[Business Name]', $business_name, $personalized_template);
            $personalized_template = str_replace('[Contact Person\'s Name]', $contact_name, $personalized_template);

            $result = send_resend_email($email, $subject, $personalized_template, $resendApiKey);
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
    echo json_encode(['message' => "Emailuri trimise: {$success_count} cu succes, {$error_count} eșuate, {$skipped_count} omise (deja trimise)."]);
    exit;
}

// --- Deal Code Endpoints ---

// Handle checking a deal code status (GET endpoint)
if ($method === 'GET' && $route === '/api/check-code') {
    // CRITICAL FIX: URL-decode the code parameter to correctly handle special characters
    // like '+' or '-' that might be part of the code string.
    $code = isset($_GET['code']) ? $_GET['code'] : '';

    $status_result = check_deal_code_status($code, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($status_result['status'] === 'ERROR') {
        http_response_code(500);
        echo json_encode(['status' => 'ERROR', 'message' => 'A apărut o eroare de rețea sau de bază de date. Vă rugăm să încercați din nou.']);
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
        echo json_encode(['status' => 'ERROR', 'message' => 'ID-ul codului este obligatoriu.']);
        exit;
    }

    // 1. Fetch the current code data to verify it exists and is 'issued'
    $query_params = [
        'select' => 'id,status',
        'id' => 'eq.' . $code_id,
        'limit' => 1
    ];
    // Use the Service Role Key ($supabaseKey)
    $result = supabase_select('codes', $query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($result['httpcode'] !== 200) {
        error_log("Redeem Code Fetch Supabase Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['status' => 'ERROR', 'message' => 'Eroare de bază de date la preluarea codului.']);
        exit;
    }

    $codes = json_decode($result['response'], true);

    if (empty($codes)) {
        http_response_code(404);
        echo json_encode(['status' => 'ERROR', 'message' => 'Codul nu a fost găsit.']);
        exit;
    }

    $code_data = $codes[0];
    $current_status = $code_data['status'];

    $update_data = [];

    // 2. Check current status and prepare update data
    if ($current_status === 'issued') {
        $update_data['status'] = 'used';
        $update_data['used_at'] = gmdate('c'); // ISO 8601 format (UTC)
    } elseif ($current_status === 'used' && ($reported_revenue_ron === null || !is_numeric($reported_revenue_ron))) {
        // If it's already used and no new valid revenue is being reported, exit successfully.
        // If reported_revenue_ron IS a number, we proceed to update it in the next block.
        http_response_code(200);
        echo json_encode(['status' => 'SUCCESS', 'message' => 'Codul este deja utilizat. Nicio modificare necesară.']);
        exit;
    } elseif ($current_status !== 'used' && $current_status !== 'issued') {
        http_response_code(400);
        echo json_encode(['status' => 'ERROR', 'message' => 'Codul are un status invalid pentru răscumpărare.']);
        exit;
    }
    
    // CRUCIAL FIX: Ensure reported_revenue_ron is only set if it's a valid number.
    if ($reported_revenue_ron !== null && is_numeric($reported_revenue_ron)) {
        // Cast to float to ensure correct numeric type for DB insertion
        $update_data['reported_revenue_ron'] = (float)$reported_revenue_ron;
    }

    // If we're here and $update_data is empty, something is wrong with the flow.
    if (empty($update_data)) {
        http_response_code(400);
        echo json_encode(['status' => 'ERROR', 'message' => 'Nicio dată validă de actualizat furnizată.']);
        exit;
    }

    // 3. Apply updates (using Service Role Key)
    $update_query_params = ['id' => 'eq.' . $code_id];
    $update_result = supabase_update('codes', $update_data, $update_query_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($update_result['httpcode'] >= 200 && $update_result['httpcode'] < 300) {
        http_response_code(200);
        echo json_encode(['status' => 'SUCCESS', 'message' => 'Cod actualizat cu succes.']);
    } else {
        error_log("Redeem Code Update Supabase Error: HTTP {$update_result['httpcode']}. Response: {$update_result['response']}");
        http_response_code(500);
        echo json_encode(['status' => 'ERROR', 'message' => 'Eroare la actualizarea statusului codului.']);
    }
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
        echo json_encode(['error' => 'Datele de abonare sunt incomplete.']);
        exit;
    }

    // Prepare data for Supabase insert
    $insert_data = [
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'expiration_time' => $expiration_time ? date('c', $expiration_time / 1000) : null // Convert ms to ISO 8601
    ];

    // Use the Anon Key for public insert, RLS policy handles permissions
    $result = supabase_insert('push_subscriptions', $insert_data, $supabaseUrl, $supabaseAnonKey, null); // Pass null for user JWT

    if ($result['httpcode'] === 201) {
        http_response_code(200);
        echo json_encode(['message' => 'Subscription saved to Supabase.']);
    } elseif ($result['httpcode'] === 409) { // Conflict, subscription already exists (due to UNIQUE constraints)
        http_response_code(200);
        echo json_encode(['message' => 'Subscription already exists in Supabase.']);
    } else {
        error_log("Supabase Subscription Save Error: HTTP {$result['httpcode']}. Response: {$result['response']}");
        http_response_code(500);
        echo json_encode(['error' => 'A apărut o eroare la salvarea abonamentului.']);
    }
    exit;
}

// If no route is matched, return a 404 error
http_response_code(404);
echo json_encode(['error' => 'Pagină negăsită.']);

?>