<?php

use App\Http\Middleware\EnsureAccountApproval;
use App\Http\Middleware\ProtectRegistrationResponse;
use App\Http\Middleware\RegistrationSafeSession;
use App\Http\Middleware\RequirePasswordConfirmation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );

        $middleware->web(
            prepend: [ProtectRegistrationResponse::class],
            append: [EnsureAccountApproval::class],
            replace: [StartSession::class => RegistrationSafeSession::class],
        );
        $middleware->alias(['password.confirm' => RequirePasswordConfirmation::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code']);
        // Routing pipelines render exceptions before outer middleware can catch them.
        // Registration failures must never report a bearer URL, mail body, or password.
        $exceptions->report(function (Throwable $exception): ?bool {
            if (! request()->is('register', 'register/*')) {
                return null;
            }

            Log::error('Email registration failed.', ['exception' => $exception::class]);

            return false;
        });

        $exceptions->render(function (Throwable $exception, Request $request): ?Response {
            if ($exception instanceof ValidationException && $request->routeIs('login.store')) {
                $exception->redirectTo(route('login'));
            }

            if (! $request->is('register', 'register/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                $exception->redirectTo(route(match (true) {
                    $request->routeIs('registration.complete.store') => 'registration.complete',
                    $request->routeIs('registration.code.verify') => 'registration.code',
                    default => 'register',
                }));

                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $message = match ($status) {
                429 => 'Too many attempts. Please try again later.',
                503 => 'Registration is temporarily unavailable. Please try again later.',
                default => 'The registration request could not be processed.',
            };

            return response($message, $status, array_merge(
                $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
                ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'],
            ));
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
