<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, string $controllerKey, string $action): void
    {
        $this->routes[strtoupper($method)][$path] = [
            'controller' => $controllerKey,
            'action' => $action
        ];
    }

    public function dispatch(Request $request, array $container): mixed
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        if (!isset($this->routes[$method][$path])) {
            http_response_code(404);
            echo "404 Not Found: $method $path";
            return null;
        }

        $route = $this->routes[$method][$path];
        $controllerKey = $route['controller'];
        $actionName = $route['action'];

        if (!isset($container[$controllerKey])) {
            throw new RuntimeException("Controller '$controllerKey' not found in container.");
        }

        $controller = $container[$controllerKey];
        if (!method_exists($controller, $actionName)) {
            throw new RuntimeException("Action '$actionName' not found on controller '$controllerKey'.");
        }

        return $controller->$actionName($request);
    }
}
