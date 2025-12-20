<?php

use App\Http\Request;
use App\Http\Router;
use App\Security\Csrf;

// Session Setup (Runtime Environment)
$lifetime = 60 * 60 * 24 * 7; // 7 days
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Bootstrap Application
$container = require_once __DIR__ . '/../bootstrap.php';

// Security & Request
$csrf = new Csrf();
$request = Request::createFromGlobals();

// Inject CSRF token globally
$container['twig']->addGlobal('csrf_token', $csrf->getToken());

// Session Activity Check (Auto-Logout)
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $lifetime)) {
        session_unset();
        session_destroy();
        header("Location: /login?expired=1");
        exit;
    }

    $_SESSION['last_activity'] = time();
}

// Global CSRF Check
if ($request->getMethod() === 'POST') {
    $submittedToken = $request->post('csrf_token');
    if (!$csrf->validate($submittedToken)) {
        http_response_code(403);
        die('403 Forbidden: Invalid CSRF Token');
    }
}

// Dispatch
$router = new Router();
$routes = require_once __DIR__ . '/../config/routes.php';
$routes($router);

try {
    $router->dispatch($request, $container);
} catch (Exception $exception) {
    if ($container['settings']['is_dev'] ?? false) {
        throw $exception;
    }

    http_response_code(500);
    echo "Application Error: " . $exception->getMessage();
}
