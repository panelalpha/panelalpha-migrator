<?php

declare(strict_types=1);

/** Loaded only in RouteProviderTest's separate PHPUnit processes. */
function register_rest_route(string $namespace, string $route, array $arguments): void
{
    $GLOBALS['route_provider_registered_routes'][$namespace . $route] = $arguments;
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && ($GLOBALS['route_provider_admin'] ?? false);
}
