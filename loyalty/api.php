<?php

require __DIR__ . '/../api/lib/secrets_loader.php';

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/http.php';
require __DIR__ . '/../api/lib/router.php';
require __DIR__ . '/../api/lib/supabase.php';

require __DIR__ . '/../api/routes/loyalty.php';

ha_require_secrets(['supabaseUrl', 'supabaseAnonKey', 'supabaseKey']);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? $_GET['route'] : '';

$routes = [
    'GET' => [
        '/api/config' => 'ha_route_loyalty_config',
        '/api/loyalty-program' => 'ha_route_loyalty_program',
        '/api/loyalty-cards' => 'ha_route_loyalty_cards',
        '/api/loyalty-card' => 'ha_route_loyalty_card'
    ],
    'POST' => [
        '/api/join-loyalty' => 'ha_route_join_loyalty',
        '/api/punch-card' => 'ha_route_punch_card',
        '/api/redeem-loyalty-code' => 'ha_route_redeem_loyalty_code'
    ]
];

ha_dispatch_routes($routes, $method, $route, 'Loyalty API route not found.');

