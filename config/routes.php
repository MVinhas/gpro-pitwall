<?php

use App\Http\Router;

return function (Router $router): void {

    // ========== AUTHENTICATION ==========
    // Display pages
    $router->add('GET', '/login', 'controller.auth', 'showLogin');
    $router->add('GET', '/register', 'controller.auth', 'showRegister');
    $router->add('GET', '/verify', 'controller.auth', 'showVerify');
    $router->add('GET', '/reauth', 'controller.auth', 'showReauth');

    // Handle authentication requests
    $router->add('POST', '/login_request', 'controller.auth', 'handleLoginRequest');
    $router->add('POST', '/register_request', 'controller.auth', 'handleRegister');
    $router->add('POST', '/login_verify', 'controller.auth', 'handleVerify');
    $router->add('POST', '/resend_code', 'controller.auth', 'resendCode');
    $router->add('POST', '/reauth_verify', 'controller.auth', 'handleReauth');
    $router->add('POST', '/logout', 'controller.auth', 'logout');

    // ========== PUBLIC PAGES ==========
    // '/' is the landing page for visitors and, for a signed-in manager, a
    // redirect to the default screen. Every screen below has a real path;
    // App\Http\Sections owns the name <-> path map, and a legacy
    // '/?main_tab=…' link is redirected to the matching path.
    $router->add('GET', '/', 'controller.page', 'index');

    foreach (App\Http\Sections::all() as $sectionPath) {
        // The Debrief has its own controller and is registered below.
        if ($sectionPath !== '/debrief') {
            $router->add('GET', $sectionPath, 'controller.page', 'index');
        }
    }

    // ========== CONTACT (logged-in only) ==========
    $router->add('GET', '/contact', 'controller.contact', 'show');
    $router->add('POST', '/contact', 'controller.contact', 'submit');

    // ========== CONTROL PANEL ==========
    $router->add('GET', '/control_panel', 'controller.control_panel', 'index');
    $router->add('POST', '/update_token', 'controller.control_panel', 'updateToken');
    $router->add('POST', '/account/delete', 'controller.control_panel', 'deleteAccount');

    // ========== DEBUG ==========
    $router->add('GET', '/debug', 'controller.debug', 'index');
    $router->add('POST', '/debug/flush', 'controller.debug', 'flushCache');

    // ========== DEBRIEF ==========
    $router->add('GET', '/debrief', 'controller.debrief', 'index');
    $router->add('GET', '/debrief/track', 'controller.debrief', 'track');
    $router->add('GET', '/debrief/insights', 'controller.debrief', 'insights');

    // ========== ADMIN ==========
    $router->add('GET',  '/admin/users', 'controller.admin_users', 'index');
    $router->add('POST', '/admin/users/toggle_admin', 'controller.admin_users', 'toggleAdmin');
    $router->add('POST', '/admin/users/send_reminder', 'controller.admin_users', 'sendUsernameReminder');
    $router->add('POST', '/admin/users/rename', 'controller.admin_users', 'rename');
    $router->add('POST', '/admin/users/delete', 'controller.admin_users', 'delete');
    $router->add('POST', '/admin/users/restore', 'controller.admin_users', 'restore');
    $router->add('GET',  '/admin/telemetry', 'controller.admin_telemetry', 'index');

    // ========== CALCULATIONS & ANALYSIS ==========
    // Baseline management
    $router->add('POST', '/add_pilot', 'controller.baseline', 'addPilot');
    $router->add('POST', '/update_season', 'controller.baseline', 'updateSeason');
    $router->add('POST', '/undo_last_pilot', 'controller.baseline', 'undoLastPilot');
    $router->add('POST', '/clear_stats', 'controller.baseline', 'clearStats');

    // Strategy calculations
    $router->add('POST', '/calculate_strategy', 'controller.strategy', 'calculate');
    $router->add('POST', '/strategy_fragment',  'controller.strategy', 'fragment');

    // Recruitment and training
    $router->add('POST', '/analyze_recruitment', 'controller.recruitment', 'analyze');
    $router->add('POST', '/calculate_training', 'controller.training', 'calculate');

    // ========== API ==========
    $router->add('POST', '/api/warmup', 'controller.api_warmup', 'warmup');
    $router->add('POST', '/api/refresh_budget', 'controller.api_warmup', 'refreshBudget');

    // ========== HEALTH ==========
    $router->add('GET', '/healthz', 'controller.health', 'check');
};
