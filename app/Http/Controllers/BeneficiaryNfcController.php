<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Support\NfcCardUid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BeneficiaryNfcController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        Gate::authorize('create', Beneficiary::class);

        $request->validate(['nfc_uid' => ['required', 'string', 'max:64']]);
        $request->merge(['nfc_uid' => NfcCardUid::normalize($request->input('nfc_uid'))]);
        $validated = $request->validate(['nfc_uid' => NfcCardUid::rules()], NfcCardUid::messages());

        return response()->json([
            'status' => 'ready', 'message' => 'Card ready to link', 'nfc_uid' => $validated['nfc_uid'],
        ]);
    }

    public function update(Request $request, Beneficiary $beneficiary): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $beneficiary);

        $request->validate([
            'nfc_uid' => ['required', 'string', 'max:64'],
        ]);

        // Preserve leading zeros; normalize hexadecimal UID separators and case.
        $uid = NfcCardUid::normalize($request->input('nfc_uid'));

        $request->merge(['nfc_uid' => $uid]);

        $validated = $request->validate([
            'nfc_uid' => NfcCardUid::rules($beneficiary),
            'check_only' => ['sometimes', 'boolean'],
            'confirm_replace' => ['sometimes', 'boolean'],
            'expected_nfc_uid' => ['nullable', 'string', 'max:64'],
        ], NfcCardUid::messages());

        try {
            $result = DB::transaction(function () use ($beneficiary, $validated) {
                // Read the current card under a lock so a stale confirmation cannot replace a newer card.
                $record = Beneficiary::query()->lockForUpdate()->findOrFail($beneficiary->getKey());
                Gate::authorize('update', $record);

                $uid = $validated['nfc_uid'];

                if ($record->nfc_uid === $uid) {
                    return ['status' => 'already_registered', 'message' => 'Already registered', 'nfc_uid' => $uid];
                }

                if ($record->nfc_uid !== null
                    && (! ($validated['confirm_replace'] ?? false)
                        || ($validated['expected_nfc_uid'] ?? null) !== $record->nfc_uid)) {
                    return [
                        'status' => 'replacement_required',
                        'message' => 'This beneficiary already has a different card. Replace it?',
                        'nfc_uid' => $record->nfc_uid,
                    ];
                }

                if ($validated['check_only'] ?? false) {
                    return ['status' => 'ready', 'message' => 'Card is available to register.', 'nfc_uid' => $uid];
                }

                $record->nfc_uid = $uid;
                abort_unless($record->save(), 500, 'Unable to save the NFC card.');

                return ['status' => 'registered', 'message' => 'NFC card registered successfully', 'nfc_uid' => $uid];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Another request may have claimed this card after validation.
            // Only translate card conflicts; unrelated database failures must still surface.
            if (! NfcCardUid::isConflict($exception)
                && ! Beneficiary::withTrashed()->where('nfc_uid', $uid)
                    ->whereKeyNot($beneficiary->getKey())->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'nfc_uid' => NfcCardUid::OWNERSHIP_ERROR,
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json($result, $result['status'] === 'replacement_required' ? 409 : 200);
        }

        if ($result['status'] === 'replacement_required') {
            throw ValidationException::withMessages(['nfc_uid' => $result['message']]);
        }

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', $result['message']);
    }

    public function destroy(Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('update', $beneficiary);

        $beneficiary->nfc_uid = null;
        $beneficiary->save();

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'NFC card unlinked successfully.');
    }
}
