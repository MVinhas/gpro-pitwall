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

    // ========== ADMIN ==========
    $router->add('GET',  '/admin/users', 'controller.admin_users', 'index');
    $router->add('POST', '/admin/users/toggle_admin', 'controller.admin_users', 'toggleAdmin');
    $router->add('POST', '/admin/users/resend_verification', 'controller.admin_users', 'resendVerification');
    $router->add('POST', '/admin/users/delete', 'controller.admin_users', 'delete');

    // ========== CALCULATIONS & ANALYSIS ==========
    // Baseline management
    $router->add('POST', '/add_pilot', 'controller.baseline', 'addPilot');
    $router->add('POST', '/update_season', 'controller.baseline', 'updateSeason');
    $router->add('POST', '/undo_last_pilot', 'controller.baseline', 'undoLastPilot');
    $router->add('POST', '/clear_stats', 'controller.baseline', 'clearStats');

    // Strategy, wear, and risk calculations
    $router->add('POST', '/calculate_strategy', 'controller.strategy', 'calculate');
    $router->add('POST', '/strategy_fragment',  'controller.strategy', 'fragment');
    $router->add('POST', '/calculate_wear', 'controller.car_wear', 'handle');
    $router->add('POST', '/car_wear_fragment', 'controller.car_wear', 'fragment');

    // Recruitment and training
    $router->add('POST', '/analyze_recruitment', 'controller.recruitment', 'analyze');
    $router->add('POST', '/calculate_training', 'controller.training', 'calculate');

    // ========== API ==========
    $router->add('POST', '/api/warmup', 'controller.api_warmup', 'warmup');

    // ========== HEALTH ==========
    $router->add('GET', '/healthz', 'controller.health', 'check');
};
