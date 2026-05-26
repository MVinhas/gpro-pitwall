<?php

use App\Http\Router;

return function (Router $router): void {

    // ========== AUTHENTICATION ==========
    // Display pages
    $router->add('GET', '/login', 'controller.auth', 'showLogin');
    $router->add('GET', '/register', 'controller.auth', 'showRegister');
    $router->add('GET', '/verify', 'controller.auth', 'showVerify');

    // Handle authentication requests
    $router->add('POST', '/login_request', 'controller.auth', 'handleLoginRequest');
    $router->add('POST', '/register_request', 'controller.auth', 'handleRegister');
    $router->add('POST', '/login_verify', 'controller.auth', 'handleVerify');
    $router->add('POST', '/logout', 'controller.auth', 'logout');

    // ========== PUBLIC PAGES ==========
    $router->add('GET', '/', 'controller.page', 'index');

    // ========== CONTROL PANEL ==========
    $router->add('GET', '/control_panel', 'controller.control_panel', 'index');
    $router->add('POST', '/update_token', 'controller.control_panel', 'updateToken');

    // ========== DEBUG ==========
    $router->add('GET', '/debug', 'controller.debug', 'index');
    $router->add('POST', '/debug/flush', 'controller.debug', 'flushCache');

    // ========== CALCULATIONS & ANALYSIS ==========
    // Baseline management
    $router->add('POST', '/add_pilot', 'controller.baseline', 'addPilot');
    $router->add('POST', '/update_season', 'controller.baseline', 'updateSeason');
    $router->add('POST', '/undo_last_pilot', 'controller.baseline', 'undoLastPilot');
    $router->add('POST', '/clear_stats', 'controller.baseline', 'clearStats');

    // Strategy, wear, and risk calculations
    $router->add('POST', '/calculate_strategy', 'controller.strategy', 'handle');
    $router->add('POST', '/calculate_wear', 'controller.car_wear', 'handle');
    $router->add('POST', '/update_track_risks', 'controller.track_risk', 'update');

    // Recruitment and training
    $router->add('POST', '/analyze_recruitment', 'controller.recruitment', 'analyze');
    $router->add('POST', '/calculate_training', 'controller.training', 'handle');
    $router->add('POST', '/import_driver', 'controller.training', 'handle');

    // ========== API ==========
    $router->add('POST', '/api/warmup', 'controller.api_warmup', 'warmup');
};
