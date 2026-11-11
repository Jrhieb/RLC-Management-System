<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse as DefaultPasskeyLoginResponse;

class ApprovalAwareResponse implements LoginResponse, PasskeyLoginResponse, TwoFactorLoginResponse, VerifyEmailResponse
{
    public function __construct(private Responsable $defaultResponse) {}

    public function toResponse($request)
    {
        if (! $request->user()?->requiresAccountReview()) {
            return $this->defaultResponse->toResponse($request);
        }

        $request->session()->forget('url.intended');

        if ($request->wantsJson()) {
            return $this->defaultResponse instanceof DefaultPasskeyLoginResponse
                ? response()->json(['redirect' => route('account.status')])
                : $this->defaultResponse->toResponse($request);
        }

        return redirect()->route('account.status');
    }
}
