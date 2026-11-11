<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BeneficiaryCaseworkerAssignmentController extends Controller
{
    public function __invoke(Request $request, AccountNotificationService $notifications): RedirectResponse
    {
        Gate::authorize('assignCaseworker', Beneficiary::class);

        if ($request->input('caseworker_id') === 'unassign') {
            $request->merge(['caseworker_id' => null]);
        }
        $validated = $request->validateWithBag('assignCaseworker', [
            'beneficiary_ids' => ['required', 'array', 'min:1', 'max:100'],
            'beneficiary_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'caseworker_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $operationId = (string) Str::uuid();
        [$count, $caseworkerName] = DB::transaction(function () use ($validated, $notifications, $operationId) {
            $caseworker = null;
            if ($validated['caseworker_id'] !== null) {
                $caseworker = User::eligibleCaseworkers()->whereKey($validated['caseworker_id'])->lockForUpdate()->first();
                if (! $caseworker) {
                    throw ValidationException::withMessages([
                        'caseworker_id' => 'Choose an approved caseworker with a verified email, or select Unassign.',
                    ])->errorBag('assignCaseworker');
                }
            }

            $beneficiaries = Beneficiary::query()->whereIn('id', $validated['beneficiary_ids'])
                ->orderBy('id')->lockForUpdate()->get();
            if ($beneficiaries->count() !== count($validated['beneficiary_ids'])) {
                throw ValidationException::withMessages([
                    'beneficiary_ids' => 'Some selected records are unavailable or archived. Close this dialog, refresh the Registry, and select again.',
                ])->errorBag('assignCaseworker');
            }
            foreach ($beneficiaries as $beneficiary) {
                Gate::authorize('assignCaseworker', $beneficiary);
            }
            $changes = [];
            $nextCaseworkerId = $caseworker?->id;
            foreach ($beneficiaries as $beneficiary) {
                $previousCaseworkerId = $beneficiary->caseworker_id;
                if ($previousCaseworkerId === $nextCaseworkerId) {
                    continue;
                }
                if ($caseworker) {
                    $beneficiary->caseworker()->associate($caseworker);
                } else {
                    $beneficiary->caseworker()->dissociate();
                }
                if (! $beneficiary->save()) {
                    throw ValidationException::withMessages([
                        'beneficiary_ids' => 'Assignments could not be saved. Please try again.',
                    ])->errorBag('assignCaseworker');
                }
                if ($previousCaseworkerId !== null) {
                    $changes[$previousCaseworkerId] ??= ['added' => 0, 'removed' => 0];
                    $changes[$previousCaseworkerId]['removed']++;
                }
                if ($nextCaseworkerId !== null) {
                    $changes[$nextCaseworkerId] ??= ['added' => 0, 'removed' => 0];
                    $changes[$nextCaseworkerId]['added']++;
                }
            }
            $notifications->assignmentsChanged($changes, $operationId);

            return [$beneficiaries->count(), $caseworker?->name];
        });

        $registryQuery = array_filter(Arr::only($request->query(), ['search', 'status', 'caseworker', 'page']), 'is_string');
        $records = $count.' '.($count === 1 ? 'beneficiary' : 'beneficiaries');

        return redirect()->route('beneficiaries.index', $registryQuery)->with('registry_selection_complete', true)->with('success', $caseworkerName === null
            ? 'Caseworker unassigned from '.$records.'.'
            : $records.' assigned to '.$caseworkerName.'.');
    }
}
