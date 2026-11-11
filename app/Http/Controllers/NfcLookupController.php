<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Support\NfcCardUid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NfcLookupController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Beneficiary::class);

        return view('nfc.lookup');
    }

    public function lookup(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $request->validate([
            'nfc_uid' => ['required', 'string', 'max:64'],
        ]);

        $request->merge(['nfc_uid' => NfcCardUid::normalize($request->input('nfc_uid'))]);

        $validated = $request->validate([
            'nfc_uid' => NfcCardUid::formatRules(),
        ], NfcCardUid::messages());

        $beneficiary = Beneficiary::query()
            ->visibleTo($request->user())
            ->where('nfc_uid', $validated['nfc_uid'])
            ->where('status', 'active')
            ->first();

        if (! $beneficiary) {
            throw ValidationException::withMessages([
                'nfc_uid' => 'No active beneficiary is linked to this card UID.',
            ]);
        }

        Gate::authorize('view', $beneficiary);

        return redirect()->route('beneficiaries.show', $beneficiary);
    }
}
