<?php

use App\Http\Middleware\SearchEngineVisibility;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $configuredHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_HOSTS', '')),
        )));
        $applicationHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
        $trustedHosts = array_values(array_unique(array_filter([
            ...$configuredHosts,
            $applicationHost,
            'localhost',
            '127.0.0.1',
            '::1',
        ])));

        $middleware->trustHosts(
            at: array_map(
                fn (string $host): string => '^'.preg_quote($host, '/').'$',
                $trustedHosts,
            ),
            subdomains: false,
        );
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '127.0.0.1'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append([
            SecurityHeaders::class,
            SearchEngineVisibility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
