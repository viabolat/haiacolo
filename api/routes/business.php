<?php

function ha_route_business_signup() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey, $recaptchaSecretKey, $resendApiKey, $projectRoot;

    $data = ha_read_json_body();

    if (!isset($data['g-recaptcha-response']) || !ha_verify_recaptcha($data['g-recaptcha-response'], $recaptchaSecretKey)) {
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

    if (!ha_validate_email($data['contact_email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address format.']);
        exit;
    }

    if (!ha_validate_phone($data['contact_phone'])) {
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
        'is_welcome_offer' => false
    ];

    $result = ha_supabase_insert('merchants', $businessData, $supabaseUrl, $supabaseAnonKey, $supabaseKey, 'return=representation');

    if ($result['httpcode'] === 201) {
        $inserted_merchants = json_decode($result['response'], true);
        if (!empty($inserted_merchants) && isset($inserted_merchants[0])) {
            ha_notify_merchant_application_edge_function($inserted_merchants[0], $supabaseUrl, $supabaseKey);
        }

        $business_email = $businessData['contact_email'];
        $business_name = $businessData['name'];

        if (!ha_supabase_check_and_record_sent_email($business_email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
            $subject = 'Aplicatia ta HaiAcolo a fost primita!';
            $html_body = file_get_contents($projectRoot . '/templates/business_welcome_email.html');
            $html_body = str_replace('{{business_name}}', $business_name, $html_body);

            $email_result = ha_send_resend_email('HaiAcolo <noreply@haiacolo.ro>', $business_email, $subject, $html_body, $resendApiKey);
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

