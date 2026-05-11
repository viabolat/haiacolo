<?php

function ha_route_public_config() {
    global $supabaseUrl, $supabaseAnonKey;

    $config = [
        'supabaseUrl' => $supabaseUrl,
        'supabaseAnonKey' => $supabaseAnonKey
    ];
    http_response_code(200);
    echo json_encode($config);
    exit;
}

