<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordConfirmation
{
    public function __construct(private RequirePassword $confirmation) {}

    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, string|int|null $passwordTimeoutSeconds = null): Response
    {
        $confirmed = false;
        $response = $this->confirmation->handle($request, function ($request) use ($next, &$confirmed) {
            $confirmed = true;

            return $next($request);
        }, $redirectToRoute, $passwordTimeoutSeconds);

        // Livewire's persistent pipeline stops redirects but ignores JSON responses.
        // Preserve Fortify's response and stop the action when confirmation expires.
        if (! $confirmed && $request->hasHeader('X-Livewire')) {
            throw new HttpResponseException($response);
        }

        return $response;
    }
}
