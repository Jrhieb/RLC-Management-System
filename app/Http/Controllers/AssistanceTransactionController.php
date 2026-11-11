<?php

namespace App\Http\Controllers;

use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AssistanceTransactionController extends Controller
{
    public function create(Beneficiary $beneficiary): View
    {
        Gate::authorize('update', $beneficiary);

        abort_unless(
            $beneficiary->status === 'active',
            403,
            'Assistance releases can only be recorded for active beneficiaries.'
        );

        return view('assistance.create', compact('beneficiary'));
    }

    public function store(Request $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('update', $beneficiary);

        abort_unless(
            $beneficiary->status === 'active',
            403,
            'Assistance releases can only be recorded for active beneficiaries.'
        );

        $validated = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'category' => [
                'required',
                Rule::in(['education', 'healthcare', 'gifts', 'other']),
            ],
            'item_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'integer', 'min:1', 'max:100000'],
            'released_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // rlc-assistance-idempotency
        $releasedAt = Carbon::createFromFormat(
            '!Y-m-d\TH:i',
            $validated['released_at'],
            'Asia/Manila'
        )->utc();

        $data = [
            'beneficiary_id' => $beneficiary->id,
            'category' => $validated['category'],
            'item_name' => $validated['item_name'],
            'quantity' => (int) $validated['quantity'],
            'released_at' => $releasedAt,
            'notes' => $validated['notes'] ?? null,
            'recorded_by' => $request->user()->id,
        ];

        $token = ['submission_token' => strtolower($validated['submission_token'])];
        $transaction = AssistanceTransaction::query()->where($token)->first();

        if (! $transaction) {
            try {
                $transaction = AssistanceTransaction::query()->create($token + $data);
            } catch (UniqueConstraintViolationException $exception) {
                // Only a submission-token conflict can be a replay.
                if ($exception->columns !== ['submission_token']
                    && $exception->index !== 'assistance_transactions_submission_token_unique') {
                    throw $exception;
                }
                $transaction = AssistanceTransaction::query()->where($token)->first() ?? throw $exception;
            }
        }

        abort_unless($transaction->exists, 500, 'Assistance release could not be saved. Please try again.');

        if (! $transaction->wasRecentlyCreated) {
            foreach ($data as $field => $value) {
                $matches = $field === 'released_at'
                    ? $transaction->released_at->equalTo($releasedAt)
                    : (string) $transaction->getAttribute($field) === (string) $value;

                if (! $matches) {
                    throw ValidationException::withMessages([
                        'submission_token' => 'This form was already used for a different release. Reload the profile and open a new form.',
                    ]);
                }
            }
        }

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', $transaction->wasRecentlyCreated
                ? 'Assistance release recorded successfully.'
                : 'This release was already saved. No duplicate record was created.');
    }
}
