<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BeneficiarySearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'caseworker' => ['bail', 'nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
                if ($value !== 'unassigned' && (! preg_match('/\A[1-9][0-9]*\z/', $value)
                    || ! User::query()->where('role', 'caseworker')->whereKey($value)->exists())) {
                    $fail('Choose an existing caseworker or Unassigned.');
                }
            }],
        ]);
        $search = trim($validated['search'] ?? '');
        $status = $validated['status'] ?? null;
        $caseworker = $validated['caseworker'] ?? null;
        $results = collect();

        if ($search !== '') {
            $results = Beneficiary::query()
                ->visibleTo($request->user())
                ->matchingSearch($search)
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->when($caseworker === 'unassigned', fn ($query) => $query->whereNull('caseworker_id'))
                ->when($caseworker !== null && $caseworker !== 'unassigned', fn ($query) => $query->where('caseworker_id', $caseworker))
                ->orderBy('full_name')
                ->orderBy('id')
                ->limit(8)
                ->get(['id', 'full_name', 'beneficiary_id', 'photo_path', 'caseworker_id'])
                ->filter(fn (Beneficiary $beneficiary) => Gate::allows('view', $beneficiary))
                ->map(fn (Beneficiary $beneficiary) => [
                    'id' => $beneficiary->id,
                    'name' => $beneficiary->full_name,
                    'beneficiary_id' => $beneficiary->beneficiary_id,
                    'url' => route('beneficiaries.show', $beneficiary),
                    'photo_url' => $beneficiary->photo_path
                        ? route('beneficiaries.photo', $beneficiary) : null,
                ])->values();
        }

        return response()->json(['results' => $results])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
