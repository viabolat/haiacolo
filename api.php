<?php

require '/home1/rcfzzwte/secrets.php';

require __DIR__ . '/api/lib/bootstrap.php';
require __DIR__ . '/api/lib/http.php';
require __DIR__ . '/api/lib/router.php';
require __DIR__ . '/api/lib/validation.php';
require __DIR__ . '/api/lib/recaptcha.php';
require __DIR__ . '/api/lib/supabase.php';
require __DIR__ . '/api/lib/deals.php';

require __DIR__ . '/api/routes/config.php';
require __DIR__ . '/api/routes/user.php';
require __DIR__ . '/api/routes/business.php';
require __DIR__ . '/api/routes/merchants.php';
require __DIR__ . '/api/routes/redemptions.php';
require __DIR__ . '/api/routes/outreach.php';
require __DIR__ . '/api/routes/push.php';

ha_require_secrets(['supabaseUrl', 'supabaseAnonKey', 'supabaseKey', 'recaptchaSecretKey', 'resendApiKey']);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? $_GET['route'] : '';

$routes = [
    'GET' => [
        '/api/config' => 'ha_route_public_config',
        '/api/merchants' => 'ha_route_merchants',
        '/api/merchant-history' => 'ha_route_merchant_history',
        '/api/process-redemption' => 'ha_route_process_redemption',
        '/api/check-code' => 'ha_route_check_code',
        '/api/get-sender-config' => 'ha_route_public_config'
    ],
    'POST' => [
        '/api/user-signup' => 'ha_route_user_signup',
        '/api/business-signup' => 'ha_route_business_signup',
        '/api/update-redemptions' => 'ha_route_update_redemptions',
        '/api/send-outreach' => 'ha_route_send_outreach',
        '/api/redeem-code' => 'ha_route_redeem_code',
        '/api/save-push-subscription' => 'ha_route_save_push_subscription'
    ]
];

ha_dispatch_routes($routes, $method, $route);