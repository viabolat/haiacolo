<?php

function ha_route_user_signup() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey, $recaptchaSecretKey, $resendApiKey, $appBaseUrl, $projectRoot;

    $data = ha_read_json_body();

    if (!isset($data['g-recaptcha-response']) || !ha_verify_recaptcha($data['g-recaptcha-response'], $recaptchaSecretKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'reCAPTCHA verification failed.']);
        exit;
    }

    $merchant_params = ['is_welcome_offer' => 'eq.true', 'select' => 'id,name,offer_description', 'limit' => 1];
    $merchant_result = ha_supabase_select('merchants', $merchant_params, $supabaseUrl, $supabaseAnonKey, $supabaseKey);

    if ($merchant_result['httpcode'] !== 200 || empty(json_decode($merchant_result['response'], true))) {
        error_log('FATAL: No merchant is configured as the welcome offer (is_welcome_offer = true).');
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
    if (!ha_validate_phone($phone_number)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid phone number format.']);
        exit;
    }

    $zones = $data['zone'];
    $customer_email = null;
    if (!empty($data['email']) && ha_validate_email($data['email'])) {
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

    $customer_id = null;
    $upsert_prefer_header = 'return=representation,resolution=merge-duplicates';

    $upsert_url = $supabaseUrl . '/rest/v1/customers?on_conflict=phone_number';

    $upsert_headers = [
        'Content-Type: application/json',
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $supabaseKey,
        'Prefer: ' . $upsert_prefer_header
    ];

    $upsert_result = ha_execute_curl_request($upsert_url, 'POST', $upsert_headers, json_encode($customer_data, JSON_UNESCAPED_UNICODE));

    if ($upsert_result['httpcode'] === 200 || $upsert_result['httpcode'] === 201) {
        $upserted_data = json_decode($upsert_result['response'], true);
        if (!empty($upserted_data) && isset($upserted_data[0]['id'])) {
            $customer_id = $upserted_data[0]['id'];
        } else {
            error_log('User Signup - Upsert succeeded but no customer ID was returned. Response: ' . $upsert_result['response']);
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

    if ($customer_id) {
        $customer_notification_data = [
            'id' => $customer_id,
            'full_name' => $customer_data['full_name'] ?? null,
            'phone_number' => $customer_data['phone_number'],
            'zone' => $customer_data['zone'],
            'interests' => $customer_data['interests'],
            'email' => $customer_email
        ];

        ha_notify_new_customer_edge_function($customer_notification_data, $supabaseUrl, $supabaseKey);

        $code_string = 'URANIA-' . substr(str_shuffle(str_repeat('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', 6)), 0, 6);

        $code_data = [
            'customer_id' => $customer_id,
            'merchant_id' => $initial_offer_merchant_id,
            'code_string' => $code_string,
            'status' => 'issued'
        ];

        $code_insert_result = ha_supabase_insert('codes', $code_data, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=minimal');

        if ($code_insert_result['httpcode'] !== 201) {
            error_log('FATAL: Could not create initial offer code for customer ' . $customer_id . '. DB Response: ' . $code_insert_result['response']);
            http_response_code(500);
            echo json_encode([
                'error' => 'Registration successful, but an error occurred while generating the offer code.',
                'db_error' => json_decode($code_insert_result['response'])
            ]);
            exit;
        }

        if ($customer_email) {
            if (!ha_supabase_check_and_record_sent_email($customer_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
                $subject = 'Bun venit la HaiAcolo! Oferta ta de la ' . $offer_partner_name;
                $verification_url = $appBaseUrl . '/offer?code=' . $code_string;
                $html_body = file_get_contents($projectRoot . '/templates/welcome_email.html');
                $html_body = str_replace('{{offer_partner_name}}', $offer_partner_name, $html_body);
                $html_body = str_replace('{{offer_description}}', $offer_description, $html_body);
                $html_body = str_replace('{{qr_code_url}}', $verification_url, $html_body);

                $email_result = ha_send_resend_email('HaiAcolo <noreply@haiacolo.ro>', $customer_email, $subject, $html_body, $resendApiKey);
                if ($email_result['httpcode'] < 200 || $email_result['httpcode'] >= 300) {
                    error_log("Failed to send welcome email to {$customer_email}. Resend HTTP Code: {$email_result['httpcode']}. Response: {$email_result['response']}");
                }
            }
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'User successfully registered.',
            'qr_data' => 'haiacolo://verify?code=' . $code_string,
            'code_string' => $code_string
        ]);

    } else {
        error_log('User Signup - DB Operation Failed. Could not retrieve customer ID.');
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred while saving data.']);
    }
    exit;
}

