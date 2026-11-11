<?php
namespace App\Http\Controllers;

use App\Models\Beneficiary;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BeneficiaryBulkRestoreController extends Controller
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
            $beneficiaries = Beneficiary::onlyTrashed()->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get();
            if ($beneficiaries->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'beneficiary_ids' => 'Some selected records are unavailable or already restored. Refresh the page and select again.',
                ]);
            }
            foreach ($beneficiaries as $beneficiary) {
                Gate::authorize('restore', $beneficiary);
            }
            foreach ($beneficiaries as $beneficiary) {
                $beneficiary->restore();
            }
            return $beneficiaries->count();
        });
        return redirect()->route('beneficiaries.archived')->with('success',
            $count.' '.($count === 1 ? 'beneficiary restored.' : 'beneficiaries restored.'));
    }

    public function photo(int $beneficiary): StreamedResponse
    {
        Gate::authorize('viewArchived', Beneficiary::class);
        $record = Beneficiary::onlyTrashed()->findOrFail($beneficiary);
        Gate::authorize('restore', $record);
        $disk = Storage::disk('local');
        abort_unless($record->photo_path && $disk->exists($record->photo_path), 404);
        return $disk->response($record->photo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
