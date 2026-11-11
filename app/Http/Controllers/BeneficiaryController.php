<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Support\NfcCardUid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BeneficiaryController extends Controller
{
    private const CASEWORKER_ERROR = 'Choose an approved caseworker with a verified email, or leave Unassigned.';

    public function index(Request $request): View|Response
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $status = $request->query('status', '');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : '';
        $tab = $request->query('tab') === 'caseloads' ? 'caseloads' : 'beneficiaries';
        $user = $request->user();
        $canManageCaseloads = Gate::allows('assignCaseworker', Beneficiary::class);

        $registryCaseworkers = User::query()->where('role', 'caseworker')
            ->when(! $canManageCaseloads, fn ($query) => $query->whereKey($user->id))
            ->select(['id', 'name', 'email', 'role', 'email_verified_at', 'approval_status'])
            ->when($tab === 'caseloads', fn ($query) => $query->withCount([
                'assignedBeneficiaries' => fn ($beneficiaries) => $beneficiaries->visibleTo($user),
            ]))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $caseworkerFilter = $canManageCaseloads ? $request->query('caseworker', '') : '';
        $caseworkerFilter = is_string($caseworkerFilter) ? $caseworkerFilter : '';
        $selectedCaseworker = $registryCaseworkers->first(fn ($account) => (string) $account->id === $caseworkerFilter);
        if ($caseworkerFilter !== 'unassigned' && ! $selectedCaseworker) {
            $caseworkerFilter = '';
        }
        $caseloadName = $caseworkerFilter === 'unassigned' ? 'Unassigned' : $selectedCaseworker?->name;
        $registryFilters = array_filter(['search' => $search, 'status' => $status, 'caseworker' => $caseworkerFilter],
            fn ($value) => $value !== '');

        $beneficiaries = null;
        $unassignedCount = 0;
        if ($tab === 'caseloads') {
            $unassignedCount = $canManageCaseloads
                ? Beneficiary::query()->visibleTo($user)->whereNull('caseworker_id')->count() : 0;
        } else {
            $beneficiaries = Beneficiary::query()
                ->visibleTo($user)
                ->with('caseworker:id,name,email')
                ->matchingSearch($search)
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->when($caseworkerFilter === 'unassigned', fn ($query) => $query->whereNull('caseworker_id'))
                ->when($selectedCaseworker !== null, fn ($query) => $query->where('caseworker_id', $selectedCaseworker->id))
                ->alphabetical()
                ->paginate(10)
                ->appends($registryFilters);
        }

        $caseworkers = Gate::allows('assignCaseworker', Beneficiary::class)
            ? $registryCaseworkers->filter(fn ($account) => $account->isEligibleCaseworker())->values()
            : collect();

        $view = view('beneficiaries.index', compact('beneficiaries', 'search', 'status', 'caseworkers',
            'registryCaseworkers', 'caseworkerFilter', 'caseloadName', 'registryFilters', 'tab', 'unassignedCount'));

        if ($tab === 'beneficiaries' && $request->header('X-Registry-Fragment') === 'results') {
            return response($view->fragments(['registry-caseload', 'registry-results']))->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $view;
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()?->isEligibleCaseworker()) {
            return redirect()->route('beneficiaries.index')
                ->with('error', 'Only Directors can add beneficiaries.');
        }

        Gate::authorize('create', Beneficiary::class);

        if ($request->has('caseworker_id')) {
            Gate::authorize('assignCaseworker', Beneficiary::class);
        }
        $caseworkers = Gate::allows('assignCaseworker', Beneficiary::class)
            ? User::eligibleCaseworkers()->orderBy('name')->orderBy('id')->get(['id', 'name', 'email'])
            : collect();
        $requestedCaseworkerId = $request->query('caseworker_id');
        $selectedCaseworkerId = (is_string($requestedCaseworkerId) || is_int($requestedCaseworkerId))
            ? $caseworkers->first(fn ($account) => (string) $account->id === (string) $requestedCaseworkerId)?->id
            : null;
        $preselectionUnavailable = $requestedCaseworkerId !== null && $requestedCaseworkerId !== '' && $selectedCaseworkerId === null;

        return view('beneficiaries.create', compact('caseworkers', 'selectedCaseworkerId', 'preselectionUnavailable'));
    }

    public function store(Request $request, AccountNotificationService $notifications): RedirectResponse
    {
        Gate::authorize('create', Beneficiary::class);

        if ($request->has('caseworker_id')) {
            Gate::authorize('assignCaseworker', Beneficiary::class);
        }
        $request->validate(['nfc_uid' => ['nullable', 'string', 'max:64']]);
        if ($request->filled('nfc_uid')) {
            $request->merge(['nfc_uid' => NfcCardUid::normalize($request->input('nfc_uid'))]);
        }

        $validated = $request->validate([
            'beneficiary_id' => [
                'required', 'string', 'max:50',
                'unique:beneficiaries,beneficiary_id',
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'registered_at' => ['required', 'date'],
            'birthdate' => ['nullable', 'date', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:1000'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_contact' => ['nullable', 'string', 'max:30'],
            'school' => ['nullable', 'string', 'max:255'],
            'grade_level' => ['nullable', 'string', 'max:50'],
            'photo' => [
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240',
            ],
            'nfc_uid' => NfcCardUid::rules(required: false),
            'caseworker_id' => ['bail', 'nullable', 'integer', 'min:1', function ($attribute, $value, $fail) {
                if (! User::eligibleCaseworkers()->whereKey($value)->exists()) {
                    $fail(self::CASEWORKER_ERROR);
                }
            }],
        ], NfcCardUid::messages());

        $nfcUid = $validated['nfc_uid'] ?? null;
        $caseworkerId = $validated['caseworker_id'] ?? null;
        unset($validated['photo'], $validated['nfc_uid'], $validated['caseworker_id']);

        // Registration is for beneficiaries already approved by RLCP.
        $validated['status'] = 'active';
        $photoPath = null;

        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')
                ->store('beneficiary-photos', 'local');

            if (! $photoPath) {
                throw ValidationException::withMessages([
                    'photo' => 'Photo could not be saved. Please try again.',
                ]);
            }

            $validated['photo_path'] = $photoPath;
        }

        $operationId = (string) Str::uuid();
        try {
            $beneficiary = DB::transaction(function () use ($validated, $nfcUid, $caseworkerId, $notifications, $operationId) {
                $caseworker = null;
                if ($caseworkerId !== null) {
                    $caseworker = User::eligibleCaseworkers()->whereKey($caseworkerId)->lockForUpdate()->first();
                    if (! $caseworker) {
                        throw ValidationException::withMessages(['caseworker_id' => self::CASEWORKER_ERROR]);
                    }
                }
                if ($nfcUid !== null) {
                    // Availability can change after scanning or initial form validation.
                    Validator::make(['nfc_uid' => $nfcUid], [
                        'nfc_uid' => NfcCardUid::rules(),
                    ], NfcCardUid::messages())->validate();
                }

                $record = new Beneficiary($validated);
                if ($caseworker !== null) {
                    Gate::authorize('assignCaseworker', $record);
                    $record->caseworker()->associate($caseworker);
                }
                $record->nfc_uid = $nfcUid;
                abort_unless($record->save(), 500, 'Unable to save the beneficiary.');
                if ($caseworker !== null) {
                    $notifications->assignmentsChanged([$caseworker->id => ['added' => 1, 'removed' => 0]], $operationId);
                }

                return $record;
            }, 3);
        } catch (\Throwable $exception) {
            if ($photoPath) {
                Storage::disk('local')->delete($photoPath);
            }

            if ($exception instanceof UniqueConstraintViolationException
                && (NfcCardUid::isConflict($exception)
                    || ($nfcUid !== null && Beneficiary::withTrashed()->where('nfc_uid', $nfcUid)->exists()))) {
                throw ValidationException::withMessages(['nfc_uid' => NfcCardUid::OWNERSHIP_ERROR]);
            }

            throw $exception;
        }

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Beneficiary registered successfully.');
    }

    public function edit(Beneficiary $beneficiary): View
    {
        Gate::authorize('update', $beneficiary);

        return view('beneficiaries.edit', compact('beneficiary'));
    }

    public function update(Request $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('update', $beneficiary);

        $validated = $request->validate([
            'beneficiary_id' => [
                'required',
                'string',
                'max:50',
                Rule::unique('beneficiaries', 'beneficiary_id')
                    ->ignore($beneficiary),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'registered_at' => ['required', 'date'],
            'birthdate' => ['nullable', 'date', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:1000'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_contact' => ['nullable', 'string', 'max:30'],
            'school' => ['nullable', 'string', 'max:255'],
            'grade_level' => ['nullable', 'string', 'max:50'],
            'photo' => [
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240',
            ],
        ]);

        unset($validated['photo']);

        $oldPhotoPath = $beneficiary->photo_path;
        $newPhotoPath = null;

        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')
                ->store('beneficiary-photos', 'local');

            if (! $newPhotoPath) {
                throw ValidationException::withMessages([
                    'photo' => 'Photo could not be saved. Please try again.',
                ]);
            }

            $validated['photo_path'] = $newPhotoPath;
        }

        try {
            $beneficiary->update($validated);
        } catch (\Throwable $exception) {
            if ($newPhotoPath) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw $exception;
        }

        // Remove the previous photo only after the record is updated.
        if ($newPhotoPath && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            try {
                Storage::disk('local')->delete($oldPhotoPath);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Beneficiary updated successfully.');
    }

    public function archived(Request $request): View
    {
        Gate::authorize('viewArchived', Beneficiary::class);

        $search = trim((string) $request->query('search', ''));

        $beneficiaries = Beneficiary::onlyTrashed()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('beneficiary_id', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('deleted_at')
            ->paginate(10)
            ->withQueryString();

        return view('beneficiaries.archived', compact('beneficiaries', 'search'));
    }

    public function destroy(Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('delete', $beneficiary);

        $beneficiary->delete();

        return redirect()
            ->route('beneficiaries.archived')
            ->with('success', 'Beneficiary archived. You can restore this record anytime.');
    }

    public function restore(string $beneficiary): RedirectResponse
    {
        Gate::authorize('viewArchived', Beneficiary::class);

        $record = Beneficiary::onlyTrashed()->findOrFail($beneficiary);
        Gate::authorize('restore', $record);
        $record->restore();

        return redirect()
            ->route('beneficiaries.show', $record)
            ->with('success', 'Beneficiary restored successfully.');
    }

    public function show(Beneficiary $beneficiary): View
    {
        Gate::authorize('view', $beneficiary);

        $attendances = ActivityAttendance::query()
            ->with('activity')
            ->where('beneficiary_id', $beneficiary->id)
            ->orderByDesc('checked_in_at')
            ->paginate(10);

        $assistanceTransactions = AssistanceTransaction::query()
            ->with('recorder')
            ->where('beneficiary_id', $beneficiary->id)
            ->orderByDesc('released_at')
            ->paginate(10, ['*'], 'assistance_page');

        return view('beneficiaries.show', compact(
            'beneficiary',
            'attendances',
            'assistanceTransactions'
        ));
    }
}
