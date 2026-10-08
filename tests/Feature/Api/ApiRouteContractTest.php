<?php

use App\Enums\WebhookEventType;
use App\Http\Middleware\Api\AuthenticateApiCredential;
use App\Http\Middleware\Api\EnsureApiRequest;
use App\Http\Middleware\Api\LogApiRequest;
use App\Http\Middleware\Api\RequireIdempotencyKey;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * SaaS-6: every API route is authenticated, rate limited, scoped and stateless; every mutation is
 * idempotent; no route names a tenant; and docs/api/openapi-v1.json describes exactly these routes.
 */

/**
 * @return list<RoutingRoute>
 */
function apiV1Routes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/')));
}

function apiRouteScope(RoutingRoute $route): ?string
{
    $scopes = array_values(array_filter($route->gatherMiddleware(), fn (string $middleware): bool => str_starts_with($middleware, 'api.scope:')));

    return $scopes === [] ? null : substr($scopes[0], strlen('api.scope:'));
}

test('every credential route is authenticated, throttled, scoped and stateless; every mutation needs an Idempotency-Key', function (): void {
    $routes = apiV1Routes();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();
        $name = implode('|', $route->methods()).' '.$route->uri();

        expect(in_array('web', $middleware, true))->toBeFalse("{$name} must not carry sessions, cookies or CSRF")
            ->and($route->parameterNames())->not->toContain('tenant', 'tenantId', 'tenant_id');

        if ($route->uri() === 'api/v1/hooks/{publicKey}') {
            expect($middleware)->toBe([LogApiRequest::class, EnsureApiRequest::class.':hook', 'throttle:api-hooks']);

            continue;
        }

        // SaaS-7: the request log first, so refusals by the later middleware are measured too.
        expect(array_slice($middleware, 0, 4))->toBe([LogApiRequest::class, EnsureApiRequest::class.':api', AuthenticateApiCredential::class, 'throttle:api'], "{$name}: request log, body checks, authentication, then the rate limit");

        if ($route->uri() !== 'api/v1/me') {
            expect(apiRouteScope($route))->not->toBeNull("{$name} has no scope");
        }

        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            expect($middleware)->toContain(RequireIdempotencyKey::class);
        }
    }
});

test('the OpenAPI description covers exactly the API routes, with their scopes', function (): void {
    $document = json_decode((string) file_get_contents(base_path('docs/api/openapi-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $documented = [];

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $documented[strtoupper($method).' '.$path] = $operation['x-scope'] ?? null;
        }
    }

    $routes = [];

    foreach (apiV1Routes() as $route) {
        $method = collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->sole();
        $routes[$method.' '.substr($route->uri(), strlen('api/v1'))] = apiRouteScope($route);
    }

    ksort($documented);
    ksort($routes);

    expect($document['openapi'])->toBe('3.1.0')
        ->and($documented)->toBe($routes)
        ->and(array_keys($document['webhooks']))->toEqualCanonicalizing(array_map(fn (WebhookEventType $type): string => $type->value, WebhookEventType::cases()));
});
