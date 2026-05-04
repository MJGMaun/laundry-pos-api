<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ModelNotFoundException is converted to NotFoundHttpException before render() fires,
        // so we catch NotFoundHttpException and inspect getPrevious() to get the model name.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $previous = $e->getPrevious();
            if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $model = class_basename($previous->getModel());
                return response()->json(['message' => "{$model} not found."], 404);
            }
            return response()->json(['message' => 'Not found.'], 404);
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $_, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $_, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        });
    })->create();
