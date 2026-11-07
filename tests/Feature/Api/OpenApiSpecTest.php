<?php

use App\Http\Middleware\ServePanelOverApi;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/*
 * public/openapi.yaml: the published API reference stays in step with routes/api.php.
 */

/**
 * Each documented `/api/v1` route (the panel bridge is private) as "METHOD /path" => its scope.
 *
 * @return array<string, string|null>
 */
function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'api.v1.') && ! Str::startsWith((string) $route->getName(), ServePanelOverApi::ROUTE_PREFIX))
        ->flatMap(function (RoutingRoute $route): array {
            $scope = collect($route->gatherMiddleware())->first(fn (mixed $middleware): bool => is_string($middleware) && Str::startsWith($middleware, 'api.scope:'));

            return collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->mapWithKeys(fn (string $method): array => [$method.' '.Str::after('/'.$route->uri(), '/api/v1') => $scope ? Str::after($scope, 'api.scope:') : null])
                ->all();
        })
        ->all();
}

/**
 * @return array<string, string|null>
 */
function specOperations(): array
{
    $spec = Yaml::parseFile(public_path('openapi.yaml'));

    return collect($spec['paths'])
        ->flatMap(fn (array $operations, string $path): array => collect($operations)
            ->mapWithKeys(fn (array $operation, string $method): array => [strtoupper($method).' '.$path => $operation['x-scope'] ?? null])
            ->all())
        ->all();
}

test('the spec documents every API route and nothing else', function () {
    expect(array_keys(specOperations()))->toEqualCanonicalizing(array_keys(apiRoutes()));
});

test('each operation names the scope its route requires', function () {
    $routes = apiRoutes();

    expect(collect(specOperations())->intersectByKeys($routes)->sortKeys()->all())->toBe(collect($routes)->sortKeys()->all());
});

test('every schema reference resolves', function () {
    $spec = Yaml::parseFile(public_path('openapi.yaml'));
    preg_match_all("~\\\$ref: '#/components/([a-zA-Z]+)/([a-zA-Z_]+)'~", file_get_contents(public_path('openapi.yaml')), $refs, PREG_SET_ORDER);

    $missing = collect($refs)->reject(fn (array $ref): bool => isset($spec['components'][$ref[1]][$ref[2]]))->map(fn (array $ref): string => "{$ref[1]}/{$ref[2]}")->unique()->values();

    expect($refs)->not->toBeEmpty()
        ->and($missing->all())->toBe([]);
});
