<?php

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Security\Csrf;
use App\Support\RequestContext;

require_once __DIR__ . '/../vendor/autoload.php';

$lifetime = 60 * 60 * 24 * 7;
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    // Trust X-Forwarded-Proto: TLS terminates at the host's proxy, so $_SERVER['HTTPS']
    // is often unset even on HTTPS. Without this the cookie ships without Secure.
    'secure' => RequestContext::isHttps($_SERVER),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();


$container = require_once __DIR__ . '/../bootstrap.php';


$csrf = new Csrf();
$request = Request::createFromGlobals();


$container['twig']->addGlobal('csrf_token', $csrf->getToken());


if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $lifetime)) {
        session_unset();
        session_destroy();
        header("Location: /login?expired=1");
        exit;
    }

    $_SESSION['last_activity'] = time();
}


$router = new Router();
$routes = require_once __DIR__ . '/../config/routes.php';
$routes($router);

$renderError = static function (int $status, ?string $message = null, ?string $reference = null) use ($container): void {
    $copy = [
        403 => ['Access denied', "You don't have permission to view this page."],
        404 => ['Page not found', "The page you're looking for doesn't exist or may have moved."],
        500 => ['Something went wrong', 'An unexpected error occurred on our end. Please try again in a moment.'],
    ];
    [$title, $defaultMessage] = $copy[$status] ?? $copy[500];

    http_response_code($status);
    echo $container['twig']->render('error.twig', [
        'status' => $status,
        'title' => $title,
        'message' => $message ?? $defaultMessage,
        'reference' => $reference,
    ]);
};

try {
    if ($request->getMethod() === 'POST' && !$csrf->validate($request->post('csrf_token'))) {
        throw new HttpException(403, 'Your session or form expired. Go back, reload the page, and try again.');
    }

    $router->dispatch($request, $container);
} catch (HttpException $exception) {
    // Expected, developer-controlled errors (403/404). The message is safe to
    // show — it never carries internal detail.
    $renderError($exception->getStatusCode(), $exception->getMessage() ?: null);
} catch (Throwable $exception) {
    if ($container['settings']['is_dev'] ?? false) {
        throw $exception;
    }

    // Never leak exception detail (paths, SQL, internals) to the client.
    // Log the real cause server-side keyed by a short id the user can quote.
    $errorId = bin2hex(random_bytes(4));
    error_log(sprintf(
        '[error] id=%s %s: %s @ %s:%d',
        $errorId,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
    ));

    $renderError(500, null, $errorId);
}
