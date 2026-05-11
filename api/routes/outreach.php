<?php

function ha_route_send_outreach() {
    global $supabaseUrl, $supabaseAnonKey, $supabaseKey, $resendApiKey;

    $data = ha_read_json_body();
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

        if (ha_validate_email($email)) {
            if (ha_supabase_check_and_record_sent_email($email, $supabaseUrl, $supabaseAnonKey, $supabaseKey)) {
                $skipped_count++;
                continue;
            }

            $subject = "Special opportunity for {$business_name} - Become a HaiAcolo Founding Member";
            $personalized_template = str_replace('[Numele afacerii]', $business_name, $html_template);
            $personalized_template = str_replace('[Numele persoanei de contact]', $contact_name, $personalized_template);
            $personalized_template = str_replace('[Business Name]', $business_name, $personalized_template);
            $personalized_template = str_replace('[Contact Person\'s Name]', $contact_name, $personalized_template);

            $result = ha_send_resend_email('HaiAcolo <noreply@haiacolo.ro>', $email, $subject, $personalized_template, $resendApiKey);
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

