<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BeneficiaryBulkArchiveController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Gate::authorize('viewArchived', Beneficiary::class);
        $validated = $request->validate([
            'beneficiary_ids' => ['required', 'array', 'min:1', 'max:100'],
            'beneficiary_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $ids = $validated['beneficiary_ids'];
        $count = DB::transaction(function () use ($ids) {
            $beneficiaries = Beneficiary::query()->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get();
            if ($beneficiaries->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'beneficiary_ids' => 'Some selected records are unavailable or already archived. Refresh the page and select again.',
                ]);
            }
            foreach ($beneficiaries as $beneficiary) {
                Gate::authorize('delete', $beneficiary);
            }
            foreach ($beneficiaries as $beneficiary) {
                $beneficiary->delete();
            }

            return $beneficiaries->count();
        });

        return redirect()->route('beneficiaries.index')->with('registry_selection_complete', true)->with('success',
            $count.' '.($count === 1 ? 'beneficiary archived.' : 'beneficiaries archived.'));
    }
}
