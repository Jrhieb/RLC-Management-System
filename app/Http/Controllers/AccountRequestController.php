<?php

namespace App\Http\Controllers;

use App\Actions\ReviewCaseworkerAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('reviewAccountRequests', User::class);
        $status = $request->query('status', 'pending');
        $status = in_array($status, ['pending', 'approved', 'rejected', 'all'], true) ? $status : 'pending';
        $accounts = User::query()->where('role', 'caseworker')
            ->when($status !== 'all', fn ($query) => $query->where('approval_status', $status))
            ->orderByRaw("CASE WHEN approval_status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15, ['id', 'name', 'email', 'created_at', 'email_verified_at', 'approval_status',
                'approval_reviewed_at', 'rejection_reason'])->withQueryString();

        return view('account-requests.index', compact('accounts', 'status'));
    }

    public function review(Request $request, User $user, ReviewCaseworkerAccount $review): RedirectResponse|JsonResponse
    {
        Gate::authorize('reviewAccountRequests', User::class);
        $returnQuery = [];
        if (in_array($request->query('status'), ['pending', 'approved', 'rejected', 'all'], true)) {
            $returnQuery['status'] = $request->query('status');
        }
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page !== false) {
            $returnQuery['page'] = $page;
        }
        $returnUrl = route('account-requests.index', $returnQuery);

        try {
            $reviewed = $review->execute($request->user(), $user, $request->only('decision', 'confirmed', 'rejection_reason'));
        } catch (ValidationException $exception) {
            $request->session()->flash('review_account_id', $user->id);
            throw $exception->redirectTo($returnUrl);
        }

        if (! $reviewed) {
            $message = 'This account has already been reviewed or is no longer eligible. Refresh Account Requests.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : redirect()->to($returnUrl)->withErrors(['review' => $message]);
        }

        $message = $request->input('decision') === 'approve' ? 'Caseworker account approved.' : 'Caseworker account rejected.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->to($returnUrl)->with('status', $message);
    }
}
