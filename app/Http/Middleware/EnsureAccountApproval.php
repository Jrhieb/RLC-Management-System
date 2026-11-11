<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountApproval
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresAccountReview()) {
            return $next($request);
        }

        // Livewire replays this middleware against each checksum-verified original
        // component route. Check this request's route, not the global update request.
        if (str_ends_with((string) $request->route()?->getName(), 'livewire.update')) {
            return $next($request);
        }

        if ($request->routeIs('verification.notice') && $user->hasVerifiedEmail()) {
            return redirect()->route('account.status');
        }

        if ($request->routeIs(
            'account.status', 'logout', 'security.edit',
            'account.notifications.index', 'account.notifications.count', 'account.notifications.read', 'account.notifications.read-all',
            'verification.notice', 'verification.verify', 'verification.send',
            'password.confirm', 'password.confirm.store', 'password.confirmation', 'user-password.update',
            'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code',
            'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
            'passkey.confirm-options', 'passkey.confirm', 'passkey.registration-options', 'passkey.store', 'passkey.destroy',
            'well-known.passkeys',
        )) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, 'Your account is awaiting Director approval or has been rejected. Check your account status.');
        }

        return redirect()->route('account.status');
    }
}
