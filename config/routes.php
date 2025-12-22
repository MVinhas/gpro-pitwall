<?php

use App\Http\Router;

return function (Router $router): void {

    $router->add('GET', '/login', 'controller.auth', 'showLogin');
    $router->add('GET', '/register', 'controller.auth', 'showRegister');
    $router->add('GET', '/verify', 'controller.auth', 'showVerify');


    $router->add('POST', '/login_request', 'controller.auth', 'handleLoginRequest');
    $router->add('POST', '/register_request', 'controller.auth', 'handleRegister');
    $router->add('POST', '/login_verify', 'controller.auth', 'handleVerify');
    $router->add('POST', '/logout', 'controller.auth', 'logout');

    $router->add('GET', '/control_panel', 'controller.control_panel', 'index');
    $router->add('POST', '/update_token', 'controller.control_panel', 'updateToken');

    $router->add('GET', '/debug', 'controller.debug', 'index');
    $router->add('POST', '/debug/flush', 'controller.debug', 'flushCache');


    $router->add('GET', '/', 'controller.page', 'index');


    $router->add('POST', '/add_pilot', 'controller.baseline', 'addPilot');
    $router->add('POST', '/update_season', 'controller.baseline', 'updateSeason');
    $router->add('POST', '/undo_last_pilot', 'controller.baseline', 'undoLastPilot');
    $router->add('POST', '/clear_stats', 'controller.baseline', 'clearStats');
    $router->add('POST', '/calculate_strategy', 'controller.strategy', 'handle');
    $router->add('POST', '/calculate_wear', 'controller.car_wear', 'handle');
    $router->add('POST', '/update_track_risks', 'controller.track_risk', 'update');
    $router->add('POST', '/analyze_recruitment', 'controller.recruitment', 'analyze');
    $router->add('POST', '/calculate_training', 'controller.training', 'handle');
    $router->add('POST', '/import_driver', 'controller.training', 'handle');

    $router->add('POST', '/api/warmup', 'controller.api_warmup', 'warmup');
};
