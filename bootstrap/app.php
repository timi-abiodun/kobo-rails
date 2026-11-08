<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'demo.only' => \App\Http\Middleware\DemoOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontFlash(['bvn', 'nin']);

        $exceptions->render(fn (\App\Identity\Exceptions\IdentityProviderUnavailable $e) => response()->json([
            'error'   => 'identity_provider_unavailable',
            'message' => 'We could not verify your identity right now. Please try again shortly.',
        ], 503)->header('Retry-After', '30'));
    })->create();
