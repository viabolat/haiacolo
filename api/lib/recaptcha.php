<?php

function ha_verify_recaptcha($token, $secretKey) {
    $debug = [];

    $debug['token_received'] = !empty($token);
    $debug['token_length'] = $token ? strlen($token) : 0;

    if (empty($token)) {
        $debug['error'] = 'No token provided';
        error_log('reCAPTCHA Debug: ' . json_encode($debug));
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

    error_log('reCAPTCHA Debug: ' . json_encode($debug));

    if (!isset($result['success']) || !$result['success']) {
        if (json_encode($debug)) {
            $GLOBALS['recaptcha_debug'] = $debug;
        }
        return false;
    }

    return true;
}

