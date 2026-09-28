<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Router
 *
 * @package WordpressMigrator\Helpers
 *
 * Handles routing for standalone execution.
 */
class Router
{
    private static array $routes = [];

    /**
     * Registers a route.
     *
     * @param string $namespace
     * @param string $route
     * @param array  $config
     */
    public static function registerRoute(string $namespace, string $route, array $config): void
    {
        $path = '/' . trim($namespace, '/') . '/' . trim($route, '/');

        self::$routes[$path] = [
            'methods' => $config['methods'] ?? 'GET',
            'callback' => $config['callback'] ?? null,
            'permission_callback' => $config['permission_callback'] ?? '__return_true',
        ];
    }

    /**
     * Handles the incoming request.
     *
     * @return bool
     */
    public static function handleRequest(): bool
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];

        // Check if this is a REST API request
        if (strpos($requestUri, '/wp-json/') === false) {
            return false;
        }

        // Extract the path after /wp-json/
        $path = str_replace('/wp-json', '', $requestUri);

        foreach (self::$routes as $routePath => $config) {
            if ($path === $routePath) {
                // Check method
                $allowedMethods = is_array($config['methods']) ? $config['methods'] : [$config['methods']];

                if (!in_array($requestMethod, $allowedMethods)) {
                    Response::error('method_not_allowed', 'Method not allowed', ['status' => 405]);
                    return true;
                }

                // Check permissions
                $permissionCallback = $config['permission_callback'];
                if ($permissionCallback !== '__return_true') {
                    if (is_callable($permissionCallback) && !call_user_func($permissionCallback)) {
                        Response::error('forbidden', 'Forbidden', ['status' => 403]);
                        return true;
                    }
                }

                // Call the callback
                if (is_callable($config['callback'])) {
                    $request = new Request();
                    $result = call_user_func($config['callback'], $request);

                    if (is_array($result)) {
                        Response::success($result);
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Gets all registered routes.
     *
     * @return array
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }
}
