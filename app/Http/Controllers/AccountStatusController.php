<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountStatusController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isCaseworker() || $user->isProjectDirector(), 403);

        if (! $user->requiresAccountReview()) {
            return redirect()->route($user->hasVerifiedEmail() ? 'dashboard' : 'verification.notice');
        }

        return view('account-status', ['account' => $user]);
    }
}
