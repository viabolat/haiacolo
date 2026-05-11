<?php

function ha_dispatch_routes(array $routes, $method, $route, $not_found_message = 'Page not found.') {
    if (isset($routes[$method][$route])) {
        call_user_func($routes[$method][$route]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => $not_found_message]);
}

