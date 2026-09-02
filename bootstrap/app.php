<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // The API error contract (DOCUMENT 10.1): a stable, honest JSON
        // shape. Never leak exception classes or stack traces; always
        // point the caller at the single source of truth for the surface.
        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return new JsonResponse([
                'error' => [
                    'status' => 422,
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                    'docs' => '/api/v1/openapi.json',
                ],
            ], 422);
        });

        $exceptions->render(function (HttpException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match ($status) {
                404 => 'This endpoint does not exist. The published OpenAPI document is the '
                    .'single source of truth for the API surface; if you expected a posting '
                    .'endpoint here, see its x-absent-operations section for the honest '
                    .'reason it is not exposed.',
                405 => 'This endpoint is read-only ('.$request->getMethod().' is not supported). '
                    .'The public surface exposes no write operations; see x-absent-operations '
                    .'in the OpenAPI document for why.',
                429 => 'Rate limit exceeded (throttle:60,1). Retry after the interval in the '
                    .'Retry-After header.',
                default => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
            };

            return new JsonResponse([
                'error' => [
                    'status' => $status,
                    'message' => $message,
                    'docs' => '/api/v1/openapi.json',
                ],
            ], $status, $e->getHeaders());
        });
    })->create();
